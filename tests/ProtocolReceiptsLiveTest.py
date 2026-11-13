#!/usr/bin/env python3
"""Основной dev: orphan/истёкшая квитанция и настоящий pending после restart.

Сначала штатные QA-команды и общий snapshot. Offline-fixture с before-image
воспроизводит только повреждённые QA-квитанции и тот же настоящий pending.
Вместо аварийного завершения — штатный stop/start, другие данные не откатываются.
"""
import argparse
import json
import math
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import uuid

from CapacitySafetyLiveTest import Client, grant
from BackgroundSaverLiveTest import sql, stats, read_name
from RamQueueRestartLiveTest import authority_pid, container
from RamRuntimeLiveTest import connect, wait_for
from PlanetClockLiveTest import manage


def fixture_action(action, path, data=None):
    result = subprocess.run(['php', 'bin/qa-protocol-receipts.php', '--' + action, '--snapshot=' + str(path)],
                            input=json.dumps(data), capture_output=True, text=True, timeout=15)
    assert result.returncode == 0, result.stderr[-2000:]
    assert json.loads(result.stdout)['ok']


def snapshot(requests, planet):
    return sql('''$db->beginTransaction();try {echo json_encode([
      'receipts'=>$db->fetchAll('SELECT request_id,state,outcome FROM protocol_commands WHERE player_id=(SELECT owner_id FROM planets WHERE id=?) AND request_id IN (?,?,?,?)',[$x['planet'],...$x['requests']]),
      'events'=>$db->fetchAll('SELECT event_data FROM player_queue_restart'),
      'name'=>$db->fetchOne('SELECT name FROM planets WHERE id=?',[$x['planet']])['name']]);}finally{$db->rollBack();}''',
               {'requests': requests, 'planet': planet})


def packet(c, name):
    return {'v': 2, 'type': 'command', 'worldId': c.world, 'requestId': str(uuid.uuid4()),
            'name': 'planet.rename', 'context': c.context, 'data': {'name': name},
            'serverEpoch': c.epoch, 'issuedAt': time.time()}


def send(c, p):
    c.ws.send(json.dumps(p))
    while True:
        r = json.loads(c.ws.recv())
        if r.get('requestId') == p['requestId']:
            return r


