"""B029: обычные действия настоящего SPA на основном dev.

bootstrap сохраняет в sessionStorage верхний ID команд старого main перед
внедрением. cycle создаёт/расформировывает только свой временный QA-флот;
verify проверяет после рестарта. Подготовка одного малого транспорта — отдельно.
Credentials/токены не выводятся и в sessionStorage теста не записываются.
"""
import argparse
import json
import sys
import websocket
from CredentialLoggingLiveTest import CredentialCheck
from FleetTargetNameLiveTest import NameCheck


class RangeCheck(NameCheck):
    def ready(self):
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda: self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.ws.readyState===1&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.record('main-qa-planet', self.js('return Page.messageData.data.Page.planet_id===26'))
        self.js("""window.__b029wire=[];WS.ws.addEventListener('message',e=>{
            const p=JSON.parse(e.data).data?.Page;if(p?.FleetList)__b029wire.push(Object.keys(p.FleetList));
            if(__b029wire.length>40)__b029wire.shift();});""")

    def sql(self):
        return self.php(r"""require 'vendor/autoload.php'; SPGame\Core\Environment::init('.env');
            $d=SPGame\Core\Database::getInstance();
            echo json_encode(['ranges'=>$d->fetchAll('SELECT repository,high_water FROM repository_id_ranges ORDER BY repository'),
                'fleets'=>$d->fetchAll('SELECT id FROM fleets ORDER BY id'),
                'commands'=>$d->fetchAll('SELECT id,fleet_id FROM fleet_commands ORDER BY id'),
                'ships'=>(int)$d->fetchOne('SELECT small_ship_cargo FROM ships WHERE id=26')['small_ship_cargo']]);""", {})

    def bootstrap(self):
        self.record('old-main-idle-fixture', self.js('return Page.messageData.data.Page.FleetList[132]?.status===0'))
        self.js("sessionStorage.setItem('b029OriginalFleets',JSON.stringify(Object.keys(Page.messageData.data.Page.FleetList)));window.__b029errors=[];addEventListener('error',e=>__b029errors.push(e.message))")
        self.open_mission(132)
        self.js("Page.navigation.$type[0].value=2;Page.navigation.$distance.val(3000);Page.navigation.$deg.val(0);Page.selectedMission=Page.getHoldMissionId();Page.missionSelector.value=Page.selectedMission;Page.updateNavigationCoordinateFields(2);Page.coordinatesChanged(132,null,true)")
        self.wait(lambda: self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js("$('#FleetSpeedRange').val(10).trigger('input').trigger('change')")
        self.wait(lambda: self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js("$('#FleetFuelRange').val(1000).trigger('input')")
        self.send_form(132)
        ids = self.js('return Page.messageData.data.Page.FlyingFleets[132].commands.map(c=>Number(c.id))')
        self.record('old-main-issued-command-floor', len(ids)>2 and max(ids)>406)
        self.js("sessionStorage.setItem('b029OldCommandFloor',arguments[0])", max(ids))
        self.command('RecallFleet', {'fleetId':132})
        ids = self.js('return Page.messageData.data.Page.FlyingFleets[132].commands.map(c=>Number(c.id))')
        floor = max(max(ids), int(self.js("return sessionStorage.getItem('b029OldCommandFloor')")))
        self.js("sessionStorage.setItem('b029OldCommandFloor',arguments[0])", floor)
        print(json.dumps({'oldCommandFloor':floor,'bootstrapFleet':132}), flush=True)

    def verify(self):
        saved = self.sql()
        original=self.js("return JSON.parse(sessionStorage.getItem('b029OriginalAllFleets')||'null')")
        if original is None:
            original=[f['id'] for f in saved['fleets']]
            self.js("sessionStorage.setItem('b029OriginalAllFleets',JSON.stringify(arguments[0]))",original)
        self.record('original-fleet-ids-preserved',all(i in [f['id'] for f in saved['fleets']] for i in original))
        history = self.js("return JSON.parse(sessionStorage.getItem('b029Cycles')||'[]')")
        for cycle in history:
            self.record('deleted-id-not-resurrected-'+str(cycle['fleet']),
                all(f['id']!=cycle['fleet'] for f in saved['fleets'])
                and self.js('return !Page.$Fleets[arguments[0]]&&!Page.messageData.data.Page.FlyingFleets[arguments[0]]',cycle['fleet']))
        self.record('qa-test-ship-preserved', saved['ships']==1 and self.js('return Page.messageData.data.Page.PlanetShips[202].count===1'))
        return saved, history

    def cycle(self, mission=False):
        saved, history = self.verify()
        self.record('free-qa-base-slot',self.js('let b=Page.messageData.data.Page.FleetLimits.base;return b.used<b.max'))
        old_ids = self.js('return Object.keys(Page.messageData.data.Page.FleetList)')
        self.js("Page.$Ships[202][0].Input.val(1).trigger('input');Page.renderNewFleetBlock()")
        self.click('.NewFleetButtons .btn-success')
        fleet_id = int(self.wait(lambda: self.js('return Object.keys(Page.messageData.data.Page.FleetList).find(id=>!arguments[0].includes(id))',old_ids)))
        ranges = {r['repository']:r['high_water'] for r in saved['ranges']}
        self.record('new-id-above-pre-restart-reserve', fleet_id>ranges['fleets'])
        self.record('new-id-never-reused', all(fleet_id!=c['fleet'] for c in history))
        self.record('native-card-has-one-ship', self.js('return !!Page.$Fleets[arguments[0]]&&Page.messageData.data.Page.FleetList[arguments[0]].ships[202].count===1',fleet_id))
        self.record('raw-ws-contains-new-id',self.js('return __b029wire.some(ids=>ids.includes(String(arguments[0])))',fleet_id))
        if mission:
            self.open_mission(fleet_id)
            self.js("Page.navigation.$type[0].value=2;Page.navigation.$distance.val(3000);Page.navigation.$deg.val(0);Page.selectedMission=Page.getHoldMissionId();Page.missionSelector.value=Page.selectedMission;Page.updateNavigationCoordinateFields(2);Page.coordinatesChanged(arguments[0],null,true)",fleet_id)
            self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
            self.js("$('#FleetSpeedRange').val(10).trigger('input').trigger('change')")
            self.send_form(fleet_id)
            ids = self.js('return Page.messageData.data.Page.FlyingFleets[arguments[0]].commands.map(c=>Number(c.id))',fleet_id)
            self.record('commands-above-old-issued-floor', min(ids)>int(self.js("return sessionStorage.getItem('b029OldCommandFloor')")))
            if history: self.record('commands-above-previous-reserve', min(ids)>history[-1]['commandsHigh'])
            self.command('RecallFleet',{'fleetId':fleet_id})
            self.wait(lambda:self.js('return !!Page.messageData.data.Page.FleetList[arguments[0]]',fleet_id),seconds=120)
            self.record('real-return-and-unload',self.js('let f=Page.messageData.data.Page.FleetList[arguments[0]];return f.status===0&&f.fuel===0&&f.ships[202].count===1',fleet_id))
        button = self.js('return Page.$Fleets[arguments[0]].Buttons.Disband[0]',fleet_id)
        self.call('/element/'+button['element-6066-11e4-a52e-4f735466cecf']+'/click',{})
        self.wait(lambda:self.js("return !!document.querySelector('dialog-buttons .btn-danger')?.getClientRects().length"))
        self.click('dialog-buttons .btn-danger')
        self.wait(lambda:self.js('return !Page.messageData.data.Page.FleetList[arguments[0]]&&WS.requestCallbacks.size===0',fleet_id))
        def gone():
            current=self.sql()
            return all(f['id']!=fleet_id for f in current['fleets']) and all(c['fleet_id']!=fleet_id for c in current['commands']) and current['ships']==1
        self.wait(gone,seconds=70)
        self.record('fleet-and-command-rows-deleted',gone())
        current=self.sql(); ranges={r['repository']:r['high_water'] for r in current['ranges']}
        entry={'fleet':fleet_id,'fleetHigh':ranges['fleets'],'commandsHigh':ranges['fleet_commands']}
        history.append(entry);self.js("sessionStorage.setItem('b029Cycles',JSON.stringify(arguments[0]))",history)
        self.record('native-disband-returns-ship-once',self.js('return Page.messageData.data.Page.PlanetShips[202].count===1'))
        print(json.dumps(entry),flush=True)

    def pages(self):
        self.token=self.js('return Data.token')
        self.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        try:
            for mode in ['overview','buildings','researchs','shipyard','defense','fleets','galaxy','messages']:
                reply=self.request(mode)
                self.record('main-page-'+mode,reply.get('status')=='ok' and bool(reply.get('data',{}).get('Page')))
        finally:
            self.ws.close();self.ws=None
        self.js("window.__b029ReadDone=false;GameEvents.emit('SendServer',{mode:'fleets',NotOverlay:true,onSuccess:m=>{ShowPage.Show(m.mode,m);__b029ReadDone=true}})")
        self.wait(lambda:self.js("return __b029ReadDone&&WS.requestCallbacks.size===0&&Page.$FleetsPlanet?.is(':visible')"))
        self.record('original-bootstrap-fleet-returned',self.js("return Page.messageData.data.Page.FleetList[132]?.status===0&&Page.messageData.data.Page.FleetList[132]?.name==='QA B016 route'"))
        self.record('browser-runtime-errors-zero',self.js('return __b029Errors.length===0'))


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['bootstrap','cycle','verify'],required=True);p.add_argument('--mission',action='store_true')
    args=p.parse_args();c=RangeCheck(args.session)
    try:
        c.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{
            'source':"window.__b029Errors=[];addEventListener('error',e=>__b029Errors.push(e.message));addEventListener('unhandledrejection',e=>__b029Errors.push(String(e.reason)));"}})
        if args.phase!='bootstrap': c.call('/refresh',{})
        c.ready()
        if args.phase=='cycle':c.cycle(args.mission)
        else:getattr(c,args.phase)()
        if args.phase=='verify':c.pages()
        print(json.dumps({'phase':args.phase,'passed':len(c.results)}),flush=True)
    except Exception as e:
        print(json.dumps({'failedAt':c.phase,'errorClass':type(e).__name__}),flush=True);sys.exit(1)
