"""B038: основной WS/SPA и QA-настройки, без изменения данных этим тестом.

python3 tests/EntitySettingsLiveTest.py --dev --session ID --efficiency 70
Нужна новая Selenium-сессия с пустой вкладкой: raw-login меняет QA-токен.
Параметр — ожидаемая эффективность рудника QA-планеты 26 после offline-подготовки.
Credentials берутся из QA-контейнера и обрабатываются только в памяти.
"""
import argparse
import json
import sys
import urllib.error
import websocket
from CredentialLoggingLiveTest import CredentialCheck


class SettingsCheck(CredentialCheck):
    def run_settings(self, efficiency):
        self.phase = 'fresh-browser-session'
        assert self.js("return location.href==='about:blank'||location.href==='data:,'"), 'Нужна новая пустая Selenium-сессия.'
        self.ws = websocket.create_connection('ws://127.0.0.1:9501', timeout=15)
        try:
            self.raw_login()
            self.record('raw-login')
            for mode in ['overview', 'buildings', 'researchs', 'shipyard', 'defense', 'fleets', 'galaxy', 'messages']:
                response = self.request(mode)
                self.record('real-page-' + mode, response.get('status') == 'ok' and bool(response.get('data', {}).get('Page')))
            for entity, expected in [(1, efficiency), (2, 100)]:
                info = self.request('information', 'get', {'id': entity}).get('data', {}).get('Page', {})
                self.record('raw-efficiency-' + str(entity), info.get('efficiency') == expected and info.get('planetId') == 26)
        finally:
            self.ws.close()
        self.phase = 'browser-navigation'
        self.call('/url', {'url': 'https://sp-game.ru/'})
        self.phase = 'browser-login'
        self.browser_login()
        self.record('native-spa-login')
        self.js("window.__settingsErrors=[];addEventListener('error',e=>__settingsErrors.push(e.message));addEventListener('unhandledrejection',e=>__settingsErrors.push(String(e.reason)))")
        self.js("Page.setActiveMode('buildings')")
        self.wait(lambda: self.js("return Data.mode==='buildings'&&Page?.messageData?.data?.Page?.page==='buildings'&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')"))
        self.record('visible-buildings', self.js("return $('#Content').is(':visible')&&document.querySelectorAll('.btn-box-info').length>0"))
        self.js("window.__settingsRender=InformationPage.render;InformationPage.render=function(c,i,h){window.__settingsInfo={planetId:i.planetId,efficiency:i.efficiency};return __settingsRender.call(this,c,i,h)}")
        for entity, expected in [(1, efficiency), (2, 100)]:
            self.js('window.__settingsInfo=null;InformationPage.show(arguments[0])', entity)
            self.wait(lambda: self.js("return __settingsInfo!==null&&WS.requestCallbacks.size===0&&!!document.querySelector('.InformationTable')"))
            self.record('spa-raw-efficiency-' + str(entity), self.js('return __settingsInfo.planetId===26&&__settingsInfo.efficiency===arguments[0]', expected))
            self.record('spa-dom-efficiency-' + str(entity), self.js("return $('.InformationPage .InformationNote').text().includes(arguments[0]+'%')", expected))
            self.js('Dialog.close()')
        self.record('browser-runtime-errors-zero', self.js('return __settingsErrors.length===0'))
        self.phase = 'browser-reload'
        self.call('/refresh', {})
        self.wait(lambda: self.js("return typeof Page!=='undefined'&&Data.mode==='buildings'&&Page?.messageData?.data?.Page?.page==='buildings'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0"))
        self.js('InformationPage.show(1)')
        self.wait(lambda: self.js("return !!document.querySelector('.InformationTable')&&WS.requestCallbacks.size===0"))
        self.record('reload-keeps-efficiency', self.js("return $('.InformationPage .InformationNote').text().includes(arguments[0]+'%')", efficiency))
        self.js('Dialog.close()')
        print(json.dumps({'passed': len(self.results), 'expectedEfficiency': efficiency, 'planetId': 26}), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--dev', action='store_true', required=True)
    parser.add_argument('--session', required=True)
    parser.add_argument('--efficiency', type=int, choices=[70, 100], required=True)
    args = parser.parse_args()
    checker = SettingsCheck(args.session)
    try:
        checker.run_settings(args.efficiency)
    except Exception as error:
        detail = {'failedAt': checker.phase, 'errorClass': type(error).__name__}
        if isinstance(error, urllib.error.HTTPError):
            try:
                detail['webdriverError'] = json.load(error).get('value', {}).get('error')
            except (ValueError, AttributeError):
                pass
        print(json.dumps(detail), flush=True)
        sys.exit(1)
