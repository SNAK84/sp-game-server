"""B018: основной dev и собственная Selenium-сессия, без второго мира.

Подготовка: migrations/20261101_planet_direction_qa.php при остановленном main.
home — после первого штатного входа QA; send — создаёт/отправляет реальный флот;
finish — после COLONIZE; verify — только чтение после restart.
Пароль отдельного QA наследуется из qaown_260924, хранится только в RAM.
"""
import argparse
import json
import sys
import time
import urllib.error
import websocket
from CredentialLoggingLiveTest import CredentialCheck


class DirectionCheck(CredentialCheck):
    def snapshot(self):
        return self.php("""
            require 'vendor/autoload.php'; SPGame\\Core\\Environment::init('.env');
            $d=SPGame\\Core\\Database::getInstance();
            $u=$d->fetchOne('SELECT u.id,u.main_planet,u.current_planet FROM users u JOIN accounts a ON a.id=u.account_id WHERE a.login=?',['qa_b018_home']);
            if ((int)$u['id']!==12) throw new LogicException('Другой QA B018.');
            $p=$d->fetchAll('SELECT p.id,p.name,p.name_generated,p.galaxy,p.system,p.planet,p.rotation,g.rotation system_rotation FROM planets p JOIN galaxy g ON p.galaxy=g.galaxy AND p.system=g.system WHERE p.owner_id=? ORDER BY p.id',[$u['id']]);
            $old=$d->fetchAll('SELECT id,name,name_generated,galaxy,system,planet,planet_type,distance,speed,rotation,size,fields,temp_min,temp_max FROM planets WHERE owner_id<>? ORDER BY id',[$u['id']]);
            echo json_encode(['user'=>$u,'planets'=>$p,'oldCount'=>count($old),'oldHash'=>hash('sha256',json_encode($old))]);
        """, {})

    def read_page(self, mode, data=None):
        self.js("GameEvents.emit('SendServer',{mode:arguments[0],data:arguments[1],NotOverlay:true,onSuccess:m=>ShowPage.Show(m.mode,m)})", mode, data or {})
        names={'galaxy':'GalaxyPageClass','fleets':'FleetsPageClass','overview':'OverViewPageClass'}
        self.wait(lambda:self.js('return Page?.Name===arguments[0]&&WS.requestCallbacks.size===0&&!$("#Content").is(":animated")',names[mode]))

    def home(self):
        saved=self.snapshot()
        self.record('home-created-by-main-not-sql',len(saved['planets'])==1 and saved['user']['main_planet']==saved['planets'][0]['id'])
        self.js("sessionStorage.setItem('b018OldHash',arguments[0])",saved['oldHash'])
        self.check_galaxy(saved['planets'])
        print(json.dumps(saved,ensure_ascii=False),flush=True)

    def check_galaxy(self,planets):
        coordinates={'galaxy':planets[0]['galaxy'],'system':planets[0]['system']}
        self.token=self.js('return Data.token')
        self.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        try:
            raw=self.request('galaxy',data=coordinates)['data']['Page']
            for p in planets:
                row=next(row for row in raw['Planets'] if row['id']==p['id'])
                self.record('raw-ws-direction-'+str(p['id']),row['rotation']==p['system_rotation'])
        finally:self.ws.close();self.ws=None
        # Обычное чтение страницы возвращает account.frame браузеру после raw WS.
        self.read_page('galaxy',coordinates)
        for p in planets:
            self.record('mysql-direction-'+str(p['id']),p['rotation']==p['system_rotation'])
            self.record('canvas-input-direction-'+str(p['id']),self.js('let p=Page.Planets.find(p=>p.id===arguments[0]);return p?.rotation===arguments[1]&&p.rotation===Page.System.rotation&&!!Page.canvas.getClientRects().length',p['id'],p['rotation']))
        self.js('window.__b018planet=Page.Planets.find(p=>p.id===arguments[0]);window.__b018canvas=Page.canvas',planets[0]['id'])
        angle="let p=Page.mapObjects.find(p=>p.kind==='planet'&&p.id===arguments[0]);return Math.atan2(p.y-Page.canvas.height/2-Page.offset.y,p.x-Page.canvas.width/2-Page.offset.x)*180/Math.PI"
        before=self.js(angle,planets[0]['id'])
        time.sleep(3)
        after=self.js(angle,planets[0]['id']);delta=(after-before+180)%360-180
        self.record('canvas-moves-in-system-direction',delta>0 if planets[0]['rotation']==1 else delta<0)
        self.record('galaxy-nodes-not-recreated',self.js('return __b018canvas===Page.canvas&&Page.Planets.includes(__b018planet)'))
        self.record('browser-runtime-errors-zero',self.js('return __b018errors.length===0'))

    def send(self):
        saved=self.snapshot();self.record('one-home-before-send',len(saved['planets'])==1)
        self.read_page('fleets')
        fleet_id=int(self.js("return sessionStorage.getItem('b018FleetId')||0"))
        if fleet_id and self.js('return Object.values(Page.messageData.data.Page.FlyingFleets||{}).some(f=>Number(f.id)===arguments[0])',fleet_id):
            self.record('real-flight-displayed',self.js('return !!document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[0]}"]`)',fleet_id))
            return
        if not fleet_id:
            self.wait(lambda:self.js('return !!Page.$Ships?.[208]?.[0]?.Input&&!!Page.$Ships?.[202]?.[0]?.Input'))
            self.js("Page.$Ships[208][0].Input.val(1).trigger('input');Page.$Ships[202][0].Input.val(1).trigger('input');Page.renderNewFleetBlock()")
            ids=self.js('return Object.keys(Page.messageData.data.Page.FleetList)')
            self.click('.NewFleetButtons .btn-success')
            fleet_id=int(self.wait(lambda:self.js('return Object.keys(Page.messageData.data.Page.FleetList).find(id=>!arguments[0].includes(id))',ids)))
        self.js("sessionStorage.setItem('b018FleetId',arguments[0]);Page.showTaskFleet(arguments[0])",fleet_id)
        self.wait(lambda:self.js("return Page.navigation?.$orbit&&Page.missionDataLoading===false&&WS.requestCallbacks.size===0"))
        self.js("Page.navigation.$orbit.val(1);Page.selectedMission=Page.getColonizeMissionId();Page.missionSelector.value=Page.selectedMission;Page.coordinatesChanged(arguments[0],null,true)",fleet_id)
        self.wait(lambda:self.js('return Page.missionDataLoading===false&&WS.requestCallbacks.size===0&&typeof Page.flightControls?.getPayload==="function"'))
        payload=self.js('return Page.flightControls.getPayload()')
        self.record('ordinary-colonize-payload',payload['missionType']==15 and payload['coordinates']['orbit']==1)
        self.record('client-does-not-send-last-known-position','lastKnownPosition' not in json.dumps(payload))
        self.wait(lambda:self.js("return !document.querySelector('.FleetMissionSendButton').disabled"))
        self.click('.FleetMissionSendButton')
        self.wait(lambda:self.js('return Object.values(Page.messageData.data.Page.FlyingFleets||{}).some(f=>Number(f.id)===arguments[0])',fleet_id))
        self.record('real-flight-displayed',self.js('return !!document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[0]}"]`)',fleet_id))
        print(json.dumps({'fleetId':fleet_id,'mission':15,'target':payload['coordinates']}),flush=True)

    def finish(self):
        self.phase='wait-real-colonization'
        saved=self.snapshot()
        assert len(saved['planets'])==2,'Колонизация пока не завершилась.'
        self.record('real-colony-created',saved['planets'][1]['planet']==1 and saved['planets'][1]['name_generated']==1)
        self.check_galaxy(saved['planets'])
        self.record('preexisting-planets-unchanged',saved['oldHash']==self.js("return sessionStorage.getItem('b018OldHash')"))
        print(json.dumps(saved,ensure_ascii=False),flush=True)

    def verify(self):
        self.call('/refresh',{})
        # Гонку B043 во время начального zoom проверяет GalaxyZoomLiveTest.
        # Здесь ждём стабильного вида для проверки направления, не меняя анимации.
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.Name!=='LoginPageClass'&&Page.$Content.Show&&WS.ws.readyState===1&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')&&(!('zoomAnimationFrameId' in Page)||Page.zoomAnimationFrameId===null)"))
        self.finish()
        self.record('home-and-colony-survive-restart',len(self.snapshot()['planets'])==2)

    def returned(self):
        state=self.php("""
            require 'vendor/autoload.php'; SPGame\\Core\\Environment::init('.env');
            $d=SPGame\\Core\\Database::getInstance();$id=(int)json_decode(stream_get_contents(STDIN),true)['id'];
            $f=$d->fetchOne('SELECT id,owner_id,status,home_planet_id,fuel_current,resource_metal,resource_crystal,resource_deuterium FROM fleets WHERE id=?',[$id]);
            $s=$d->fetchOne('SELECT small_ship_cargo_count,colonizer_count FROM fleets_ships WHERE id=?',[$id]);
            $c=$d->fetchOne('SELECT COUNT(*) n FROM fleet_commands WHERE fleet_id=? AND executed=0',[$id]);
            echo json_encode(['fleet'=>$f,'ships'=>$s,'pending'=>$c['n']]);
        """, {'id':int(self.js("return sessionStorage.getItem('b018FleetId')"))})
        f=state['fleet'];self.record('returned-to-own-home',f['owner_id']==12 and f['home_planet_id']==35 and f['status']==0 and state['pending']==0)
        self.record('colonizer-consumed-escort-preserved',state['ships']=={'small_ship_cargo_count':1,'colonizer_count':0})
        self.record('return-unloaded-cargo-and-fuel',all(f[k]==0 for k in ['fuel_current','resource_metal','resource_crystal','resource_deuterium']))
        self.read_page('fleets')
        self.record('returned-fleet-visible-in-spa',self.js('return !!Page.$Fleets?.[arguments[0]]?.is(":visible")',f['id']))


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['home','send','finish','verify','returned'],required=True)
    a=p.parse_args();c=DirectionCheck(a.session)
    try:
        getattr(c,a.phase)()
        print(json.dumps({'phase':a.phase,'passed':len(c.results)}),flush=True)
    except Exception as e:
        error={'failedAt':c.phase,'errorClass':type(e).__name__}
        if isinstance(e,urllib.error.HTTPError):
            detail=json.load(e).get('value',{})
            error['webdriverError']=detail.get('error')
            message=detail.get('message','').split('\n')[0][:180]
            if c.clean(message):error['message']=message
        print(json.dumps(error),flush=True);sys.exit(1)