def status(c, rid):
    return c.call('query', name='command.status.get', data={'commandId': rid}, context={})['data']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.parse_args()
    assert stats()['authorized'] == 0, 'Нужен dev без других активных клиентов.'
    directory = Path(tempfile.mkdtemp(prefix='sp-game-receipts-qa.', dir='/tmp'))
    path = directory / 'fixture.json'
    c = None
    paused = False
    staged = False
    passed = False
    original = None
    checks = 0

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    g = grant('qaown_260924')
    try:
        c = Client('qaown_260924', world_grant=g)
        planet = int(c.context['planetId'])
        original = next(p['name'] for p in c.planets['items'] if int(p['id']) == planet)
        packets = []
        for _ in range(3):
            p = packet(c, original)
            check(send(c, p).get('type') == 'commandAccepted', 'Не принята QA-команда.')
            wait_for(lambda: status(c, p['requestId'])['state'] == 'applied', 15)
            packets.append(p)
        p = packet(c, 'QA Receipt Restore')
        packets.append(p)
        ids = [p['requestId'] for p in packets]
        pid = authority_pid()
        last = stats()['storage']['world.lastPassAt']
        time.sleep(max(0, last + math.ceil(time.time() - last) + .15 - time.time()))
        check(send(c, p).get('type') == 'commandAccepted', 'Нет настоящего pending.')
        os.kill(pid, signal.SIGSTOP); paused = True
        probe = subprocess.run(['docker', 'exec', 'swoole_app', 'php', '-r',
            'require "vendor/autoload.php";SPGame\\Core\\Environment::init(".env");'
            'try{SPGame\\Game\\Repositories\\MiningCheckpoints::worldLocked(fn()=>true);}catch(Throwable){exit(3);}'],
            capture_output=True, timeout=5)
        check(probe.returncode == 0, 'Worker остановлен внутри барьера.')
        saved = wait_for(lambda: (lambda s: s if len(s['events']) == 1 else None)(snapshot(ids, planet)), 42)
        event = saved['events'][0]['event_data']
        check(json.loads(event)['data']['_protocolV2']['requestId'] == ids[3], 'Захвачено другое событие.')
        check(next(r for r in saved['receipts'] if r['request_id'] == ids[3])['state'] == 'accepted', 'Нет accepted в общем снимке.')
        check(saved['name'] == original, 'Pending уже исполнен.')
        os.kill(pid, signal.SIGCONT); paused = False
        wait_for(lambda: status(c, ids[3])['state'] == 'applied', 15)
        c.close(); c = None
        manage('stop')
        fixture_action('prepare', path, {'requests': ids, 'event': event, 'originalName': original})
        staged = True
        print('RECEIPTS_QA_FIXTURE_PREPARED', str(path), flush=True)
        manage('start')
        c = connect(g)
        check(c.epoch != p['serverEpoch'], 'Epoch не сменился.')
        wait_for(lambda: next(x['name'] for x in c.query('player.planets.get')['items'] if int(x['id']) == planet) == p['data']['name'], 15)
        for index in [0, 2]:
            s = status(c, ids[index])
            check(s['state'] == 'unknown' and s['outcome']['reason'] == 'runtime_changed', 'Старый orphan раскрыт неверно.')
            check(send(c, packets[index])['data']['state'] == 'unknown', 'Orphan опубликован повторно.')
        check(status(c, ids[1])['outcome']['reason'] == 'not_found', 'Истёкший orphan не закрыт.')
        check(send(c, packets[1]).get('error', {}).get('code') == 'command.stale_epoch', 'Истёкший старый пакет принят.')
        check(send(c, packets[3])['data']['state'] == 'unknown', 'Повтор pending после рестарта принят.')
        conflict = {**packets[0], 'data': {'name': 'QA conflict'}}
        check(send(c, conflict).get('error', {}).get('code') == 'command.id_conflict', 'Не проверен fingerprint.')

        def reconciled():
            s = snapshot(ids, planet)
            receipts = {r['request_id']: r for r in s['receipts']}
            return s if not s['events'] and ids[1] not in receipts and all(
                receipts.get(ids[i], {}).get('state') == state for i, state in [(0, 'unknown'), (2, 'unknown'), (3, 'applied')]) else None

        saved = wait_for(reconciled, 50)
        for index in [0, 2]:
            r = next(r for r in saved['receipts'] if r['request_id'] == ids[index])
            check(json.loads(r['outcome'])['reason'] == 'execution_unconfirmed', 'Orphan не сохранён как unknown.')
        check(saved['name'] == p['data']['name'], 'Не исполнен сохранённый pending.')
        c.close(); c = None
        manage('stop'); manage('start')
        c = connect(g)
        check(reconciled() is not None, 'Сверка не пережила второй restart.')
        check(status(c, ids[3])['state'] == 'unknown', 'Старое applied раскрыто без runtime_changed.')
        check(stats()['storage']['storage.runtimeSqlRejected'] == 0, 'SQL в runtime.')
        passed = True
    finally:
        if paused and container()['Running']:
            os.kill(pid, signal.SIGCONT)
        if c is not None: c.close()
        if (staged or path.exists()) and not passed:
            if container()['Running']: manage('stop')
            try: fixture_action('restore', path)
            finally: manage('start')
        if not container()['Running']: manage('start')
        if original is not None:
            c = connect(g)
            try:
                c.command('planet.rename', {'name': original})
                wait_for(lambda: read_name(planet) == original)
            finally: c.close()
    print('PROTOCOL_RECEIPTS_LIVE_OK', json.dumps({'checks': checks, 'qaNameRestored': True, 'serverRunning': True}), flush=True)


if __name__ == '__main__':
    main()
