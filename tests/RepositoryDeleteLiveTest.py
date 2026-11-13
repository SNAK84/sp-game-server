"""B039: создание/расформирование только временного QA-флота в настоящем SPA.

--phase lifecycle требует новую пустую Selenium-сессию и подготовленный
qaown_260924: планета26, один малый транспорт и свободный слот базы.
--phase verify только читает после штатного restart/восстановления QA-fixture.
Credentials и токены обрабатываются только в памяти, SQL-записей здесь нет.
"""
import argparse
import json
import sys
import urllib.error
import websocket
from CredentialLoggingLiveTest import CredentialCheck


class DeleteCheck(CredentialCheck):
    created_id = 0

    def raw_fleets(self):
        response = self.request('fleets')
        assert response.get('status') == 'ok'
        assert response['data']['PlanetList']['current_planet'] == 26
        return response['data']['Page']

    def sql(self, fleet_id):
        return self.php(r"""require 'vendor/autoload.php';
            SPGame\Core\Environment::init('.env');
            $id=json_decode(stream_get_contents(STDIN),true)['id'];
            $db=SPGame\Core\Database::getInstance();
            echo json_encode(['fleet'=>$db->fetchOne('SELECT id,owner_id,home_planet_id FROM fleets WHERE id=?',[$id]),
                'ships'=>$db->fetchOne('SELECT id,fleet_id,small_ship_cargo_count FROM fleets_ships WHERE fleet_id=?',[$id]),
                'commands'=>$db->fetchAll('SELECT id FROM fleet_commands WHERE fleet_id=?',[$id]),
                'planetShips'=>(int)$db->fetchOne('SELECT small_ship_cargo FROM ships WHERE id=26')['small_ship_cargo']]);""", {'id': fleet_id})

    def setup_browser(self, phase):
        self.phase = 'browser-open'
        self.call('/goog/cdp/execute', {'cmd': 'Page.addScriptToEvaluateOnNewDocument', 'params': {
            'source': "window.__deleteErrors=[];addEventListener('error',e=>__deleteErrors.push(e.message));addEventListener('unhandledrejection',e=>__deleteErrors.push(String(e.reason)));"}})
        if phase == 'lifecycle':
            assert self.js("return location.href==='about:blank'||location.href==='data:,'"), 'Нужна новая пустая Selenium-сессия.'
            self.call('/url', {'url': 'https://sp-game.ru/'})
            self.browser_login()
        else:
            self.call('/refresh', {})
            self.wait(lambda: self.js("return typeof Page!=='undefined'&&Page?.pageActive&&Page?.Name!=='LoginPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js("Page.setActiveMode('fleets')")
        self.wait(lambda: self.js("return Data.mode==='fleets'&&!!Page?.messageData?.data?.Page?.FleetList&&typeof Page.$Fleets==='object'&&Page.$FleetsPlanet?.is(':visible')&&WS.requestCallbacks.size===0&&$('#Content').is(':visible')&&!$('#Content').is(':animated')"))
        self.token = self.js('return Data.token')
        self.ws = websocket.create_connection('ws://127.0.0.1:9501', timeout=15)
        self.record('native-spa-and-shared-token')

    def lifecycle(self):
        before = self.raw_fleets()
        self.record('qa-one-transport-ready', before['PlanetShips']['202']['count'] == 1)
        ids = set(before['FleetList'])
        self.js("Page.$Ships[202][0].Input.val(1).trigger('input');Page.renderNewFleetBlock()")
        self.record('create-button-enabled', self.js('return !Page.$ShipsBlock.NewFleetButtons.ToOrbit.prop("disabled")'))
        self.click('.NewFleetButtons .btn-success')
        self.wait(lambda: self.js("return Object.keys(Page.messageData.data.Page.FleetList).some(id=>!arguments[0].includes(id))", list(ids)))
        after = self.raw_fleets()
        added = set(after['FleetList']) - ids
        self.record('one-new-real-fleet', len(added) == 1)
        self.created_id = int(added.pop())
        self.record('new-fleet-ships', after['FleetList'][str(self.created_id)]['ships']['202']['count'] == 1)
        self.record('planet-ship-loaded-once', after['PlanetShips']['202']['count'] == 0)
        self.record('new-card-visible', self.js("let c=Page.$Fleets[arguments[0]];return !!c&&document.contains(c[0])&&c.is(':visible')", self.created_id))
        self.phase = 'ordinary-saver-persists-fleet'
        self.wait(lambda: self.sql(self.created_id)['fleet'] is not None and self.sql(self.created_id)['ships'] is not None, seconds=45)
        saved = self.sql(self.created_id)
        self.record('fleet-and-ships-durable-before-delete', saved['fleet']['owner_id'] == 9
            and saved['fleet']['home_planet_id'] == 26 and saved['ships']['small_ship_cargo_count'] == 1)
        self.record('planet-load-durable', saved['planetShips'] == 0)
        # Обычный WebDriver-клик по кнопке конкретной карточки, не вызов сервера напрямую.
        button = self.js('return Page.$Fleets[arguments[0]].Buttons.Disband[0]', self.created_id)
        self.call('/element/' + button['element-6066-11e4-a52e-4f735466cecf'] + '/click', {})
        self.wait(lambda: self.js("return !!document.querySelector('dialog-buttons .btn-danger')?.getClientRects().length"))
        self.click('dialog-buttons .btn-danger')
        self.wait(lambda: self.js('return !Page.messageData.data.Page.FleetList[arguments[0]]&&WS.requestCallbacks.size===0', self.created_id))
        after = self.raw_fleets()
        self.record('raw-ws-fleet-deleted', str(self.created_id) not in after['FleetList'])
        self.record('all-preexisting-fleets-preserved', set(after['FleetList']) == ids)
        self.record('dom-card-removed', self.js('return !Page.$Fleets[arguments[0]]', self.created_id))
        self.record('ship-returned-once', after['PlanetShips']['202']['count'] == 1)
        self.phase = 'ordinary-saver-deletes-fleet'
        def deleted_sql():
            saved = self.sql(self.created_id)
            return saved['fleet'] is None and saved['ships'] is None and saved['commands'] == [] and saved['planetShips'] == 1
        self.wait(deleted_sql, seconds=45)
        self.record('mysql-delete-complete', deleted_sql())
        self.call('/refresh', {})
        self.wait(lambda: self.js("return typeof Page!=='undefined'&&!!Page?.messageData?.data?.Page?.FleetList&&typeof Page.$Fleets==='object'&&Page.$FleetsPlanet?.is(':visible')&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.record('reload-no-resurrection', self.js('return !Page.messageData.data.Page.FleetList[arguments[0]]', self.created_id))
        self.record('reload-ship-returned-once', self.js("return Page.messageData.data.Page.PlanetShips[202].count===1"))

    def verify(self, fleet_id, expected_ships):
        self.created_id = fleet_id
        raw = self.raw_fleets()
        saved = self.sql(fleet_id)
        self.record('restart-raw-no-resurrection', str(fleet_id) not in raw['FleetList'])
        self.record('restart-dom-no-resurrection', self.js('return !Page.$Fleets[arguments[0]]&&!Page.messageData.data.Page.FleetList[arguments[0]]', fleet_id))
        self.record('restart-mysql-no-resurrection', saved['fleet'] is None and saved['ships'] is None and saved['commands'] == [])
        self.record('qa-ships-restored', raw['PlanetShips']['202']['count'] == expected_ships and saved['planetShips'] == expected_ships)
        for mode in ['overview', 'buildings', 'researchs', 'shipyard', 'defense', 'fleets', 'galaxy', 'messages']:
            response = self.request(mode)
            self.record('real-page-' + mode, response.get('status') == 'ok' and bool(response.get('data', {}).get('Page')))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--dev', action='store_true', required=True)
    parser.add_argument('--session', required=True)
    parser.add_argument('--phase', choices=['lifecycle', 'verify'], required=True)
    parser.add_argument('--fleet-id', type=int, default=0)
    parser.add_argument('--expected-ships', type=int, default=0)
    args = parser.parse_args()
    checker = DeleteCheck(args.session)
    try:
        checker.setup_browser(args.phase)
        if args.phase == 'lifecycle': checker.lifecycle()
        else:
            assert args.fleet_id > 0
            checker.verify(args.fleet_id, args.expected_ships)
        checker.record('browser-runtime-errors-zero', checker.js('return __deleteErrors.length===0'))
        print(json.dumps({'passed': len(checker.results), 'fleetId': checker.created_id, 'phase': args.phase}), flush=True)
    except Exception as error:
        detail = {'failedAt': checker.phase, 'errorClass': type(error).__name__, 'fleetId': checker.created_id}
        if isinstance(error, urllib.error.HTTPError):
            try: detail['webdriverError'] = json.load(error).get('value', {}).get('error')
            except (ValueError, AttributeError): pass
        print(json.dumps(detail), flush=True)
        sys.exit(1)
    finally:
        if checker.ws is not None: checker.ws.close()
