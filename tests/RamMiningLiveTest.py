#!/usr/bin/env python3
"""Основной dev: ненулевая добыча QA-флота у своей базы, отзыв и общий saver.

Использует существующий QA HomeLoss stay [site5]; не выдаёт кораблей/ресурсов,
не ускоряет время. Расход/добыча настоящие, остаток груза сохраняется на борту.
"""
import argparse
import json
import time

from CapacitySafetyLiveTest import Client, rows
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import wait_for


def saved(fleet_id, site_id):
    return sql('''echo json_encode(['fleet'=>$db->fetchOne('SELECT id,status,home_site_id,resource_deuterium,fuel_current FROM fleets WHERE id=?',[$x['fleet']]),
      'head'=>$db->fetchOne('SELECT revision,phase FROM mining_checkpoints WHERE site_id=?',[$x['site']])]);''', {'fleet': fleet_id, 'site': site_id})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.parse_args()
    c = Client('qaown_260924')
    active = False
    fid = None

    def fleet():
        page = c.query('fleets.get')
        return next(f for part in ('FleetList', 'FlyingFleets') for f in rows(page[part]) if f['id'] == fid)

    try:
        initial = next(f for f in rows(c.query('fleets.get')['FleetList']) if f['name'] == 'QA HomeLoss stay')
        fid = initial['id']
        assert initial['status'] == 0 and initial['home_site_id'] == '5' and '224' in initial['ships']
        target = initial['current']
        before = saved(fid, target['siteId'])
        preview = c.query('fleet.missions.get', {'fleetId': fid, 'coordinates': target, 'missionType': 50, 'speedFactor': 10,
                         'mining': {'mode': 'deuterium', 'duration': 3600, 'finish': 'stay'}})
        assert 50 in preview['AvailableMissions']['missions'], preview['AvailableMissions']['messages']
        c.command('fleet.send', {'fleetId': fid, 'coordinates': target, 'missionType': 50,
                  'speedFactor': 10, 'fuel': 0, 'resources': {},
                  'mining': {'mode': 'deuterium', 'duration': 3600, 'finish': 'stay'}})
        active = True
        wait_for(lambda: fleet().get('mining'), 15)
        print('RAM_MINING_STARTED_OK', fid, flush=True)
        deadline = time.monotonic() + 12
        while time.monotonic() < deadline:
            assert fleet().get('mining') is not None
            time.sleep(.5)
        c.command('fleet.recall', {'fleetId': fid})
        wait_for(lambda: fleet().get('status') == 0, 30)
        active = False
        result = fleet()
        cargo = result['resources']['903']['count']
        assert cargo > initial['resources']['903']['count'], 'Не начислена ненулевая добыча.'
        assert result['home_site_id'] == initial['home_site_id'] and result['current'] == initial['current']
        assert {k: v['count'] for k, v in result['ships'].items()} == {k: v['count'] for k, v in initial['ships'].items()}
        wait_for(lambda: saved(fid, target['siteId'])['fleet']['resource_deuterium'] == cargo)
        after = saved(fid, target['siteId'])
        assert after['head']['phase'] == 'applied' and after['head']['revision'] > (before['head']['revision'] if before['head'] else 0)
        assert stats()['storage']['storage.runtimeSqlRejected'] == 0
        print('RAM_MINING_RECALL_PERSISTENCE_OK', json.dumps({'fleetId': fid,
              'deuteriumGained': cargo - initial['resources']['903']['count'], 'revision': after['head']['revision']}), flush=True)
    finally:
        try:
            if active:
                c.command('fleet.recall', {'fleetId': fid})
                wait_for(lambda: fleet().get('status') == 0, 30)
        finally:
            c.close()


if __name__ == '__main__':
    main()
