"""B042: нагрузка штатными командами QA на основном сервере и настоящий SPA.

--phase stress требует новую пустую Selenium-сессию и исполненный QA-fixture
с именем Fleet122 «QA B042 large». После нагрузки возвращает прежнее имя через UI.
--phase finish позволяет отдельно завершить UI-проверку после последнего barrier.
--phase verify после restart только читает. Credentials/token — только в RAM.
"""
import argparse
import json
import sys
import urllib.error
import websocket
from CredentialLoggingLiveTest import CredentialCheck


class QueueCheck(CredentialCheck):
    def fleets(self):
        response = self.request('fleets')
        assert response.get('status') == 'ok' and response['data']['PlanetList']['current_planet'] == 26
        return response['data']['Page']

    def fleet_name(self):
        return self.fleets()['FleetList']['122']['name']

    def sql(self):
        return self.php(r"""require 'vendor/autoload.php';SPGame\Core\Environment::init('.env');
            $db=SPGame\Core\Database::getInstance();echo json_encode([
            'name'=>$db->fetchOne('SELECT name FROM fleets WHERE id=122')['name'],
            'snapshotRows'=>(int)$db->fetchOne('SELECT COUNT(*) AS n FROM player_queue_restart')['n']]);""", {})

    def setup(self, phase):
        self.phase = 'browser-open'
        self.call('/goog/cdp/execute', {'cmd': 'Page.addScriptToEvaluateOnNewDocument', 'params': {
            'source': "window.__queueErrors=[];addEventListener('error',e=>__queueErrors.push(e.message));addEventListener('unhandledrejection',e=>__queueErrors.push(String(e.reason)));"}})
        if phase == 'stress':
            assert self.js("return location.href==='about:blank'||location.href==='data:,'"), 'Нужна новая пустая Selenium-сессия.'
            self.call('/url', {'url': 'https://sp-game.ru/'})
            self.browser_login()
        else:
            self.call('/refresh', {})
            self.wait(lambda: self.js("return typeof Page!=='undefined'&&Page?.pageActive&&Page?.Name!=='LoginPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda: self.js("return Data.mode==='fleets'&&typeof Page?.$Fleets==='object'&&Page.$FleetsPlanet?.is(':visible')&&!!Page.$Fleets[122]&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.token = self.js('return Data.token')
        self.ws = websocket.create_connection('ws://127.0.0.1:9501', timeout=15)
        self.record('native-spa-and-shared-token')

    def stress(self, count):
        self.record('restored-large-event-executed', self.fleet_name() == 'QA B042 large')
        self.record('restart-snapshot-consumed', self.sql()['snapshotRows'] == 0)
        self.js('window.__queueFleetNode=Page.$Fleets[122][0]')
        acknowledged = barriers = 0
        while acknowledged < count:
            self.phase = 'selection-batch-' + str(acknowledged)
            for _ in range(min(50, count - acknowledged)):
                response = self.request('fleets', 'planetselect', {'planetId': 26})
                assert response.get('status') == 'ok' and response.get('mode') == 'none'
                acknowledged += 1
            name = 'QA B042 barrier ' + str(acknowledged)
            response = self.request('fleets', 'RenameFleet', {'FleetId': 122, 'Name': name})
            assert response.get('status') == 'ok'
            self.wait(lambda: self.fleet_name() == name, seconds=15)
            barriers += 1
            if acknowledged % 250 == 0 or acknowledged == count:
                print(json.dumps({'selectionAcks': acknowledged, 'executedBarriers': barriers}), flush=True)
        self.record('commands-beyond-sync-capacity', acknowledged == count and count > 2048)
        self.record('ordered-barriers-complete', barriers == (count + 49) // 50)
        self.bind_browser(name)
        self.record('same-card-still-connected', self.js("return Page.$Fleets[122][0]===__queueFleetNode&&document.contains(__queueFleetNode)&&Page.$Fleets[122].is(':visible')"))
        self.restore_ui()
        return {'selectionAcks': acknowledged, 'executedBarriers': barriers, 'publicCommands': acknowledged + barriers + 1}

    def bind_browser(self, name):
        # Текущий сервер хранит один Accounts.frame: raw-диагностика не должна
        # перехватывать ActualData у проверяемого SPA во время нажатий в UI.
        if self.ws is not None:
            self.ws.close()
            self.ws = None
        self.js("GameEvents.emit('SendServer',{mode:'fleets'})")
        self.wait(lambda: self.js("return Page.messageData.data.Page.FleetList[122].name===arguments[0]&&Page.$Fleets[122].Name.text()===arguments[0]&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')", name))

    def restore_ui(self):
        button = self.js("return Page.$Fleets[122].find('.FleetOrbitRename')[0]")
        self.call('/element/' + button['element-6066-11e4-a52e-4f735466cecf'] + '/click', {})
        self.wait(lambda: self.js("return !!document.querySelector('.FleetNameInput')?.getClientRects().length"))
        self.js("$('.FleetNameInput').val('QA B004 source').trigger('input')")
        self.click('dialog-buttons .btn-info')
        self.wait(lambda: self.js("return Page.messageData.data.Page.FleetList[122].name==='QA B004 source'&&Page.$Fleets[122].Name.text()==='QA B004 source'&&WS.requestCallbacks.size===0"))
        self.record('native-ui-command-after-stress', self.js("return Page.$Fleets[122].Name.text()==='QA B004 source'"))
        self.phase = 'ordinary-saver'
        self.wait(lambda: self.sql()['name'] == 'QA B004 source', seconds=45)
        self.record('original-name-durable', self.sql()['name'] == 'QA B004 source')
        self.call('/refresh', {})
        self.wait(lambda: self.js("return typeof Page!=='undefined'&&typeof Page?.$Fleets==='object'&&Page.$FleetsPlanet?.is(':visible')&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.record('reload-keeps-original-name', self.js("return Page.messageData.data.Page.FleetList[122].name==='QA B004 source'"))

    def finish(self, count):
        name = 'QA B042 barrier ' + str(count)
        self.record('last-stress-barrier-confirmed', self.fleet_name() == name)
        self.js('window.__queueFleetNode=Page.$Fleets[122][0]')
        self.bind_browser(name)
        self.record('same-card-after-browser-rebind', self.js('return Page.$Fleets[122][0]===__queueFleetNode&&document.contains(__queueFleetNode)'))
        self.restore_ui()
        return {}

    def verify(self):
        self.record('restart-name-and-snapshot', self.sql() == {'name': 'QA B004 source', 'snapshotRows': 0})
        self.record('restart-ws-name', self.fleet_name() == 'QA B004 source')
        self.record('restart-dom-name', self.js("return Page.$Fleets[122].Name.text()==='QA B004 source'"))
        for mode in ['overview', 'buildings', 'researchs', 'shipyard', 'defense', 'fleets', 'galaxy', 'messages']:
            response = self.request(mode)
            self.record('real-page-' + mode, response.get('status') == 'ok' and bool(response.get('data', {}).get('Page')))
        self.bind_browser('QA B004 source')
        return {}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--dev', action='store_true', required=True)
    parser.add_argument('--session', required=True)
    parser.add_argument('--phase', choices=['stress', 'finish', 'verify'], required=True)
    parser.add_argument('--events', type=int, default=2200)
    args = parser.parse_args()
    checker = QueueCheck(args.session)
    try:
        assert args.events >= 2200
        checker.setup(args.phase)
        result = checker.stress(args.events) if args.phase == 'stress' else (checker.finish(args.events) if args.phase == 'finish' else checker.verify())
        checker.record('browser-runtime-errors-zero', checker.js('return __queueErrors.length===0'))
        print(json.dumps({'passed': len(checker.results), 'phase': args.phase, **result}), flush=True)
    except Exception as error:
        detail = {'failedAt': checker.phase, 'errorClass': type(error).__name__}
        if isinstance(error, urllib.error.HTTPError):
            try: detail['webdriverError'] = json.load(error).get('value', {}).get('error')
            except (ValueError, AttributeError): pass
        print(json.dumps(detail), flush=True)
        sys.exit(1)
    finally:
        if checker.ws is not None: checker.ws.close()
