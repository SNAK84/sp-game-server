"""B015: основной dev, QA-checkpoint и реальный SPA; без второго мира.

before читает старый прогноз; after сверяет новый и штатно отзывает explicit;
progress повторяет положительную проверку со шкалой после отдельного rearm;
complete проверяет завершение implicit на запасной планете, reload и SQL.
"""
import argparse
import json
import sys
import websocket
from CredentialLoggingLiveTest import CredentialCheck


class ProjectionCheck(CredentialCheck):
    def scene(self):
        return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');
            $db=SPGame\Core\Database::getInstance();$out=[];
            foreach($db->fetchAll("SELECT * FROM fleets WHERE name IN ('QA B015 implicit','QA B015 explicit') ORDER BY id") as $f){
                $f['commands']=$db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=? ORDER BY id',[$f['id']]);
                $out[$f['name']]=$f;
            }echo json_encode($out,JSON_THROW_ON_ERROR);""", {})

    def run(self, phase):
        scene = self.scene()
        self.implicit = int(scene['QA B015 implicit']['id'])
        self.explicit = int(scene['QA B015 explicit']['id'])
        if phase == 'before':
            self.call('/goog/cdp/execute', {'cmd':'Page.addScriptToEvaluateOnNewDocument','params':{
                'source':"window.__b015errors=[];addEventListener('error',e=>__b015errors.push(e.message));addEventListener('unhandledrejection',e=>__b015errors.push(String(e.reason)));"}})
            self.call('/url', {'url':'https://sp-game.ru/'})
            self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&WS.ws.readyState===1"))
            if self.js("return Page.Name==='LoginPageClass'"):
                self.browser_login()
        else:
            self.call('/refresh', {})
            self.wait(lambda:self.js("return typeof Page!=='undefined'&&Page?.pageActive&&Page.Name!=='LoginPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda:self.js("return Data.mode==='fleets'&&Page?.$FleetsPlanet?.is(':visible')&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.token=self.js('return Data.token')
        self.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=15)
        page=self.request('fleets')['data']['Page']
        self.record('native-spa-and-server-page')
        if phase in ('before','after','progress'):
            one=page['FlyingFleets'][str(self.implicit)]
            pending=[r for r in one['commands'] if r['status']=='pending']
            if phase=='before':
                self.record('old-forecast-points-to-lost-site',any(r['target'].get('siteId')==19 for r in pending if r['target']))
                self.record('old-control-contains-orphan-move',any(s.get('coordinates',{}).get('siteId')==19 for s in one['control']['steps']))
                print(json.dumps({'implicit':self.implicit,'explicit':self.explicit,'arrival':one['route_leg']['arrives_at'],
                    'missionEnd':one['mission_end_time'],'steps':one['control']['steps']}),flush=True)
            else:
                self.record('forecast-does-not-target-lost-site',not any(r['target'].get('siteId')==19 for r in pending if r['target']))
                self.record('home-unload-and-stay-projected',[r['type'] for r in pending]==[32,10,32,52])
                self.record('control-ends-with-single-return',one['control']['steps'][-1].get('kind')=='return'
                    and not any(s.get('coordinates',{}).get('siteId')==19 for s in one['control']['steps']))
                if phase=='after':
                    two=page['FlyingFleets'][str(self.explicit)]
                    self.record('explicit-target-preserved',any(r['target'].get('siteId')==19 for r in two['commands'] if r['target']))
                self.record('arrival-unchanged',one['route_leg']['arrives_at']==scene['QA B015 implicit']['commands'][0]['execute_time'])
                self.record('new-forecast-follows-arrival',0<=one['mission_end_time']-one['route_leg']['arrives_at']<120)
                self.record('projection-keeps-saved-command-rows',scene['QA B015 implicit']['commands']==self.scene()['QA B015 implicit']['commands'])
                repeated=self.request('fleets')['data']['Page']['FlyingFleets'][str(self.implicit)]
                self.record('projection-keeps-runtime-command-version',repeated['control']['version']==one['control']['version'])
                # Возвращаем контрольный explicit штатным действием, не исполняем его дальний путь.
                if phase=='after':
                    response=self.request('fleets','RecallFleet',{'fleetId':self.explicit})
                    self.record('explicit-control-recalled',response.get('status')=='ok')
                else:
                    progress=one['route_progress']
                    self.record('progress-uses-same-return-tail',progress['total']==2 and progress['boundaries']==[1,2]
                        and progress['active']['ends_at']==one['route_leg']['arrives_at'])
                print(json.dumps({'implicit':self.implicit,'arrival':one['route_leg']['arrives_at'],
                    'missionEnd':one['mission_end_time'],'projectedTargets':[r['target'] for r in pending]}),flush=True)
        else:
            self.record('implicit-finished-in-server',page['FleetList'][str(self.implicit)]['status']==0)
            self.record('implicit-no-longer-flying',str(self.implicit) not in page['FlyingFleets'])
            one=page['FleetList'][str(self.implicit)]
            self.record('composition-and-experience-kept',one['count']==1 and one['experience']==7)
            self.record('home-and-cargo-final',one['current']=={'type':1,'galaxy':1,'system':6,'orbit':2,'objectType':1}
                and one['fuel']==0 and all(v['count']==0 for v in one['resources'].values()))
            self.record('explicit-control-finished',page['FleetList'][str(self.explicit)]['status']==0)
            self.wait(lambda:all(int(row['status'])==0 and float(row['fuel_current'])==0 for row in self.scene().values()),seconds=45)
            self.record('ordinary-saver-finished',all(not any(int(c['executed'])==0 for c in row['commands']) for row in self.scene().values()))
            for mode in ['overview','galaxy','fleets']:
                response=self.request(mode)
                self.record('real-page-'+mode,response.get('status')=='ok' and bool(response.get('data',{}).get('Page')))
        self.ws.close();self.ws=None
        self.js("GameEvents.emit('SendServer',{mode:'fleets'})")
        self.wait(lambda:self.js("return WS.requestCallbacks.size===0&&(Page.messageData.data.Page.FleetList[arguments[0]]||Page.messageData.data.Page.FlyingFleets[arguments[0]])",str(self.implicit)))
        if phase!='complete':
            self.wait(lambda:self.js("return !!document.querySelector('[data-fleet-id=\"'+arguments[0]+'\"]')",str(self.implicit)))
            self.record('dom-matches-authoritative-commands',self.js("""
                const f=Page.messageData.data.Page.FlyingFleets[arguments[0]],rows=[...document.querySelectorAll('.FleetFlightCommandRow')];
                return f.commands.every(c=>rows.some(r=>r.dataset.commandKey===String(c.id)&&r.querySelector('.FleetFlightCommandTarget').textContent===Page.formatFleetCoordinate(c.target)));
            """,str(self.implicit)))
        else:
            self.record('idle-card-in-dom',self.js("return Page.$Fleets[arguments[0]]?.is(':visible')&&!document.querySelector('.FleetFlightCard[data-fleet-id=\"'+arguments[0]+'\"]')",str(self.implicit)))
        self.record('browser-runtime-errors-zero',self.js('return __b015errors.length===0'))


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--dev',action='store_true',required=True)
    parser.add_argument('--session',required=True);parser.add_argument('--phase',choices=['before','after','progress','complete'],required=True)
    args=parser.parse_args();check=ProjectionCheck(args.session)
    try:
        check.run(args.phase)
        print(json.dumps({'passed':len(check.results),'phase':args.phase}),flush=True)
    except Exception as error:
        print(json.dumps({'failedAt':check.phase,'errorClass':type(error).__name__}),flush=True);sys.exit(1)
    finally:
        if check.ws is not None:check.ws.close()
