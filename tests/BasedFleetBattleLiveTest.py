"""B033: карточка IDLE-защитника, настоящий SPA/основной dev.

--phase during проверяет battle DTO/DOM и сохраняет ссылки на существующие
узлы. --phase complete ждёт реальный результат того же боя без reload.
--phase reload отдельно подтверждает сохранённое штатным saver состояние.
Только QA B033; бой подготовлен отдельной guarded migration, не этим клиентом.
"""
import argparse
import json
import sys
from CredentialLoggingLiveTest import CredentialCheck


class BasedCheck(CredentialCheck):
    def scene(self):
        return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$d=SPGame\Core\Database::getInstance();
            $f=$d->fetchAll('SELECT id,name,status,current_coordinate_type,current_site_id,home_site_id,fuel_current FROM fleets WHERE name LIKE ? ORDER BY id',['QA B033 %']);
            $b=$d->fetchAll('SELECT id,phase,state FROM space_battles');
            foreach($b as &$r)$r['state']=json_decode($r['state'],true,512,JSON_THROW_ON_ERROR);unset($r);
            $r=$d->fetchAll('SELECT id,ready,payload FROM battle_reports WHERE payload LIKE ?',['%QA B033%']);
            foreach($r as &$p)$p['payload']=json_decode($p['payload'],true,512,JSON_THROW_ON_ERROR);unset($p);
            echo json_encode(['fleets'=>$f,'battles'=>$b,'reports'=>$r]);""",{})

    def ready(self,phase):
        if phase=='during':
            self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{'source':
                "window.__b033Errors=[];addEventListener('error',e=>__b033Errors.push(e.message));addEventListener('unhandledrejection',e=>__b033Errors.push(String(e.reason)));"}})
            self.call('/url',{'url':'https://sp-game.ru/'})
        elif phase=='reload':self.call('/refresh',{})
        if phase!='complete':
            self.wait(lambda:self.js("return typeof Page!=='undefined'&&!!Page?.Name&&WS.ws.readyState===1"))
            if self.js("return Page.Name==='LoginPageClass'"):self.browser_login()
            self.wait(lambda:self.js('return !!Page.pageActive&&WS.requestCallbacks.size===0'))
            self.js("Page.setActiveMode('fleets')")
            self.wait(lambda:self.js("return Page.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        scene=self.scene();self.guard=next(f['id'] for f in scene['fleets'] if f['name']=='QA B033 guard')

    def during(self):
        self.wait(lambda:self.js('return !!Page.messageData.data.Page.FleetList[arguments[0]]?.battle',self.guard))
        fleet=self.js('return Page.messageData.data.Page.FleetList[arguments[0]]',self.guard)
        self.record('idle-guard-remains-in-fleet-list',fleet['status']==0 and fleet['home_site_id']==4)
        self.record('public-battle-fields-only',sorted(fleet['battle'])==['phase','round','rounds_max'])
        self.record('freeze-without-standing-clock',fleet['current']['type']==2 and fleet['standing'] is None)
        self.record('battle-label-and-disabled-actions',self.js('let c=Page.$Fleets[arguments[0]];return c.Battle.is(":visible")&&c.Battle.text().includes("Участвует в бою")&&c.Buttons.Task.prop("disabled")&&c.Buttons.Disband.prop("disabled")&&!c.Buttons.Plan.is(":visible")&&!c.Buttons.MoreDetails.prop("disabled")',self.guard))
        self.js('''let id=arguments[0];window.__b033Node=Page.$Fleets[id][0];window.__b033Name=Page.$Fleets[id].Name[0];window.__b033Battle=Page.$Fleets[id].Battle[0];
            window.__b033Samples=[];window.__b033Timer=setInterval(()=>{let f=Page.messageData.data.Page?.FleetList?.[id],c=Page.$Fleets?.[id];if(f&&c){let row={phase:f.battle?.phase||'none',round:f.battle?.round||0,standing:!!f.standing,disabled:c.Buttons.Task.prop('disabled'),same:c[0]===__b033Node&&c.Name[0]===__b033Name&&c.Battle[0]===__b033Battle};let last=__b033Samples.at(-1);if(!last||JSON.stringify(last)!==JSON.stringify(row))__b033Samples.push(row);}},100);
            window.__b033Wire=[];WS.ws.addEventListener('message',e=>{let f=JSON.parse(e.data)?.data?.Page?.FleetList?.[id];if(f)__b033Wire.push({battle:f.battle,standing:!!f.standing});});''',self.guard)
        for _ in range(2):
            self.js("window.__b033Read=false;GameEvents.emit('SendServer',{mode:'fleets',NotOverlay:true,...Page.getRequestOptions({kind:'read'}),onSuccess:m=>{ShowPage.Show(m);__b033Read=true;}})")
            self.wait(lambda:self.js('return __b033Read&&WS.requestCallbacks.size===0'))
        self.record('same-card-and-label-after-real-responses',self.js('let c=Page.$Fleets[arguments[0]];return c[0]===__b033Node&&c.Name[0]===__b033Name&&c.Battle[0]===__b033Battle',self.guard))
        self.js('Page.showTaskFleet(arguments[0]);Page.DisbandFleet(arguments[0]);',self.guard,self.guard)
        self.record('disabled-actions-do-not-open-dialog',self.js('return getComputedStyle(Dialog.dialogDiv).display==="none"'))
        print(json.dumps({'guard':self.guard,'battle':fleet['battle']}),flush=True)

    def complete(self):
        self.wait(lambda:self.js('let f=Page.messageData.data.Page.FleetList[arguments[0]];return f&&!f.battle&&!!f.standing',self.guard),seconds=55)
        self.record('result-arrived-without-page-reload')
        self.record('same-card-through-battle-result',self.js('let c=Page.$Fleets[arguments[0]];return c[0]===__b033Node&&c.Name[0]===__b033Name&&c.Battle[0]===__b033Battle',self.guard))
        self.record('battle-hidden-and-actions-restored',self.js('let c=Page.$Fleets[arguments[0]];return !c.Battle.is(":visible")&&!c.Buttons.Task.prop("disabled")&&!c.find(".FleetOrbitRename").prop("disabled")&&c.Standing.is(":visible")',self.guard))
        self.record('same-node-for-all-background-updates',self.js('return __b033Samples.length>=2&&__b033Samples.every(s=>s.same)'))
        self.record('raw-battle-and-result-updates',self.js('return __b033Wire.some(s=>s.battle)&&__b033Wire.some(s=>s.battle===null&&s.standing)'))
        print(json.dumps({'samples':self.js('return __b033Samples')}),flush=True)
        self.js('clearInterval(__b033Timer)')
        self.saved_result()

    def saved_result(self):
        saved=self.wait(lambda:(lambda s:s if s['reports'] else None)(self.scene()),seconds=35)
        self.record('one-ready-report',len(saved['reports'])==1 and saved['reports'][0]['ready']==1)
        report=saved['reports'][0]['payload']
        self.record('guard-in-real-battle',any(p['fleetId']==self.guard for p in report['participants'].values()))
        print(json.dumps({'report':saved['reports'][0]['id'],'rounds':len(report['rounds']),'outcome':report['outcome']}),flush=True)

    def reload(self):
        self.record('reload-idle-guard-not-fighting',self.js('let f=Page.messageData.data.Page.FleetList[arguments[0]];return f.status===0&&f.battle===null&&f.current.siteId===4&&!!f.standing',self.guard))
        self.saved_result()


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['during','complete','reload'],required=True);a=p.parse_args();c=BasedCheck(a.session)
    try:
        c.ready(a.phase);getattr(c,a.phase)();c.record('browser-runtime-errors-zero',c.js('return __b033Errors.length===0'))
        print(json.dumps({'phase':a.phase,'passed':len(c.results)}),flush=True)
    except Exception as e:
        print(json.dumps({'failedAt':c.phase,'errorClass':type(e).__name__}),flush=True);sys.exit(1)
