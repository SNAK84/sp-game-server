#!/usr/bin/env python3
"""Аудит C06/C07: проверки прав на текущем dev через настоящий протокол v2.

python3 tests/CapacitySafetyLiveTest.py --execute
Только два QA-аккаунта: создаёт/отменяет свою партию верфи и временный флот.
Чужие очереди/сообщения пробует изменить отрицательными запросами. SQL только
читает; после обычного saver проверяет отсутствие фантомов/воскрешённого флота.
"""
import argparse
import json
import subprocess
import time
import uuid

import websocket


def grant(login):
    assert login in ('qaown_260924', 'qafor_260924')
    code = r'''
    require 'src/bootstrap.php';
    $pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
    $login=json_decode(stream_get_contents(STDIN),true)['login'];
    $s=$pdo->prepare("SELECT account_id FROM legacy_account_links WHERE source_key='sp-game' AND source_login=?");
    $s->execute([$login]);$id=$s->fetchColumn();if(!$id)throw new LogicException('Нет QA-аккаунта.');
    $a=(new SPGame\Cabinet\Repositories\SessionRepository($pdo))->create($id,'native');
    echo json_encode((new SPGame\Cabinet\Services\WorldService($pdo))->start($a['accessToken'],'sp-game-dev'));
    '''
    result = subprocess.run(['docker', 'exec', '-i', 'sp_game_cabinet', 'php', '-r', code],
                            input=json.dumps({'login': login}), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


class Client:
    def __init__(self, login, world_grant=None):
        self.retryable_errors = 0
        g = world_grant if world_grant is not None else grant(login)
        self.world = g['worldId']
        self.ws = websocket.create_connection('ws://127.0.0.1:9501/', timeout=20)
        self.epoch = self.call('hello', data={'versions': [2]})['serverEpoch']
        self.call('auth', data={'gameToken': g['gameToken']})
        self.planets = self.query('player.planets.get')
        self.context = {'planetId': self.planets['mainPlanetId']}

    def call(self, kind, **fields):
        rid = str(uuid.uuid4())
        packet = json.dumps({'v': 2, 'type': kind, 'requestId': rid, 'worldId': self.world, **fields})
        self.ws.send(packet)
        deadline = time.monotonic() + 3
        while True:
            r = json.loads(self.ws.recv())
            if r.get('requestId') == rid:
                # Ожидаемый неблокирующий world-barrier во время capture.
                # Только явный retryable, тот же requestId/issuedAt/fingerprint;
                # при timeout/обрыве отправка команд повторно НЕ выполняется.
                if kind in ('query', 'command') and r.get('type') == 'error' \
                        and r.get('error', {}).get('code') == 'service.unavailable' \
                        and r['error'].get('retryable') is True and time.monotonic() < deadline:
                    self.retryable_errors += 1
                    time.sleep(.1)
                    self.ws.send(packet)
                    continue
                return r

    def query(self, name, data=None, context=None):
        r = self.call('query', name=name, context=context if context is not None else getattr(self, 'context', {}), data=data or {})
        assert r['type'] == 'queryResult', (name, r.get('error', {}).get('code'))
        return r['data']['value']

    def command(self, name, data, expected='applied'):
        r = self.call('command', name=name, context=self.context, data=data,
                      serverEpoch=self.epoch, issuedAt=time.time())
        if r['type'] == 'error':
            assert expected == 'rejected' and r['error']['code'] == 'resource.unavailable', (name, r['error']['code'])
            return
        command_id = r['requestId']
        for _ in range(75):
            if r['data']['state'] != 'accepted':
                break
            time.sleep(.2)
            r = self.call('query', name='command.status.get', data={'commandId': command_id}, context={})
        assert r['data']['state'] == expected, (name, r['data']['state'], r['data'].get('outcome'))

    def close(self):
        self.ws.close()


def rows(value):
    return list(value.values()) if isinstance(value, dict) else value


def sql_state(unknown, fleet_id=0):
    code = r'''
    require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');
    $x=json_decode(stream_get_contents(STDIN),true);$db=SPGame\Core\Database::independent();
    echo json_encode([
      'messages'=>$db->fetchAll('SELECT id FROM messages WHERE id=?',[$x['unknown']]),
      'queues'=>$db->fetchAll('SELECT id FROM queues WHERE id=?',[$x['unknown']]),
      'users'=>$db->fetchAll('SELECT id FROM users WHERE id=?',[$x['unknown']]),
      'friends'=>$db->fetchAll('SELECT id FROM friends WHERE user_low=? OR user_high=?',[$x['unknown'],$x['unknown']]),
      'fleet'=>$db->fetchAll('SELECT id FROM fleets WHERE id=?',[$x['fleet']]),
      'ships'=>$db->fetchAll('SELECT id FROM fleets_ships WHERE fleet_id=?',[$x['fleet']]),
      'currency'=>$db->fetchAll("SELECT r.* FROM resources_user r JOIN users u ON u.id=r.id JOIN accounts a ON a.id=u.account_id WHERE a.login IN ('qaown_260924','qafor_260924') ORDER BY r.id"),
      'world'=>array_map(fn($t)=>(int)$db->fetchOne('SELECT COUNT(*) n FROM `'.$t.'`')['n'],['galaxy','galaxy_orbits','orbital_resource_sites'])
    ]);
    '''
    result = subprocess.run(['docker', 'exec', '-i', 'swoole_app', 'php', '-r', code],
                            input=json.dumps({'unknown': unknown, 'fleet': fleet_id}), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.parse_args()
    owner = actor = None
    qid = fid = None
    unknown = 2147483001
    checks = 0
    try:
        owner, actor = Client('qaown_260924'), Client('qafor_260924')
        before = sql_state(unknown)
        assert not before['messages'] and not before['queues'] and not before['users'] and not before['friends']
        base = owner.query('fleets.get')['FleetLimits']['base']
        assert base['used'] < base['max'], ('Нужен один свободный слот QA-базирования перед тестом', base)
        h = owner.query('shipyard.get')
        assert not h['QueueList'] and not h['IsHangarBuild'], 'Нужна свободная QA-верфь.'
        assert next(x for x in rows(h['FleetList']) if x['id'] == '202')['accessible']
        owner.command('shipyard.build', {'items': {'202': 4}})
        q = next(x for x in rows(owner.query('shipyard.get')['QueueList']) if x['id'] == '202')
        qid = q['qid']
        for name in ('shipyard.cancel', 'defense.cancel', 'building.cancel', 'research.cancel'):
            actor.command(name, {'id': qid}, 'rejected')
            owner.command(name, {'id': str(unknown)}, 'rejected')
            checks += 2
        owner.command('building.cancel', {'id': qid}, 'rejected')
        owner.command('research.cancel', {'id': qid}, 'rejected')
        assert next(x for x in rows(owner.query('shipyard.get')['QueueList']) if x['qid'] == qid) == q
        owner.command('shipyard.cancel', {'id': qid})
        assert not owner.query('shipyard.get')['QueueList']
        qid = None
        checks += 4
        victim_messages = owner.query('messages.get')['allMessages']
        assert victim_messages, 'Нужно хотя бы одно существующее QA-сообщение.'
        mid = victim_messages[0]['id']
        snapshot = next(m for m in victim_messages if m['id'] == mid)
        for name, field in (('messages.read', 'ReadId'), ('messages.delete', 'Ids')):
            actor.command(name, {field: [str(unknown), mid]}, 'rejected' if name == 'messages.delete' else 'applied')
            assert next(m for m in owner.query('messages.get')['allMessages'] if m['id'] == mid) == snapshot
            checks += 1
        owner.command('friend.request', {'id': str(unknown), 'text': 'QA nonexistent recipient'}, 'rejected')
        checks += 1
        for data in ({'galaxy': 0, 'system': 1}, {'galaxy': 2, 'system': 1},
                     {'galaxy': 1, 'system': 129}, {'galaxy': [], 'system': 1},
                     {'galaxy': True, 'system': 1}, {'galaxy': '1', 'system': 1},
                     {'galaxy': 1.5, 'system': 1}, {'galaxy': 1, 'system': -1}):
            r = owner.call('query', name='galaxy.system.get', context=owner.context, data=data)
            assert r['type'] == 'error' and r['error']['code'] == 'query.unavailable'
            checks += 1
        original = owner.query('fleets.get')
        original_fleets = {f['id'] for f in rows(original['FleetList'])}
        assert original['PlanetShips']['202']['count'] >= 1
        donor = next(f for f in rows(original['FleetList']) if f['ships'].get('202', {}).get('count', 0) >= 2
                     and f['ships']['202'].get('experience', -1) == 0 and f['status'] == 0)
        owner.command('fleet.create', {'items': {'202': 1}})
        created = set(f['id'] for f in rows(owner.query('fleets.get')['FleetList'])) - original_fleets
        assert len(created) == 1
        fid = created.pop()
        for source, target, ship in ((fid, fid, '202'), ('0', '0', '202'), (fid, '0', '203')):
            owner.command('fleet.ships.transfer', {'fromFleetId': source, 'toFleetId': target, 'shipId': ship, 'count': 1}, 'rejected')
            checks += 1
        actor.command('fleet.ships.transfer', {'fromFleetId': fid, 'toFleetId': '0', 'shipId': '202', 'count': 1}, 'rejected')
        owner.command('fleet.ships.transfer', {'fromFleetId': donor['id'], 'toFleetId': fid, 'shipId': '202', 'count': 1})
        owner.command('fleet.ships.transfer', {'fromFleetId': fid, 'toFleetId': donor['id'], 'shipId': '202', 'count': 1})
        owner.command('fleet.ships.transfer', {'fromFleetId': fid, 'toFleetId': '0', 'shipId': '202', 'count': 1})
        final = owner.query('fleets.get')
        assert {f['id'] for f in rows(final['FleetList'])} == original_fleets
        assert final['PlanetShips'] == original['PlanetShips']
        assert next(f for f in rows(final['FleetList']) if f['id'] == donor['id'])['ships'] == donor['ships']
        deleted_id = fid
        fid = None
        checks += 7
        print('LIVE_CAPACITY_SAFETY_REQUESTS_OK', checks, flush=True)
        # Ждём обычный saver, не обращаемся к Tables из постороннего процесса.
        time.sleep(35)
        after = sql_state(unknown, deleted_id)
        assert not after['messages'] and not after['queues'] and not after['users'] and not after['friends']
        assert not after['fleet'] and not after['ships']
        assert after['currency'] == before['currency']
        assert after['world'] == before['world']
        print('LIVE_CAPACITY_SAFETY_PERSISTENCE_OK', checks + 8, flush=True)
    finally:
        if owner:
            if qid:
                owner.command('shipyard.cancel', {'id': qid})
            if fid:
                owner.command('fleet.disband', {'FleetId': fid})
            owner.close()
        if actor:
            actor.close()


if __name__ == '__main__':
    main()
