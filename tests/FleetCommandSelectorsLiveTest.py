#!/usr/bin/env python3
"""B069/B070 на основном dev: настоящая история stop/resume, restart и очистка.

Не создаёт отдельный мир и не пишет игровые строки SQL. Использует существующий
свободный флот qaown_260924; топливо расходуется штатно. В finally отзывает рейс
домой и удаляет только сообщения этого рейса.
"""
import argparse
import json
import subprocess
import tempfile
import time
from pathlib import Path

from CapacitySafetyLiveTest import Client, grant, rows
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import wait_for
from PlanetClockLiveTest import manage, running


def saved_commands(fleet_id):
    return sql('echo json_encode($db->fetchAll("SELECT id,command_type,executed,execute_time FROM fleet_commands WHERE fleet_id=? ORDER BY id",[$x]));', int(fleet_id))


def version_compatibility(fleet_id):
    """Чистое сравнение сериализации реального SQL-снимка; игрового мира здесь нет."""
    return sql(r'''
    $rows=$db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=? ORDER BY id',[$x]);
    if(count($rows)<64)throw new LogicException('Нет сохранённой длинной QA-истории.');
    $method=new ReflectionMethod(SPGame\Game\Services\FleetControlService::class,'commandVersion');
    $reference=static function(array $commands):string {
        foreach($commands as &$row){
            $p=SPGame\Game\Services\FleetControlService::params($row);
            unset($p['fuelNotified'],$p['loadNotified'],$p['interceptNotified']);
            $row['params']=json_encode($p,JSON_THROW_ON_ERROR);
        }
        unset($row);
        return hash('sha256',json_encode($commands,JSON_THROW_ON_ERROR));
    };
    $legacyReference=require 'tests/FleetLegacyProgressReference.php';
    $measure=static function(callable $operation):array {
        memory_reset_peak_usage();$start=memory_get_usage();$hash=$operation();
        return [$hash,max(0,memory_get_peak_usage()-$start)];
    };
    // Прогреваем автозагрузку/рефлексию перед сравнением PHP-аллокаций.
    $empty=$method->invoke(null,[]);$method->invoke(null,array_slice($rows,0,1));
    $before=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    [$actual,$peak]=$measure(static fn()=>$method->invoke(null,$rows));
    [$expected,$oldPeak]=$measure(static fn()=>$reference($rows));
    $markers=$rows;
    $p=SPGame\Game\Services\FleetControlService::params($markers[0]);
    foreach(['fuelNotified','loadNotified','interceptNotified'] as $key)$p[$key]='QA:маркер/42';
    $markers[0]['params']=json_encode($p,JSON_THROW_ON_ERROR);
    $progress=new ReflectionMethod(SPGame\Game\Services\FleetControlService::class,'legacyProgress');
    $legacy=$rows;
    foreach($legacy as &$row){
        if(!in_array((int)$row['command_type'],[10,11,12],true))continue;
        $p=SPGame\Game\Services\FleetControlService::params($row);
        $start=(float)($p['startedAt']??0);
        if(empty($p['cancelled'])||!isset($p['progressCompleted'])||$start<=0||(float)$row['execute_time']<=$start)continue;
        unset($p['progressCompleted']);$row['params']=json_encode($p,JSON_THROW_ON_ERROR);break;
    }
    unset($row);
    $legacyExpected=$legacyReference($legacy);
    echo json_encode(['rows'=>count($rows),'sameOldHash'=>$actual===$expected,
        'emptyCanonical'=>$empty===hash('sha256','[]'),
        'markersIgnored'=>$method->invoke(null,$markers)===$actual,
        'snapshotUnchanged'=>$before===hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),
        'modernProgressSame'=>$progress->invoke(null,$rows)===$legacyReference($rows),
        'legacyProgressSame'=>$legacyExpected[1]!==[]&&$progress->invoke(null,$legacy)===$legacyExpected,
        'peakAdditionalBytes'=>$peak,'legacyPeakAdditionalBytes'=>$oldPeak]);
    ''', int(fleet_id))


