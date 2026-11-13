"""B043: основной SPA/main, read-only zoom/ActualData/Hide.

Своя Selenium-сессия QA; --before воспроизводит остановившуюся отрисовку
до исправления. Серверные снимки и часы не подменяются.
"""
import argparse
import json
import sys
import time
import urllib.request

from CredentialLoggingLiveTest import CredentialCheck
from check_fleet_mission_layout import http


class ZoomCheck(CredentialCheck):
    def ready(self):
        self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{'source':"""
            window.__zoomErrors=[];
            addEventListener('error',e=>__zoomErrors.push(e.message));
            addEventListener('unhandledrejection',e=>__zoomErrors.push(String(e.reason)));
        """}})
        self.call('/url',{'url':'https://sp-game.ru/'})
        self.browser_login()
        loaded=self.call('/execute/async',{'script':"""
            const done=arguments[arguments.length-1];
            ShowPage._loadPageClass('galaxy','GalaxyPageClass').then(()=>done(!!window.GalaxyPageClass));
        """,'args':[]})
        assert loaded
        self.js("""
            window.__zoomWire=[];window.__zoomSent=[];
            const send=WS.ws.send.bind(WS.ws);
            WS.ws.send=function(raw){
                const m=JSON.parse(raw);__zoomSent.push({mode:m.mode,action:m.action||''});
                return send(raw);
            };
            WS.ws.addEventListener('message',e=>{
                const m=JSON.parse(e.data);
                if(m.mode==='galaxy'&&m.data?.Page?.System)__zoomWire.push({id:m.requestId,
                    action:m.action,status:m.status,system:m.data.Page.System.system,
                    elapsed:window.__zoomInitial?performance.now()-__zoomInitial.started:null});
            });
        """)

    def read(self,mode,system=None):
        self.js('WS.Submit(arguments[0],"",arguments[1])',mode,{'galaxy':1,'system':system} if system else {})
        names={'galaxy':'GalaxyPageClass','overview':'OverViewPageClass'}
        self.wait(lambda:self.js('return Page?.Name===arguments[0]&&Page.$Content.Show&&WS.requestCallbacks.size===0',names[mode]))

    def initial_zoom(self,width):
        self.read('overview')
        self.call('/goog/cdp/execute',{'cmd':'Emulation.setDeviceMetricsOverride','params':{
            'width':width,'height':900 if width>700 else 844,'deviceScaleFactor':1,'mobile':width<700}})
        self.js("""
            window.__zoomWireStart=__zoomWire.length;window.__zoomInitial=null;
            const prototype=GalaxyPageClass.prototype,start=prototype.smoothZoomTo;
            prototype.smoothZoomTo=function(target,duration){
                const result=start.call(this,target,duration);
                if(duration===1200){
                    prototype.smoothZoomTo=start;
                    __zoomInitial={version:this.renderVersion,zoom:this.zoom,started:performance.now(),
                        requestedDuringFit:this.zoomAnimationFrameId!==null};
                    // Реальный read отправляется в том же task, что начало fit,
                    // без задержки нескольких последовательных WebDriver RTT.
                    WS.Submit('galaxy','ActualData');
                }
                return result;
            };
        """)
        self.read('galaxy',6)
        self.wait(lambda:self.js("return Page.SystemShow&&__zoomInitial!==null"))
        self.js("""
            window.__zoomNodes={page:Page,canvas:Page.canvas,system:Page.$System[0],
                galaxy:document.querySelector('#NavigationGalaxyInput'),
                input:document.querySelector('#NavigationSystemInput')};
        """)
        self.record('initial-fit-active-'+str(width),self.js('return __zoomInitial.requestedDuringFit'))

    def state(self):
        return self.js("""
            const p=Page.mapObjects.find(p=>p.kind==='planet');
            return {version:Page.renderVersion,zoom:Page.zoom,fit:Page.fitZoom,
                zoomFrame:Page.zoomAnimationFrameId,frame:Page.animationFrameId,last:Page.lastFrameTime||0,
                angle:p?Math.atan2(p.y-Page.canvas.height/2-Page.offset.y,
                    p.x-Page.canvas.width/2-Page.offset.x):null,
                planet:p?.id,pending:WS.requestCallbacks.size};
        """)

    def wait_fit(self):
        self.wait(lambda:self.js("return Page.SystemShow&&Page.zoomAnimationFrameId===null&&WS.requestCallbacks.size===0"))

    def redraws(self,label):
        before=self.state();time.sleep(1.2);after=self.state()
        self.record('frames-advance-'+label,after['last']>before['last'] and after['frame']!=before['frame'])
        self.record('planet-position-advances-'+label,before['angle'] is not None and after['angle']!=before['angle'])
        self.record('DOM-preserved-'+label,self.js("""
            return Page===__zoomNodes.page&&Page.canvas===__zoomNodes.canvas
                &&Page.$System[0]===__zoomNodes.system&&document.querySelector('#NavigationGalaxyInput')===__zoomNodes.galaxy
                &&document.querySelector('#NavigationSystemInput')===__zoomNodes.input;
        """))

    def snapshot_during_fit(self,before=False):
        self.wait(lambda:self.js("return __zoomWire.slice(__zoomWireStart).some(m=>m.action==='ActualData'&&m.status==='ok')&&WS.requestCallbacks.size===0&&Page.renderVersion>__zoomInitial.version"))
        self.record('real-ActualData-during-fit',self.js("return __zoomWire.slice(__zoomWireStart).some(m=>m.action==='ActualData'&&m.elapsed<1200)"))
        if before:
            time.sleep(.2);a=self.state();time.sleep(2);b=self.state()
            print(json.dumps({'before':a,'after':b}),flush=True)
            self.record('before-stale-zoom-blocks-main-loop',b['zoomFrame'] is not None and b['frame']!=a['frame']
                and b['last']==a['last'] and b['angle']==a['angle'])
            return
        self.wait_fit()
        self.record('fit-reaches-target-after-snapshot',self.js('return Math.abs(Page.zoom-Page.fitZoom)<1e-9'))
        self.redraws('after-ActualData')

    def stale_callback(self):
        result=self.js("""
            const page=Page,raf=window.requestAnimationFrame;let old;
            window.requestAnimationFrame=callback=>{old=callback;return raf(callback)};
            try{page.smoothZoomTo(page.fitZoom*.9,800)}finally{window.requestAnimationFrame=raf}
            page.smoothZoomTo(page.fitZoom,900);
            const frame=page.zoomAnimationFrameId,zoom=page.zoom;
            // UI-replay уже отменённого callback, не серверное обновление.
            old(performance.now()+5000);
            const preserved=page.zoomAnimationFrameId===frame&&page.zoom===zoom;
            page.cancelZoomAnimation();const cancelledZoom=page.zoom;
            old(performance.now()+5000);
            return {preserved,cancelled:page.zoomAnimationFrameId===null&&page.zoom===cancelledZoom};
        """)
        self.record('old-callback-cannot-clear-new-owner',result['preserved'])
        self.record('old-callback-cannot-revive-cancelled-zoom',result['cancelled'])
        self.redraws('after-cancel')

    def wheel(self):
        self.js('Page.smoothZoomTo(Page.fitZoom*.8,1200)')
        before=self.js('return Page.zoom')
        point=self.js("const r=Page.canvas.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}")
        self.call('/goog/cdp/execute',{'cmd':'Input.dispatchMouseEvent','params':{
            'type':'mouseWheel','x':point['x'],'y':point['y'],'deltaX':0,'deltaY':-100}})
        self.wait(lambda:self.js('return Page.zoomAnimationFrameId===null'))
        wheel_zoom=self.js('return Page.zoom');time.sleep(1.3)
        self.record('real-wheel-cancels-fit-and-keeps-user-zoom',self.js('return Page.zoom===arguments[0]',wheel_zoom)
            and wheel_zoom!=before)
        self.redraws('after-wheel')

    def system_switch(self):
        self.js('Page.smoothZoomTo(Page.fitZoom*.8,1200)')
        # Настоящие input/change/blur навигации и настоящий ответ другой системы.
        self.js("""
            const e=document.querySelector('#NavigationSystemInput');e.value='7';
            e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));
            e.dispatchEvent(new FocusEvent('blur',{bubbles:true}));
        """)
        self.wait(lambda:self.js('return Page.System.system===7&&Page.SystemShow&&WS.requestCallbacks.size===0'))
        self.wait_fit()
        self.record('switch-system-during-fit-completes',self.js('return Math.abs(Page.zoom-Page.fitZoom)<1e-9'))
        self.redraws('after-system-switch')

    def pan(self):
        point=self.js("const r=Page.canvas.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}")
        for _ in range(7):
            self.call('/goog/cdp/execute',{'cmd':'Input.dispatchMouseEvent','params':{
                'type':'mouseWheel','x':point['x'],'y':point['y'],'deltaX':0,'deltaY':-100}})
        before=self.js('return {...Page.offset}')
        for event in [dict(type='mousePressed',x=point['x'],y=point['y'],button='left',buttons=1,clickCount=1),
                dict(type='mouseMoved',x=point['x']+45,y=point['y']+30,button='left',buttons=1),
                dict(type='mouseReleased',x=point['x']+45,y=point['y']+30,button='left',buttons=0,clickCount=1)]:
            self.call('/goog/cdp/execute',{'cmd':'Input.dispatchMouseEvent','params':event})
        camera=self.js('return {zoom:Page.zoom,...Page.offset}')
        self.record('native-drag-moves-zoomed-system',camera['x']!=before['x'] or camera['y']!=before['y'])
        count=self.js('return __zoomWire.length')
        self.js("WS.Submit('galaxy','ActualData')")
        self.wait(lambda:self.js('return __zoomWire.length>arguments[0]&&WS.requestCallbacks.size===0',count))
        self.record('ActualData-preserves-user-camera',self.js(
            'return Page.zoom===arguments[0].zoom&&Page.offset.x===arguments[0].x&&Page.offset.y===arguments[0].y',camera))
        self.redraws('after-pan-and-snapshot')

    def hide(self):
        self.js('window.__zoomOld=Page;Page.smoothZoomTo(Page.fitZoom*.9,1200)')
        self.read('overview');time.sleep(1.4)
        self.record('leaving-page-stops-both-loops',self.js('return !__zoomOld.pageActive&&__zoomOld.animationFrameId===null&&__zoomOld.zoomAnimationFrameId===null'))
        self.read('galaxy',6);self.wait_fit()
        self.record('return-to-galaxy-works')
        self.js("""
            const page=Page,fadeIn=page.$System.fadeIn;
            window.__zoomHideInFade=null;
            page.$System.fadeIn=function(...args){
                page.$System.fadeIn=fadeIn;
                const result=fadeIn.apply(this,args);
                // UI-lifecycle replay: Hide прямо во время настоящего fadeIn.
                page.Hide();
                __zoomHideInFade={page,stopped:page.animationFrameId===null&&page.zoomAnimationFrameId===null};
                return result;
            };
            WS.Submit('galaxy','',{galaxy:1,system:7});
        """)
        self.wait(lambda:self.js('return __zoomHideInFade!==null'))
        self.record('Hide-during-fade-does-not-start-new-zoom',self.js('return __zoomHideInFade.stopped'))
        self.read('overview');time.sleep(.3)
        self.record('hidden-page-has-no-late-animation',self.js('return __zoomHideInFade.page.zoomAnimationFrameId===null&&__zoomHideInFade.page.animationFrameId===null'))

    def run(self,before=False):
        self.ready()
        for width in ([1440] if before else [1440,390]):
            self.initial_zoom(width);self.snapshot_during_fit(before)
            if before:break
            self.stale_callback();self.wheel();self.pan();self.system_switch();self.hide()
            self.record('runtime-errors-zero-'+str(width),self.js('return __zoomErrors.length===0'))
        self.record('only-read-requests',self.js("return __zoomSent.every(m=>['','ActualData','ping'].includes(m.action))"))


def main():
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True)
    p.add_argument('--before',action='store_true');p.add_argument('--navigation-regression',action='store_true')
    args=p.parse_args()
    sid=http('/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome',
        'goog:chromeOptions':{'args':['--headless=new','--no-sandbox','--disable-dev-shm-usage']}}}})['sessionId']
    q=ZoomCheck(sid)
    try:
        q.run(args.before)
        print(json.dumps({'passed':len(q.results),'phase':'before' if args.before else 'after'}))
        if args.navigation_regression:
            from check_galaxy_navigation import NavigationCheck
            for mobile in [False,True]:NavigationCheck(sid,mobile).run()
    except Exception:
        print('FAIL phase='+q.phase,file=sys.stderr);raise
    finally:
        req=urllib.request.Request('http://127.0.0.1:4444/session/'+sid,method='DELETE')
        urllib.request.urlopen(req,timeout=20).close()


if __name__=='__main__':main()
