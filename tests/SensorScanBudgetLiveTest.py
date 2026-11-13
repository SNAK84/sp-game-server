#!/usr/bin/env python3
"""B066: местный QA-рейс на основном dev, time budget и высокий уровень раскрытия.

Перед запуском нужен qa-sensor-cache.php --prepare: один новый разведчик
в ангаре qafor_260924. Тест не меняет чужие рейсы, технологии или SQL.
В finally отзывает только свой рейс120, возвращает разведчика и удаляет
только свои новые сообщения. Подготовка/before-image восстанавливаются отдельно.
"""
import argparse
import json
import time
import uuid

from CapacitySafetyLiveTest import rows
from ProtocolPushBudgetLiveTest import PushClient
from BackgroundSaverLiveTest import stats
from RamRuntimeLiveTest import wait_for
from SensorIndexesLiveTest import fleet


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.add_argument('--expect-budget-ms', type=int, default=1)
    args = parser.parse_args()
    client = peer = None
    added = sent = False
    checks = 0
    latencies = []
    before_messages = set()

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    try:
        client, peer = PushClient('qafor_260924'), PushClient('qaown_260924')
        original = fleet(client, '120')
        check(original['status'] == 0 and not original['control']['steps'] and original['fuel'] == 0, 'QA120 занят.')
        check('221' not in original['ships'], 'В QA120 уже есть разведчик.')
        before_messages = {m['id'] for m in client.query('messages.get')['allMessages']}
        client.command('fleet.ships.transfer', {'fromFleetId': '0', 'toFleetId': '120', 'shipId': '221', 'count': 1})
        added = True
        home = next(p['coordinate'] for p in client.planets['items'] if p['id'] == client.context['planetId'])
        navigation = client.query('fleet.navigation.get', {'fleetId': '120', 'galaxy': home['galaxy'], 'system': home['system']})['NavigationSystem']
        point = {'type': 2, 'galaxy': home['galaxy'], 'system': home['system'],
                 'distance': navigation['minDistance'] + (navigation['maxDistance'] - navigation['minDistance']) * .2, 'angle': 85.0}
        preview = client.query('fleet.missions.get', {'fleetId': '120', 'missionType': 30, 'coordinates': point, 'speedFactor': 10})
        current = fleet(client, '120')
        fuel = max(1000, preview['TravelInfo']['fuel']['max'] * 3)
        check(30 in preview['AvailableMissions']['missions'] and fuel < current['capacity'], 'Не хватает запаса на рейс.')
        client.command('fleet.send', {'fleetId': '120', 'missionType': 30, 'coordinates': point, 'speedFactor': 10, 'fuel': fuel, 'resources': {}})
        sent = True
        print('SENSOR_SLICE_LOCAL_FLIGHT_SENT', json.dumps({'durationMax': preview['TravelInfo']['duration']['max']}), flush=True)
        wait_for(lambda: fleet(client, '120')['control']['holding'], 180)
        check(not fleet(client, '120')['sensors']['enabled'], 'Удержание включило сенсоры само.')
        sid = str(uuid.uuid4())
        subscribed = client.call('subscribe', subscriptionId=sid, name='galaxy.system.get', context=client.context,
                                 data={'galaxy': home['galaxy'], 'system': home['system']})
        check(subscribed['type'] == 'subscribed', 'Нет обычной подписки галактики.')
        before_hits = stats()['storage']['sensor.scanBudgetHits']
        client.command('fleet.sensors.set', {'fleetId': '120', 'enabled': True})

        def contacts():
            start = time.monotonic()
            result = client.query('galaxy.system.get', {'galaxy': home['galaxy'], 'system': home['system']})['SensorContacts']
            latencies.append(time.monotonic() - start)
            return result

        known_peer = {f['id']: f for f in rows(peer.query('fleets.get')['FlyingFleets'])}
        observed = wait_for(lambda: [c for c in contacts() if c['fresh'] and c['tier'] == 3 and c['id'] in known_peer], 90)
        chosen = observed[0]
        check({str(s['id']): s['count'] for s in chosen['ships']} ==
              {str(k): s['count'] for k, s in known_peer[chosen['id']]['ships'].items() if s['count'] > 0}, 'Нет точного состава.')
        for contact in observed:
            for field in ['fuel', 'resources', 'commands', '_observation', 'sourceId', 'sourceCommandId', 'sourceVersion', 'score', 'viewerId']:
                check(field not in contact, 'Раскрыто внутреннее поле ' + field)
            if 'route' in contact:
                check(contact['route']['motionStart']['system'] == home['system']
                      and contact['route']['motionEnd']['system'] == home['system'], 'Раскрыт чужой участок.')
        newest = wait_for(lambda: next((c for c in contacts() if c['id'] == chosen['id'] and c['fresh']
                            and c['observedAt'] > chosen['observedAt']), None), 90)
        client.drain()
        check(any(p.get('subscriptionId') == sid and any(c['id'] == chosen['id'] and c['fresh']
                  for c in p['data']['value']['SensorContacts']) for _, p in client.events), 'Контакт не пришёл обычным push.')
        client.command('fleet.sensors.set', {'fleetId': '120', 'enabled': False})
        stale = wait_for(lambda: next((c for c in contacts() if c['id'] == chosen['id'] and not c['fresh']), None), 45)
        time.sleep(11)
        frozen = next(c for c in contacts() if c['id'] == chosen['id'])
        check(not frozen['fresh'] and frozen['observedAt'] == stale['observedAt'] and frozen['position'] == stale['position'], 'Stale следит за живой целью.')
        wait_for(lambda: stats()['storage']['sensor.scanBudgetHits'] > before_hits, 45)
        metrics = stats()['storage']
        check(metrics['sensor.scanBudgetMs'] == args.expect_budget_ms, 'Проверяется другой временной бюджет.')
        check(metrics['sensor.scanLastSteps'] <= metrics['sensor.scanMaxSteps'], 'Превышены шаги порции.')
        check(metrics['sensor.scanPeakBytes'] <= metrics['sensor.scanMaxBytes'], 'Превышен промежуточный JSON.')
        check(metrics['storage.runtimeSqlRejected'] == 0 and metrics['storage.lastErrorAt'] == 0, 'Ошибка RAM/saver.')
        print('SENSOR_SLICE_TIME_BUDGET_PRIVACY_PUSH_OK', json.dumps({'checks': checks, 'maxQueryMs': round(max(latencies) * 1000, 2),
              'contacts': len(observed), 'scan': {k:v for k,v in metrics.items() if k.startswith('sensor.scan')}}), flush=True)
    finally:
        if sent:
            current = fleet(client, '120')
            if current['status'] != 0 and not current.get('is_return'):
                client.command('fleet.recall', {'fleetId': '120'})
            wait_for(lambda: fleet(client, '120')['status'] == 0, 200)
            returned = fleet(client, '120')
            check(not returned['control']['steps'] and returned['fuel'] == 0, 'Не завершён возврат QA.')
        if added:
            client.command('fleet.ships.transfer', {'fromFleetId': '120', 'toFleetId': '0', 'shipId': '221', 'count': 1})
            check(fleet(client, '120')['ships'] == original['ships'], 'Не восстановлен состав QA120.')
        if client is not None and before_messages:
            fresh = [m['id'] for m in client.query('messages.get')['allMessages'] if m['id'] not in before_messages
                     and str(json.loads(m.get('data') or '{}').get('FleetId')) == '120']
            if fresh: client.command('messages.delete', {'Ids': fresh})
        if client is not None: client.close()
        if peer is not None: peer.close()
    print('SENSOR_SCAN_BUDGET_LIVE_OK', json.dumps({'checks': checks, 'fleetReturned': True}), flush=True)


if __name__ == '__main__':
    main()
