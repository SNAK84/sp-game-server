"""B031: реальные раунды основного main, без изолированного мира.

--prepare before2|after: остановленная QA-подготовка, обязательный запуск main,
затем SIGSTOP только его двух TaskWorker на 9 секунд, SIGCONT в finally.
Основной event loop и WebSocket продолжают работать. Никаких credentials в выводе.
"""
import argparse
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import time

MANAGER='/opt/sp-game/swoole_manager.sh'


def sql(source):
    p=subprocess.run(['docker','exec','-i','-w','/var/www/html','swoole_app','php','-r',
        "require 'vendor/autoload.php';SPGame\\Core\\Environment::init('.env');$d=SPGame\\Core\\Database::getInstance();"+source],
        capture_output=True,text=True,timeout=20)
    assert p.returncode==0,'Read-only SQL failed'
    return json.loads(p.stdout)


def restart_start():
    process=subprocess.Popen([MANAGER,'start'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    try:
        until=time.monotonic()+110
        while time.monotonic()<until:
            try:
                with socket.create_connection(('127.0.0.1',9501),timeout=.5):return
            except OSError:time.sleep(.3)
        raise AssertionError('Main did not start')
    finally:
        if process.poll() is None:os.killpg(process.pid,signal.SIGTERM)


def prepare(scene):
    stopped=subprocess.run([MANAGER,'stop'],capture_output=True,timeout=85)
    assert stopped.returncode==0,'Main stop failed'
    try:
        run=subprocess.run(['docker','run','--rm','--network','host','--volumes-from','swoole_app','-w','/var/www/html',
            '--entrypoint','php','sp-game_swoole','migrations/20261101_space_pending_reinforcement_qa.php',scene],
            capture_output=True,text=True,timeout=45)
        if run.returncode:raise AssertionError('QA preparation failed: '+run.stderr[-1500:])
        data=json.loads(run.stdout.strip().splitlines()[-1])
    finally:restart_start()
    print(json.dumps(data),flush=True)
    return data


def snapshot(scene):
    assert scene in ('before2','after')
    return sql("$b=$d->fetchAll('SELECT id,phase,state FROM space_battles ORDER BY id DESC LIMIT 5');"
        "foreach($b as &$r)$r['state']=json_decode($r['state'],true,512,JSON_THROW_ON_ERROR);unset($r);"
        f"$f=$d->fetchAll(\"SELECT * FROM fleets WHERE name LIKE 'QA B031 {scene} %' ORDER BY id\");"
        "$ids=array_column($f,'id');$q=implode(',',array_map('intval',$ids));"
        "$c=$q?$d->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id IN ('.$q.') ORDER BY id'):[];"
        "$s=$q?$d->fetchAll('SELECT * FROM fleets_ships WHERE id IN ('.$q.') ORDER BY id'):[];"
        f"$r=$d->fetchAll(\"SELECT id,ready,payload FROM battle_reports WHERE payload LIKE '%QA B031 {scene} winner%'\");"
        "foreach($r as &$p)$p['payload']=json_decode($p['payload'],true,512,JSON_THROW_ON_ERROR);unset($p);"
        "echo json_encode(['battles'=>$b,'fleets'=>$f,'commands'=>$c,'ships'=>$s,'reports'=>$r]);")


def gate(data):
    # Не трогаем event-loop, manager, saver или посторонний PHP-процесс.
    while time.time()<data['startAt']-1:time.sleep(min(.2,max(.01,data['startAt']-1-time.time())))
    root=int(subprocess.check_output(['docker','inspect','-f','{{.State.Pid}}','swoole_app'],text=True))
    children=Path(f'/proc/{root}/task/{root}/children').read_text().split()
    workers=[]
    for child in children:
        path=Path('/proc')/child
        cmd=path.joinpath('cmdline').read_bytes().replace(b'\x00',b' ').strip()
        if cmd==b'php /var/www/html/src/main.php' and 'skb_wait' in path.joinpath('wchan').read_text():
            workers.append(int(child))
    assert len(workers)==2,'Cannot unambiguously identify two TaskWorker'
    stopped=[]
    try:
        for pid in workers:os.kill(pid,signal.SIGSTOP);stopped.append(pid)
        print(json.dumps({'taskWorkersStopped':workers,'eventLoopUnaffected':True}),flush=True)
        while time.time()<data['startAt']+8:time.sleep(.1)
    finally:
        for pid in stopped:os.kill(pid,signal.SIGCONT)
        print(json.dumps({'taskWorkersResumed':stopped}),flush=True)
    until=time.monotonic()+60
    while time.monotonic()<until:
        saved=snapshot(data['scene'])
        b=next((b for b in saved['battles'] if b['id']==data['key']),None)
        if b and b['phase']=='applied':
            state=b['state'];parts=state['mission']['participants']
            assert len(state['combat']['rounds'])==1 and len(parts)==4,'Race not reproduced'
            for role in ('lateAttack','lateDefense'):assert parts[str(data['fleets'][role])]['joinedRound']==2
            print(json.dumps({'battle':data['key'],'phase':'applied','rounds':1,
                'pendingJoinedRound':{r:parts[str(data['fleets'][r])]['joinedRound'] for r in ('lateAttack','lateDefense')},
                'engaged':state['resolution']['engaged'],'resolutionKeys':list(state['resolution'])}),flush=True)
            return
        time.sleep(.5)
    raise AssertionError('Battle not applied or not saved')


def verify_saved(scene,fixed):
    saved=snapshot(scene)
    fleet=lambda role:next(f for f in saved['fleets'] if f['name']=='QA B031 '+scene+' '+role)
    fid=fleet('lateAttack')['id']
    battle=next((b for b in saved['battles'] if str(fid) in b['state']['mission']['participants']),None)
    state=battle['state'] if battle else None
    # Applied checkpoint штатно удаляется после saver; готовый доклад долговечен.
    assert len(saved['reports'])==1 and saved['reports'][0]['ready']==1
    report=saved['reports'][0]['payload'];assert len(report['rounds'])==1
    if state:assert battle['phase']=='applied' and len(state['combat']['rounds'])==1
    for role,xp in [('lateAttack',321),('lateDefense',654)]:
        f=fleet(role)
        assert all(p['fleetId']!=f['id'] for p in report['participants'].values())
        if state:
            part=state['mission']['participants'][str(f['id'])]
            assert part['joinedRound']==2 and str(f['id']) not in state['resolution']['engaged']
        chain=[c for c in saved['commands'] if c['fleet_id']==f['id']]
        ships=next(s for s in saved['ships'] if s['id']==f['id'])
        assert sum(v for k,v in ships.items() if k.endswith('_count'))==1
        assert sum(v for k,v in ships.items() if k.endswith('_experience'))==xp
        if fixed and role=='lateAttack' and f['status']==0:
            assert f['home_planet_id']==27 and f['current_coordinate_type']==1 and f['current_orbit']==5
            assert not chain and f['fuel_current']==0 and all(f['resource_'+r]==0 for r in ['metal','crystal','deuterium'])
            print(json.dumps({'verified':role,'fleet':f['id'],'returnedHome':27,'cargoAndFuelUnloaded':True,'xp':xp}),flush=True)
            continue
        route_cost=sum(float(json.loads(c['params']).get('fuelCost',0)) for c in chain if c['execute_time']>0)
        assert abs(f['fuel_current']+route_cost-f['fuel_total'])<.000001
        assert [f['resource_'+r] for r in ['metal','crystal','deuterium']]==[123,45,67]
        action=next(c for c in chain if c['command_type']==20)
        assert action['executed']==int(fixed) and 'battleWait' not in json.loads(action['params'])
        pending=[c for c in chain if not c['executed']]
        if not fixed:assert json.loads(action['params'])['pause']=='battle' and action['execute_time']==0
        elif role=='lateAttack':assert pending[0]['execute_time']>0 and 'pause' not in json.loads(pending[0]['params'])
        else:assert pending[0]['command_type']==10 and pending[0]['execute_time']==0 and json.loads(pending[0]['params'])['pause']=='battle'
        print(json.dumps({'verified':role,'fleet':f['id'],'attackExecuted':action['executed'],
            'nextCommand':pending[0]['id'],'nextType':pending[0]['command_type'],
            'pause':json.loads(pending[0]['params']).get('pause'),'fuel':f['fuel_current'],'xp':xp}),flush=True)
    print('OK: ordinary pending round2, finished round1; no losses, loot or XP for late fleets',flush=True)


def browser_verify(session,phase):
    from CredentialLoggingLiveTest import CredentialCheck
    check=CredentialCheck(session)
    check.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{
        'source':"window.__b031Errors=[];addEventListener('error',e=>__b031Errors.push(e.message));addEventListener('unhandledrejection',e=>__b031Errors.push(String(e.reason)));"}})
    check.call('/refresh',{})
    check.wait(lambda:check.js("return typeof Page!=='undefined'&&!!Page?.Name&&WS.ws.readyState===1"))
    if check.js("return Page.Name==='LoginPageClass'"):check.browser_login()
    check.wait(lambda:check.js('return !!Page.pageActive&&WS.requestCallbacks.size===0'))
    check.js("Page.setActiveMode('fleets')")
    check.wait(lambda:check.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
    fid=check.wait(lambda:check.js("return Object.values(Page.messageData.data.Page.FlyingFleets||{}).find(f=>f.name==='QA B031 after lateDefense'&&!f.battle)?.id"))
    f=check.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]]',fid)
    action=next(c for c in f['commands'] if c['type']==20)
    check.record('authoritative-attack-completed',action['status']=='completed')
    check.record('defender-awaits-next-move',f['control']['reason']=='battle' and next(c for c in f['commands'] if c['status']!='completed')['type']==10)
    check.wait(lambda:check.js('let e=document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[0]}"]`);return !!e?.getClientRects().length&&e.textContent.includes("Приостановлен")&&!!e.querySelector(".FleetFlightResumeButton")',fid))
    check.record('dom-card-and-resume-visible')
    check.record('raw-no-active-battle',f['battle'] is None)
    if phase=='resume':
        check.click(f'.FleetFlightCard[data-fleet-id="{fid}"] .FleetFlightResumeButton')
        check.wait(lambda:check.js('return !!document.querySelector(".FleetResumeSubmit")'))
        check.record('normal-resume-dialog',check.js('return document.querySelector("input[name=fleet-resume-mode][value=available]").checked'))
        check.click('.FleetResumeSubmit')
        check.wait(lambda:check.js('let f=Page.messageData.data.Page.FlyingFleets[arguments[0]];return f&&!f.control.paused&&f.command?.type===10&&WS.requestCallbacks.size===0',fid))
        check.record('native-resume-started-next-move')
    check.record('browser-runtime-errors-zero',check.js('return __b031Errors.length===0'))
    print(json.dumps({'browserPhase':phase,'fleet':fid,'passed':len(check.results)}),flush=True)


if __name__=='__main__':
    parser=argparse.ArgumentParser();group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--prepare',choices=['before2','after']);group.add_argument('--verify',choices=['before2','after'])
    group.add_argument('--browser',choices=['after','resume']);parser.add_argument('--session')
    args=parser.parse_args()
    if args.prepare:gate(prepare(args.prepare))
    elif args.verify:verify_saved(args.verify,args.verify=='after')
    else:
        assert args.session,'Selenium session is required'
        browser_verify(args.session,args.browser)
