#!/usr/bin/env python3
"""T005: текущий dev, настоящий world barrier, два штатных клиента.

Без копии мира и backend-hook. Барьер удерживается до первого явного busy
ответа, максимум2с; меняется и восстанавливается имя только QA-планеты.
Токен передаётся через stdin, а не argv/логи/файлы.
"""
import argparse
import json
import selectors
import subprocess


def line(process, timeout=15):
    with selectors.DefaultSelector() as selector:
        selector.register(process.stdout, selectors.EVENT_READ)
        if not selector.select(timeout):
            raise TimeoutError('Нет ответа диагностического процесса.')
    value = process.stdout.readline().strip()
    if not value:
        raise RuntimeError('Диагностический процесс завершился без результата.')
    return value


def lock_world():
    code = r'''
require 'vendor/autoload.php';
SPGame\Core\Environment::init('.env');
for ($attempt=0; $attempt<100; ++$attempt) {
    try {
        SPGame\Game\Repositories\MiningCheckpoints::worldLocked(static function (): void {
            echo "LOCKED\n"; fflush(STDOUT);
            $read=[STDIN]; $write=$except=null;
            if (stream_select($read, $write, $except, 2) > 0) fgets(STDIN);
        }, true);
        exit(0);
    } catch (RuntimeException $error) {
        if ($error->getCode() !== SPGame\Game\Repositories\MiningCheckpoints::LOCK_BUSY) throw $error;
        usleep(10000);
    }
}
exit(1);
'''
    process = subprocess.Popen(['docker', 'exec', '-i', 'swoole_app', 'php', '-r', code],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        assert line(process) == 'LOCKED'
        return process
    except BaseException:
        process.communicate(timeout=5)
        raise


CLIENT_CODE = r'''
import asyncio, copy, json, sys, time
from app.protocol_v2 import ProtocolV2Client
from app.ws_client import GameClient

grant = __GRANT__
phase = None
sent = []
busy = 0
results = []

async def log(event, fields):
    global busy
    if not phase:
        return
    if event == 'request_sent':
        sent.append(copy.deepcopy(fields['request']))
    if event == 'response_received' and fields['response'].get('type') == 'error':
        error = fields['response']['error']
        assert error['code'] == 'service.unavailable' and error['retryable'] is True and error['fatal'] is False
        busy += 1
        print('BUSY', flush=True)

async def operation(client, kind, **fields):
    method = client._call if isinstance(client, ProtocolV2Client) else client._v2_call
    return await method(kind, **fields)

async def applied(client, packet):
    status = packet['data']
    deadline = time.monotonic() + 10
    while status['state'] == 'accepted' and time.monotonic() < deadline:
        await asyncio.sleep(.05)
        status = (await operation(client, 'query', name='command.status.get', data={'commandId': status['commandId']}))['data']
    assert status['state'] == 'applied'

async def held(client, kind, **fields):
    global phase, sent, busy
    phase, sent, busy = client.__class__.__name__ + ':' + kind, [], 0
    print('HOLD ' + phase, flush=True)
    assert (await asyncio.to_thread(sys.stdin.readline)).strip() == 'GO'
    try:
        response = await operation(client, kind, **fields)
    finally:
        phase = None
    assert busy >= 1 and 2 <= len(sent) <= 6
    assert all(packet == sent[0] for packet in sent)
    assert response['requestId'] == sent[0]['requestId']
    results.append({'client': client.__class__.__name__, 'kind': kind, 'attempts': len(sent),
                    'requestId': response['requestId'], 'identicalPackets': True})
    return response

async def main():
    for client_class in (ProtocolV2Client, GameClient):
        client = client_class(grant['websocketUrl'], world_id=grant['worldId'], log=log)
        client.token = grant['gameToken']
        original = planet = None
        try:
            await client.connect()
            snapshot = (await held(client, 'query', name='player.planets.get'))['data']['value']
            planet = snapshot['selectedPlanetId']
            original = next(p['name'] for p in snapshot['items'] if p['id'] == planet)
            response = await held(client, 'command', name='planet.rename', context={'planetId': planet},
                                  data={'name': 'T005 retry QA'}, serverEpoch=client.server_epoch,
                                  issuedAt=time.time()+client._clock_offset)
            await applied(client, response)
            changed = (await operation(client, 'query', name='player.planets.get'))['data']['value']
            assert next(p['name'] for p in changed['items'] if p['id'] == planet) == 'T005 retry QA'
        finally:
            if original is not None and client.authenticated:
                restore = await operation(client, 'command', name='planet.rename', context={'planetId': planet},
                                          data={'name': original}, serverEpoch=client.server_epoch,
                                          issuedAt=time.time()+client._clock_offset)
                await applied(client, restore)
                restored = (await operation(client, 'query', name='player.planets.get'))['data']['value']
                assert next(p['name'] for p in restored['items'] if p['id'] == planet) == original
            await client.close()
    print('RESULT ' + json.dumps({'cases': results, 'qaNameRestored': True}), flush=True)

asyncio.run(main())
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.parse_args()
    seed = r'''<?php
require 'src/bootstrap.php';
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$id=$pdo->query("SELECT account_id FROM legacy_account_links WHERE source_key='sp-game' AND source_login='qaown_260924'")->fetchColumn();
$session=(new SPGame\Cabinet\Repositories\SessionRepository($pdo))->create($id,'native');
echo json_encode((new SPGame\Cabinet\Services\WorldService($pdo))->start($session['accessToken'],'sp-game-dev'));
'''
    grant = json.loads(subprocess.run(['docker', 'exec', '-i', 'sp_game_cabinet', 'php'], input=seed,
                                     text=True, capture_output=True, check=True, timeout=15).stdout)
    grant['websocketUrl'] = 'ws://host.docker.internal:9501/'
    code = CLIENT_CODE.replace('__GRANT__', repr(grant))
    bootstrap = 'import sys; exec(compile(sys.stdin.read(int(sys.argv[1])), "<T005-live>", "exec"))'
    client = subprocess.Popen(['docker', 'exec', '-i', 'sp_game_qa_bot', 'python', '-u', '-c', bootstrap, str(len(code))],
                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    lock = None
    try:
        client.stdin.write(code)
        client.stdin.flush()
        while True:
            value = line(client)
            if value.startswith('HOLD '):
                assert lock is None
                lock = lock_world()
                client.stdin.write('GO\n')
                client.stdin.flush()
            elif value == 'BUSY':
                if lock is not None:
                    lock.communicate('RELEASE\n', timeout=5)
                    assert lock.returncode == 0
                    lock = None
            elif value.startswith('RESULT '):
                result = json.loads(value[7:])
                assert len(result['cases']) == 4 and result['qaNameRestored']
                client.stdin.close()
                assert client.wait(timeout=10) == 0
                print('LIVE_V2_WORLD_BUSY_RETRY_OK', json.dumps(result))
                break
            else:
                raise AssertionError('Неожиданный ответ диагностического клиента.')
    finally:
        if lock is not None:
            lock.communicate('RELEASE\n', timeout=5)
        if client.stdin and not client.stdin.closed:
            client.stdin.close()
        if client.poll() is None:
            client.wait(timeout=15)


if __name__ == '__main__':
    main()
