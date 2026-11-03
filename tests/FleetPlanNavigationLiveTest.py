"""B041: настоящий SPA/main, только read-preview существующего QA-флота.

--before воспроизводит зависшее ожидание до исправления. Обычный прогон
проверяет invalid NavigationSystem, timeout, поздний ответ и восстановление.
Новые флоты/игровые события не создаются; Selenium-сессия принадлежит тесту.
"""
import argparse
import json
import sys
import time
import urllib.request

from CredentialLoggingLiveTest import CredentialCheck
from check_fleet_mission_layout import http


class PlanNavigationCheck(CredentialCheck):
    def ready(self):
        self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{'source':"""
            window.__planNavErrors=[];
            addEventListener('error',e=>__planNavErrors.push(e.message));
            addEventListener('unhandledrejection',e=>__planNavErrors.push(String(e.reason)));
        """}})
        self.call('/url',{'url':'https://sp-game.ru/'})
        self.browser_login()
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda:self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.fleet_id=self.js("""
            return Object.values(Page.messageData.data.Page.FleetList||{}).find(f=>
                Number(f.status)===0&&f.control&&f.actions?.SetFleetCommands!==false)?.id;
        """)
        assert self.fleet_id and self.js('return Page.messageData.data.Page.FleetCapabilities.plan')
        self.js("""
            window.__planNavReplies=[];window.__planNavHeld=[];window.__planNavBlock=false;
            window.__planNavSent=[];window.__planNavFailures=[];
            const emit=GameEvents.emit;
            GameEvents.emit=function(name,payload,...args){
                if(name==='SendServer'&&['SetFleetCommands','SendMission'].includes(payload.action))
                    throw Error('B041: игровая запись запрещена');
                if(name==='SendServer'&&payload.action==='GetSystemNavigationInfo'){
                    const failure=payload.onError;
                    payload.onError=e=>{__planNavFailures.push(e.code);failure?.(e)};
                    if(__planNavBlock)payload.timeout=350;
                }
                return emit.call(this,name,payload,...args);
            };
            const send=WS.ws.send.bind(WS.ws);
            WS.ws.send=function(raw){
                const m=JSON.parse(raw);
                __planNavSent.push({mode:m.mode,action:m.action||'',id:m.requestId,
                    galaxy:m.data?.galaxy,system:m.data?.system});
                return send(raw);
            };
            const receive=WS.ws.onmessage;
            WS.ws.onmessage=function(e){
                const m=JSON.parse(e.data);
                if(m.action==='GetSystemNavigationInfo'){
                    __planNavReplies.push({id:m.requestId,status:m.status,info:m.data?.Page?.NavigationSystem});
                    if(__planNavBlock){__planNavHeld.push(()=>receive.call(WS.ws,e));return}
                }
                return receive.call(WS.ws,e);
            };
        """)

    def open(self,width):
        self.js('Dialog.close()')
        self.wait(lambda:self.js("return !$(Dialog.dialogDiv).is(':visible')"))
        self.call('/goog/cdp/execute',{'cmd':'Emulation.setDeviceMetricsOverride','params':{
            'width':width,'height':900 if width>700 else 844,'deviceScaleFactor':1,'mobile':width<700}})
        self.js('Page.showFleetPlan(arguments[0])',self.fleet_id)
        self.settled()
        self.origin=self.js("""
            const p=$('.FleetPlanStep:visible');
            return {galaxy:p.find('input[aria-label="Галактика"]').val(),
                system:p.find('input[aria-label="Система"]').val()};
        """)
        self.js("""
            window.__planNavNodes={editor:document.querySelector('.FleetPlanEditor'),
                panel:$('.FleetPlanStep:visible')[0],galaxy:$('.FleetPlanStep:visible input[aria-label="Галактика"]')[0],
                submit:document.querySelector('.FleetPlanSubmit')};
        """)
        self.record('editor-open-'+str(width))

    def field(self,name,value):
        self.js("""
            const e=$('.FleetPlanStep:visible').find('input[aria-label="'+arguments[0]+'"]')[0];
            e.focus();e.value=arguments[1];
            e.dispatchEvent(new Event('input',{bubbles:true}));
            e.dispatchEvent(new Event('change',{bubbles:true}));
        """,name,str(value))

    def settled(self):
        self.wait(lambda:self.js("return WS.requestCallbacks.size===0&&!!document.querySelector('.FleetPlanEditor')&&!document.querySelector('.FleetPlanStep[aria-busy=\"true\"]')"))

    def state(self):
        return self.js("""
            return {pending:WS.requestCallbacks.size,
                busy:[...document.querySelectorAll('.FleetPlanStep')].map(p=>p.getAttribute('aria-busy')),
                fuel:document.querySelector('.FleetPlanFuelNotice').textContent,
                disabled:document.querySelector('.FleetPlanSubmit').disabled,
                hints:$('.FleetPlanStep:visible .FleetPlanHint:visible').map((_,p)=>p.textContent).get()};
        """)

    def stable(self,name):
        self.record('stable-DOM-'+name,self.js("""
            return document.querySelector('.FleetPlanEditor')===__planNavNodes.editor
                &&$('.FleetPlanStep:visible')[0]===__planNavNodes.panel
                &&$('.FleetPlanStep:visible input[aria-label="Галактика"]')[0]===__planNavNodes.galaxy
                &&document.querySelector('.FleetPlanSubmit')===__planNavNodes.submit;
        """))

    def invalid(self,name,value,before=False):
        start=self.js('return __planNavReplies.length')
        self.field(name,value)
        self.wait(lambda:self.js('return __planNavReplies.length>arguments[0]&&WS.requestCallbacks.size===0',start))
        time.sleep(.3)
        reply=self.js('return __planNavReplies.at(-1)')
        state=self.state()
        self.record('real-invalid-system-'+name,reply['status']=='ok' and reply['info']['valid'] is False)
        if before:
            self.record('before-invalid-stuck-busy-with-no-requests',state['pending']==0 and 'true' in state['busy']
                and 'Расчёт расхода' in state['fuel'] and state['disabled'])
            print(json.dumps({'before':state,'raw':reply['info']},ensure_ascii=False),flush=True)
            return
        self.settled()
        state=self.state()
        self.record('invalid-leaves-no-loading-'+name,'true' not in state['busy'] and 'Расчёт расхода' not in state['fuel'])
        self.record('invalid-stays-disabled-with-reason-'+name,state['disabled'] and any('недоступна' in h for h in state['hints']))
        self.stable('invalid-'+name)

    def recover(self,name):
        self.field(name,self.origin['galaxy' if name=='Галактика' else 'system'])
        self.settled()
        self.record('valid-system-recovered-'+name,self.js("""
            return __planNavReplies.at(-1).info.valid===true
                &&!document.querySelector('.FleetPlanSubmit').disabled
                &&!document.querySelector('.FleetPlanFuelNotice').textContent.includes('Расчёт расхода');
        """))
        self.stable('recovery-'+name)

    def timeout(self):
        self.js('window.__planNavBlock=true')
        target=7 if int(self.origin['system'])!=7 else 6
        self.field('Система',target)
        self.wait(lambda:self.js('return __planNavFailures.includes("TIMEOUT")&&__planNavHeld.length>0&&WS.requestCallbacks.size===0'))
        time.sleep(.3)
        self.settled()
        state=self.state()
        self.record('timeout-leaves-no-loading','Расчёт расхода' not in state['fuel'] and state['disabled'])
        self.record('timeout-shows-error',any('ответил' in h for h in state['hints']))
        self.stable('timeout')
        self.js('window.__planNavBlock=false;__planNavHeld.splice(0).forEach(deliver=>deliver())')
        time.sleep(.2)
        self.record('late-response-does-not-clear-error',self.state()['disabled'])
        self.recover('Система')

    def run(self,before=False):
        self.ready()
        for width in ([1440] if before else [1440,390]):
            self.open(width)
            self.invalid('Галактика',0,before)
            if before:break
            self.recover('Галактика')
            self.invalid('Система',0)
            self.recover('Система')
            self.timeout()
            self.record('runtime-errors-zero-'+str(width),self.js('return __planNavErrors.length===0'))
        self.js('Dialog.close()')
        self.wait(lambda:self.js("return !$(Dialog.dialogDiv).is(':visible')&&WS.requestCallbacks.size===0"))
        self.record('only-read-requests',self.js("return __planNavSent.every(m=>['','ping','GetAvailableMissions','GetSystemNavigationInfo'].includes(m.action))"))


def main():
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True)
    p.add_argument('--before',action='store_true')
    p.add_argument('--resource-regression',action='store_true');args=p.parse_args()
    session=http('/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome',
        'goog:chromeOptions':{'args':['--headless=new','--no-sandbox','--disable-dev-shm-usage']}}}})['sessionId']
    q=PlanNavigationCheck(session)
    try:
        q.run(args.before)
        print(json.dumps({'passed':len(q.results),'phase':'before' if args.before else 'after','fleet':q.fleet_id}))
        if args.resource_regression:
            assert not args.before,'Регрессия предназначена для исправленного клиента'
            from check_fleet_resource_preview import ResourcePreviewCheck
            for viewport in [(1365,900),(390,844)]:
                q.wait(lambda:q.js("return typeof Page!=='undefined'&&Page?.Name==='FleetsPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
                regression=ResourcePreviewCheck();regression.viewport=viewport;regression.run()
    except Exception:
        print('FAIL phase='+q.phase,file=sys.stderr);raise
    finally:
        request=urllib.request.Request('http://127.0.0.1:4444/session/'+session,method='DELETE')
        urllib.request.urlopen(request,timeout=20).close()


if __name__=='__main__':main()
