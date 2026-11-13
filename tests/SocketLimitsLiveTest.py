#!/usr/bin/env python3
"""Текущий dev: только свои неавторизованные сокеты, без игровых мутаций.

--native-headroom проверяет nofile контейнера и 1100 одновременно живых
WebSocket v2. Это проверка сетевого предела, не приёмка 1000 онлайн-игроков.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import resource
import subprocess
import time
import uuid

import websocket


def connect():
    ws = websocket.create_connection('ws://127.0.0.1:9501/', timeout=25)
    try:
        ws.send(json.dumps({'v': 2, 'type': 'hello', 'requestId': str(uuid.uuid4()), 'data': {'versions': [2]}}))
        result = json.loads(ws.recv())
        assert result['type'] == 'hello'
        return ws, result['worldId']
    except BaseException:
        ws.close()
        raise


def native_headroom():
    from BackgroundSaverLiveTest import stats

    configured = json.loads(subprocess.run(
        ['docker', 'inspect', '--format', '{{json .HostConfig.Ulimits}}', 'swoole_app'],
        check=True, capture_output=True, text=True, timeout=10).stdout)
    nofile = next((x for x in configured or [] if x['Name'] == 'nofile'), None)
    assert nofile and nofile['Soft'] >= 65536 and nofile['Hard'] >= nofile['Soft'], \
        'Не применён явный nofile контейнера; одного docker restart недостаточно.'
    before = stats()
    authority_pid = int(before['storage']['world.authorityPid'])
    assert authority_pid > 0 and time.time() - before['storage']['world.lastPassAt'] < 15

    def process_limits():
        # Читаем сам игровой процесс, не лимиты отдельного docker exec CLI.
        code = r'''
        $pid=(int)$argv[1];
        $limits=file_get_contents('/proc/'.$pid.'/limits');
        if(!preg_match('/^Max open files\s+(\d+)\s+(\d+)/m',$limits,$m))exit(1);
        echo json_encode(['soft'=>(int)$m[1],'hard'=>(int)$m[2],
            'fds'=>count(scandir('/proc/'.$pid.'/fd'))-2]);
        '''
        return json.loads(subprocess.run(
            ['docker', 'exec', 'swoole_app', 'php', '-r', code, str(authority_pid)],
            check=True, capture_output=True, text=True, timeout=10).stdout)

    limits = process_limits()
    assert limits['soft'] == nofile['Soft'] and limits['hard'] == nofile['Hard'], limits
    count = 1100
    # Не вытесняем чужие подключения из Connect(2048).
    assert int(before['connections']) + count <= 1536, 'Недостаточно свободного запаса Connect для проверки.'
    original_limits = resource.getrlimit(resource.RLIMIT_NOFILE)
    required = count + 256
    assert original_limits[1] == resource.RLIM_INFINITY or original_limits[1] >= required, \
        'Hard nofile самого генератора ниже требуемого числа сокетов.'
    if original_limits[0] != resource.RLIM_INFINITY and original_limits[0] < required:
        resource.setrlimit(resource.RLIMIT_NOFILE, (required, original_limits[1]))
    futures = []
    started = time.monotonic()
    try:
        with ThreadPoolExecutor(max_workers=16) as pool:
            for _ in range(count):
                futures.append(pool.submit(connect))
            opened = [future.result() for future in futures]
        assert time.monotonic() - started < 15, 'Проверка приблизилась к штатному auth_timeout.'
        # Ответы на всех сокетах после открытия последнего исключают проверку
        # последовательных переподключений вместо одновременных соединений.
        # Прикладной v2 ping требует auth. Control ping/pong WebSocket
        # проверяет живое соединение, не ослабляя серверную авторизацию.
        for ws, _ in opened:
            ws.ping('B075')
        for ws, _ in opened:
            frame = ws.recv_frame()
            assert frame.opcode == websocket.ABNF.OPCODE_PONG and frame.data == b'B075', \
                (frame.opcode, frame.data)
        live = process_limits()
        assert live['fds'] >= count, live
        assert time.monotonic() - started < 19, 'Проверка вышла за безопасное окно до auth_timeout.'
        print('LIVE_NATIVE_HEADROOM_OK', json.dumps({
            'concurrentWebSockets': count, 'softNofile': live['soft'], 'hardNofile': live['hard'],
            'authorityPid': authority_pid, 'authorityFds': live['fds'],
            'seconds': round(time.monotonic() - started, 3),
        }), flush=True)
    finally:
        # После выхода из pool завершены и задания, следующие за упавшим.
        # Закрываем также их сокеты, не только уже извлечённые результаты.
        for future in futures:
            if not future.cancelled() and future.exception() is None:
                future.result()[0].close(timeout=.5)
        resource.setrlimit(resource.RLIMIT_NOFILE, original_limits)


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--execute', action='store_true', required=True)
parser.add_argument('--native-headroom', action='store_true')
args = parser.parse_args()
if args.native_headroom:
    native_headroom()
ws, world = connect()
try:
    started = time.monotonic()
    frame = ws.recv_frame()
    opcode, body = frame.opcode, frame.data
    assert opcode == 8 and int.from_bytes(body[:2], 'big') == 1008 and b'auth_timeout' in body
    print('LIVE_AUTH_DEADLINE_OK', round(time.monotonic() - started, 2), flush=True)
finally:
    ws.close()
ws, world = connect()
try:
    while time.time() % 1 > .1:
        time.sleep(.01)
    for _ in range(30):
        # Повтор hello — отдельная fatal-ошибка, не тест rate limit.
        ws.send(json.dumps({'v': 2, 'type': 'ping', 'requestId': str(uuid.uuid4()), 'worldId': world}))
    for _ in range(40):
        # recv_data автоматически отправляет ответный close; при backlog
        # сервер уже мог закрыть TCP. Проверяем полученный close до этого ACK.
        frame = ws.recv_frame()
        opcode, body = frame.opcode, frame.data
        if opcode == 8:
            assert int.from_bytes(body[:2], 'big') == 1008 and b'rate_limit' in body
            print('LIVE_UNAUTH_RATE_LIMIT_OK', flush=True)
            break
    else:
        raise AssertionError('Не сработал ограничитель запросов.')
finally:
    ws.close()
