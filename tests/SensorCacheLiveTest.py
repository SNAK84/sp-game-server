#!/usr/bin/env python3
"""B064 на основном dev: настоящий cache-full, неизменность живого снимка и TTL.

Нужны qa-sensor-cache.php --prepare и временный SENSOR_CONTACT_VIEWERS_LIMIT,
равный числу существующих не-QA наблюдателей+1 (сейчас2). Оба QA летят обычным
OBSERVE в своей системе и видят настоящие чужие флоты. Часы не ускоряются:
ожидание настоящих300с TTL, затем автоматическая публикация второго QA.
В finally оба рейса отзываются, разведчик возвращается в ангар, удаляются
только их новые сообщения; топливо списывается штатно. Before-image и .env
восстанавливаются отдельно после завершения живого теста при остановке мира.
"""
import argparse
import json
import time

from CapacitySafetyLiveTest import rows
from ProtocolPushBudgetLiveTest import PushClient
from BackgroundSaverLiveTest import stats
from RamRuntimeLiveTest import wait_for
from SensorIndexesLiveTest import fleet


class Client(PushClient):
    # Только явный retryable и тот же пакет/rid; timeout/обрыв не replay.
    # Длинный живой TTL-тест должен пережить кратковременный отказ кабинета.
    retry_seconds = 90


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--capacity', type=int, required=True)
    args = parser.parse_args()
    baseline = stats()
    assert baseline['authorized'] == 0, 'Нужен dev без чужих подключённых клиентов.'
    assert baseline['storage']['sensor.cacheCapacity'] == args.capacity
    assert baseline['storage']['sensor.cacheViewers'] == args.capacity - 1, 'Не совпало число уже работающих наблюдателей.'
    clients = []
    launched = []
    carrier = None
    scout_added = False
    before_messages = []
    checks = 0

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    def local_contacts(client):
        home = next(p['coordinate'] for p in client.planets['items'] if p['id'] == client.context['planetId'])
        return client.query('galaxy.system.get', {'galaxy': home['galaxy'], 'system': home['system']})['SensorContacts']

    try:
        clients = [Client('qaown_260924'), Client('qafor_260924')]
        for c in clients:
            before_messages.append({m['id'] for m in c.query('messages.get')['allMessages']})
        sources = [next(f for f in rows(clients[0].query('fleets.get')['FleetList']) if f['name'] == 'QA P001 sensor')]
        page = clients[1].query('fleets.get')
        check(page['PlanetShips']['221']['count'] >= 1, 'Нет подготовленного разведчика.')
        carrier = next(f['id'] for f in rows(page['FleetList']) if f['name'] == 'QA Restart restored')
        clients[1].command('fleet.ships.transfer', {'fromFleetId': '0', 'toFleetId': carrier, 'shipId': '221', 'count': 1})
        scout_added = True
        sources.append(fleet(clients[1], carrier))
        check(sources[1]['ships']['221']['count'] == 1, 'Разведчик не добавлен в штатный QA-флот.')
        for c, source in zip(clients, sources):
            check(source['status'] == 0 and not source['control']['steps'], 'Источник уже занят.')
            home = next(p['coordinate'] for p in c.planets['items'] if p['id'] == c.context['planetId'])
            nav = c.query('fleet.navigation.get', {'galaxy': home['galaxy'], 'system': home['system'], 'fleetId': source['id']})['NavigationSystem']
            point = {'type': 2, 'galaxy': home['galaxy'], 'system': home['system'],
                     'distance': nav['minDistance'] + (nav['maxDistance'] - nav['minDistance']) * .2, 'angle': 85.0}
            preview = c.query('fleet.missions.get', {'fleetId': source['id'], 'coordinates': point, 'speedFactor': 10, 'missionType': 30})
            check(30 in preview['AvailableMissions']['missions'], 'OBSERVE недоступен.')
            check(preview['TravelInfo']['fuel']['max'] < 500 < source['capacity'], 'Недостаточный тестовый резерв.')
            launched.append((c, source))
            c.command('fleet.send', {'fleetId': source['id'], 'missionType': 30, 'coordinates': point,
                                     'speedFactor': 10, 'fuel': 500, 'resources': {}})
            print('SENSOR_CACHE_REAL_FLIGHT', json.dumps({'fleet': source['id'], 'system': home['system'], 'durationMax': preview['TravelInfo']['duration']['max']}), flush=True)
        wait_for(lambda: all(fleet(c, f['id'])['control']['holding'] for c, f in launched), 180)

        clients[0].command('fleet.sensors.set', {'fleetId': sources[0]['id'], 'enabled': True})
        first = wait_for(lambda: (v if (v := local_contacts(clients[0])) and any(x['fresh'] for x in v) else None), 25)
        check(all(x['id'] != sources[0]['id'] for x in first), 'Свои корабли выданы чужими.')
        check(all('resources' not in x and 'fuel' not in x and '_observation' not in x for x in first), 'Утечка данных.')
        check(not local_contacts(clients[1]), 'Второй QA сканирует до включения.')
        clients[1].command('fleet.sensors.set', {'fleetId': sources[1]['id'], 'enabled': True})
        wait_for(lambda: stats()['storage']['sensor.cacheRejected'] > baseline['storage']['sensor.cacheRejected'], 30)
        check(not local_contacts(clients[1]), 'Переполнение обошло ограничение.')
        check(any(x['fresh'] for x in local_contacts(clients[0])), 'Новый наблюдатель вытеснил действующий снимок.')
        check(all(fleet(c, f['id'])['sensors']['enabled'] for c, f in launched), 'Backpressure отключил сам флот/сенсоры.')
        full = stats()['storage']
        check(full['sensor.cacheViewers'] == args.capacity, 'Не достигнута фактическая граница буфера.')
        print('SENSOR_CACHE_REAL_FULL_OK', json.dumps({'checks': checks, 'viewers': full['sensor.cacheViewers'], 'rejected': full['sensor.cacheRejected']}), flush=True)

        clients[0].command('fleet.sensors.set', {'fleetId': sources[0]['id'], 'enabled': False})
        stale = wait_for(lambda: (v if (v := local_contacts(clients[0])) and all(not x['fresh'] for x in v) else None), 10)
        original = {x['id']: (x['observedAt'], x['position']) for x in stale}
        until = max(x['retainUntil'] for x in stale)
        # Подписка использует тот же обычный dirty/flush механизм B065.
        import uuid
        sid = str(uuid.uuid4())
        home = next(p['coordinate'] for p in clients[0].planets['items'] if p['id'] == clients[0].context['planetId'])
        response = clients[0].call('subscribe', name='galaxy.system.get', subscriptionId=sid,
                                   context=clients[0].context, data={'galaxy': home['galaxy'], 'system': home['system']})
        check(response['type'] == 'subscribed', 'Не создана подписка очистки.')
        print('SENSOR_CACHE_WAIT_REAL_TTL', json.dumps({'remainingSeconds': round(until - time.time(), 1)}), flush=True)
        last_update = time.monotonic()
        while time.time() < until - 1:
            visible = local_contacts(clients[0])
            check(all(not x['fresh'] and (x['observedAt'], x['position']) == original[x['id']] for x in visible), 'Устаревшая отметка продолжила следить за целью.')
            check(not local_contacts(clients[1]), 'Живой снимок вытеснен до TTL.')
            if time.monotonic() - last_update > 30:
                print('SENSOR_CACHE_TTL_PROGRESS', round(until - time.time(), 1), flush=True)
                last_update = time.monotonic()
            time.sleep(min(3, max(.1, until - 1 - time.time())))
        wait_for(lambda: not local_contacts(clients[0]), 10)
        recovered = wait_for(lambda: (v if (v := local_contacts(clients[1])) and any(x['fresh'] for x in v) else None), 25)
        check(recovered is not None, 'Публикация не восстановилась автоматически после TTL.')
        check(fleet(clients[0], sources[0]['id'])['control']['holding'], 'Очистка кэша отменила удержание.')
        check(fleet(clients[1], sources[1]['id'])['sensors']['enabled'], 'Второй сенсор потребовал ручного повторного включения.')
        end = wait_for(lambda: (g if (g := stats()['storage'])['sensor.cacheExpired'] > full['sensor.cacheExpired'] else None), 10)
        check(end['sensor.cacheViewers'] <= args.capacity, 'Превышен бюджет после повторной публикации.')
        check(end['storage.runtimeSqlRejected'] == 0, 'SQL в runtime.')
        def pushed_clear():
            clients[0].call('ping')
            return any(v['subscriptionId'] == sid and v['data']['value']['SensorContacts'] == [] for _, v in clients[0].events)
        check(wait_for(pushed_clear, 10), 'Очистка не доставлена обычной подпиской без перезагрузки.')
        check(clients[0].call('unsubscribe', subscriptionId=sid)['type'] == 'unsubscribed', 'Не удалена подписка.')
        print('SENSOR_CACHE_REAL_TTL_RECOVERY_OK', json.dumps({'checks': checks, 'expired': end['sensor.cacheExpired'],
              'rejected': end['sensor.cacheRejected'], 'newContacts': len(recovered)}), flush=True)
    finally:
        for c, f in launched:
            current = fleet(c, f['id'])
            if current['status'] != 0 and not current.get('is_return'):
                c.command('fleet.recall', {'fleetId': f['id']})
        if launched:
            wait_for(lambda: all(fleet(c, f['id'])['status'] == 0 for c, f in launched), 200)
            for c, original_fleet in launched:
                returned = fleet(c, original_fleet['id'])
                check(returned['fuel'] == 0 and not returned['control']['steps'], 'Не очищен возврат.')
                check({k:s['count'] for k,s in returned['ships'].items()} == {k:s['count'] for k,s in original_fleet['ships'].items()}, 'Изменён состав.')
        if scout_added:
            clients[1].command('fleet.ships.transfer', {'fromFleetId': carrier, 'toFleetId': '0', 'shipId': '221', 'count': 1})
            check('221' not in fleet(clients[1], carrier)['ships'], 'Разведчик не возвращён в ангар.')
        for i, c in enumerate(clients):
            if i < len(before_messages):
                owned = {f['id'] for one, f in launched if one is c}
                fresh_ids = [m['id'] for m in c.query('messages.get')['allMessages'] if m['id'] not in before_messages[i]
                             and str(json.loads(m.get('data') or '{}').get('FleetId')) in owned]
                if fresh_ids: c.command('messages.delete', {'Ids': fresh_ids})
            c.close()
    print('SENSOR_CACHE_LIVE_OK', json.dumps({'checks': checks, 'qaReturned': True}), flush=True)


if __name__ == '__main__':
    main()
