"""B044: основной main, настоящий SPA и пассивные WS участников.

--prepare делает штатный stop/admin-QA/start в finally. Во время боя
нет чтений страниц, reload или polling: наблюдаем штатные ActualData.
"""
import argparse
import json
import socket
import subprocess
import sys
import time
import urllib.request
import uuid

import websocket
from CredentialLoggingLiveTest import CredentialCheck
from check_fleet_mission_layout import http


def prepare(phase):
    print('QA prepare: stopping main',flush=True)
    try:
        subprocess.run(['./swoole_manager.sh','stop'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True,timeout=75)
        state=json.loads(subprocess.check_output(['docker','inspect','swoole_app'],text=True))[0]['State']
        assert not state['Running'] and state['ExitCode']==0 and not state['OOMKilled'],'Нештатная остановка main'
        result=subprocess.run(['docker','run','--rm','--network','host','--volumes-from','swoole_app',
            '-w','/var/www/html',subprocess.check_output(['docker','inspect','--format','{{.Config.Image}}','swoole_app'],text=True).strip(),
            'php','migrations/20261101_battle_round_push_qa.php',phase],capture_output=True,text=True,timeout=50)
        if result.returncode:
            # Вывод admin-подготовки не содержит credentials; ошибки показываем адресно.
            lines=[line for line in (result.stdout+'\n'+result.stderr).splitlines()
                if 'Uncaught ' in line or 'Fatal error:' in line or line.startswith('  thrown in ')]
            raise RuntimeError('QA prepare failed rc='+str(result.returncode)+': '+' '.join(lines)[-900:])
        scene=json.loads(result.stdout.strip().splitlines()[-1])
        print(json.dumps(scene),flush=True)
    finally:
        # manager start хвостит исторический лог; его не выводим и не очищаем.
        print('QA prepare: starting main',flush=True)
        try:subprocess.run(['./swoole_manager.sh','start'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=4)
        except subprocess.TimeoutExpired:pass
        deadline=time.monotonic()+60
        while True:
            try:
                with socket.create_connection(('127.0.0.1',9501),timeout=.5):break
            except OSError:
                if time.monotonic()>deadline:raise RuntimeError('Main не открыл WS после подготовки')
                time.sleep(.3)
    return scene


class RoundCheck(CredentialCheck):
    def ready(self,scene):
        self.scene=scene
        self.call('/goog/cdp/execute',{'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{'source':"""
            window.__roundErrors=[];
            addEventListener('error',e=>__roundErrors.push(e.message));
            addEventListener('unhandledrejection',e=>__roundErrors.push(String(e.reason)));
        """}})
        self.call('/url',{'url':'https://sp-game.ru/'})
        self.browser_login()
        self.js("""
            window.__roundPageRead=null;const original=WS.ws.send.bind(WS.ws);let requestId,start;
            WS.ws.send=function(raw){const m=JSON.parse(raw);if(m.mode==='fleets'&&!m.action){requestId=m.requestId;start=performance.now()}return original(raw)};
            WS.ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.requestId===requestId)__roundPageRead=performance.now()-start});
            Page.setActiveMode('fleets');
        """)
        self.wait(lambda:self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.wait(lambda:self.js('return !!Page.$Fleets[arguments[0]]&&!!document.querySelector(`.FleetFlightCard[data-fleet-id="${arguments[1]}"]`)',scene['space']['guard'],scene['planet']['attacker']))
        self.js("""
            window.__roundScene=arguments[0];window.__roundWire=[];window.__roundDOM=[];window.__roundSent=[];
            const guard=__roundScene.space.guard,attack=__roundScene.planet.attacker;
            window.__roundNodes={page:Page,guard:Page.$Fleets[guard][0],label:Page.$Fleets[guard].Battle[0],
                attack:document.querySelector(`.FleetFlightCard[data-fleet-id="${attack}"]`)};
            const send=WS.ws.send.bind(WS.ws);
            WS.ws.send=function(raw){const m=JSON.parse(raw);__roundSent.push({mode:m.mode,action:m.action||''});return send(raw)};
            WS.ws.addEventListener('message',e=>{
                const m=JSON.parse(e.data);if(m.action!=='ActualData'||m.mode!=='fleets')return;
                const p=m.data?.Page;
                __roundWire.push({at:m.serverTime||Date.now()/1000,
                    space:p?.FleetList?.[guard]?.battle??null,planet:p?.FlyingFleets?.[attack]?.battle??null});
            });
            window.__roundTimer=setInterval(()=>{
                const p=Page.messageData?.data?.Page,c=Page.$Fleets?.[guard],f=p?.FlyingFleets?.[attack];
                const node=document.querySelector(`.FleetFlightCard[data-fleet-id="${attack}"]`);
                const row={space:p?.FleetList?.[guard]?.battle?.round??null,planet:f?.battle?.round??null,
                    spaceText:c?.Battle.text()||'',
                    planetText:node?$('<div>').html($(node).find('.FleetFlightTrack').data('tooltip')||'')
                        .find('.FleetFlightProgressTooltipCommand strong').text():'',
                    same:Page===__roundNodes.page&&c?.[0]===__roundNodes.guard&&c?.Battle[0]===__roundNodes.label&&node===__roundNodes.attack};
                if(JSON.stringify(row)!==JSON.stringify(__roundDOM.at(-1)))__roundDOM.push(row);
            },100);
        """,scene)
        self.record('SPA-attached-before-rounds',time.time()<scene['space']['startAt'])
        print(json.dumps({'initialFleetPageMs':self.js('return __roundPageRead')}),flush=True)

    def monitor(self,container,login,field,planet=None):
        q=CredentialCheck('unused')
        if container:
            q.login,q.password=json.loads(subprocess.check_output(['docker','exec',container,'python3','-c',
                "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"],text=True))
        else:q.login=login  # QA B018 унаследовал пароль qaown при создании.
        assert q.login==login
        q.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        q.raw_login()
        original=q.request('fleets')['data']['PlanetList']['current_planet']
        if planet is not None:
            q.request('fleets','planetselect',{'planetId':planet})
            # Только подготовка подписки до боя; после неё страниц не запрашиваем.
            end=time.monotonic()+15
            while time.monotonic()<end:
                m=json.loads(q.ws.recv())
                if m.get('data',{}).get('PlanetList',{}).get('current_planet')==planet:break
            else:raise AssertionError('Не применён QA planetselect')
        q.ws.settimeout(.05)
        return {'client':q,'field':field,'wire':[],'lastPing':0,'originalPlanet':original}

    def reports(self):
        return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');
            $d=SPGame\Core\Database::getInstance();$ids=json_decode(stream_get_contents(STDIN),true);$out=[];
            foreach($ids as $id){$r=$d->fetchOne('SELECT id,ready,payload FROM battle_reports WHERE id=?',[$id]);
                if($r){$p=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);$out[]=['id'=>$id,'ready'=>$r['ready'],
                    'rounds'=>count($p['rounds']),'outcome'=>$p['outcome'],'duration'=>$p['finishedAt']-$p['startedAt']];}}echo json_encode($out);""",
            [self.scene[k]['uuid'] for k in ['space','planet']])

    def run(self,scene,before):
        monitors=[]
        try:
            self.ready(scene)
            monitors.append(self.monitor('sp_game_qa_foreign_bot','qafor_260924','space'))
            monitors.append(self.monitor(None,'qa_b018_home','planet',36))
            self.record('all-participants-attached-before-rounds',time.time()<scene['space']['startAt'])
            deadline=scene['planet']['startAt']+45;next_notice=0
            while time.time()<deadline:
                now=time.time()
                for monitor in monitors:
                    q=monitor['client']
                    if now-monitor['lastPing']>=1:
                        q.ws.send(json.dumps({'requestId':str(uuid.uuid4()),'mode':'connect','action':'ping','token':q.token}))
                        monitor['lastPing']=now
                    while True:
                        try:m=json.loads(q.ws.recv())
                        except websocket.WebSocketTimeoutException:break
                        if m.get('action')=='ActualData' and m.get('mode')=='fleets':
                            p=m.get('data',{}).get('Page',{})
                            fid=scene[monitor['field']]['attacker' if monitor['field']=='space' else 'guard']
                            row=p.get('FlyingFleets' if monitor['field']=='space' else 'FleetList',{}).get(str(fid),{})
                            monitor['wire'].append(row.get('battle'))
                state=self.js("""
                    const p=Page.messageData?.data?.Page;
                    return {space:p?.FleetList?.[__roundScene.space.guard]?.battle??null,
                        planet:p?.FlyingFleets?.[__roundScene.planet.attacker]?.battle??null};
                """)
                if now>=next_notice:
                    print(json.dumps({'waiting':round(scene['planet']['startAt']-now),**state}),flush=True);next_notice=now+15
                if now>scene['planet']['startAt']+10 and state['space'] is None and state['planet'] is None:break
                time.sleep(.15)
            else:raise AssertionError('Бои не завершились штатными ActualData')
            wire=self.js('return __roundWire');dom=self.js('return __roundDOM')
            for key in ['space','planet']:
                seen=sorted({int(row[key]['round']) for row in wire if row[key] and int(row[key]['round'])>0})
                dom_seen=sorted({int(row[key]) for row in dom if row[key] and ('раунд '+str(row[key])+'/10') in row[key+'Text']})
                self.record(('before-missing-progress-' if before else 'all-ten-rounds-pushed-')+key,
                    len(seen)<10 if before else seen==list(range(1,11)))
                print(json.dumps({'kind':key,'wire':seen,'DOM':dom_seen}),flush=True)
                if not before:self.record('all-ten-rounds-visible-'+key,dom_seen==list(range(1,11)))
            for monitor in monitors:
                rounds=sorted({int(b['round']) for b in monitor['wire'] if b and int(b['round'])>0})
                self.record(('before-other-owner-misses-' if before else 'other-owner-receives-')+monitor['field'],
                    len(rounds)<10 if before else rounds==list(range(1,11)))
                self.record('public-battle-fields-only-'+monitor['field'],all(b is None or sorted(b)==['phase','round','rounds_max'] for b in monitor['wire']))
                print(json.dumps({'otherOwner':monitor['field'],'rounds':rounds}),flush=True)
            self.record('same-DOM-through-rounds-and-result',len(dom)>1 and all(row['same'] for row in dom))
            self.record('no-page-requests-during-battle',self.js("return __roundSent.every(m=>m.mode==='connect'&&m.action==='ping')"))
            self.record('runtime-errors-zero',self.js('return __roundErrors.length===0'))
            reports=self.wait(lambda:(lambda r:r if len(r)==2 else None)(self.reports()),seconds=40)
            self.record('two-real-ten-round-draw-reports',all(r['ready']==1 and r['rounds']==10 and r['outcome']=='draw' for r in reports))
            if scene['phase']=='after3':self.record('round-push-does-not-slow-QA-battles',all(r['duration']<20 for r in reports))
            print(json.dumps({'reports':reports}),flush=True)
            self.js('clearInterval(__roundTimer)')
        finally:
            for m in monitors:
                if m['field']=='planet':
                    try:
                        q=m['client'];q.ws.settimeout(15)
                        q.request('fleets','planetselect',{'planetId':m['originalPlanet']})
                        end=time.monotonic()+15
                        while time.monotonic()<end:
                            reply=json.loads(q.ws.recv())
                            if reply.get('data',{}).get('PlanetList',{}).get('current_planet')==m['originalPlanet']:break
                    except Exception:pass
                m['client'].ws.close()


