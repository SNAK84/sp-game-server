#!/usr/bin/env python3
"""Основной dev: настоящий pending PlayerQueue в общем snapshot и restart.

Только qafor_260924; по умолчанию требует отсутствия остальных клиентов.
SIGSTOP/SIGCONT кратко удерживает authority-worker между тиками, не меняет данные.
По умолчанию — штатный restart после исполнения. --crash требует отдельного
разрешения оператора: аварийное завершение после snapshot и приватного backup.
SQL-записей тестом и восстановления старого dump нет.
"""
import argparse
import json
import os
import math
from pathlib import Path
import signal
import subprocess
import time
import uuid
import tempfile

from CapacitySafetyLiveTest import Client, grant
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import connect, wait_for


def container():
    return json.loads(subprocess.check_output(['docker', 'inspect', '--format', '{{json .State}}', 'swoole_app'], text=True))


def authority_pid():
    metrics = wait_for(lambda: (lambda m: m if m.get('world.authorityPid', 0) > 0 else None)(stats()['storage']), 15)
    namespace_pid = int(metrics['world.authorityPid'])
    processes = subprocess.check_output(['docker', 'top', 'swoole_app', '-eo', 'pid'], text=True).splitlines()[1:]
    for line in processes:
        host_pid = int(line.strip())
        fields = Path('/proc/' + str(host_pid) + '/status').read_text().splitlines()
        nspid = next(row.split()[1:] for row in fields if row.startswith('NSpid:'))
        if int(nspid[-1]) == namespace_pid:
            return host_pid
    raise AssertionError('Не найден фактический процесс игровых тиков.')


