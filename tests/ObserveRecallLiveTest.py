"""P001: существующий main + настоящий SPA, только новые QA P001.

Одноразовую сцену готовит migrations/20261101_observe_recall_qa.php при
остановленном main. before/after выполняются в одной собственной сессии;
reload/returned проверяют сохранение. Никакого второго игрового мира.
"""
import argparse
import json
import sys
import time
from FleetTargetNameLiveTest import NameCheck


class ObserveCheck(NameCheck):
    def ready(self,phase):
        if phase=='before':
            self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{
                'source':"window.__p001Errors=[];addEventListener('error',e=>__p001Errors.push(e.message));addEventListener('unhandledrejection',e=>__p001Errors.push(String(e.reason)));"}})
            self.call('/url',{'url':'https://sp-game.ru/'})
            self.browser_login()
        else:self.call('/refresh',{})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda:self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        rows=self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');
            echo json_encode(SPGame\Core\Database::getInstance()->fetchAll('SELECT id,name FROM fleets WHERE name LIKE ?',['QA P001 %']));""",{})
        self.ids={r['name'].split()[-1]:r['id'] for r in rows}
        assert sorted(self.ids)==['low','ordinary','sensor']
        self.js("window.__p001Wire=[];WS.ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.data?.Page?.FlyingFleets)__p001Wire.push(m.data.Page.FlyingFleets);});")
        self.record('qa-home-26',self.js('return Page.messageData.data.Page.planet_id===26'))

    def submit(self,action,data):
        self.js("""window.__p001Ack=null;GameEvents.emit('SendServer',{mode:'fleets',action:arguments[0],data:arguments[1],NotOverlay:true,
            ...Page.getRequestOptions({kind:'command'}),onSuccess:()=>__p001Ack={ok:true},onError:e=>__p001Ack={ok:false,code:e.code}});""",action,data)
        return self.wait(lambda:self.js('return __p001Ack'))

    def baseline(self):
        saved={role:self.flight(fid) for role,fid in self.ids.items()}
        self.js("sessionStorage.setItem('p001Before',JSON.stringify(arguments[0]))",saved)
        for role,f in saved.items():
            self.record('old-holding-'+role,f['command']['type']==43 and f['control']['holding'] and not f['is_return'])
            self.record('old-recall-hidden-'+role,not f['can_recall'] and self.js('return !document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[0]}"] .FleetFlightRecallButton`)?.getClientRects().length',self.ids[role]))
        self.record('sensor-really-enabled',saved['sensor']['sensors']['enabled'])
        for role in ['ordinary','sensor']:
            reply=self.submit('RecallFleet',{'fleetId':self.ids[role]})
            self.record('old-server-rejects-'+role,not reply['ok'] and reply['code']=='fleet_recall_unavailable')
        self.record('browser-errors-zero',self.js('return __p001Errors.length===0'))

    def recall(self):
        before={role:self.flight(fid) for role,fid in self.ids.items()}
        self.js("window.__p001Nodes={};for(const id of arguments[0])__p001Nodes[id]=document.querySelector(`.FleetFlightCard[data-fleet-id=\"${id}\"]`)",list(self.ids.values()))
        for role in ['ordinary','sensor']:
            fid=self.ids[role];old=before[role]
            self.record('recall-now-available-'+role,old['can_recall'])
            self.click(f'.FleetFlightCard[data-fleet-id="{fid}"] .FleetFlightRecallButton')
            f=self.wait(lambda:(lambda row:row if row and row['is_return'] and row['command']['type']==10 else None)(self.flight(fid)),seconds=25)
            active=next(c for c in f['commands'] if c['id']==f['command']['id'])
            expected=old['fuel']-old['control']['holdRate']*(f['start_time']-old['control']['fuelTime'])-active['fuel_cost']
            self.record('hold-and-flight-fuel-exact-'+role,abs(f['fuel']-expected)<.00001)
            self.record('stationary-origin-preserved-'+role,all(abs(f['segment_start'][k]-old['segment_start'][k])<.00001 for k in ['distance','angle']))
            self.record('home-and-no-pending-transition-'+role,f['segment_target']=={'type':1,'galaxy':1,'system':6,'orbit':2,'objectType':1} and not f['recall_pending'])
            self.record('same-card-node-'+role,self.js('return __p001Nodes[arguments[0]]===document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[0]}"]`)',fid))
            self.record('sensors-no-longer-active-'+role,not f['sensors']['available'] and not f['sensors']['enabled'])
            reply=self.submit('RecallFleet',{'fleetId':fid})
            self.record('repeat-recall-rejected-'+role,not reply['ok'] and reply['code']=='fleet_recall_unavailable')
            print(json.dumps({'role':role,'fleet':fid,'fuelBefore':old['fuel'],'holdRate':old['control']['holdRate'],
                'holdSeconds':f['start_time']-old['control']['fuelTime'],'flightCost':active['fuel_cost'],'fuelAfter':f['fuel'],
                'duration':f['end_time']-f['start_time']},ensure_ascii=False),flush=True)
        # Отрицательный реальный commit: топлива для возврата нет, удержание сохраняется.
        low=self.ids['low'];old=self.flight(low);count=self.js('return __p001Wire.length')
        reply=self.submit('RecallFleet',{'fleetId':low});self.record('low-fuel-request-accepted-before-execution',reply['ok'])
        self.wait(lambda:self.js('return __p001Wire.length>arguments[0]',count));time.sleep(2)
        current=self.flight(low)
        self.record('failed-recall-restores-full-hold',current['command']==old['command'] and current['control']['version']==old['control']['version']
            and current['fuel']==old['fuel'] and current['control']['fuelTime']==old['control']['fuelTime'] and current['control']['holding']
            and not current['is_return'] and not current['recall_pending'])
        self.record('low-cargo-unchanged',current['resources']==old['resources'])
        self.js("sessionStorage.setItem('p001Recalled',JSON.stringify(arguments[0]))",{role:self.flight(fid) for role,fid in self.ids.items()})
        # Возвращаем low существующим пользовательским действием с дозаправкой из трюма.
        reply=self.submit('ResumeFleet',{'fleetId':low,'fuelMode':'route'});self.record('low-resumes-with-existing-cargo-refuel',reply['ok'])
        self.wait(lambda:(lambda f:f and f['command']['type']==10)(self.flight(low)),seconds=25)
        self.record('browser-errors-zero',self.js('return __p001Errors.length===0'))

    def durable(self):
        saved=self.js("return JSON.parse(sessionStorage.getItem('p001Recalled'))")
        for role in ['ordinary','sensor']:
            f=self.flight(self.ids[role]);old=saved[role]
            if f is not None:
                self.record('return-survives-reload-'+role,f['is_return'] and f['command']==old['command'] and f['fuel']==old['fuel']
                    and abs(f['end_time']-old['end_time'])<.00001 and not f['sensors']['enabled'])
            else:self.record('already-idle-after-reload-'+role,self.js('return !!Page.messageData.data.Page.FleetList[arguments[0]]',self.ids[role]))
        self.record('browser-errors-zero',self.js('return __p001Errors.length===0'))

    def returned(self):
        self.wait(lambda:self.js('return arguments[0].every(id=>!!Page.messageData.data.Page.FleetList[id]&&!Page.messageData.data.Page.FlyingFleets[id])',list(self.ids.values())),seconds=180)
        def saved():
            rows=self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$d=SPGame\Core\Database::getInstance();
            $out=[];foreach(json_decode(stream_get_contents(STDIN),true) as $id){$f=$d->fetchOne('SELECT id,name,status,home_planet_id,current_coordinate_type,current_galaxy,current_system,current_orbit,is_return,fuel_current,resource_metal,resource_crystal,resource_deuterium FROM fleets WHERE id=?',[$id]);
                $f['pending']=$d->fetchOne('SELECT COUNT(*) n FROM fleet_commands WHERE fleet_id=? AND executed=0',[$id])['n'];$out[]=$f;}echo json_encode($out);""",list(self.ids.values()))
            return rows if all(f['status']==0 and f['pending']==0 for f in rows) else None
        rows=self.wait(saved,seconds=75)
        for f in rows:
            self.record('durable-idle-unloaded-'+str(f['id']),f['status']==0 and f['home_planet_id']==26 and f['current_coordinate_type']==1
                and [f['current_galaxy'],f['current_system'],f['current_orbit']]==[1,6,2] and f['pending']==0
                and all(f[k]==0 for k in ['fuel_current','resource_metal','resource_crystal','resource_deuterium']))
        self.record('browser-errors-zero',self.js('return __p001Errors.length===0'))

    def launch(self):
        # Реальный SendMission проверяет сохранение выбранной скорости в новой OBSERVE.
        fid=self.ids['sensor']
        self.wait(lambda:self.js('return !!Page.messageData.data.Page.FleetList[arguments[0]]',fid),seconds=90)
        point=self.js("return JSON.parse(sessionStorage.getItem('p001Before')).sensor.segment_start")
        self.open_mission(fid)
        self.js("Page.navigation.$type[0].value=2;Page.navigation.$distance.val(arguments[0].distance);Page.navigation.$deg.val(arguments[0].angle);Page.selectedMission=Page.getHoldMissionId();Page.missionSelector.value=Page.selectedMission;Page.updateNavigationCoordinateFields(2);Page.coordinatesChanged(arguments[1],null,true)",point,fid)
        self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js("$('#FleetSpeedRange').val(50).trigger('input').trigger('change')")
        self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js("$('#FleetFuelRange').val(500).trigger('input')")
        payload=self.send_form(fid)
        self.record('native-observe-sent-at-half-speed',payload['missionType']==30 and payload['speedFactor']==5 and payload['fuel']==500)
        def future_hold():
            return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$d=SPGame\Core\Database::getInstance();
                $id=json_decode(stream_get_contents(STDIN),true);$rows=$d->fetchAll('SELECT params FROM fleet_commands WHERE fleet_id=? AND command_type=43 AND executed=0',[$id]);echo json_encode($rows===[]?null:json_decode($rows[0]['params'],true));""",fid)
        hold=self.wait(future_hold,seconds=75)
        self.record('native-observe-persists-half-speed',hold['speedFactor']==5)
        f=self.flight(fid)
        self.record('native-outbound-move-active',f['command']['type']==10 and not f['is_return'])
        self.click(f'.FleetFlightCard[data-fleet-id="{fid}"] .FleetFlightRecallButton')
        self.wait(lambda:(lambda f:f and f['is_return'] and f['command']['type']==10)(self.flight(fid)),seconds=25)
        self.record('ordinary-move-recall-still-works')
        self.record('browser-errors-zero',self.js('return __p001Errors.length===0'))


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['before','after','reload','launch','returned'],required=True);a=p.parse_args();q=ObserveCheck(a.session)
    try:
        q.ready(a.phase)
        {'before':q.baseline,'after':q.recall,'reload':q.durable,'launch':q.launch,'returned':q.returned}[a.phase]()
        print(json.dumps({'phase':a.phase,'passed':len(q.results)}),flush=True)
    except Exception as e:
        print(json.dumps({'failedAt':q.phase,'errorClass':type(e).__name__}),flush=True);raise
