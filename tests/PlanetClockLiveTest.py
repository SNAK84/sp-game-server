#!/usr/bin/env python3
"""C7/B060 на основном dev: реальная поздняя очередь, угол, ресурсы, верфь.

Запускает обычные QA-задачи, штатно останавливает мир, с before-image сдвигает
только время оплаченной лаборатории в прошлое. Не меняет скорость мира/орбиты.
В finally возвращает QA-состояние и оставляет основной сервер работающим.
"""
import argparse
import json
import math
from pathlib import Path
import subprocess
import tempfile
import time

from CapacitySafetyLiveTest import Client, rows
from RamRuntimeLiveTest import wait_for


def running():
    return subprocess.check_output(['docker', 'inspect', '--format', '{{.State.Running}}', 'swoole_app'], text=True).strip() == 'true'


def manage(action):
    result = subprocess.run((['timeout', '12'] if action == 'start' else []) + ['./swoole_manager.sh', action],
                            cwd='/opt/sp-game', stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
    assert result.returncode in ((0, 124) if action == 'start' else (0,))


def fixture_action(action, path):
    result = subprocess.run(['php', 'bin/qa-planet-clock.php', '--' + action, '--snapshot=' + str(path)],
                            cwd='/opt/sp-game', capture_output=True, text=True, check=True, timeout=10)
    assert json.loads(result.stdout)['ok']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--expect-legacy', action='store_true')
    args = parser.parse_args()
    snapshot = Path(tempfile.mkdtemp(prefix='sp-game-clock-qa.', dir='/tmp')) / 'fixture.json'
    c = None
    owns_queues = False
    checks = 0

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    try:
        c = Client('qaown_260924')
        check(not c.query('buildings.get')['QueueList'] and not c.query('shipyard.get')['QueueList'], 'Нужна свободная QA-очередь.')
        check(not c.query('research.get')['QueueList'], 'Нужно отсутствие QA-исследования.')
        owns_queues = True
        c.command('building.upgrade', {'id': '31'})
        c.command('shipyard.build', {'items': {'202': 3}})
        c.close(); c = None
        manage('stop')
        fixture_action('prepare', snapshot)
        fixture = json.loads(snapshot.read_text())
        print('PLANET_CLOCK_REAL_QUEUE_PREPARED', str(snapshot), flush=True)
        manage('start')
        c = Client('qaown_260924')
        wait_for(lambda: c.query('buildings.get')['BuildList']['31']['level'] == fixture['laboratory'] + 1, 15)
        original = fixture['planet']
        omega = original['speed'] * fixture['speedPlanets'] / 24 / 3600 * (-1 if original['rotation'] == 0 else 1)

        def planet():
            page = c.query('galaxy.system.get', {'galaxy': original['galaxy'], 'system': original['system']})
            return next(p for p in rows(page['Planets']) if p['id'] == str(original['id']))

        def angle_error(p):
            expected = original['deg'] + omega * (p['update_time'] - original['update_time'])
            return (p['deg'] - expected + 180) % 360 - 180

        first = planet()
        error = angle_error(first)
        expected_error = -2 * fixture['lag'] * omega if args.expect_legacy else 0.0
        check(abs(error - expected_error) < .00001, 'Ошибка угла: ' + str(error) + ', ожидалось ' + str(expected_error))
        check(first['update_time'] >= original['update_time'], 'update_time ушёл назад.')
        for field in ('speed', 'rotation', 'distance', 'galaxy', 'system', 'planet'):
            check(first[field] == original[field], 'Изменилась физика: ' + field)
        later = wait_for(lambda: (lambda p: p if p['update_time'] > first['update_time'] + 1 else None)(planet()), 10)
        check(abs(angle_error(later) - expected_error) < .00001, 'Повторное обновление изменило ошибку угла.')
        check(abs((later['deg'] - first['deg'] - omega * (later['update_time'] - first['update_time']) + 180) % 360 - 180) < .00001,
              'Прямое течение времени изменилось.')
        status = c.query('player.status.get')['topNav']['Resources']
        for key, name in [('901', 'metal'), ('902', 'crystal'), ('903', 'deuterium')]:
            r = status[key]
            delta = (r['time'] - original['update_time']) * (r['perhour'] + r.get('holding_perhour', 0)) / 3600
            before = fixture['resources'][name]
            expected = max(0, before + delta) if delta < 0 else (min(before + delta, r['max']) if before <= r['max'] else before)
            check(abs(r['count'] - expected) < .001, 'Ресурс начислен не один раз: ' + name)
        hangar = c.query('shipyard.get')
        ship = next(s for s in rows(hangar['FleetList']) if s['id'] == '202')
        check(ship['totalFuture'] == fixture['ships'] + 3, 'Нарушен баланс корабли+очередь.')
        built = ship['count'] - fixture['ships']
        max_built = max(0, math.floor((time.time() - fixture['hangarQueue']['start_time']) / fixture['hangarQueue']['time']))
        check(0 <= built <= min(3, max_built), 'Верфь произвела корабли раньше времени.')
        print('PLANET_CLOCK_LEGACY_REPRODUCED' if args.expect_legacy else 'PLANET_CLOCK_LIVE_OK',
              json.dumps({'checks': checks, 'angleErrorDegrees': round(error, 9), 'rotation': original['rotation']}), flush=True)
    finally:
        try:
            if not running():
                manage('start')
            if c is None:
                c = Client('qaown_260924')
            if owns_queues:
                for name, query in [('building.cancel', 'buildings.get'), ('shipyard.cancel', 'shipyard.get')]:
                    for q in reversed(rows(c.query(query)['QueueList'])):
                        c.command(name, {'id': q['qid']})
            c.close(); c = None
            if snapshot.exists():
                manage('stop')
                try:
                    fixture_action('restore', snapshot)
                finally:
                    manage('start')
        finally:
            if c is not None:
                c.close()
            if not running():
                manage('start')
    print('PLANET_CLOCK_QA_RESTORED_SERVER_RUNNING', flush=True)


if __name__ == '__main__':
    main()
