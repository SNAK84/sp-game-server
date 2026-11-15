#!/usr/bin/env python3
"""Основной dev с временным onStart PhalanxCapacityLiveMainWorldProbe.

Только snak1: две команды скана и временный выбор своей луны. Cache заполняет
fixture, не отдельный мир. Управляющие файлы приватные; SQL игры не используется.
"""
import argparse
import json
from pathlib import Path
import subprocess
import time
import uuid

from CapacitySafetyLiveTest import Client


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--proof', type=Path, required=True)
    args = parser.parse_args()

    def proof():
        return json.loads(args.proof.read_text())

    def wait_phase(expected):
        until = time.monotonic() + 100
        while time.monotonic() < until:
            try:
                value = proof()
                if value['phase'] == expected:
                    return value
            except (FileNotFoundError, json.JSONDecodeError):
                pass
            time.sleep(.1)
        raise AssertionError('Не наступила фаза fixture: ' + expected)

    fixture = wait_phase('full')
    assert not (args.proof.parent / (args.proof.name + '.finish')).exists(), 'Нужен новый proof path.'
    code = r'''
require 'src/bootstrap.php';
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$q=$pdo->prepare("SELECT account_id FROM legacy_account_links WHERE source_key='sp-game' AND source_login=?");
$q->execute(['snak1']);$id=$q->fetchColumn();if(!$id)throw new LogicException('Нет QA snak1.');
$s=(new SPGame\Cabinet\Repositories\SessionRepository($pdo))->create($id,'native');
echo json_encode((new SPGame\Cabinet\Services\WorldService($pdo))->start($s['accessToken'],'sp-game-dev'));
'''
    result = subprocess.run(['docker', 'exec', 'sp_game_cabinet', 'php', '-r', code],
                            text=True, capture_output=True, check=True, timeout=15)
    client = None
    original_context = None
    success = False
    try:
        client = Client('snak1', world_grant=json.loads(result.stdout))
        original_context = {'planetId': client.planets['selectedPlanetId']}
        client.context = {'planetId': fixture['sourceId']}
        client.command('planet.select', {})

        def deuterium():
            return float(client.query('player.status.get')['topNav']['Resources']['903']['count'])

        def scan():
            r = client.call('command', name='phalanx.scan', context=client.context,
                            data={'targetId': fixture['targetId'], 'scanToken': str(uuid.uuid4())},
                            serverEpoch=client.epoch, issuedAt=time.time())
            assert r['type'] in ('commandAccepted', 'commandResult'), ('Нет принятой команды скана', r.get('error', {}).get('code'))
            command_id = r['requestId']
            for _ in range(100):
                if r['data']['state'] != 'accepted':
                    return r['data']
                time.sleep(.1)
                r = client.call('query', name='command.status.get', data={'commandId': command_id}, context={})
            raise AssertionError('Команда не завершилась.')

        initial = deuterium()
        assert abs(initial - fixture['initialDeuterium']) < .000001
        rejected = scan()
        assert rejected['state'] == 'rejected', ('При полном cache должен быть rejected, не unknown', rejected['state'])
        assert 'Буфер' in rejected['outcome']['error']['message']
        assert deuterium() == initial, 'Полный буфер списал дейтерий.'
        args.proof.with_name(args.proof.name + '.release').touch(mode=0o600)
        wait_phase('released')
        applied = scan()
        assert applied['state'] == 'applied', applied['state']
        assert deuterium() == initial - 5000, 'Успешный скан не списал ровно5000.'
        page = client.query('galaxy.system.get', {'galaxy': fixture['galaxy'], 'system': fixture['system']})
        assert page['PhalanxScan']['targetId'] == fixture['targetId'] and '_observations' not in page['PhalanxScan']
        success = True
    finally:
        try:
            if client is not None and original_context is not None:
                client.context = original_context
                client.command('planet.select', {})
        finally:
            if client is not None:
                client.close()
            args.proof.with_name(args.proof.name + '.finish').touch(mode=0o600)
    final = wait_phase('complete')
    assert success and final['fixturesReleased'] and final['refunded'] and not final['timeout']
    assert final['finalDeuterium'] == fixture['initialDeuterium'] and not final.get('unexpectedDeuterium')
    print('LIVE_PHALANX_CAPACITY_OK', json.dumps({'fullCacheState': 'rejected', 'fullCacheCharge': 0,
          'successfulCharge': 5000, 'qaFuelRestored': True, 'qaPlanetRestored': True,
          'fixturesReleased': True, 'newWorld': False}), flush=True)


if __name__ == '__main__':
    main()
