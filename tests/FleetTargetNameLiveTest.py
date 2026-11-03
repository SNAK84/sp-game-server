"""B019: настоящие рейсы основного dev и SPA, только QA fleets131/132.

start выполняется в браузере с прежним formatter, after — после загрузки правки.
cleanup отзывает оба флота и возвращает их прежние имена. Fixtures в after
проверяют только клиентское форматирование, без нового игрового мира/SQL-записей.
"""
import argparse
import json
import sys
import time
import urllib.error
from CredentialLoggingLiveTest import CredentialCheck


class NameCheck(CredentialCheck):
    target=131
    source=132

    def command(self,action,data):
        self.js("""
            window.__b019ack=null;
            GameEvents.emit('SendServer',{mode:'fleets',action:arguments[0],data:arguments[1],NotOverlay:true,
                ...Page.getRequestOptions({kind:'command'}),
                onSuccess:()=>__b019ack={ok:true},onError:e=>__b019ack={ok:false,code:e.code}});
        """,action,data)
        reply=self.wait(lambda:self.js('return __b019ack'))
        assert reply.get('ok'),reply

    def flight(self,fleet_id):
        return self.js('return Page.messageData.data.Page.FlyingFleets?.[arguments[0]]||null',fleet_id)

    def watch_wire(self):
        self.js("""
            window.__b019wire=[];
            WS.ws.addEventListener('message',event=>{
                const page=JSON.parse(event.data).data?.Page;
                if(page?.FlyingFleets?.[132]) {
                    __b019wire.push({target:page.FlyingFleets[132].mission_target,
                        name:page.FleetTargets?.find(f=>Number(f.id)===131)?.name,
                        ownName:page.FlyingFleets[131]?.name});
                    if(__b019wire.length>30)__b019wire.shift();
                }
            });
        """)

    def open_mission(self,fleet_id):
        self.js('Dialog.close();Page.showTaskFleet(arguments[0])',fleet_id)
        self.wait(lambda:self.js("return Page.missionDataLoading===false&&WS.requestCallbacks.size===0&&!!Dialog.dialogDiv.getClientRects().length&&!$(Dialog.dialogDiv).is(':animated')"))

    def send_form(self,fleet_id):
        # Ждём расчёт именно выбранной скорости, а не предыдущий preview.
        self.wait(lambda:self.js('let sent=JSON.parse(Page.lastCoordinates||"null");return sent?.speedFactor===Page.flightControls?.getSpeedFactor()&&!Page.missionDataLoading&&WS.requestCallbacks.size===0&&typeof Page.flightControls?.getPayload==="function"&&!document.querySelector(".FleetMissionSendButton").disabled'))
        payload=self.js('return Page.flightControls.getPayload()')
        self.click('.FleetMissionSendButton')
        self.wait(lambda:self.js('return !!Page.messageData.data.Page.FlyingFleets?.[arguments[0]]',fleet_id))
        return payload

    def launch(self):
        self.phase='qa-guard'
        self.record('qa-planet-and-controlled-fleets',self.js('let p=Page.messageData.data.Page;return p.planet_id===26&&[131,132].every(id=>(p.FleetList[id]||p.FlyingFleets[id])?.ships[203]?.count===1)&&p.FleetList[132].status===0'))
        self.js("if(!sessionStorage.getItem('b019Names'))sessionStorage.setItem('b019Names',JSON.stringify([131,132].map(id=>[id,Page.messageData.data.Page.FleetList[id].name])))")
        self.watch_wire()
        if not self.flight(self.target):
            self.open_mission(self.target)
            self.js("Page.navigation.$type[0].value=2;Page.navigation.$distance.val(3000);Page.navigation.$deg.val(0);Page.selectedMission=Page.getHoldMissionId();Page.missionSelector.value=Page.selectedMission;Page.updateNavigationCoordinateFields(2);Page.coordinatesChanged(131,null,true)")
            self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
            self.js("$('#FleetFuelRange').val(1000).trigger('input')")
            payload=self.send_form(self.target)
            self.record('target-real-point-observe',payload['coordinates']['type']==2 and payload['missionType']==30 and payload['fuel']==1000)
        self.open_mission(self.source)
        self.js('Page.navigation.$type[0].value=3;Page.updateNavigationCoordinateFields(3);Page.navigation.$fleetTarget.val("131").trigger("change")')
        self.wait(lambda:self.js('return !Page.missionDataLoading&&WS.requestCallbacks.size===0'))
        self.js("$('#FleetSpeedRange').val(10).trigger('input').trigger('change')")
        self.js("$('#ResInput901').val(1).trigger('input');$('#FleetRefuelReceiver').prop('checked',false).trigger('change')")
        payload=self.send_form(self.source)
        self.record('source-real-fleet-transport',payload['coordinates']=={'type':3,'fleetId':131} and payload['missionType']==10 and payload['speedFactor']==1)
        self.wait(lambda:self.js('return __b019wire.some(p=>p.target?.type===3&&Number(p.target.fleetId)===131&&p.name)'))
        self.record('raw-ws-has-authorized-name',self.js("return __b019wire.some(p=>p.name==='QA B016 legacy'&&p.ownName===p.name)"))
        label=self.js('return document.querySelector(`.FleetFlightCard[data-fleet-id="132"] .FleetFlightMissionTarget`).textContent')
        self.record('old-client-bug-reproduced',label=='Флот #131')
        print(json.dumps({'targetName':'QA B016 legacy','oldDom':label,'source':132,'target':131},ensure_ascii=False),flush=True)

    def check_card(self,name):
        self.wait(lambda:self.js('return document.querySelector(`.FleetFlightCard[data-fleet-id="132"] .FleetFlightMissionTarget`)?.textContent===arguments[0]',name))
        self.record('card-target-name',self.js('return Page.formatNavigationTarget({type:3,fleetId:131})===arguments[0]',name))
        self.js('Page.expandedFlyingFleetCommands.add(132);Page.renderFlyingFleets()')
        self.record('commands-target-name',self.js('return [...document.querySelectorAll(`.FleetFlightCard[data-fleet-id="132"] .FleetFlightCommandTarget`)].some(e=>e.textContent===arguments[0])',name))
        self.record('tooltip-keeps-authoritative-segment',self.js('let f=Page.messageData.data.Page.FlyingFleets[132],d=document.createElement("div");d.innerHTML=$(`.FleetFlightCard[data-fleet-id="132"] .FleetFlightTrack`).data("tooltip");return [...d.querySelectorAll("dd")].some(e=>e.textContent===Page.formatFleetCoordinate(f.segment_target??f.segment_end))'))

    def after(self):
        self.call('/refresh',{})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.ws.readyState===1&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.check_card('QA B016 legacy')
        self.watch_wire()
        self.js('window.__b019card=document.querySelector(`.FleetFlightCard[data-fleet-id="132"]`);window.__b019target=__b019card.querySelector(".FleetFlightMissionTarget");window.__b019command=[...__b019card.querySelectorAll(".FleetFlightCommandTarget")].find(e=>e.textContent==="QA B016 legacy")')
        self.command('RenameFleet',{'FleetId':131,'Name':'QA B019 Target B'})
        self.check_card('QA B019 Target B')
        self.record('raw-ws-new-name',self.js("return __b019wire.some(p=>p.name==='QA B019 Target B'&&p.ownName===p.name)"))
        self.record('rename-preserves-dom-nodes',self.js('return __b019card===document.querySelector(`.FleetFlightCard[data-fleet-id="132"]`)&&__b019target===__b019card.querySelector(".FleetFlightMissionTarget")&&__b019command===[...__b019card.querySelectorAll(".FleetFlightCommandTarget")].find(e=>e.textContent==="QA B019 Target B")'))
        self.fixtures()
        self.record('browser-runtime-errors-zero',self.js('return __b019errors.length===0'))

    def fixtures(self):
        result=self.js("""
            const original=Page.messageData.data.Page,results=[];
            const check=(page,expected)=>{
                Page.messageData.data.Page=page;
                results.push(Page.formatFleetCoordinate({type:3,fleetId:'777'})===expected
                    &&Page.formatNavigationTarget({type:3,fleetId:777})===expected);
            };
            try {
                check({FleetTargets:[{id:777,name:'Target name'}],FleetList:{777:{name:'Older name'}}},'Target name');
                check({FleetList:{777:{name:'Own idle'}}},'Own idle');
                check({FlyingFleets:{777:{name:'Own moving',is_own:true}}},'Own moving');
                check({FlyingFleets:{777:{name:'',is_own:false}}},'Флот #777');
                check({SensorContacts:[{id:777,name:'Hidden sensor name'}]},'Флот #777');
                check({FleetTargets:[{id:777,name:''}],FleetList:{},FlyingFleets:{}},'Флот #777');
                check({FleetTargets:[{id:777,name:''}],FlyingFleets:{777:{name:'Older snapshot name'}}},'Флот #777');
                check({FleetList:{777:{name:''}},FlyingFleets:{777:{name:'Older snapshot name'}}},'Флот #777');
                check({FleetTargets:[{id:777,name:'Known unavailable',selectable:false}]},'Known unavailable');
                check({},'Флот #777');
                const name='<b>Literal name</b>';
                Page.messageData.data.Page={...original,FleetTargets:[{id:777,name}]};
                const f=original.FlyingFleets[132];
                const copy={...f,commands:f.commands.map(c=>({...c,target:{type:3,fleetId:777}}))};
                const $commands=Page.createFlyingFleetCommands(copy);
                const tip=$commands.find('.FleetFlightCommandTarget').first().data('tooltip');
                const d=document.createElement('div');d.innerHTML=tip;
                results.push(d.textContent===name&&!d.querySelector('b'));
                d.innerHTML=Page.createFlyingFleetProgressTooltip({...f,segment_target:{type:3,fleetId:777}},'test','test');
                results.push([...d.querySelectorAll('dd')].some(e=>e.textContent===name)&&!d.querySelector('b'));
                return results;
            } finally {Page.messageData.data.Page=original;}
        """)
        for index,passed in enumerate(result):self.record('browser-formatter-guard-'+str(index+1),passed)

    def cleanup(self):
        self.command('RenameFleet',{'FleetId':131,'Name':'QA B016 legacy'})
        self.wait(lambda:self.js('return Page.formatFleetCoordinate({type:3,fleetId:131})==="QA B016 legacy"'))
        for fleet_id in [132,131]:
            f=self.flight(fleet_id)
            if not f or f['is_return']:continue
            if f['can_recall']:self.command('RecallFleet',{'fleetId':fleet_id})
            elif f.get('control',{}).get('holding') and f['control'].get('can_continue'):
                self.command('ResumeFleet',{'fleetId':fleet_id,'fuelMode':'available'})
            else:raise AssertionError('Нет штатного действия возврата QA.')
        self.record('qa-names-restored-and-normal-return-actions-accepted')

    def returned(self):
        rows=self.php("""
            require 'vendor/autoload.php'; SPGame\\Core\\Environment::init('.env');
            $d=SPGame\\Core\\Database::getInstance();
            echo json_encode($d->fetchAll('SELECT f.id,f.name,f.owner_id,f.home_planet_id,f.status,f.fuel_current,f.resource_metal,f.resource_crystal,f.resource_deuterium,s.big_ship_cargo_count,(SELECT COUNT(*) FROM fleet_commands c WHERE c.fleet_id=f.id AND c.executed=0) pending FROM fleets f JOIN fleets_ships s ON s.id=f.id WHERE f.id IN (131,132) ORDER BY f.id'));
        """,{})
        self.record('two-qa-fleets-preserved',len(rows)==2)
        for f in rows:
            self.record('returned-and-durable-'+str(f['id']),f['owner_id']==9 and f['home_planet_id']==26 and f['status']==0 and f['pending']==0 and f['big_ship_cargo_count']==1)
            self.record('unloaded-and-name-restored-'+str(f['id']),all(f[k]==0 for k in ['fuel_current','resource_metal','resource_crystal','resource_deuterium']) and f['name']==('QA B016 legacy' if f['id']==131 else 'QA B016 route'))
        self.record('browser-runtime-errors-zero',self.js('return __b019errors.length===0'))

    def cleared(self):
        self.command('RenameFleet',{'FleetId':131,'Name':''})
        self.check_card('Флот #131')
        self.record('real-redacted-targets-keep-id-fallback',self.js("let targets=Page.messageData.data.Page.FleetTargets.filter(f=>!f.is_own&&!f.name);return targets.length>0&&targets.every(f=>Page.formatFleetCoordinate({type:3,fleetId:f.id})===`Флот #${f.id}`)"))
        self.call('/refresh',{})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.ws.readyState===1&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.check_card('Флот #131')
        self.record('reload-does-not-revive-cleared-name',self.js("return Page.messageData.data.Page.FleetTargets.find(f=>Number(f.id)===131)?.name===''&&Page.messageData.data.Page.FlyingFleets[131]?.name===''"))


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['start','after','cleared','cleanup','returned'],required=True)
    a=p.parse_args();c=NameCheck(a.session)
    try:
        (c.launch if a.phase=='start' else getattr(c,a.phase))()
        print(json.dumps({'phase':a.phase,'passed':len(c.results)}),flush=True)
    except Exception as e:
        result={'failedAt':c.phase,'errorClass':type(e).__name__}
        if isinstance(e,urllib.error.HTTPError):
            detail=json.load(e).get('value',{});result['webdriverError']=detail.get('error')
            message=detail.get('message','').split('\n')[0][:180]
            if c.clean(message):result['message']=message
        print(json.dumps(result),flush=True);sys.exit(1)
