"""B030: основной dev/настоящий SPA; новые QA B030, без второго мира.

before — подтверждение отказа старой фазы; send — обычная кнопка отправки;
verify — только чтение результата и сохранности пауз/маршрутов после restart.
"""
import argparse
import json
import subprocess
import sys
from FleetTargetNameLiveTest import NameCheck
from CredentialLoggingLiveTest import CredentialCheck


class PausedCheck(NameCheck):
    attacker=8331

    def __init__(self,session):
        super().__init__(session)
        self.login,self.password=json.loads(subprocess.check_output(['docker','exec','sp_game_qa_foreign_bot','python3','-c',
            "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"],text=True))
        assert self.login=='qafor_260924'
        self.values.append(self.password)

    def ready(self,phase):
        self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{
            'source':"window.__b030Errors=[];addEventListener('error',e=>__b030Errors.push(e.message));addEventListener('unhandledrejection',e=>__b030Errors.push(String(e.reason)));"}})
        if phase=='before':
            self.call('/url',{'url':'https://sp-game.ru/'})
            self.wait(lambda:self.js("return typeof Page!=='undefined'&&!!Page?.Name&&WS.ws.readyState===1"))
            if self.js("return Page.Name==='LoginPageClass'"):self.browser_login()
        else:self.call('/refresh',{})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda:self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.record('foreign-qa-base',self.js('return Page.messageData.data.Page.planet_id===27'))

    def snapshot(self):
        return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$d=SPGame\Core\Database::getInstance();
            $fleets=$d->fetchAll('SELECT * FROM fleets WHERE id IN (86,8328,8329,8330,8331) ORDER BY id');
            $commands=$d->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id IN (86,8328,8329,8330,8331) ORDER BY fleet_id,id');
            $ships=$d->fetchAll('SELECT * FROM fleets_ships WHERE id IN (86,8328,8329,8330,8331) ORDER BY id');
            $battles=$d->fetchAll('SELECT id,phase,state FROM space_battles ORDER BY id DESC LIMIT 3');
            foreach($battles as &$b)$b['state']=json_decode($b['state'],true,512,JSON_THROW_ON_ERROR);unset($b);
            $reports=$d->fetchAll('SELECT id,ready,payload FROM battle_reports WHERE payload LIKE ? ORDER BY created_at DESC LIMIT 3',['%QA B030%']);
            foreach($reports as &$r)$r['payload']=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);unset($r);
            echo json_encode(['fleets'=>$fleets,'commands'=>$commands,'ships'=>$ships,'battles'=>$battles,
                'reports'=>$reports,'fuelNotices'=>(int)$d->fetchOne('SELECT COUNT(*) n FROM messages WHERE sample=? AND data LIKE ?',
                    ['FleetFuelStopped','%QA B030%'])['n'],'fuelNoticeTimes'=>array_column($d->fetchAll('SELECT time FROM messages WHERE sample=? AND data LIKE ?',
                    ['FleetFuelStopped','%QA B030%']),'time'),'base'=>$d->fetchOne('SELECT * FROM resource_bases WHERE id=1')]);""",{})

    def preview(self):
        self.js("""window.__b030Preview=null;GameEvents.emit('SendServer',{mode:'fleets',action:'GetAvailableMissions',NotOverlay:true,
            data:{fleetId:8331,coordinates:{type:1,galaxy:1,system:10,orbit:3,objectType:5,siteId:1},missionType:45,speedFactor:10},
            onSuccess:m=>__b030Preview={page:m.getData('Page')},onError:e=>__b030Preview={error:e.message,code:e.code}});""")
        return self.wait(lambda:self.js('return __b030Preview'))

    def before(self):
        saved=self.snapshot();self.js("sessionStorage.setItem('b030Before',JSON.stringify(arguments[0]))",saved)
        response=self.preview();available=response.get('page',{}).get('AvailableMissions',{})
        self.record('old-code-rejects-paused-move',45 not in available.get('missions',[]) and 5 in available.get('missions',[]))
        self.record('no-attack-created',all(c['fleet_id']!=self.attacker for c in self.snapshot()['commands']))
        print(json.dumps({'availableBefore':available},ensure_ascii=False),flush=True)

    def send(self):
        response=self.preview()
        self.record('new-code-allows-capture',45 in response.get('page',{}).get('AvailableMissions',{}).get('missions',[]))
        self.open_mission(self.attacker)
        self.js("Page.navigation.$type[0].value=1;Page.navigation.$objectType[0].value=5;Page.updateNavigationCoordinateFields(1);Page.updateResourceSiteTarget(1);Page.navigation.$site.val('1').trigger('change')")
        self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js('Page.selectedMission=45;Page.missionSelector.value=45;Page.coordinatesChanged(8331,null,true)')
        self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js("window.__b030wire=[];WS.ws.addEventListener('message',e=>{let p=JSON.parse(e.data).data?.Page,f=p?.FlyingFleets?.[8331];if(f){__b030wire.push({status:f.status,battle:f.battle,commands:f.commands});if(__b030wire.length>100)__b030wire.shift();}})")
        payload=self.send_form(self.attacker)
        self.record('native-capture-sent',payload['missionType']==45 and payload['coordinates']['siteId']==1)
        print(json.dumps({'sent':{k:payload.get(k) for k in ['coordinates','missionType','fuel','speedFactor']}},ensure_ascii=False),flush=True)

    def verify(self):
        before=self.js("return JSON.parse(sessionStorage.getItem('b030Before'))")
        def settled():
            saved=self.snapshot()
            return saved if saved['reports'] and saved['reports'][0]['ready']==1 and all(
                f['current_coordinate_type']==1 for f in saved['fleets'] if f['id'] in (8329,8330)) else None
        after=self.wait(settled,seconds=45)
        self.record('one-ready-combined-report',len(after['reports'])==1 and after['reports'][0]['ready']==1)
        report=after['reports'][0]['payload']
        participants=sorted(p['fleetId'] for p in report['participants'].values())
        self.record('all-three-defenders-in-one-battle',participants==[8328,8329,8330,8331])
        self.record('neighbor86-not-in-battle',86 not in participants)
        def fleet(rows,i):return next(f for f in rows if f['id']==i)
        for fid,reason in [(8329,'fuel'),(8330,'manual')]:
            original=[c for c in before['commands'] if c['fleet_id']==fid]
            current=[c for c in after['commands'] if c['fleet_id']==fid]
            normalized=lambda rows:[{**c,'params':json.loads(c['params'])} for c in rows]
            self.record('route-identical-'+str(fid),normalized(current)==normalized(original))
            f=fleet(after['fleets'],fid);old=fleet(before['fleets'],fid)
            self.record('object-position-restored-'+str(fid),f['current_coordinate_type']==1 and f['current_site_id']==1)
            self.record('cargo-and-fuel-preserved-'+str(fid),all(f[k]==old[k] for k in ['fuel_current','resource_metal','resource_crystal','resource_deuterium','home_planet_id','home_site_id']) and f['base_fuel_time']==0)
            self.record('ships-preserved-'+str(fid),fleet(after['ships'],fid)==fleet(before['ships'],fid))
        self.record('neighbor86-untouched',fleet(after['fleets'],86)==fleet(before['fleets'],86) and [c for c in after['commands'] if c['fleet_id']==86]==[c for c in before['commands'] if c['fleet_id']==86])
        self.record('control-remains-with-defender',after['base']['owner_id']==9)
        self.record('no-repeat-fuel-notice',all(t<report['startedAt'] for t in after['fuelNoticeTimes']))
        self.record('browser-runtime-errors-zero',self.js('return __b030Errors.length===0'))
        print(json.dumps({'reportId':after['reports'][0]['id'],'participants':participants,'reportOutcome':report.get('outcome')}),flush=True)
        print(json.dumps({'battles':[{'key':b['id'],'phase':b['phase'],'uuid':b['state']['id'],
            'participants':list(b['state'].get('mission',{}).get('participants',{})),
            'outcome':b['state'].get('resolution',{}).get('outcome')} for b in after['battles']]},ensure_ascii=False),flush=True)

    def defender(self):
        self.js("Page.setActiveMode('logout')")
        self.wait(lambda:self.js("return Page?.Name==='LoginPageClass'&&WS.requestCallbacks.size===0"))
        own=CredentialCheck(self.base.split('/')[-1]);own.browser_login()
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda:self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        for fid,reason in [(8329,'fuel'),(8330,'manual')]:
            self.record('defender-authoritative-reason-'+str(fid),self.js('let f=Page.messageData.data.Page.FlyingFleets[arguments[0]];return f.control.reason===arguments[1]&&f.segment_start.siteId===1&&f.battle===null',fid,reason))
            self.record('defender-dom-paused-card-'+str(fid),self.js('let e=document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[0]}"]`);return !!e?.getClientRects().length&&e.textContent.includes("Приостановлен")',fid))
        self.record('browser-runtime-errors-zero',self.js('return __b030Errors.length===0'))


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['before','send','verify','defender'],required=True);a=p.parse_args();c=PausedCheck(a.session)
    try:
        if a.phase!='defender':c.ready(a.phase)
        getattr(c,a.phase)();print(json.dumps({'phase':a.phase,'passed':len(c.results)}),flush=True)
    except Exception as e:
        print(json.dumps({'failedAt':c.phase,'errorClass':type(e).__name__}),flush=True);sys.exit(1)