def legacy_progress_fixture(fleet_id, hold_id):
    """Старый формат только реальной QA-истории, при выключенном основном мире."""
    assert not running(), 'SQL fixture разрешена только при выключенном мире.'
    code = r'''
    require 'vendor/autoload.php'; SPGame\Core\Environment::init('.env');
    $x=json_decode(stream_get_contents(STDIN),true);$db=SPGame\Core\Database::independent();
    $fleet=$db->fetchOne("SELECT f.* FROM fleets f JOIN users u ON u.id=f.owner_id JOIN accounts a ON a.id=u.account_id
        WHERE f.id=? AND a.login='qaown_260924'",[$x['fleet']]);
    if($fleet===null||(int)$fleet['status']!==1)throw new LogicException('Нет QA-рейса.');
    $rows=$db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=? ORDER BY id',[$x['fleet']]);
    $active=array_values(array_filter($rows,static fn($r)=>!(int)$r['executed']&&(float)$r['execute_time']>0));
    if(count($rows)<64||count($active)!==1||(int)$active[0]['id']!==$x['hold']||(int)$active[0]['command_type']!==43)
        throw new LogicException('Нужна настоящая история с активным QA-удержанием.');
    $changed=0;$committed=false;$db->beginTransaction();
    try {
        foreach($rows as &$row){
            if(!(int)$row['executed']||!in_array((int)$row['command_type'],[10,11,12],true))continue;
            $p=json_decode($row['params'],true,512,JSON_THROW_ON_ERROR);
            if(empty($p['cancelled'])||!isset($p['progressCompleted'])||(float)($p['startedAt']??0)<=0)continue;
            unset($p['progressCompleted']);$encoded=json_encode($p,JSON_THROW_ON_ERROR);
            if($db->executeAffected('UPDATE fleet_commands SET params=? WHERE id=? AND params=?',[$encoded,$row['id'],$row['params']])!==1)
                throw new LogicException('QA-образ изменился.');
            $row['params']=$encoded;++$changed;
        }
        unset($row);
        $reference=require 'tests/FleetLegacyProgressReference.php';
        [$weights,$completed]=$reference($rows);
        $total=$done=0.0;$boundaries=[];
        foreach($rows as $row){
            if(!in_array((int)$row['command_type'],[10,11,12],true))continue;
            $p=json_decode($row['params'],true,512,JSON_THROW_ON_ERROR);
            if(!empty($p['cancelled'])){
                $value=max(0.0,(float)($p['progressCompleted']??$completed[$row['id']]??0));$total+=$value;$done+=$value;continue;
            }
            $weight=max(0.0,(float)($p['progressWeight']??$weights[$row['id']]??1));$total+=$weight;$boundaries[]=$total;
            if((int)$row['executed'])$done+=$weight;
            else $done+=min($weight,max(0.0,(float)($p['progressDone']??0)));
        }
        if(count($boundaries)>255)throw new LogicException('Fixture рассчитана на короткий остаток маршрута.');
        $db->commit();$committed=true;
        echo json_encode(['changed'=>$changed,'completed'=>count($completed),'expected'=>[
            'completed'=>$done,'total'=>$total,'active'=>null,'boundaries'=>$boundaries]],JSON_THROW_ON_ERROR);
    }catch(Throwable $error){if(!$committed)$db->rollBack();throw $error;}
    '''
    result = subprocess.run(['php', '-r', code], input=json.dumps({'fleet': int(fleet_id), 'hold': int(hold_id)}),
                            text=True, capture_output=True, check=True, timeout=15)
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.add_argument('--cycles', type=int, default=24)
    parser.add_argument('--history-version', action='store_true', help='B070: совместимость SHA256 и PHP-память на реальной истории.')
    parser.add_argument('--legacy-progress', action='store_true', help='B074: настоящий restart со старой QA-историей без progressCompleted.')
    parser.add_argument('--browser-history', action='store_true')
    parser.add_argument('--pause-for-undo-probe', action='store_true', help='Пауза для временного bootstrap-probe на основном мире.')
    args = parser.parse_args()
    assert 24 <= args.cycles <= 80, 'Нужна история больше нескольких блоков16 ID.'
    g = grant('qaown_260924')
    client = Client('qaown_260924', world_grant=g)
    fid = None
    flying = False
    checks = 0
    max_query_ms = 0.0
    old_messages = set()

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    def fleet(history_page=None):
        nonlocal max_query_ms
        start = time.monotonic()
        page = client.query('fleets.get', {'historyPages': {fid: history_page}} if history_page is not None else {})
        max_query_ms = max(max_query_ms, (time.monotonic() - start) * 1000)
        return next(f for key in ('FleetList', 'FlyingFleets') for f in rows(page[key]) if f['id'] == fid)

    def selected(event, kind):
        commands = event['commands']
        check(len(commands) <= 50, 'В карточку выгружена вся история.')
        ids = [int(c['id']) for c in commands]
        check(ids == sorted(ids), 'Публичная цепочка не отсортирована по ID.')
        active = [c for c in commands if c['status'] == 'active']
        check(len(active) == 1 and int(active[0]['id']) == int(event['command']['id']), 'ACTIVE-селектор и проекция расходятся.')
        check(event['command']['type'] == kind, 'Выбрана не та команда: ' + str(event['command']))
        return int(event['command']['id'])

    def return_home():
        nonlocal flying
        if flying:
            current = fleet()
            if current['status'] != 0:
                client.command('fleet.recall', {'fleetId': fid})
                if args.cycles > 40:
                    # Историю набираем на 10% скорости, но уборка не должна
                    # ждать длинного орбитального возврата. Штатный редактор,
                    # без ускорения времени/подмены позиции.
                    current = fleet()
                    if current['actions']['StopFleet']:
                        client.command('fleet.stop', {'fleetId': fid})
                        current = fleet()
                    client.command('fleet.route.set', {'fleetId': fid, 'plan': {
                        'version': current['control']['version'],
                        'steps': [{'kind': 'return', 'speedFactor': 10}], 'fuel': 0, 'resources': {}}})
                    client.command('fleet.resume', {'fleetId': fid})
                wait_for(lambda: fleet()['status'] == 0, 240)
            flying = False

    try:
        page = client.query('fleets.get')
        chosen = next(f for f in rows(page['FleetList']) if f['status'] == 0 and f['home_site_id'] == '0'
                      and f['fuel'] == 0 and f['ships'].get('202', {}).get('count', 0) > 0 and not f['control']['steps'])
        fid = chosen['id']
        check(not saved_commands(fid), 'Нельзя затронуть прежнюю цепочку QA.')
        counts = {k: v['count'] for k, v in chosen['ships'].items()}
        place = chosen['current']
        old_messages = {m['id'] for m in client.query('messages.get')['allMessages']}
        nav = client.query('fleet.navigation.get', {'galaxy': place['galaxy'], 'system': place['system'], 'fleetId': fid})['NavigationSystem']
        target = {'type': 2, 'galaxy': place['galaxy'], 'system': place['system'],
                  'distance': nav['minDistance'] + (nav['maxDistance'] - nav['minDistance']) * .7, 'angle': 85.0}
        speed = 1 if args.cycles > 40 else 5
        preview = client.query('fleet.missions.get', {'fleetId': fid, 'coordinates': target, 'speedFactor': speed, 'missionType': 30})
        check(30 in preview['AvailableMissions']['missions'], 'Удержание недоступно.')
        fuel = min(chosen['capacity'] - 1, max(1000, preview['TravelInfo']['fuel']['max'] * 3))
        client.command('fleet.send', {'fleetId': fid, 'missionType': 30, 'coordinates': target,
                                     'speedFactor': speed, 'fuel': fuel, 'resources': {}})
        flying = True
        selected(fleet(), 10)
        for cycle in range(args.cycles):
            client.command('fleet.stop', {'fleetId': fid})
            held = fleet()
            active_id = selected(held, 43)
            check(held['control']['holding'] and held['actions']['ResumeFleet'], 'Нет управляемого удержания.')
            pending = [int(c['id']) for c in held['commands'] if c['status'] == 'pending']
            next_page = held['commands_page']['page'] + 1
            while len(pending) + 1 < held['commands_left'] and next_page <= held['commands_page']['pages']:
                pending.extend(int(c['id']) for c in fleet(next_page)['commands'] if c['status'] == 'pending')
                next_page += 1
            check(bool(pending), 'После остановки пропал остаток маршрута.')
            if cycle == args.cycles - 1:
                break
            client.command('fleet.resume', {'fleetId': fid})
            resumed = fleet()
            check(selected(resumed, 10) == min(pending), 'Продолжение выбрало не минимальный pending ID.')
            if (cycle + 1) % 8 == 0:
                print('COMMAND_HISTORY_CYCLES', cycle + 1, flush=True)
        history = wait_for(lambda: (lambda cs: cs if any(int(c['id']) == active_id and not c['executed'] for c in cs) else None)(saved_commands(fid)))
        completed = [c for c in history if c['executed']]
        check(len(completed) >= 64, 'История недостаточна для проверки многоблочного индекса.')
        check([int(c['id']) for c in history if not c['executed'] and c['execute_time'] == 0] == pending,
              'Pending-проекция не совпала с сохранённым остатком.')
        print('COMMAND_HISTORY_SAVED', json.dumps({'fleetId': fid, 'rows': len(history), 'completed': len(completed)}), flush=True)
        pages = [fleet(number) for number in range(1, held['commands_page']['pages'] + 1)]
        visible = [c for page in pages for c in page['commands']]
        check(len(visible) == held['commands_total'], 'Пагинация потеряла/дублировала команды.')
        check(len({c['id'] for c in visible}) == len(visible), 'Дубли ID между страницами.')
        check([c['position'] for c in visible] == list(range(1, len(visible) + 1)), 'Сломана общая нумерация.')
        check(all(len(page['commands']) <= 50 for page in pages), 'Страница больше 50.')
        check(all(page['route_progress'] == held['route_progress'] and page['control']['steps'] == held['control']['steps'] for page in pages),
              'Страница изменила прогресс/редактор.')
        clamped = fleet(1000000)
        check(clamped['commands_page']['page'] == held['commands_page']['pages'] and clamped['commands'] == pages[-1]['commands'],
              'Номер за концом истории не ограничен последней страницей.')
        for bad in ({fid: 0}, {fid: '1'}, {'0': 1}, {'wrong': 1}, {fid: True}):
            response = client.call('query', name='fleets.get', context=client.context, data={'historyPages': bad})
            check(response['type'] == 'error', 'Неправильная пагинация принята.')
        response = client.call('query', name='fleets.get', context=client.context,
                               data={'historyPages': {str(2147400000 + i): 1 for i in range(33)}})
        check(response['type'] == 'error', 'Принято больше 32 параметров истории.')
        client.query('fleets.get', {'historyPages': {'2147483001': 1}})
        check(True, 'Удалённый флот не ломает сохранённую подписку.')
        foreign = sql('echo json_encode($db->fetchOne("SELECT id FROM fleets WHERE owner_id<>(SELECT owner_id FROM fleets WHERE id=?) LIMIT 1",[$x])["id"]??null);', int(fid))
        if foreign is not None:
            response = client.call('query', name='fleets.get', context=client.context, data={'historyPages': {str(foreign): 1}})
            check(response['type'] == 'error' and response['error']['code'] == 'resource.unavailable', 'Принята чужая история.')
        if args.history_version:
            proof = version_compatibility(fid)
            for key in ('sameOldHash', 'emptyCanonical', 'markersIgnored', 'snapshotUnchanged', 'modernProgressSame', 'legacyProgressSame'):
                check(proof[key], 'Несовместимость версии: ' + key)
            check(proof['peakAdditionalBytes'] < proof['legacyPeakAdditionalBytes'], 'Не уменьшена дополнительная PHP-память версии.')
            print('COMMAND_VERSION_COMPATIBILITY_OK', json.dumps(proof), flush=True)
        steps = held['control']['steps']
        progress = held['route_progress']
        client.close()
        if args.browser_history:
            result = subprocess.run(['python3','/var/www/spa.snak84.ru/tools/check_fleet_history_live.py','--execute'],
                                    input=json.dumps({'grant':g,'fleetId':fid}),text=True,capture_output=True,timeout=100)
            print(result.stdout,end='',flush=True)
            if result.returncode:
                print('BROWSER_HISTORY_FAILED', result.stderr[-2000:], flush=True)
            assert result.returncode == 0,result.stderr[-2000:]
        if args.pause_for_undo_probe:
            directory = Path(tempfile.mkdtemp(prefix='.qa-b070-', dir='/opt/sp-game/logs'))
            print('READY_FOR_UNDO_PROBE',json.dumps({'fleetId':fid,'directory':str(directory)}),flush=True)
            deadline = time.monotonic()+240
            while not (directory/'continue').exists():
                assert time.monotonic()<deadline,'Не подготовлен основной undo-probe.'
                time.sleep(.3)
        if args.legacy_progress:
            manage('stop')
            legacy = legacy_progress_fixture(fid, active_id)
            check(legacy['changed'] >= args.cycles and legacy['completed'] >= args.cycles, 'Недостаточно настоящих старых остановок.')
            progress = legacy['expected']
            print('LEGACY_PROGRESS_MAIN_FIXTURE', json.dumps(legacy), flush=True)
            manage('start')
        else:
            result = subprocess.run(['timeout', '12', './swoole_manager.sh', 'restart'],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
            assert result.returncode in (0, 124), 'Не выполнен restart.'
        if args.pause_for_undo_probe:
            probe = json.loads((directory/'result.json').read_text())
            check(probe['ok'], 'Undo-probe не пройден.')
            print('MAIN_WORLD_PROGRESS_PROBE_OK' if args.legacy_progress else 'MAIN_WORLD_UNDO_PROBE_OK',json.dumps(probe),flush=True)
        client = Client('qaown_260924', world_grant=g)
        restored = fleet()
        check(selected(restored, 43) == active_id, 'После restart потеряна ACTIVE-команда.')
        check([int(c['id']) for c in restored['commands'] if c['status'] == 'pending'] == pending,
              'После restart изменился порядок pending.')
        check(restored['control']['steps'] == steps, 'После restart изменились этапы редактора.')
        check(restored['route_progress'] == progress, 'После restart изменён прогресс/границы маршрута.')
        client.command('fleet.resume', {'fleetId': fid})
        resumed = fleet()
        check(selected(resumed, 10) == min(pending), 'После restart выбран неверный pending.')
        before_id = resumed['command']['id']
        client.command('fleet.resume', {'fleetId': fid}, expected='rejected')
        check(fleet()['command']['id'] == before_id, 'Отказ повторного resume изменил ACTIVE.')
        return_home()
        final = fleet()
        check({k: v['count'] for k, v in final['ships'].items()} == counts, 'Изменён состав.')
        check(final['current'] == place and final['fuel'] == 0, 'Флот не выгружен на исходной базе.')
        check(not final['control']['steps'], 'Не очищен маршрут.')
        wait_for(lambda: not saved_commands(fid))
        check(not saved_commands(fid), 'Многоблочная очистка пропустила команды.')
        check(stats()['storage']['storage.runtimeSqlRejected'] == 0, 'SQL в authority.')
    finally:
        # Новый grant не выдаётся: он вытеснил бы исходную игровую QA-сессию.
        try:
            if not running():
                manage('start')
            try:
                client.close()
            finally:
                client = Client('qaown_260924', world_grant=g)
            if fid is not None:
                return_home()
                own = [m['id'] for m in client.query('messages.get')['allMessages'] if m['id'] not in old_messages
                       and str(json.loads(m.get('data') or '{}').get('FleetId')) == fid]
                if own:
                    client.command('messages.delete', {'Ids': own})
                wait_for(lambda: not saved_commands(fid))
                check(not saved_commands(fid), 'Не сохранена очистка команд.')
        finally:
            client.close()
    print('FLEET_COMMAND_SELECTORS_LIVE_OK', json.dumps({'checks': checks, 'cycles': args.cycles,
          'maxQueryMs': round(max_query_ms, 2), 'fleetHome': True, 'historyCleared': True, 'serverRunning': True}), flush=True)


if __name__ == '__main__':
    main()
