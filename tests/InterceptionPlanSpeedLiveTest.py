"""B034: основной main, настоящий SPA, реальный сенсор и редактор.

before: отправка50% старым pipeline, фиксирует ошибку100% редактора.
after: та же активная MOVE после правки; штатное сохранение/повторное открытие.
new: новый pipeline явно сохраняет скорость ATTACK; priority проверяет явные70%.
cleanup возвращает четыре новых QA, final проверяет хвост после restart.
Старые111/112 всегда только читаются.
"""
import argparse
import json
import subprocess
import sys
import time
import websocket
from FleetTargetNameLiveTest import NameCheck
from CredentialLoggingLiveTest import CredentialCheck


class SpeedCheck(NameCheck):
    before_id=14472
    after_id=14473
    target=14475

    def saved(self,fid):
        return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$d=SPGame\Core\Database::getInstance();
            $p=json_decode(stream_get_contents(STDIN),true);$f=$d->fetchOne('SELECT * FROM fleets WHERE id=?',[$p['id']]);
            $c=$d->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=? ORDER BY id',[$p['id']]);
            echo json_encode(['fleet'=>$f,'commands'=>$c]);""",{'id':fid})

    def legacy(self):
        q=CredentialCheck(self.base.split('/')[-1])
        q.login,q.password=json.loads(subprocess.check_output(['docker','exec','sp_game_qa_foreign_bot','python3','-c',
            "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"],text=True))
        assert q.login=='qafor_260924';q.values.append(q.password)
        q.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        try:
            q.raw_login();page=q.request('fleets')['data']['Page']
            return {str(fid):page['FlyingFleets'][str(fid)]['control']['steps'] for fid in [111,112]}
        finally:q.ws.close()

    def ready(self,phase):
        if phase=='before':
            self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{'source':
                "window.__b034Errors=[];addEventListener('error',e=>__b034Errors.push(e.message));addEventListener('unhandledrejection',e=>__b034Errors.push(String(e.reason)));"}})
            self.call('/url',{'url':'https://sp-game.ru/'})
        else:self.call('/refresh',{})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&!!Page?.Name&&WS.ws.readyState===1"))
        if self.js("return Page.Name==='LoginPageClass'"):self.browser_login()
        self.wait(lambda:self.js('return !!Page.pageActive&&WS.requestCallbacks.size===0'))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda:self.js("return Page.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))

    def launch(self,fid):
        self.open_mission(fid)
        self.wait(lambda:self.js('return Page.getFleetTargets(arguments[0]).some(f=>Number(f.id)===arguments[1]&&f.interceptable)',fid,self.target))
        self.js('Page.navigation.$type[0].value=3;Page.updateNavigationCoordinateFields(3);Page.navigation.$fleetTarget.val(String(arguments[0])).trigger("change")',self.target)
        self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js('$("#FleetSpeedRange").val(50).trigger("input").trigger("change")')
        payload=self.send_form(fid)
        self.record('native-interception-at50-'+str(fid),payload['missionType']==1 and payload['speedFactor']==5 and payload['coordinates']=={'type':3,'fleetId':self.target})
        return self.flight(fid)

    def open_plan(self,fid):
        self.js('Page.showFleetPlan(arguments[0])',fid)
        self.wait(lambda:self.js('return !!document.querySelector(".FleetPlanEditor")&&WS.requestCallbacks.size===0&&!$(Dialog.dialogDiv).is(":animated")'))
        return self.js('return [...document.querySelectorAll(".FleetPlanStep input[type=range]")].filter(e=>e.getAttribute("aria-label")==="Скорость полёта").map(e=>Number(e.value))')

    def close_plan(self):
        self.js('Dialog.close()');self.wait(lambda:self.js('return getComputedStyle(Dialog.dialogDiv).display==="none"'))

    def before(self):
        old=self.legacy();self.js('sessionStorage.setItem("b034LegacyBefore",JSON.stringify(arguments[0]))',old)
        self.record('legacy111-112-show100-before',all(steps[0]['speedFactor']==10 for steps in old.values()))
        f=self.flight(self.before_id) or self.launch(self.before_id)
        self.record('live-active50-but-action100',f['control']['steps'][0]['speedFactor']==10)
        self.record('native-editor-before100',self.open_plan(self.before_id)==[100,100])
        self.close_plan()
        # saveAll сохраняет обычную отправку раз в30с; не запускаем тестовый saver.
        saved=self.wait(lambda:(lambda s:s if s['fleet']['status']==1 and any(c['command_type']==10 and c['execute_time']>0 for c in s['commands']) else None)(self.saved(self.before_id)),seconds=45)
        move=next(c for c in saved['commands'] if c['command_type']==10 and not c['executed'])
        attack=next(c for c in saved['commands'] if c['command_type']==20)
        self.record('saved50-without-attack-speed',json.loads(move['params'])['speedFactor']==5 and 'speedFactor' not in json.loads(attack['params']))
        self.js('sessionStorage.setItem("b034Before",JSON.stringify(arguments[0]))',saved)
        print(json.dumps({'beforeLegacy':old,'source':self.before_id,'move':move['id'],'arrival':move['execute_time']}),flush=True)

    def after(self):
        old=self.legacy()
        self.record('legacy-active-and-arrived-inherit50',all(steps[0]['speedFactor']==5 and steps[-1]['speedFactor']==5 for steps in old.values()))
        initial=self.js('return JSON.parse(sessionStorage.getItem("b034Before"))')
        same=self.saved(self.before_id)
        self.record('read-only-projection-kept-all-command-rows',same['commands']==initial['commands'])
        self.record('existing-flight-fuel-and-start-unchanged',all(same['fleet'][k]==initial['fleet'][k] for k in ['current_position_time','fuel_current','fuel_total']))
        self.record('native-editor-after50',self.open_plan(self.before_id)==[50,50])
        version=self.flight(self.before_id)['control']['version']
        self.js('''window.__b034Plan=null;const send=WS.ws.send.bind(WS.ws);WS.ws.send=function(raw){const m=JSON.parse(raw);if(m.action==='SetFleetCommands')__b034Plan={fleetId:m.data.fleetId,steps:m.data.plan.steps};return send(raw);};''')
        self.wait(lambda:self.js('return !document.querySelector(".FleetPlanSubmit").disabled&&WS.requestCallbacks.size===0'))
        self.click('.FleetPlanSubmit')
        self.wait(lambda:self.js('return getComputedStyle(Dialog.dialogDiv).display==="none"&&WS.requestCallbacks.size===0'))
        self.wait(lambda:self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]].control.version!==arguments[1]',self.before_id,version))
        self.record('native-save-preserved50-payload',self.js('return __b034Plan.fleetId===arguments[0]&&__b034Plan.steps.length===2&&__b034Plan.steps.every(s=>s.speedFactor===5)',self.before_id))
        self.record('reopened-editor-stays50',self.open_plan(self.before_id)==[50,50]);self.close_plan()
        def stored():
            s=self.saved(self.before_id)
            return s if any(c['command_type']==20 and not c['executed'] and json.loads(c['params']).get('planStep') for c in s['commands']) else None
        saved=self.wait(stored,seconds=45)
        active=next(c for c in initial['commands'] if c['command_type']==10 and not c['executed'])
        self.record('active-move-byte-identical-after-save',next(c for c in saved['commands'] if c['id']==active['id'])==active)
        self.record('no-flight-fuel-recharged',all(saved['fleet'][k]==initial['fleet'][k] for k in ['current_position_time','fuel_current','fuel_total']))
        future=[c for c in saved['commands'] if not c['executed'] and c['id']!=active['id']]
        self.record('future-pipeline-keeps50',all(json.loads(c['params'])['speedFactor']==5 for c in future))
        print(json.dumps({'legacyAfter':old,'activeMove':active['id'],'arrivalUnchanged':active['execute_time'],'futureIds':[c['id'] for c in future]}),flush=True)

    def new(self):
        self.launch(self.after_id)
        self.record('new-native-editor50',self.open_plan(self.after_id)==[50,50]);self.close_plan()
        def stored():
            s=self.saved(self.after_id)
            return s if s['fleet']['status']==1 and any(c['command_type']==20 for c in s['commands']) else None
        saved=self.wait(stored,seconds=45)
        action=next(c for c in saved['commands'] if c['command_type']==20)
        self.record('new-attack-explicit50',json.loads(action['params'])['speedFactor']==5)
        print(json.dumps({'newFleet':self.after_id,'attack':action['id'],'speedFactor':json.loads(action['params'])['speedFactor']}),flush=True)

    def cleanup(self):
        for fid in [self.before_id,self.after_id]:
            self.command('RecallFleet',{'fleetId':fid})
            self.wait(lambda:self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]]?.is_return===true',fid))
            self.record('only-new-interceptor-recalled-'+str(fid))
        sensor=14474
        assert self.flight(sensor)['name']=='QA B034 sensor'
        self.command('SetFleetSensors',{'fleetId':sensor,'enabled':False})
        self.wait(lambda:self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]]?.sensors.enabled===false',sensor))
        self.command('SetFleetCommands',{'fleetId':sensor,'plan':{'version':self.flight(sensor)['control']['version'],
            'steps':[{'kind':'return','speedFactor':5}]}})
        self.wait(lambda:self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]].control.steps.some(s=>s.kind==="return")',sensor))
        self.command('ResumeFleet',{'fleetId':sensor,'fuelMode':'available'})
        self.wait(lambda:self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]]?.is_return===true',sensor))
        self.record('new-sensor-disabled-and-returning')
        q=CredentialCheck(self.base.split('/')[-1])
        q.login,q.password=json.loads(subprocess.check_output(['docker','exec','sp_game_qa_foreign_bot','python3','-c',
            "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"],text=True))
        assert q.login=='qafor_260924';q.values.append(q.password)
        q.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        try:
            q.raw_login();f=q.request('fleets')['data']['Page']['FlyingFleets'][str(self.target)]
            assert f['name']=='QA B034 target'
            reply=q.request('fleets','SetFleetCommands',{'fleetId':self.target,'plan':{'version':f['control']['version'],
                'steps':[{'kind':'return','speedFactor':5}]}});assert reply['status']=='ok'
            until=time.monotonic()+15
            while time.monotonic()<until:
                f=q.request('fleets')['data']['Page']['FlyingFleets'][str(self.target)]
                if any(s['kind']=='return' for s in f['control']['steps']):break
                time.sleep(.2)
            else:raise AssertionError('Target plan not applied')
            reply=q.request('fleets','ResumeFleet',{'fleetId':self.target,'fuelMode':'available'});assert reply['status']=='ok'
            until=time.monotonic()+15
            while time.monotonic()<until:
                f=q.request('fleets')['data']['Page']['FlyingFleets'][str(self.target)]
                if f['is_return']:break
                time.sleep(.2)
            else:raise AssertionError('Target return not activated')
            self.record('new-foreign-target-returning')
        finally:q.ws.close()

    def priority(self):
        if self.flight(self.after_id)['control']['steps'][0]['speedFactor']==5:
            self.record('new-editor-starts50',self.open_plan(self.after_id)==[50,50])
            self.js('$(".FleetPlanStep").first().find("input[type=range]").filter((_,e)=>e.getAttribute("aria-label")==="Скорость полёта").val(70).trigger("input").trigger("change")')
            self.wait(lambda:self.js('return !document.querySelector(".FleetPlanSubmit").disabled&&WS.requestCallbacks.size===0'))
            self.click('.FleetPlanSubmit')
            self.wait(lambda:self.js('return getComputedStyle(Dialog.dialogDiv).display==="none"&&WS.requestCallbacks.size===0'))
        # ACK подтверждает приём, а не применение PlayerQueue. Ждём новый Page.
        self.wait(lambda:self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]].control.steps[0].speedFactor===7',self.after_id))
        self.record('explicit-future-speed-overrides-inheritance',self.open_plan(self.after_id)==[70,50]);self.close_plan()
        self.record('explicit-values-in-authoritative-response',self.js('let f=Page.messageData.data.Page.FlyingFleets[arguments[0]];return f.control.steps[0].speedFactor===7&&f.control.steps[1].speedFactor===5',self.after_id))

    def final(self):
        old=self.legacy()
        self.record('legacy-speeds-survive-restart',all(s[0]['speedFactor']==5 and s[-1]['speedFactor']==5 for s in old.values()))
        for fid in [self.before_id,self.after_id,14474]:
            f=self.flight(fid)
            if f:
                self.record('cancelled-stages-not-restored-'+str(fid),f['is_return'] and all(s['kind']=='return' and s['speedFactor']==5 for s in f['control']['steps']))
            else:
                self.record('new-qa-already-home-'+str(fid),self.js('return Page.messageData.data.Page.FleetList[arguments[0]]?.status===0',fid))


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['before','after','new','priority','cleanup','final'],required=True);a=p.parse_args();c=SpeedCheck(a.session)
    try:
        c.ready(a.phase);getattr(c,a.phase)();c.record('browser-runtime-errors-zero',c.js('return __b034Errors.length===0'))
        print(json.dumps({'phase':a.phase,'passed':len(c.results)}),flush=True)
    except Exception as e:
        print(json.dumps({'failedAt':c.phase,'errorClass':type(e).__name__}),flush=True);sys.exit(1)
