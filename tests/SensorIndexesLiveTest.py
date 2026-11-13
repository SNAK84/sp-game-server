#!/usr/bin/env python3
"""Основной dev: настоящие OBSERVE двух QA, индексный скан и чужой рейс.

Только существующие QA-флоты. Нет подготовки SQL/ускорения часов/изолированного
мира. Обычный расход полёта/удержания остаётся; созданные сообщения удаляются.
При ошибке тест отзывает свои рейсы и ждёт их настоящего возвращения.
"""
import argparse
import json
import time

from CapacitySafetyLiveTest import Client, rows
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import wait_for


def fleet(c, fid):
    page = c.query('fleets.get')
    return next(f for key in ['FleetList', 'FlyingFleets'] for f in rows(page[key]) if f['id'] == fid)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--expect-overflow', action='store_true', help='B066: малый JSON-бюджет сохраняет прежний слабый контакт')
    parser.add_argument('--allow-active', action='store_true', help='Разрешить обычный QA-рейс при чужом подключённом клиенте; чужие данные не меняются')
    args = parser.parse_args()
    assert args.allow_active or stats()['authorized'] == 0, 'Нужен свободный dev или --allow-active для неразрушительного QA-рейса.'
    owner = target = None
    launched = []
    checks = 0

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    try:
        owner, target = Client('qaown_260924'), Client('qafor_260924')
        source_rows = rows(owner.query('fleets.get')['FleetList'])
        weak = next(f for f in source_rows if f['name'] == 'QA P001 sensor')
        strong = next(f for f in source_rows if f['name'] == 'QA B034 sensor')
        visitor = next(f for f in rows(target.query('fleets.get')['FleetList']) if f['name'] == 'QA Restart restored')
        for f in [weak, strong, visitor]:
            check(f['status'] == 0 and not f['control']['steps'] and f['fuel'] == 0, 'Нужны свободные QA-флоты.')
        check(weak['ships']['221']['count'] == 1 and strong['ships']['221']['count'] == 256, 'Изменился состав источников.')
        check(sum(s['count'] for s in visitor['ships'].values()) == 1, 'Нужна одиночная цель.')
        before_messages = {id(c): {m['id'] for m in c.query('messages.get')['allMessages']} for c in [owner, target]}
        home = next(p for p in owner.planets['items'] if p['id'] == owner.context['planetId'])['coordinate']
        nav = owner.query('fleet.navigation.get', {'galaxy': home['galaxy'], 'system': home['system'], 'fleetId': weak['id']})['NavigationSystem']
        point = {'type': 2, 'galaxy': home['galaxy'], 'system': home['system'],
                 'distance': nav['minDistance'] + (nav['maxDistance'] - nav['minDistance']) * .2, 'angle': 85.0}

        def contacts():
            return owner.query('galaxy.system.get', {'galaxy': home['galaxy'], 'system': home['system']})['SensorContacts']

        def contact():
            return next((x for x in contacts() if x['id'] == visitor['id']), None)

        def holding(c, fid):
            return fleet(c, fid)['control']['holding']

        # Сначала длинный чужой рейс: источники доберутся за его время.
        for c, f, fuel in [(target, visitor, 1000), (owner, weak, 500), (owner, strong, 10000)]:
            p = c.query('fleet.missions.get', {'fleetId': f['id'], 'coordinates': point, 'speedFactor': 10, 'missionType': 30})
            check(30 in p['AvailableMissions']['missions'], 'OBSERVE недоступен.')
            check(p['TravelInfo']['fuel']['max'] < fuel < f['capacity'], 'Неверный запас топлива.')
            launched.append((c, f))
            c.command('fleet.send', {'fleetId': f['id'], 'missionType': 30, 'coordinates': point,
                                     'speedFactor': 10, 'fuel': fuel, 'resources': {}})
            print('SENSOR_INDEX_FLIGHT_SENT', json.dumps({'fleet': f['id'], 'durationMax': p['TravelInfo']['duration']['max']}), flush=True)
        wait_for(lambda: holding(owner, weak['id']) and holding(owner, strong['id']), 180)
        check(not fleet(owner, weak['id'])['sensors']['enabled'] and not fleet(owner, strong['id'])['sensors']['enabled'], 'Удержание самовольно сканирует.')
        owner.command('fleet.sensors.set', {'fleetId': weak['id'], 'enabled': True})
        check(fleet(owner, weak['id'])['sensors']['enabled'], 'Слабый источник не включён.')
        # Межсистемный переход действительно проходит, а не подменяется тестом.
        def transition():
            f = fleet(target, visitor['id'])
            return f if f.get('command', {}).get('type') in (11, 12) else None

        wait_for(transition, 200)
        check(contact() is None, 'Чужой переход виден сенсорам.')
        print('SENSOR_INDEX_TRANSITION_HIDDEN_OK', flush=True)
        last_progress = time.monotonic()
        deadline = time.monotonic() + 600
        low = None
        while time.monotonic() < deadline:
            low = contact()
            if low is not None and low['fresh']: break
            if time.monotonic() - last_progress > 40:
                print('SENSOR_INDEX_WAITING_REAL_ARRIVAL', flush=True)
                last_progress = time.monotonic()
            time.sleep(1)
        check(low is not None and low['fresh'] and low['tier'] == 1, 'Нет слабого контакта в системе источника.')
        for key in ['ships', 'route', 'owner_name', 'countRange', 'fuel', 'resources', 'commands', '_observation', 'sourceId', 'score']:
            check(key not in low, 'Слабый контакт раскрывает ' + key)
        check(not any(x['id'] in [weak['id'], strong['id']] for x in contacts()), 'Сенсор выдаёт свои флоты как чужие.')
        print('SENSOR_INDEX_TIER1_PRIVACY_OK', flush=True)
        before_overflow = stats()['storage'].get('sensor.scanOversized', 0)
        owner.command('fleet.sensors.set', {'fleetId': strong['id'], 'enabled': True})
        if args.expect_overflow:
            # Подписка/чтение не обновляют контакт. Два законченных oversized
            # скана не должны публиковать сильную часть/продлевать старый снимок.
            wait_for(lambda: stats()['storage'].get('sensor.scanOversized', 0) > before_overflow, 75)
            frozen = contact()
            check(frozen is not None and frozen['tier'] == 1 and 'route' not in frozen and 'ships' not in frozen, 'Переполнение заменило старый снимок.')
            time.sleep(35)
            preserved = contact()
            check(preserved is not None and preserved['observedAt'] == frozen['observedAt']
                  and preserved['position'] == frozen['position'] and preserved['freshUntil'] == frozen['freshUntil']
                  and preserved['retainUntil'] == frozen['retainUntil'], 'Переполнение продлило или изменило контакт.')
            check(not preserved['fresh'], 'Переполнение продлевает свежесть без нового наблюдения.')
            print('SENSOR_SCAN_OVERFLOW_PRESERVES_CONTACT_OK', flush=True)
        else:
            high = wait_for(lambda: (lambda x: x if x and x['fresh'] and x['tier'] == 3 and 'route' in x else None)(contact()), 40)
            check(high['ships'][0]['count'] == 1, 'Не раскрыт точный состав.')
            check(all(key in high['route'] for key in ['start', 'end', 'motionStart', 'motionEnd', 'startsAt', 'endsAt']), 'Нет текущего участка.')
            for key in ['fuel', 'resources', 'commands', '_observation', 'sourceId', 'score']:
                check(key not in high, 'Сильный контакт раскрывает ' + key)
            check(high['route']['motionStart']['system'] == home['system'] and high['route']['motionEnd']['system'] == home['system'], 'Раскрыт участок другой системы.')
        owner.command('fleet.sensors.set', {'fleetId': strong['id'], 'enabled': False})
        wait_for(lambda: (lambda x: x if x and x['fresh'] and x['tier'] == 1
                 and (not args.expect_overflow or x['observedAt'] > frozen['observedAt']) else None)(contact()), 75)
        owner.command('fleet.sensors.set', {'fleetId': weak['id'], 'enabled': False})
        stale = wait_for(lambda: (lambda x: x if x and not x['fresh'] else None)(contact()), 40)
        observed = stale['observedAt']
        position = stale['position']
        time.sleep(11)
        later = contact()
        check(later is not None and not later['fresh'] and later['observedAt'] == observed and later['position'] == position, 'Устаревший контакт следит за живой целью.')
        check(fleet(owner, weak['id'])['control']['holding'] and fleet(owner, strong['id'])['control']['holding'], 'Выключение сенсора отменило удержание.')
        metrics = stats()['storage']
        counts = sql('echo json_encode(["fleets"=>(int)$db->fetchOne("SELECT COUNT(*) n FROM fleets")["n"],"nonIdle"=>(int)$db->fetchOne("SELECT COUNT(*) n FROM fleets WHERE status<>0")["n"]]);', {})
        check(metrics['sensor.sourceCandidates'] < counts['fleets'] and 0 < metrics['sensor.targetCandidates'] <= counts['nonIdle'], 'Скан не ограничен индексными кандидатами.')
        check(metrics['sensor.scanLastSteps'] <= metrics['sensor.scanMaxSteps'], 'Нарушен бюджет порции.')
        if metrics['sensor.scanMaxSteps'] <= 32:
            check(metrics['sensor.scanBudgetHits'] > 0, 'Не проверено продолжение между тиками.')
        check(metrics['sensor.scanPeakBytes'] <= metrics['sensor.scanMaxBytes'], 'Нарушен бюджет промежуточного JSON.')
        check(metrics['storage.runtimeSqlRejected'] == 0, 'SQL в игровом цикле.')
        print('SENSOR_INDEX_CONTACT_LIFECYCLE_OK', json.dumps({'checks': checks, 'candidates': {k:v for k,v in metrics.items() if k.startswith('sensor.')}, 'totalFleets': counts['fleets']}), flush=True)
    finally:
        # Сначала всем задаём возврат, затем ждём параллельные реальные рейсы.
        for c, f in launched:
            current = fleet(c, f['id'])
            if current['status'] != 0 and not current.get('is_return'):
                c.command('fleet.recall', {'fleetId': f['id']})
        if launched:
            wait_for(lambda: all(fleet(c, f['id'])['status'] == 0 for c, f in launched), 700)
            for c, original in launched:
                returned = fleet(c, original['id'])
                check(not returned['control']['steps'] and returned['fuel'] == 0, 'После возврата остался маршрут/топливо.')
                check({k:s['count'] for k,s in returned['ships'].items()} == {k:s['count'] for k,s in original['ships'].items()}, 'Потерян состав.')
            for c in [owner, target]:
                own_ids = {f['id'] for one, f in launched if one is c}
                fresh = [m['id'] for m in c.query('messages.get')['allMessages'] if m['id'] not in before_messages[id(c)]
                         and str(json.loads(m.get('data') or '{}').get('FleetId')) in own_ids]
                if fresh: c.command('messages.delete', {'Ids': fresh})
        if owner is not None: owner.close()
        if target is not None: target.close()
    print('SENSOR_INDEXES_LIVE_OK', json.dumps({'checks': checks, 'qaFleetsReturned': True, 'serverRunning': True}), flush=True)


if __name__ == '__main__':
    main()