def snapshot(request_id, planet):
    code = '''$db->beginTransaction();try {
      echo json_encode(['queued'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM player_queue_restart WHERE event_data LIKE ?',['%'.$x['request'].'%'])['n'],
      'receipt'=>$db->fetchOne('SELECT state,epoch FROM protocol_commands WHERE request_id=?',[$x['request']]),
      'name'=>$db->fetchOne('SELECT name FROM planets WHERE id=?',[$x['planet']])['name']]);
    } finally {$db->rollBack();}'''
    data = {'request': request_id, 'planet': planet}
    if container()['Running']:
        return sql(code, data)
    # После штатного stop в контейнере нечего запускать. Только SELECT с хоста.
    prelude = 'require "/opt/sp-game/vendor/autoload.php";SPGame\\Core\\Environment::init("/opt/sp-game/.env");'
    prelude += '$x=json_decode(stream_get_contents(STDIN),true);$db=SPGame\\Core\\Database::independent();'
    result = subprocess.run(['php', '-r', prelude + code], input=json.dumps(data), text=True,
                            capture_output=True, check=True, timeout=10, cwd='/opt/sp-game')
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.add_argument('--crash', action='store_true', help='Только с разрешения: допустим откат к последнему снимку.')
    parser.add_argument('--allow-active-clients', action='store_true',
                        help='Только с отдельного разрешения оператора на остановку при открытых клиентах.')
    args = parser.parse_args()
    assert stats()['authorized'] == 0 or args.allow_active_clients, 'Нужен свободный dev без чужих подключений.'
    g = grant('qafor_260924')
    c = Client('qafor_260924', world_grant=g)
    planet = int(c.context['planetId'])
    original = next(p['name'] for p in c.planets['items'] if int(p['id']) == planet)
    pid = authority_pid()
    paused = False
    request_id = str(uuid.uuid4())
    packet = {'v': 2, 'type': 'command', 'worldId': c.world, 'requestId': request_id,
              'name': 'planet.rename', 'context': c.context, 'data': {'name': 'QA Queue Restore'},
              'serverEpoch': c.epoch, 'issuedAt': time.time()}
    try:
        # В установленном Swoole режим по умолчанию BASE: WS и tick могут
        # находиться в одном worker. Сначала публикуем команду, потом пауза.
        last = stats()['storage']['world.lastPassAt']
        time.sleep(max(0, last + math.ceil(time.time() - last) + .15 - time.time()))
        c.ws.send(json.dumps(packet))
        while True:
            reply = json.loads(c.ws.recv())
            if reply.get('requestId') == request_id:
                break
        assert reply['type'] == 'commandAccepted' and reply['data']['state'] == 'accepted'
        os.kill(pid, signal.SIGSTOP)
        paused = True
        probe = subprocess.run(['docker', 'exec', 'swoole_app', 'php', '-r',
            'require "vendor/autoload.php";SPGame\\Core\\Environment::init(".env");'
            'try{SPGame\\Game\\Repositories\\MiningCheckpoints::worldLocked(fn()=>true);}catch(Throwable){exit(3);}'],
            capture_output=True, timeout=5)
        assert probe.returncode == 0, 'Authority остановлена внутри world-барьера.'
        pending = wait_for(lambda: (lambda s: s if s['queued'] == 1 else None)(snapshot(request_id, planet)), 42)
        assert pending['receipt']['state'] == 'accepted' and pending['name'] == original
        print('RAM_PENDING_SNAPSHOT_OK', flush=True)
        if args.crash:
            directory = tempfile.mkdtemp(prefix='sp-game-storage-backup.', dir='/tmp')
            backup = json.loads(subprocess.check_output(['php', '/tmp/sp-game-storage-backup.php', directory], text=True, timeout=20))
            print('RAM_CRASH_BACKUP', json.dumps(backup), flush=True)
            assert container()['Running']
            subprocess.run(['docker', 'kill', '--signal=KILL', 'swoole_app'], check=True,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
            paused = False
        else:
            os.kill(pid, signal.SIGCONT)
            paused = False
            wait_for(lambda: c.call('query', name='command.status.get', data={'commandId': request_id}, context={})['data']['state'] == 'applied', 15)
            subprocess.run(['./swoole_manager.sh', 'stop'], cwd='/opt/sp-game', check=True,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=55)
        pending = snapshot(request_id, planet)
        assert pending['queued'] == (1 if args.crash else 0) and pending['receipt']['state'] == ('accepted' if args.crash else 'applied')
        c.close()
        c = None
        start = subprocess.run(['timeout', '12', './swoole_manager.sh', 'start'], cwd='/opt/sp-game',
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
        assert start.returncode in (0, 124)
        c = connect(g)
        assert c.epoch != packet['serverEpoch']
        wait_for(lambda: next(p['name'] for p in c.query('player.planets.get')['items'] if int(p['id']) == planet) == 'QA Queue Restore', 20)
        state = c.call('query', name='command.status.get', data={'commandId': request_id}, context={})['data']
        assert state['state'] == 'unknown' and state['outcome']['reason'] == 'runtime_changed'
        c.ws.send(json.dumps(packet))
        while True:
            reply = json.loads(c.ws.recv())
            if reply.get('requestId') == request_id:
                break
        assert reply['data']['state'] == 'unknown', 'Старый пакет опубликован повторно.'
        wait_for(lambda: (lambda s: s['queued'] == 0 and s['receipt']['state'] == 'applied')(snapshot(request_id, planet)))
        print('RAM_PENDING_RESTART_APPLIED_ONCE_OK' if args.crash else 'RAM_QUEUE_GRACEFUL_RESTART_OK', 'oldEpochUnknown=1', flush=True)
    finally:
        current = container()
        if paused and current['Running']:
            try:
                os.kill(pid, signal.SIGCONT)
            except ProcessLookupError:
                pass
        if not current['Running']:
            subprocess.run(['timeout', '12', './swoole_manager.sh', 'start'], cwd='/opt/sp-game',
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
        if c is not None:
            c.close()
        c = connect(g)
        try:
            c.command('planet.rename', {'name': original})
            wait_for(lambda: snapshot(request_id, planet)['name'] == original)
        finally:
            c.close()
    assert stats()['storage']['storage.runtimeSqlRejected'] == 0
    print('RAM_QUEUE_RESTART_LIVE_OK qaNameRestored=1 runtimeSqlRejected=0', flush=True)


if __name__ == '__main__':
    main()
