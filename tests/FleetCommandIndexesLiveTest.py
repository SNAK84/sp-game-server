#!/usr/bin/env python3
"""Основной dev: индекс неактивных команд, доставка3 остановок и dedup.

Offline-fixture с before-image меняет только params трёх остановленных
QA-команд. Не меняет топливо/позиции/таймеры; не имитирует саму нехватку топлива.
Сообщения доставляет настоящий EventLoop; тест удаляет только свои3 сообщения.
"""
import argparse
import json
from pathlib import Path
import subprocess
import tempfile
import time

from CapacitySafetyLiveTest import Client, grant
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import connect, wait_for
from PlanetClockLiveTest import manage, running


def fixture_action(action, path):
    r = subprocess.run(['php', 'bin/qa-fleet-notices.php', '--' + action, '--snapshot=' + str(path)],
                       capture_output=True, text=True, timeout=15)
    assert r.returncode == 0, r.stderr[-2000:]
    assert json.loads(r.stdout)['ok']


def messages(fixture):
    rows = sql('echo json_encode($db->fetchAll("SELECT id,sample,data FROM messages WHERE to_id=? AND sample IN (?,?,?)",[$x["owner"],"FleetFuelStopped","FleetLoadStopped","FleetInterceptionStopped"]));', fixture)
    expected = {'fuel': ('FleetFuelStopped', 'FuelEpisode'), 'load': ('FleetLoadStopped', 'FuelEpisode'), 'intercept': ('FleetInterceptionStopped', 'InterceptionEpisode')}
    result = {role: [] for role in expected}
    for row in rows:
        data = json.loads(row['data'])
        for role, (sample, field) in expected.items():
            if row['sample'] == sample and data.get(field) == fixture['commands'][role]['episode']:
                result[role].append(row)
    return result


def commands(ids=None):
    if ids is None:
        return sql('echo json_encode($db->fetchAll("SELECT * FROM fleet_commands ORDER BY id"));', {})
    return sql('echo json_encode($db->fetchAll("SELECT * FROM fleet_commands WHERE id IN (?,?,?) ORDER BY id",$x));', ids)


def check_route_cleanup():
    """Штатный v2-рейс; его завершение удаляет только собственную цепочку."""
    before = commands()
    result = subprocess.run(['python3', '/opt/sp-game-bot/qa/check_protocol_v2_flight.py', '--execute'],
                            capture_output=True, text=True, timeout=260)
    print(result.stdout, end='', flush=True)
    assert result.returncode == 0, result.stderr[-3000:]
    fleet = int(next(line.split()[-1] for line in result.stdout.splitlines() if line.startswith('V2_FLIGHT_SEND_OK ')))
    after = wait_for(lambda: (lambda rows: rows if not any(r['fleet_id'] == fleet for r in rows) else None)(commands()))
    assert not any(r['fleet_id'] == fleet for r in before), 'У исходного базированного QA уже были команды.'
    assert after == before, 'Очистка собственного маршрута изменила чужую цепочку.'
    assert stats()['storage']['storage.runtimeSqlRejected'] == 0
    print('FLEET_ROUTE_CLEANUP_INDEX_LIVE_OK', json.dumps({'checks': 3, 'flightFleetId': fleet, 'otherCommandsUnchanged': True}), flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--flight-only', action='store_true', help='Только штатный рейс и проверка очистки маршрута.')
    args = parser.parse_args()
    assert stats()['authorized'] == 0, 'Нужен dev без чужих клиентов.'
    if args.flight_only:
        check_route_cleanup()
        return
    path = Path(tempfile.mkdtemp(prefix='sp-game-notices-qa.', dir='/tmp')) / 'fixture.json'
    before = commands()
    g = grant('qaown_260924')
    c = None
    checks = 0

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    try:
        manage('stop')
        fixture_action('prepare', path)
        fixture = json.loads(path.read_text())
        print('FLEET_INDEX_QA_FIXTURE_PREPARED', str(path), flush=True)
        manage('start')
        c = connect(g)
        delivered = wait_for(lambda: (lambda m: m if all(len(v) == 1 for v in m.values()) else None)(messages(fixture)), 45)
        page = c.query('messages.get')['allMessages']
        for role, one in delivered.items():
            check(len(one) == 1, 'Нет одной доставки ' + role)
            check(any(m['id'] == str(one[0]['id']) for m in page), 'Сообщение отсутствует в v2 ' + role)
            check(str(json.loads(one[0]['data'])['FleetId']) == str(fixture['commands'][role]['row']['fleet_id']), 'Другой FleetId ' + role)
        time.sleep(6)
        check(messages(fixture) == delivered, 'Повторная доставка на следующих тиках.')
        ids = [x['row']['id'] for x in fixture['commands'].values()]
        rows = {r['id']: r for r in commands(ids)}
        for item in fixture['commands'].values():
            row = rows[item['row']['id']]
            check(json.loads(row['params'])[item['marker']] == item['episode'], 'Marker не сохранён.')
            check({k: v for k, v in row.items() if k != 'params'} == {k: v for k, v in item['row'].items() if k != 'params'}, 'Изменилась команда помимо params.')
        before_other = [r for r in before if r['id'] not in ids]
        check([r for r in commands() if r['id'] not in ids] == before_other, 'Изменены команды других флотов/хвост маршрута.')
        check(stats()['storage']['storage.runtimeSqlRejected'] == 0, 'SQL в authority.')
    finally:
        if c is not None: c.close()
        if path.exists():
            if running(): manage('stop')
            try: fixture_action('restore', path)
            finally: manage('start')
            fixture = json.loads(path.read_text())
            c = connect(g)
            try:
                own = [str(m['id']) for group in messages(fixture).values() for m in group]
                if own: c.command('messages.delete', {'Ids': own})
                wait_for(lambda: all(not v for v in messages(fixture).values()))
            finally: c.close()
            check(commands([x['row']['id'] for x in fixture['commands'].values()]) == sorted([x['row'] for x in fixture['commands'].values()], key=lambda r: r['id']), 'Before-image не восстановлен.')
        if not running(): manage('start')
    print('FLEET_COMMAND_INDEXES_LIVE_OK', json.dumps({'checks': checks, 'qaCommandsRestored': True, 'ownMessagesDeleted': True, 'serverRunning': True}), flush=True)


if __name__ == '__main__':
    main()
