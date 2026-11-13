#!/usr/bin/env python3
"""B070: продолжить проверку уже накопленной реальной истории QA-флота.

SPA/push/reconnect, native undo-probe при штатном restart, редактор v2 и возврат
на максимальной штатной скорости. SQL только читает. Никаких ускорений мира.
"""
import argparse
import json
from pathlib import Path
import subprocess
import time

from CapacitySafetyLiveTest import Client, grant, rows
from BackgroundSaverLiveTest import sql, stats
from RamRuntimeLiveTest import wait_for
from PlanetClockLiveTest import manage, running


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.add_argument('--fleet-id', default='37')
    parser.add_argument('--probe-directory', type=Path)
    args = parser.parse_args()
    fid = args.fleet_id
    g = grant('qaown_260924')
    c = Client('qaown_260924', world_grant=g)
    before_messages = {m['id'] for m in c.query('messages.get')['allMessages']}
    checks = 0

    def fleet(page=None):
        p = c.query('fleets.get', {'historyPages': {fid: page}} if page else {})
        return next(f for key in ('FleetList','FlyingFleets') for f in rows(p[key]) if f['id'] == fid)

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    def return_quickly():
        f = fleet()
        if f['status'] == 0:
            return
        if f['actions']['StopFleet']:
            c.command('fleet.stop', {'fleetId': fid})
            f = fleet()
        assert f['control']['holding'] or f['control']['paused'], 'Нужна управляемая остановка.'
        c.command('fleet.route.set', {'fleetId':fid,'plan':{'version':f['control']['version'],
                  'steps':[{'kind':'return','speedFactor':10}],'fuel':0,'resources':{}}})
        c.command('fleet.resume', {'fleetId':fid})
        print('QA_NORMAL_RETURN_ROUTE_APPLIED',fid,flush=True)
        wait_for(lambda: fleet()['status'] == 0, 300)

    try:
        f = fleet()
        check(f['status'] != 0, 'Нужен существующий QA-рейс с историей.')
        counts = {k:v['count'] for k,v in f['ships'].items()}
        original_name = f['name']
        if f['actions']['StopFleet']:
            c.command('fleet.stop', {'fleetId':fid})
        held = fleet()
        check(held['commands_page']['pages'] >= 2, 'Нужны две страницы настоящей истории.')
        all_rows = [row for page in range(1,held['commands_page']['pages']+1) for row in fleet(page)['commands']]
        check(len(all_rows) == held['commands_total'], 'Потеря команд при пагинации.')
        check([r['position'] for r in all_rows] == list(range(1,len(all_rows)+1)), 'Общая нумерация.')
        c.close()
        browser = subprocess.run(['python3','/var/www/spa.snak84.ru/tools/check_fleet_history_live.py','--execute'],
                                 input=json.dumps({'grant':g,'fleetId':fid}),text=True,capture_output=True,timeout=100)
        print(browser.stdout,end='',flush=True)
        if browser.returncode:
            print('BROWSER_HISTORY_FAILED',browser.stderr[-2000:],flush=True)
        assert browser.returncode == 0,browser.stderr[-2000:]
        if args.probe_directory:
            print('READY_FOR_UNDO_PROBE',json.dumps({'fleetId':fid,'directory':str(args.probe_directory)}),flush=True)
            deadline=time.monotonic()+180
            while not (args.probe_directory/'continue').exists():
                assert time.monotonic()<deadline,'Не подготовлен основной probe.'
                time.sleep(.3)
            manage('stop'); manage('start')
            proof=json.loads((args.probe_directory/'result2.json').read_text())
            print('MAIN_WORLD_UNDO_PROBE_RESULT',json.dumps(proof),flush=True)
            check(proof['ok'], 'Основной undo-probe не пройден.')
        c=Client('qaown_260924',world_grant=g)
        restored=fleet()
        check(restored['control']['steps']==held['control']['steps'], 'Редактор после restart.')
        check(restored['route_progress']==held['route_progress'], 'Прогресс после restart.')
        return_quickly()
        final=fleet()
        check({k:v['count'] for k,v in final['ships'].items()}==counts, 'Состав изменился.')
        check(final['name']==original_name and final['fuel']==0, 'Имя/выгрузка топлива.')
    finally:
        if not running(): manage('start')
        try:
            c.close()
            c=Client('qaown_260924',world_grant=g)
            return_quickly()
            own=[m['id'] for m in c.query('messages.get')['allMessages'] if m['id'] not in before_messages
                 and str(json.loads(m.get('data') or '{}').get('FleetId'))==fid]
            if own: c.command('messages.delete',{'Ids':own})
        finally:c.close()
    wait_for(lambda: sql('echo json_encode((int)$db->fetchOne("SELECT COUNT(*) n FROM fleet_commands WHERE fleet_id=?",[$x])["n"]);',int(fid))==0)
    check(stats()['storage']['storage.runtimeSqlRejected']==0,'SQL в authority.')
    print('FLEET_HISTORY_RESUME_LIVE_OK',json.dumps({'checks':checks,'fleetHome':True,'historyCleared':True,'serverRunning':True}),flush=True)


if __name__=='__main__':
    main()
