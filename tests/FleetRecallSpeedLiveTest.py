#!/usr/bin/env python3
"""B073 на основном dev: вылет → stop → recall при 10/50/100% скорости.

Только свободный QA-флот, обычные v2-команды и реальное время. SQL читает
снимок saver. --legacy-hold удаляет только speedFactor тестового удержания
при выключенном мире. При сбое уборка обычным редактором, отдельного мира нет.
"""
import argparse
import json
import math
import subprocess
import time

from CapacitySafetyLiveTest import Client, grant, rows
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import wait_for
from PlanetClockLiveTest import manage, running


def offline_sql(code, data):
    assert not running(), 'SQL-фикстура допустима только при выключенном мире.'
    prelude = 'require "vendor/autoload.php"; SPGame\\Core\\Environment::init(".env"); '
    prelude += '$x=json_decode(stream_get_contents(STDIN),true); $db=SPGame\\Core\\Database::independent(); '
    result = subprocess.run(['php', '-r', prelude + code], input=json.dumps(data), text=True,
                            capture_output=True, check=True, timeout=10)
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--expect-legacy', action='store_true')
    parser.add_argument('--legacy-hold', action='store_true', help='Старое сохранённое удержание без speedFactor после restart.')
    parser.add_argument('--speeds', type=int, choices=[1, 5, 10], nargs='+', default=[10, 5, 1])
    args = parser.parse_args()
    g = grant('qaown_260924')
    c = Client('qaown_260924', world_grant=g)
    fid = '37'
    checks = 0
    samples = []
    messages = {m['id'] for m in c.query('messages.get')['allMessages']}

    def fleet():
        page = c.query('fleets.get')
        return next(f for key in ('FleetList', 'FlyingFleets') for f in rows(page[key]) if f['id'] == fid)

    def check(ok, label):
        nonlocal checks
        assert ok, label
        checks += 1

    def return_with_editor():
        current = fleet()
        if current['status'] == 0:
            return
        if current['actions']['StopFleet']:
            c.command('fleet.stop', {'fleetId': fid})
            current = fleet()
        c.command('fleet.route.set', {'fleetId': fid, 'plan': {'version': current['control']['version'],
                  'steps': [{'kind': 'return', 'speedFactor': 10}], 'fuel': 0, 'resources': {}}})
        c.command('fleet.resume', {'fleetId': fid})
        wait_for(lambda: fleet()['status'] == 0, 240)

    original = fleet()
    try:
        check(original['status'] == 0 and original['fuel'] == 0 and not original['control']['steps'], 'Нужен свободный QA Fleet37.')
        wait_for(lambda: sql('echo json_encode((int)$db->fetchOne("SELECT COUNT(*) n FROM fleet_commands WHERE fleet_id=?",[$x])["n"]);', int(fid)) == 0)
        check(True, 'Завершён saver предыдущей QA-истории.')
        nav = c.query('fleet.navigation.get', {'galaxy': original['current']['galaxy'],
                      'system': original['current']['system'], 'fleetId': fid})['NavigationSystem']
        target = {'type': 2, 'galaxy': original['current']['galaxy'], 'system': original['current']['system'],
                  'distance': nav['minDistance'] + .7 * (nav['maxDistance'] - nav['minDistance']), 'angle': 85.0}
        for speed in ([10] if args.expect_legacy else args.speeds):
            preview = c.query('fleet.missions.get', {'fleetId': fid, 'coordinates': target, 'speedFactor': speed, 'missionType': 30})
            check(30 in preview['AvailableMissions']['missions'], 'Нет удержания в списке миссий.')
            fuel = min(original['capacity'] - 1, max(1000, math.ceil(preview['TravelInfo']['fuel']['max'] * 3)))
            c.command('fleet.send', {'fleetId': fid, 'coordinates': target, 'missionType': 30,
                                    'speedFactor': speed, 'fuel': fuel, 'resources': {}})
            check(fleet()['command']['type'] == 10, 'Не начался внутрисистемный перелёт.')
            time.sleep(2)
            c.command('fleet.stop', {'fleetId': fid})
            held = fleet()
            check(held['control']['holding'] and held['command']['type'] == 43, 'Нет ручного удержания.')
            if speed == 5:
                c.command('fleet.resume', {'fleetId': fid})
                check(fleet()['command']['type'] == 10, 'Не продолжился прерванный участок.')
                c.command('fleet.stop', {'fleetId': fid})
                held = fleet()
                check(held['control']['holding'], 'Повторная остановка потеряна.')
                c.close()
                manage('stop')
                if args.legacy_hold:
                    changed = offline_sql(r'''
                    $row=$db->fetchOne("SELECT c.* FROM fleet_commands c JOIN fleets f ON f.id=c.fleet_id
                        JOIN users u ON u.id=f.owner_id JOIN accounts a ON a.id=u.account_id
                        WHERE c.id=? AND c.fleet_id=? AND a.login='qaown_260924'",[$x['command'], $x['fleet']]);
                    if($row===null||(int)$row['command_type']!==43||(int)$row['executed']!==0)throw new LogicException('Нет QA-удержания.');
                    $p=json_decode($row['params'],true,512,JSON_THROW_ON_ERROR);
                    if(($p['speedFactor']??null)!=5)throw new LogicException('Не сохранилась скорость нового удержания.');
                    unset($p['speedFactor']);$after=json_encode($p,JSON_THROW_ON_ERROR);
                    if($db->executeAffected('UPDATE fleet_commands SET params=? WHERE id=? AND params=?',[$after,$row['id'],$row['params']])!==1)
                        throw new LogicException('QA-образ изменился.');
                    echo json_encode(['newHoldPersistedSpeed'=>5,'legacyFieldRemoved'=>true]);
                    ''', {'command': int(held['command']['id']), 'fleet': int(fid)})
                    check(changed['newHoldPersistedSpeed'] == 5 and changed['legacyFieldRemoved'], 'Не создан старый QA-формат удержания.')
                manage('start')
                c = Client('qaown_260924', world_grant=g)
                check(fleet()['control']['holding'], 'Остановка не пережила restart.')
            c.command('fleet.recall', {'fleetId': fid})
            recalled = fleet()
            expected = 1 if args.expect_legacy else speed
            check(recalled['is_return'] and recalled['command']['type'] == 10, 'Не начался возврат.')
            returned_speed = next(s['speedFactor'] for s in recalled['control']['steps'] if s['kind'] == 'return')
            check(returned_speed == expected, f'Скорость возврата {returned_speed}, ожидалось {expected}.')
            seconds = recalled['end_time'] - time.time()
            cleanup_at_full_speed = args.expect_legacy or speed == 1 and seconds > 180
            samples.append({'selected': speed, 'returned': returned_speed, 'returnSeconds': round(seconds, 3),
                            'cleanupAtFullSpeed': cleanup_at_full_speed})
            print('RECALL_SPEED_CASE', json.dumps(samples[-1]), flush=True)
            if cleanup_at_full_speed:
                # На 10% правильный возврат тоже длиннее QA-таймаута. Его
                # исходная скорость уже проверена; уборка явным return/100%.
                return_with_editor()
            else:
                wait_for(lambda: fleet()['status'] == 0, 240)
            final = fleet()
            check(final['ships'] == original['ships'] and final['name'] == original['name'], 'Изменён состав/опыт/имя QA.')
            check(final['current'] == original['current'] and final['fuel'] == 0, 'Не выгружен бак/не достигнута база.')
            check(not final['control']['steps'], 'Маршрут не очищен после возврата.')
    finally:
        if not running():
            manage('start')
        try:
            c.close()
            c = Client('qaown_260924', world_grant=g)
            return_with_editor()
            own = [m['id'] for m in c.query('messages.get')['allMessages'] if m['id'] not in messages
                   and str(json.loads(m.get('data') or '{}').get('FleetId')) == fid]
            if own:
                c.command('messages.delete', {'Ids': own})
        finally:
            c.close()
    wait_for(lambda: sql('echo json_encode((int)$db->fetchOne("SELECT COUNT(*) n FROM fleet_commands WHERE fleet_id=?",[$x])["n"]);', int(fid)) == 0)
    check(stats()['storage']['storage.runtimeSqlRejected'] == 0, 'SQL в runtime authority.')
    print('FLEET_RECALL_SPEED_' + ('LEGACY_REPRODUCED' if args.expect_legacy else 'LIVE_OK'),
          json.dumps({'checks': checks, 'samples': samples, 'fleetHome': True, 'serverRunning': True}), flush=True)


if __name__ == '__main__':
    main()
