"""B037: настоящий SPA/main, без игровых команд и изменения протокола.

Создаёт и закрывает свою Selenium-сессию QA. Replay использует уже полученный
настоящий snapshot; это проверка UI-гонки, не новый серверный ActualData.
"""
import argparse
import json
import sys
import time
import urllib.request

from CredentialLoggingLiveTest import CredentialCheck
from check_fleet_mission_layout import http


class NavigationCheck(CredentialCheck):
    def ready(self):
        self.call('/goog/cdp/execute', {'cmd': 'Page.addScriptToEvaluateOnNewDocument', 'params': {'source': """
            window.__navErrors=[];
            addEventListener('error',e=>__navErrors.push(e.message));
            addEventListener('unhandledrejection',e=>__navErrors.push(String(e.reason)));
        """}})
        self.call('/url', {'url': 'https://sp-game.ru/'})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&!!Page?.Name&&WS.ws.readyState===1"))
        # У QA B018 уже есть две планеты. Используем его существующий токен
        # только в RAM, без смены пароля/токена или подготовки новых объектов.
        auth=self.php("""
            require 'vendor/autoload.php'; SPGame\\Core\\Environment::init('.env');
            echo json_encode(SPGame\\Core\\Database::getInstance()->fetchOne(
                'SELECT token FROM accounts WHERE login=?',['qa_b018_home']));
        """,{})
        assert len(auth.get('token') or '')==128,'Нужен действующий QA B018'
        self.values.append(auth['token'])
        self.js("Data.token=arguments[0];Data.mode='overview';WS.Submit('overview')",auth['token'])
        self.settled('overview')
        self.original_planet=self.js('return String(Page.messageData.data.PlanetList.current_planet)')
        self.record('existing-multi-planet-QA-ready')
        self.js("""
            window.__navSent=[];window.__navReplies=[];window.__navReplay=0;
            const send=WS.ws.send.bind(WS.ws);
            WS.ws.send=function(raw){
                const m=JSON.parse(raw);
                if(m.mode!=='connect'&&m.mode!=='lang')__navSent.push({
                    mode:m.mode,action:m.action||'',id:m.requestId,
                    planetId:m.action==='planetselect'?m.data.planetId:null});
                return send(raw);
            };
            WS.ws.addEventListener('message',e=>{
                const m=JSON.parse(e.data);
                if(m.mode!=='connect'&&m.mode!=='lang')__navReplies.push({
                    mode:m.mode,action:m.action||'',id:m.requestId,status:m.status,
                    planet:m.data?.PlanetList?.current_planet});
            });
        """)

    def settled(self, mode):
        self.wait(lambda: self.js("""
            return Page?.messageData?.mode===arguments[0]&&Data.mode===arguments[0]
                &&Page.pageActive&&Page.ownsContent()&&Page.$Content.Show
                &&document.querySelector('#Content').children.length>0
                &&$('#Content').is(':visible')&&!$('#Content').is(':animated')
                &&!$('#layer').is(':animated')&&WS.requestCallbacks.size===0
                &&![...document.querySelectorAll('body>overlay')].some(e=>getComputedStyle(e).display!=='none');
        """, mode))

    def read(self, mode):
        # Подготовка исходной страницы, не проверка обходного пути меню.
        self.js("WS.Submit(arguments[0])", mode)
        self.settled(mode)

    def native_click(self, selector):
        if self.js('return innerWidth<700') and selector.startswith('#LeftMenu '):
            self.click('#MenuBtn')
            self.wait(lambda: self.js("return Page.$LeftMenu.hasClass('menu_open')"))
        self.click(selector)

    def transition(self, kind, source, target, delay, expected_missing=False):
        self.read(source)
        planet = None
        if kind == 'planet':
            planet = self.js("""
                const s=document.querySelector('#planets-select');
                return [...s.options].find(o=>o.value!==s.value).value;
            """)
            if self.js("return innerWidth<700&&!Page.$LeftMenu.hasClass('menu_open')"):
                self.click('#MenuBtn')
            self.click('#planets-select-button')
        # Сохраняем именно исходную страницу и её реальный snapshot.
        self.js("""
            window.__navSource=Page;window.__navSnapshot=Page.messageData;
            window.__navStart=__navSent.length;window.__navReplayed=false;
        """)
        if kind == 'planet':
            element = self.js("""
                const w=$('#planets-select').planetselectmenu('instance');
                return w.menu.children().filter((_,li)=>
                    $(li).data('ui-selectmenu-item')?.value===arguments[0])
                    .find('.ui-menu-item-wrapper')[0];
            """, planet)
            key = 'element-6066-11e4-a52e-4f735466cecf'
        else:
            selector='#Messages' if kind == 'messages' else '#LeftMenu [mode="'+target+'"]'
            element=self.call('/element',{'using':'css selector','value':selector})
        # Таймер запускается самим настоящим click, не следующим WebDriver RTT.
        if delay is not None:
            self.js("""
                const target=arguments[0],delay=arguments[1];
                target.addEventListener('click',()=>setTimeout(()=>{
                    if(Page===__navSource){
                        __navSource.Show(__navSnapshot.mode,__navSnapshot);
                        __navReplay++;__navReplayed=true;
                    }
                },delay),{once:true,capture:true});
                const socket=WS.ws,receive=socket.onmessage;
                // Только доставка настоящего ответа задержана в этом браузере.
                // Снимок и серверная обработка не подменяются.
                socket.onmessage=function(e){
                    const m=JSON.parse(e.data);
                    if(__navSent.slice(__navStart).some(s=>s.id===m.requestId)){
                        socket.onmessage=receive;
                        setTimeout(()=>receive.call(socket,e),650);
                    }else receive.call(socket,e);
                };
                window.__navRestoreReceive=()=>{socket.onmessage=receive};
            """, element, delay)
        if kind == 'planet':
            self.call('/element/'+element[key]+'/click', {})
        else:
            self.native_click(selector)
        time.sleep(1.4)
        if delay is not None:self.js('__navRestoreReceive()')
        sent = self.js('return __navSent.slice(__navStart)')
        relevant = [m for m in sent if m['mode'] == target and
                    m['action'] == ('planetselect' if kind == 'planet' else '')]
        name = kind+'-'+source+'-'+target+'-'+str(delay)
        if expected_missing:
            print(json.dumps({'beforeCase':name,'sent':relevant,'replayed':self.js('return __navReplayed')}),flush=True)
            self.record('before-missing-'+name, len(relevant) == 0 and self.js('return __navReplayed'))
            return
        self.record('exactly-one-wire-request-'+name, len(relevant) == 1)
        self.settled(target)
        rid = relevant[0]['id']
        self.record('real-server-response-'+name, self.js(
            'return __navReplies.some(m=>m.id===arguments[0]&&m.status==="ok")', rid))
        if planet is not None:
            self.wait(lambda:self.js("""
                return String(Page.messageData.data.PlanetList.current_planet)===arguments[0]
                    &&document.querySelector('#planets-select').value===arguments[0];
            """, planet))
            self.record('selected-planet-applied-'+name)
        self.record('visible-owned-content-'+name)

    def run(self, before=False):
        self.ready()
        if before:
            self.transition('menu','shipyard','defense',50,expected_missing=True)
            return
        for width,height in [(1440,900),(390,844)]:
            self.call('/goog/cdp/execute', {'cmd':'Emulation.setDeviceMetricsOverride', 'params':{
                'width':width,'height':height,'deviceScaleFactor':1,'mobile':width<700}})
            for delay in [50,None]:
                for kind,source,target in [('menu','shipyard','defense'),
                        ('menu','defense','shipyard'),('messages','fleets','messages'),
                        ('planet','buildings','buildings')]:
                    self.transition(kind,source,target,delay)
            self.record('runtime-errors-zero-'+str(width),self.js('return __navErrors.length===0'))
        self.read('galaxy')
        self.record('galaxy-working-after-navigation')
        syntax=self.call('/execute/async',{'script':"""
            const done=arguments[arguments.length-1];
            fetch('/js/BasePageClass.js').then(r=>r.text()).then(source=>{
                new Function(source);done(true);
            }).catch(()=>done(false));
        """,'args':[]})
        self.record('deployed-javascript-compiles-in-chromium',syntax)
        if self.js("return innerWidth<700&&!Page.$LeftMenu.hasClass('menu_open')"):
            self.click('#MenuBtn')
        self.click('#planets-select-button')
        self.js("""
            window.__navStable={page:Page,content:document.querySelector('#Content'),
                canvas:document.querySelector('#Content canvas'),
                select:document.querySelector('#planets-select'),
                widget:$('#planets-select').planetselectmenu('instance')};
        """)
        for index in range(3):
            start=self.js('return __navReplies.length')
            # Настоящий запрос/ответ ActualData, а не replay и не новый push-механизм.
            self.js("WS.Submit('galaxy','ActualData')")
            self.wait(lambda:self.js("return __navReplies.slice(arguments[0]).some(m=>m.mode==='galaxy'&&m.action==='ActualData'&&m.status==='ok')",start))
            self.settled('galaxy')
            self.record('real-ActualData-keeps-open-selector-and-DOM-'+str(index),self.js("""
                return Page===__navStable.page&&document.querySelector('#Content')===__navStable.content
                    &&document.querySelector('#Content canvas')===__navStable.canvas
                    &&document.querySelector('#planets-select')===__navStable.select
                    &&$('#planets-select').planetselectmenu('instance')===__navStable.widget
                    &&__navStable.widget.isOpen;
            """))
        time.sleep(11)
        self.record('selector-still-open-after-eleven-seconds',self.js('return __navStable.widget.isOpen'))
        self.js("$('#planets-select').planetselectmenu('close')")
        self.record('no-gameplay-commands',self.js("return __navSent.every(m=>['','planetselect','ActualData'].includes(m.action))"))
        self.record('final-runtime-errors-zero',self.js('return __navErrors.length===0'))
        self.record('original-planet-restored',self.js(
            'return String(Page.messageData.data.PlanetList.current_planet)===arguments[0]',self.original_planet))


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--dev',action='store_true',required=True)
    parser.add_argument('--before',action='store_true')
    args=parser.parse_args()
    session=http('/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome',
        'goog:chromeOptions':{'args':['--headless=new','--no-sandbox','--disable-dev-shm-usage']}}}})['sessionId']
    check=NavigationCheck(session)
    try:
        check.run(args.before)
        print(json.dumps({'passed':len(check.results),'phase':'before' if args.before else 'after'}))
    except Exception:
        print('FAIL phase='+check.phase,file=sys.stderr)
        raise
    finally:
        request=urllib.request.Request('http://127.0.0.1:4444/session/'+session,method='DELETE')
        urllib.request.urlopen(request,timeout=20).close()


if __name__=='__main__':main()
