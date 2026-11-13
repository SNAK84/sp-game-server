#!/usr/bin/env python3
"""Основной dev: первая авторизация QA, RAM-доклад/закладка, сохранение и restart.

Создаёт только выделенный QA-аккаунт, не отправляет почту. Пароль случайный и
не выводится. Закладка тестового аккаунта возвращается в исходное состояние.
Игровой SQL в тесте только читается; все действия идут по настоящему v2.
"""
import argparse
import hashlib
import json
import subprocess
import time

from CapacitySafetyLiveTest import Client
from BackgroundSaverLiveTest import sql, stats


def prepare():
    code = r'''
    require 'src/bootstrap.php';
    $db=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
    $repo=new SPGame\Cabinet\Repositories\AccountRepository($db);
    $account=$repo->create('qa.ram-runtime@sp-game.invalid',password_hash(bin2hex(random_bytes(32)),PASSWORD_ARGON2ID));
    $repo->verifyEmail($account['id']);
    $session=(new SPGame\Cabinet\Repositories\SessionRepository($db))->create($account['id'],'native');
    echo json_encode((new SPGame\Cabinet\Services\WorldService($db))->start($session['accessToken'],'sp-game-dev'));
    '''
    result = subprocess.run(['docker', 'exec', '-i', 'sp_game_cabinet', 'php', '-r', code],
                            capture_output=True, text=True, check=True, timeout=15)
    return json.loads(result.stdout)


def bookmark(report_id, planet_id):
    return sql('''
    echo json_encode(['saved'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM battle_report_saves WHERE report_id=? AND user_id=(SELECT owner_id FROM planets WHERE id=?)',[$x['report'],$x['planet']])['n'],
      'payloadHash'=>$db->fetchOne('SELECT SHA2(payload,256) hash FROM battle_reports WHERE id=?',[$x['report']])['hash'],
      'identity'=>$db->fetchOne('SELECT a.id account_id,u.id player_id FROM planets p JOIN users u ON u.id=p.owner_id JOIN accounts a ON a.id=u.account_id WHERE p.id=?',[$x['planet']])]);
    ''', {'report': report_id, 'planet': planet_id})


def wait_for(predicate, seconds=45):
    deadline = time.monotonic() + seconds
    while True:
        value = predicate()
        if value:
            return value
        assert time.monotonic() < deadline, 'Истёк срок живой проверки.'
        time.sleep(.4)


def connect(g):
    for _ in range(40):
        try:
            return Client('qa-ram-runtime', world_grant=g)
        except (OSError, AssertionError):
            time.sleep(.5)
    raise AssertionError('Не удалось подключиться к запущенному миру.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.parse_args()
    g = prepare()
    client = None
    report_id = original = planet_id = None
    try:
        client = connect(g)
        planet_id = int(client.context['planetId'])
        overview = client.query('overview.get')
        assert overview and planet_id > 0
        print('RAM_NEW_PLAYER_AUTH_OK', json.dumps({'planetId': planet_id}), flush=True)
        report_id = sql('echo json_encode($db->fetchOne("SELECT id FROM battle_reports WHERE ready=1 AND (expires_at>? OR saved_count>0) ORDER BY created_at DESC LIMIT 1",[microtime(true)])["id"]);', {})
        before = client.query('combat.report.get', {'id': report_id})
        original = before['savedByMe']
        digest = hashlib.sha256(json.dumps(before['report'], sort_keys=True).encode()).hexdigest()
        client.command('combat.report.save', {'id': report_id})
        assert client.query('combat.report.get', {'id': report_id})['savedByMe']
        assert any(r['id'] == report_id for r in client.query('combat.reports.get', {'page': 1})['reports'])
        wait_for(lambda: bookmark(report_id, planet_id)['saved'] == 1)
        saved = bookmark(report_id, planet_id)
        assert saved['identity'], 'Первая авторизация не сохранена общим снимком.'
        print('RAM_REPORT_SAVE_SQL_OK', flush=True)
        client.close()
        client = None
        result = subprocess.run(['timeout', '12', './swoole_manager.sh', 'restart'], cwd='/opt/sp-game',
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
        assert result.returncode in (0, 124), 'Не выполнен штатный restart.'
        client = connect(g)
        assert int(client.context['planetId']) == planet_id
        after = client.query('combat.report.get', {'id': report_id})
        assert after['savedByMe']
        assert hashlib.sha256(json.dumps(after['report'], sort_keys=True).encode()).hexdigest() == digest
        assert bookmark(report_id, planet_id)['payloadHash'] == saved['payloadHash']
        print('RAM_IDENTITY_REPORT_RESTART_OK', flush=True)
    finally:
        if client is not None:
            try:
                if report_id is not None and original is not None:
                    client.command('combat.report.save' if original else 'combat.report.unsave', {'id': report_id})
                    wait_for(lambda: bookmark(report_id, planet_id)['saved'] == int(original))
            finally:
                client.close()
    wait_for(lambda: stats()['storage']['storage.workerHeartbeat'] > time.time() - 6)
    assert stats()['storage']['storage.runtimeSqlRejected'] == 0, 'Найден SQL-вызов игрового обработчика.'
    print('RAM_RUNTIME_LIVE_OK bookmarkRestored=1 runtimeSqlRejected=0', flush=True)


if __name__ == '__main__':
    main()
