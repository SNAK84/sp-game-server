#!/usr/bin/env python3
"""Пагинация на основном dev. Подготовка — 21 сообщение только qaown_260924.

--prepare выполняется при остановленном основном сервере; --execute сравнивает
все страницы с SQL и удаляет только подготовленные сообщения штатным v2.
Никаких отдельных миров, ускорения времени и подмен репозиториев.
"""
import argparse
import hashlib
import json
import subprocess
import time
import uuid

from CapacitySafetyLiveTest import Client


def sql(prepare=False):
    code = r'''
    require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');
    $db=SPGame\Core\Database::independent();
    $user=$db->fetchOne("SELECT u.id FROM users u JOIN accounts a ON a.id=u.account_id WHERE a.login=?",['qaown_260924']);
    if(!$user)throw new LogicException('Нет QA-аккаунта.');$id=(int)$user['id'];
    $fixture=$db->fetchAll("SELECT * FROM messages WHERE to_id=? AND sample='QAMessagesPagination' ORDER BY id",[$id]);
    if(__PREPARE__ && !$fixture){
        $db->beginTransaction();
        try{
            $range=$db->fetchOne("SELECT high_water FROM repository_id_ranges WHERE repository='messages' FOR UPDATE");
            if(!$range)throw new LogicException('Нет долговечного резерва сообщений.');
            $start=max((int)$range['high_water'],(int)$db->fetchOne('SELECT MAX(id) n FROM messages')['n']);
            $db->query("UPDATE repository_id_ranges SET high_water=? WHERE repository='messages'",[$start+1024]);
            $ids=range($start+1,$start+21);
            foreach($ids as $n)$db->query('INSERT INTO messages (id,from_id,`from`,to_id,type,subject,text,sample,data,time,`read`) VALUES (?,0,?,?,3,?,?,?, ?,?,0)',
                [$n,'QA',$id,'QA pagination','Проверка страницы сообщений','QAMessagesPagination','{}',time()-120]);
            $data=json_encode(['probe'=>bin2hex(random_bytes(4090))],JSON_THROW_ON_ERROR);
            if(strlen($data)!==8192)throw new LogicException('Неверная граница payload.');
            $db->query('INSERT INTO messages (id,from_id,`from`,to_id,type,subject,text,sample,data,time,`read`) VALUES (?,0,?,?,2,?,?,?,?,?,0)',
                [$start+22,'QA',$id,'QA payload',str_repeat('Я',512),'QAMessagesPayload',$data,time()-120]);
            $db->query('INSERT INTO messages (id,from_id,`from`,to_id,type,subject,text,sample,data,time,`read`) VALUES (?,0,?,?,2,?,?,?,?,?,0)',
                [$start+23,'QA',$id,'QA expired','Проверка TTL','QAMessagesExpired','{}',time()-8*86400]);
            $db->commit();
        }catch(Throwable $e){$db->rollBack();throw $e;}
    }
    if(__PREPARE__){
        $browser=$db->fetchOne("SELECT u.id FROM users u JOIN accounts a ON a.id=u.account_id WHERE a.login=?",['qafor_260924']);
        if(!$browser)throw new LogicException('Нет browser QA.');
        if(!$db->fetchOne("SELECT id FROM messages WHERE to_id=? AND sample='QAMessagesBrowser' LIMIT 1",[$browser['id']])){
            $db->beginTransaction();
            try{
                $range=$db->fetchOne("SELECT high_water FROM repository_id_ranges WHERE repository='messages' FOR UPDATE");
                $start=(int)$range['high_water'];
                $db->query("UPDATE repository_id_ranges SET high_water=? WHERE repository='messages'",[$start+1024]);
                for($i=1;$i<=47;$i++)$db->query('INSERT INTO messages (id,from_id,`from`,to_id,type,subject,text,sample,data,time,`read`) VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
                    [$start+$i,'QA',(int)$browser['id'],$i<=45?23:21,'QA browser','Проверка страницы','QAMessagesBrowser','{}',time()-60]);
                $db->commit();
            }catch(Throwable $e){$db->rollBack();throw $e;}
        }
    }
    echo json_encode($db->fetchAll('SELECT id,to_id,type,time,`read`,sample,SHA2(CONCAT(text,CHAR(0),data),256) body_hash FROM messages WHERE to_id=? AND time>? ORDER BY time DESC,id DESC',[$id,microtime(true)-7*86400]));
    '''.replace('__PREPARE__', 'true' if prepare else 'false')
    output = subprocess.run(['php', '-r', code], cwd='/opt/sp-game',
                            text=True, capture_output=True, check=True)
    return json.loads(output.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--prepare', action='store_true')
    mode.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    if args.prepare:
        running = subprocess.run(['docker', 'inspect', 'swoole_app', '--format', '{{.State.Running}}'],
                                 capture_output=True, text=True, check=True)
        assert running.stdout.strip() == 'false', 'Подготовка разрешена только после swoole_manager.sh stop.'
        rows = sql(True)
        assert len([x for x in rows if x['sample'] == 'QAMessagesPagination']) == 21
        print('MESSAGES_PAGINATION_QA_PREPARED21', flush=True)
        return

    client = actor = None
    try:
        expected = sql()
        fixture = [str(x['id']) for x in expected if x['sample'] == 'QAMessagesPagination']
        assert len(fixture) == 21, 'Сначала подготовить QA при остановленном main.'
        client = Client('qaown_260924')
        actor = Client('qafor_260924')
        counts = {str(t): {'total': 0, 'unread': 0} for t in [1, 2, 3, 11, 12, 21, 22, 23, 24, 99]}
        for row in expected:
            counts['99']['total'] += 1
            counts['99']['unread'] += int(row['read'] == 0)
            if str(row['type']) in counts and row['type'] != 99:
                counts[str(row['type'])]['total'] += 1
                counts[str(row['type'])]['unread'] += int(row['read'] == 0)
        checks = 0
        for category in map(int, counts):
            selected = [r for r in expected if category == 99 or r['type'] == category]
            pages = max(1, (len(selected) + 19) // 20)
            for page in range(1, pages + 1):
                value = client.query('messages.get', {'page': page, 'category': category})
                assert value['messageCounts'] == counts
                assert [m['id'] for m in value['allMessages']] == [str(r['id']) for r in selected[(page - 1) * 20:page * 20]]
                for message, stored in zip(value['allMessages'], selected[(page - 1) * 20:page * 20]):
                    assert hashlib.sha256((message['text'] + '\0' + message['data']).encode()).hexdigest() == stored['body_hash']
                assert value['pagination'] == dict(page=page, size=20, pages=pages, total=len(selected), category=category)
                checks += 1
            clipped = client.query('messages.get', {'page': 1000000, 'category': category})
            assert clipped['pagination']['page'] == pages
        print('LIVE_MESSAGES_SQL_PAGES_MATCH', checks, flush=True)
        for data in [{'page': x} for x in [0, -1, '2', True, None, 2.5, 1000001]] + [{'category': x} for x in [0, 98, '3', True, None, []]]:
            for kind in ['query', 'subscribe']:
                options = {'subscriptionId': str(uuid.uuid4())} if kind == 'subscribe' else {}
                result = client.call(kind, name='messages.get', context=client.context, data=data, **options)
                assert result['type'] == 'error' and result['error']['code'] == 'query.invalid_input', (kind, result.get('type'))
                checks += 1

        sub = str(uuid.uuid4())
        subscribed = client.call('subscribe', name='messages.get', context=client.context,
                                 data={'page': 2, 'category': 3}, subscriptionId=sub)
        before = subscribed['data']['value']
        last = before['allMessages'][-1]['id']
        assert last in fixture
        actor.command('messages.delete', {'Ids': [last]}, 'rejected')
        assert client.query('messages.get', {'page': 2, 'category': 3}) == before
        client.command('messages.read', {'ReadId': [last]})
        read = client.query('messages.get', {'page': 2, 'category': 3})
        assert read['pagination'] == before['pagination'] and next(m for m in read['allMessages'] if m['id'] == last)['read'] == 1
        client.command('messages.delete', {'Ids': [last]})
        removed = client.query('messages.get', {'page': 2, 'category': 3})
        assert removed['pagination']['page'] == 1 and removed['pagination']['total'] == 20
        for offset in range(0, 20, 10):
            remaining = [x for x in fixture if x != last][offset:offset + 10]
            client.command('messages.delete', {'Ids': remaining})
        payload = [str(r['id']) for r in expected if r['sample'] == 'QAMessagesPayload']
        if payload:
            client.command('messages.read', {'ReadId': payload})
            client.command('messages.delete', {'Ids': payload})
        empty = client.query('messages.get', {'page': 2, 'category': 3})
        assert empty['allMessages'] == [] and empty['pagination']['page'] == 1 and empty['pagination']['total'] == 0
        client.call('unsubscribe', subscriptionId=sub)
        # Обычный saver; отсутствие новых строк проверяется и в долговечном SQL.
        deadline = time.monotonic() + 45
        original = [r for r in expected if r['sample'] not in ('QAMessagesPagination', 'QAMessagesPayload')]
        while time.monotonic() < deadline:
            after = sql()
            if not any(r['sample'] in ('QAMessagesPagination', 'QAMessagesPayload') for r in after):
                break
            time.sleep(1)
        assert after == original, 'Изменились исходные QA-сообщения или не сохранилось удаление fixture.'
        print('LIVE_MESSAGES_PAGINATION_OK', json.dumps({'pageChecks': checks, 'sqlCompared': len(expected),
              'invalidInputs': 26, 'fixtureRemoved': 21 + len(payload), 'payloadHashesVerified': True,
              'originalMessagesUnchanged': True}), flush=True)
    finally:
        if client:
            client.close()
        if actor:
            actor.close()


if __name__ == '__main__':
    main()
