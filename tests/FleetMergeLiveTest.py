#!/usr/bin/env python3
"""B070: настоящее слияние с удерживающимся QA-флотом на основном dev.

Создаёт донора из одного малого транспорта ангара, сливает и возвращает этот
корабль в ангар. Берёт только принимающий флот с нулевым опытом, чтобы уборка
не теряла XP. SQL только читает итог saver; время/позиции не подменяются.
"""
import argparse
import json
import math
import time

from CapacitySafetyLiveTest import Client, rows
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import wait_for


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.parse_args()
    c = Client('qaown_260924')
    donor = receiver = None
    original = None
    checks = 0
    messages = {m['id'] for m in c.query('messages.get')['allMessages']}

    def page(): return c.query('fleets.get')

    def fleets():
        p = page()
        return {f['id']: f for key in ('FleetList', 'FlyingFleets') for f in rows(p[key])}

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    def send(fid, target, mission):
        f = fleets()[fid]
        preview = c.query('fleet.missions.get', {'fleetId': fid, 'coordinates': target,
                                               'speedFactor': 10, 'missionType': mission})
        check(mission in preview['AvailableMissions']['missions'], 'Миссия недоступна.')
        requested = max(1000, preview['TravelInfo']['fuel']['max'] * 3) if mission == 30 else math.ceil(preview['TravelInfo']['fuel']['max'])
        fuel = min(f['capacity'] - 1, requested)
        c.command('fleet.send', {'fleetId': fid, 'missionType': mission, 'coordinates': target,
                                'speedFactor': 10, 'fuel': fuel, 'resources': {}})

    def home(fid):
        f = fleets().get(fid)
        if f is None or f['status'] == 0: return
        if not f['is_return']: c.command('fleet.recall', {'fleetId': fid})
        f = fleets()[fid]
        check(next(s['speedFactor'] for s in f['control']['steps'] if s['kind'] == 'return') == 10,
              'B073: после слияния/ручного удержания отзыв потерял скорость.')
        wait_for(lambda: (fleets().get(fid) or {}).get('status') == 0, 240)

    try:
        p = page()
        check(p['FleetLimits']['base']['used'] < p['FleetLimits']['base']['max'], 'Нужно свободное место базирования QA.')
        hangar = next(s for s in rows(p['PlanetShips']) if s['id'] == '202')['count']
        check(hangar >= 1, 'Нужен один малый транспорт в ангаре QA.')
        original = next(f for f in rows(p['FleetList']) if f['status'] == 0 and f['home_site_id'] == '0'
                        and f['fuel'] == 0 and f['ships'].get('202', {}).get('count', 0) > 0
                        and all(s['experience'] == 0 for s in f['ships'].values()) and not f['control']['steps'])
        receiver = original['id']
        before = set(fleets())
        c.command('fleet.create', {'items': {'202': 1}})
        created = set(fleets()) - before
        check(len(created) == 1, 'Не создан отдельный QA-донор.')
        donor = created.pop()
        place = original['current']
        nav = c.query('fleet.navigation.get', {'galaxy': place['galaxy'], 'system': place['system'],
                                              'fleetId': receiver})['NavigationSystem']
        point = {'type': 2, 'galaxy': place['galaxy'], 'system': place['system'],
                 'distance': nav['minDistance'] + .2 * (nav['maxDistance'] - nav['minDistance']), 'angle': 85.0}
        send(receiver, point, 30)
        time.sleep(2)
        c.command('fleet.stop', {'fleetId': receiver})
        held = fleets()[receiver]
        check(held['control']['holding'], 'Получатель не удерживается.')
        send(donor, {'type': 3, 'fleetId': receiver}, 35)
        print('QA_MERGE_DONOR_SENT', donor, flush=True)
        wait_for(lambda: donor not in fleets(), 240)
        merged = fleets()[receiver]
        check(merged['name'] == original['name'] and merged['mission_start'] == held['mission_start'], 'Имя/исходная база получателя.')
        check(merged['ships']['202']['count'] == original['ships']['202']['count'] + 1, 'Донор не передал корабль.')
        check(merged['control']['steps'] == held['control']['steps'] and merged['control']['holding'], 'Изменён маршрут получателя.')
        print('QA_MERGE_APPLIED_ROUTE_PRESERVED', flush=True)
        home(receiver)
        c.command('fleet.ships.transfer', {'fromFleetId': receiver, 'toFleetId': '0', 'shipId': '202', 'count': 1})
        final = fleets()[receiver]
        check(final['ships'] == original['ships'], 'Состав/опыт после уборки.')
        check(final['current'] == place, 'Домашняя база изменилась.')
        check(final['fuel'] == 0 and final['name'] == original['name'], 'Бак/имя после возврата.')
        check(next(s for s in rows(page()['PlanetShips']) if s['id'] == '202')['count'] == hangar, 'Ангар не восстановлен.')
    finally:
        try:
            if donor is not None and donor in fleets():
                home(donor)
                c.command('fleet.disband', {'FleetId': donor})
            if receiver is not None:
                home(receiver)
                f = fleets()[receiver]
                extra = f['ships']['202']['count'] - original['ships']['202']['count']
                if extra == 1:
                    c.command('fleet.ships.transfer', {'fromFleetId': receiver, 'toFleetId': '0', 'shipId': '202', 'count': 1})
                else: assert extra == 0, 'Нестандартный состав QA после слияния.'
            own = [m['id'] for m in c.query('messages.get')['allMessages'] if m['id'] not in messages
                   and str(json.loads(m.get('data') or '{}').get('FleetId')) in (receiver, donor)]
            if own: c.command('messages.delete', {'Ids': own})
        finally: c.close()
    wait_for(lambda: sql('echo json_encode($db->fetchOne("SELECT COUNT(*) n FROM fleet_commands WHERE fleet_id IN (?,?)",$x)["n"]);',
                        [int(receiver), int(donor)]) == 0)
    check(stats()['storage']['storage.runtimeSqlRejected'] == 0, 'SQL в authority.')
    print('FLEET_MERGE_LIVE_OK', json.dumps({'checks': checks, 'donorRemoved': True,
          'receiverHome': True, 'hangarRestored': True, 'serverRunning': True}), flush=True)


if __name__ == '__main__': main()