def scene_from_sql(q,phase):
    return q.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');$d=SPGame\Core\Database::getInstance();
        $phase=json_decode(stream_get_contents(STDIN),true)['phase'];$rows=$d->fetchAll('SELECT id,name FROM fleets WHERE name LIKE ?',['QA B044 %']);
        $names=array_column($rows,'id','name');$s=$d->fetchOne('SELECT id,state FROM space_battles WHERE state LIKE ?',['%QA B044 '.$phase.' space attacker%']);
        $p=$d->fetchOne('SELECT id,state FROM planet_battles WHERE state LIKE ?',['%QA B044 '.$phase.' planet attacker%']);$out=['phase'=>$phase];
        foreach(['space'=>$s,'planet'=>$p] as $kind=>$row){$b=json_decode($row['state'],true,512,JSON_THROW_ON_ERROR);
            $out[$kind]=['key'=>$row['id'],'uuid'=>$b['id'],'startAt'=>$b['startedAt'],'guard'=>$names['QA B044 '.$kind.' guard'],
                'attacker'=>$names['QA B044 '.$phase.' '.$kind.' attacker']];}echo json_encode($out);""",{'phase':phase})


def main():
    p=argparse.ArgumentParser();p.add_argument('--dev',action='store_true',required=True)
    p.add_argument('--phase',choices=['before','after','after2','after3'],required=True);p.add_argument('--prepare',action='store_true');args=p.parse_args()
    scene=prepare(args.phase) if args.prepare else None
    sid=http('/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome',
        'goog:chromeOptions':{'args':['--headless=new','--no-sandbox','--disable-dev-shm-usage']}}}})['sessionId']
    q=RoundCheck(sid)
    try:
        if scene is None:scene=scene_from_sql(q,args.phase)
        q.run(scene,args.phase=='before')
        print(json.dumps({'phase':args.phase,'passed':len(q.results)}))
    except Exception:
        print('FAIL phase='+q.phase,file=sys.stderr);raise
    finally:
        req=urllib.request.Request('http://127.0.0.1:4444/session/'+sid,method='DELETE');urllib.request.urlopen(req,timeout=20).close()


if __name__=='__main__':main()
