"""B016: сообщения реальных due-событий main и обычный SPA обоих QA.

before требует новую собственную Selenium-сессию; after/verify используют её же.
Учётные данные — только RAM. Сцену готовит отдельная guarded migration.
"""
import argparse
import json
import subprocess
import sys
import time
import urllib.error
import websocket
from CredentialLoggingLiveTest import CredentialCheck


class SourceCheck(CredentialCheck):
    def finish(self):
        self.call('/refresh',{})
        self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&Page.Name!=='LoginPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        assert self.js("return sessionStorage.getItem('b016Account')")=='owner'
        # B037 навигации отложен: это штатный read без анимации меню, не исправление клиента.
        self.js("GameEvents.emit('SendServer',{mode:'fleets',NotOverlay:true,onSuccess:m=>ShowPage.Show(m.mode,m)})")
        self.wait(lambda:self.js("return Page.Name==='FleetsPageClass'&&Page.$FleetsPlanet?.is(':visible')&&WS.requestCallbacks.size===0"))
        self.token=self.js('return Data.token');self.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        page=self.request('fleets')['data']['Page']
        rows=[f for f in page['FleetList'].values() if f['name'].startswith('QA B016 ')]
        self.record('all-five-returned',len(rows)==5 and all(f['status']==0 and f['fuel']==0
            and all(r['count']==0 for r in f['resources'].values()) for f in rows))
        self.record('composition-and-experience-kept',all(f['count']==1 and f['experience']==11 for f in rows))
        self.ws.close();self.ws=None
        self.js("GameEvents.emit('SendServer',{mode:'fleets',NotOverlay:true})")
        self.wait(lambda:self.js("return WS.requestCallbacks.size===0&&Page.$FleetsPlanet.is(':visible')"))
        self.record('native-idle-cards',self.js("return arguments[0].every(id=>Page.$Fleets[id]?.is(':visible')&&!document.querySelector('.FleetFlightCard[data-fleet-id=\"'+id+'\"]'))",[f['id'] for f in rows]))
        def saved():
            return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$db=SPGame\Core\Database::getInstance();
                $rows=$db->fetchAll("SELECT status,fuel_current,resource_metal,resource_crystal,resource_deuterium FROM fleets WHERE name LIKE 'QA B016 %'");
                echo json_encode(count($rows)===5&&array_reduce($rows,static fn($ok,$r)=>$ok&&array_sum($r)==0,true));""",{})
        self.wait(saved,seconds=75);self.record('ordinary-saver-final-state',saved())
        self.record('browser-runtime-errors-zero',self.js('return __b016errors.length===0'))
        print(json.dumps({'phase':'finish','passed':len(self.results)}),flush=True)

    def run(self, phase, account):
        if phase=='before':
            self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{
                'source':"window.__b016errors=[];addEventListener('error',e=>__b016errors.push(e.message));addEventListener('unhandledrejection',e=>__b016errors.push(String(e.reason)));"}})
            self.call('/url',{'url':'https://sp-game.ru/'})
            self.browser_login()
            self.js("sessionStorage.setItem('b016Account','owner')")
        else:
            self.call('/refresh',{})
            self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&Page.Name!=='LoginPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        if self.js("return sessionStorage.getItem('b016Account')")!=account:
            self.js("Page.setActiveMode('logout')")
            self.wait(lambda:self.js("return Page?.Name==='LoginPageClass'&&WS.requestCallbacks.size===0"))
            if account=='target':
                self.login,self.password=json.loads(subprocess.check_output(['docker','exec','sp_game_qa_foreign_bot','python3','-c',
                    "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"],text=True))
                assert self.login=='qafor_260924'
            self.browser_login()
            self.js("sessionStorage.setItem('b016Account',arguments[0])",account)
        self.js("Page.setActiveMode('messages')")
        self.wait(lambda:self.js("return Page?.Name==='MessagesPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.record('native-'+account+'-messages')
        self.token=self.js('return Data.token')
        self.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        expected={'QA B016 before':'[1:6:2]'} if phase=='before' else {
            'QA B016 legacy':'[1:7:3] Пояс · сектор 2',
            'QA B016 route':'[1:6:2]',
            'QA B016 snapshot':'[1:7:3] Пояс · сектор 2'}
        if phase=='verify' and account=='owner':expected['QA B016 moveonly']='[1:10:5]'
        sample='FleetTransportArrivalOwner' if account=='owner' else 'FleetTransportArrivalTarget'
        end=time.monotonic()+100
        while True:
            page=self.request('messages')['data']['Page']
            rows={}
            for row in page['allMessages']:
                data=json.loads(row['data'])
                if row['sample']==sample and data.get('FleetName') in expected:
                    assert data['FleetName'] not in rows,'Repeated delivery'
                    rows[data['FleetName']]=(row,data)
            if len(rows)==len(expected):break
            assert time.monotonic()<end,'Missing delivery'
            time.sleep(1)
        for name,source in expected.items():
            row,data=rows[name]
            self.record('raw-source-'+name,data['SourceCoordinate']==source)
            self.record('raw-resources-'+name,data['Resources']=={'901':31,'902':17,'903':9})
            if name in ('QA B016 route','QA B016 moveonly'):self.record('localized-planet-name-present-'+name,bool(data['SourcePlanetName']))
        self.ws.close();self.ws=None
        self.js("GameEvents.emit('SendServer',{mode:'messages',NotOverlay:true})")
        self.wait(lambda:self.js("return WS.requestCallbacks.size===0&&Page?.Name==='MessagesPageClass'"))
        for name,(row,data) in rows.items():
            message_id=int(row['id'])
            selector='#Message'+str(message_id)
            self.wait(lambda:self.js("return $(arguments[0]+' .MessageOpen').is(':visible')&&Page.$Content.is(':visible')&&!Page.$Content.is(':animated')&&!$('#Content').is(':animated')&&!$('#layer').is(':animated')",selector))
            self.js("window.__b016node=document.querySelector(arguments[0])",selector)
            if not self.js("return document.querySelector(arguments[0]+' .MessageOpen').getAttribute('aria-expanded')==='true'",selector):
                self.click(selector+' .MessageOpen')
            self.wait(lambda:self.js("return $(arguments[0]+' .MessageText').is(':visible')&&WS.requestCallbacks.size===0",selector))
            text=self.js("return document.querySelector(arguments[0]+' .MessageText').innerText",selector)
            self.record('dom-'+name,name in text and data['TargetCoordinate'] in text)
            if account=='target':
                self.record('dom-source-'+name,data['SourceCoordinate'] in text)
                if name!='QA B016 route':self.record('future-home-not-disclosed-'+name,'[1:6:2]' not in text)
            self.record('same-message-node-'+name,self.js("return document.querySelector(arguments[0])===__b016node",selector))
        self.record('browser-runtime-errors-zero',self.js('return __b016errors.length===0'))
        print(json.dumps({'phase':phase,'account':account,'passed':len(self.results),
            'messages':{n:int(r[0]['id']) for n,r in rows.items()}},ensure_ascii=False),flush=True)


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True);p.add_argument('--session',required=True)
    p.add_argument('--phase',choices=['before','after','verify','finish'],required=True);p.add_argument('--account',choices=['owner','target'],default='owner')
    a=p.parse_args();c=SourceCheck(a.session)
    try:
        if a.phase=='finish':c.finish()
        else:c.run(a.phase,a.account)
    except Exception as e:
        detail={'failedAt':c.phase,'errorClass':type(e).__name__}
        if isinstance(e,urllib.error.HTTPError):
            try:detail['webdriverError']=json.load(e).get('value',{}).get('error')
            except (ValueError,AttributeError):pass
        print(json.dumps(detail),flush=True);sys.exit(1)
    finally:
        if c.ws is not None:c.ws.close()
