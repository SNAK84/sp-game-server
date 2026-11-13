"""B017: Logger + настоящий WebSocket/SPA основного dev, без второго мира.

python3 tests/CredentialLoggingLiveTest.py --dev --session ID
Меняет только авторизацию своего QA. Не создаёт аккаунты, не шлёт письма,
не отправляет игровые команды. Credentials и содержимое логов — только в RAM.
"""
import argparse
import datetime
import json
import secrets
import subprocess
import sys
import time
import uuid
import websocket

sys.path.insert(0, '/var/www/spa.snak84.ru/tools')
from check_fleet_mission_layout import Check


class CredentialCheck(Check):
    def __init__(self, session):
        self.base = '/session/' + session
        self.results = []
        self.phase = 'prepare'
        self.values = []
        self.console = []
        self.start = datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds').replace('+00:00', 'Z')
        self.login, self.password = json.loads(subprocess.check_output(['docker', 'exec', 'sp_game_qa_bot',
            'python3', '-c', "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"], text=True))
        assert self.login == 'qaown_260924'
        self.values.append(self.password)
        self.token = ''
        self.ws = None

    def record(self, name, passed=True):
        self.phase = name
        assert passed
        self.results.append({'name': name, 'passed': True})
        print('OK ' + name, flush=True)

    def php(self, source, data):
        p = subprocess.run(['docker', 'exec', '-i', '-w', '/var/www/html', 'swoole_app', 'php', '-r', source],
            input=json.dumps(data), text=True, capture_output=True, timeout=20)
        assert p.returncode == 0, 'PHP diagnostic failed'
        return json.loads(p.stdout)

    def clean(self, text):
        variants = []
        for value in self.values:
            if not value:
                continue
            variants.extend([value, json.dumps(value, ensure_ascii=True)[1:-1],
                json.dumps(value, ensure_ascii=False)[1:-1].replace('/', '\\/')])
        return not any(value in text for value in variants)

    def request(self, mode, action='', data=None, token=None):
        rid = str(uuid.uuid4())
        self.ws.send(json.dumps({'requestId': rid, 'mode': mode, 'action': action,
            'token': self.token if token is None else token, 'data': data or {}}))
        end = time.monotonic() + 15
        while time.monotonic() < end:
            message = json.loads(self.ws.recv())
            if message.get('requestId') == rid:
                return message
        raise RuntimeError('Missing reply')

    def raw_login(self):
        message = self.request('login', 'login', {'login': self.login, 'password': self.password}, token='')
        assert message.get('status') == 'ok' and len(message.get('token', '')) == 128
        self.token = message['token']
        self.values.append(self.token)

    def browser_login(self):
        self.wait(lambda: self.js("return typeof Page!=='undefined'&&Page?.Name==='LoginPageClass'&&WS.ws.readyState===1"))
        self.js("$('#LoginForm #login').val(arguments[0]).trigger('input');$('#LoginForm #password').val(arguments[1]).trigger('input');", self.login, self.password)
        self.click('#LoginForm #submit')
        self.wait(lambda: self.js("return Page?.Name==='OverViewPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0&&!$('#Content').is(':animated')&&!$('#layer').is(':animated')"))
        self.values.append(self.js('return Data.token'))

    def capture_console(self):
        self.console.extend(self.js('return __credentialLogs.map(e=>e.text)'))
        self.record('browser-console-contains-no-known-credentials', self.clean('\n'.join(self.console)))
        self.record('browser-runtime-errors-zero', self.js('return __credentialErrors.length===0'))

    def run(self):
        p = subprocess.run(['docker', 'exec', '-w', '/var/www/html', 'swoole_app', 'php', 'tests/CredentialLoggerProbe.php'],
            capture_output=True, text=True, timeout=20)
        assert p.returncode == 0
        probe = json.loads(p.stdout)
        assert probe['passed']
        self.record('logger-five-levels-nested-fields-trace-and-input-immutability')
        self.phase = 'private-credential-snapshot'
        private = self.php("""
            require 'vendor/autoload.php'; SPGame\\Core\\Environment::init('.env');
            $d=json_decode(stream_get_contents(STDIN),true);
            $r=SPGame\\Core\\Database::getInstance()->fetchOne('SELECT password FROM accounts WHERE login=?',[$d['login']]);
            echo json_encode($r);
        """, {'login': self.login})
        self.values.append(private['password'])
        fake = 'b017_' + secrets.token_hex(16)
        fake_token = secrets.token_hex(64)
        # Заведомо длиннее runtime email_pin (8 байт), совпадение исключено.
        bad_pin = 'b017_pin_' + secrets.token_hex(8)
        self.values.extend([fake, fake_token, bad_pin])

        self.ws = websocket.create_connection('ws://127.0.0.1:9501', timeout=15)
        self.raw_login(); self.record('real-login-contract-unchanged')
        self.record('token-authorizes-real-overview', self.request('overview').get('data', {}).get('Page', {}).get('page') == 'overview')
        self.ws.close(); self.ws = websocket.create_connection('ws://127.0.0.1:9501', timeout=15)
        self.record('same-token-after-new-connection', self.request('overview').get('status') == 'ok')
        self.record('invalid-token-rejected', self.request('overview', token=fake_token).get('error', {}).get('code') == 'invalid_token')
        self.record('missing-token-rejected', self.request('overview', token='').get('error', {}).get('code') == 'missing_token')
        self.record('invalid-registration-without-account-or-email', self.request('login', 'register',
            {'login': '_', 'password': fake, 'email': 'invalid'}).get('status') == 'error')
        self.record('invalid-pin-does-not-disclose-either-value', self.request('login', 'verify_email', {'PinCode': bad_pin}).get('status') == 'error')
        self.ws.send('{"password":' + json.dumps(fake) + ',"token":' + json.dumps(self.token) + ',')
        self.record('malformed-json-keeps-connection-usable', self.request('connect', 'ping').get('status') == 'ok')
        controlled = self.request('galaxy', data={
            'galaxy': 1, 'system': [], 'password': fake, 'nested': {'token': self.token}})
        self.expected_exception_id = controlled.get('requestId')
        self.record('controlled-handler-exception-is-contained', controlled.get('error', {}).get('code') == 'exception')
        self.record('overview-after-handler-exception', self.request('overview').get('status') == 'ok')
        previous = self.token
        self.record('real-logout-contract-unchanged', self.request('logout').get('status') == 'ok')
        self.record('logged-out-token-still-rejected', self.request('overview', token=previous).get('status') == 'error')
        self.raw_login(); self.record('relogin-rotates-token', self.token != previous)
        self.ws.close(); self.ws = None

        self.phase = 'browser-preload'
        self.call('/goog/cdp/execute', {'cmd': 'Page.addScriptToEvaluateOnNewDocument', 'params': {'source': """
            window.__credentialLogs=[];window.__credentialErrors=[];
            for(const level of ['log','debug','warn','error','info']){
                const original=console[level];console[level]=function(...args){
                    let text;try{text=args.map(x=>typeof x==='string'?x:JSON.stringify(x)).join(' ')}catch{text='[unserializable]'}
                    __credentialLogs.push({level,text});return original.apply(this,args);
                };
            }
            addEventListener('error',e=>__credentialErrors.push(e.message));
            addEventListener('unhandledrejection',e=>__credentialErrors.push(String(e.reason)));
        """}})
        self.phase = 'browser-navigation'
        self.call('/url', {'url': 'https://sp-game.ru/'})
        self.phase = 'browser-login'
        self.browser_login(); self.record('native-spa-login-and-overview')
        self.record('log-metadata-does-not-change-wire-or-input', self.js("""
            const m=new Message().setMode('login').setAction('login').setToken(arguments[0]).setData('password',arguments[0]);
            const safe=Message.toLog(m),plain=Message.toLog({mode:'login',data:{password:arguments[0]},token:arguments[0]});
            return !JSON.stringify([safe,plain]).includes(arguments[0])
                &&m.toJSON().token===arguments[0]&&m.getData('password')===arguments[0]
                &&safe.requestId===m.requestId;
        """, fake))
        self.js("""
            WS.ws.onmessage({data:'{"password":'+JSON.stringify(arguments[0])+',"token":'+JSON.stringify(Data.token)+','});
            const socket=WS.ws,count=WS.messageQueue.length;
            try{WS.ws={readyState:WebSocket.CLOSED};WS.send(new Message().setMode('login').setData('password',arguments[0]));}
            finally{WS.ws=socket;WS.messageQueue.splice(count);}
        """, fake)
        self.record('client-parse-error-and-offline-queue-log-only-metadata', self.js("return __credentialLogs.some(e=>e.text.startsWith('Ошибка парсинга данных'))&&__credentialLogs.some(e=>e.text.startsWith('📦 Добавлено в очередь:'))"))
        self.js("GameEvents.emit('SendServer',{mode:'fleets',NotOverlay:true,onSuccess:m=>ShowPage.Show(m.mode,m),...Page.getRequestOptions({latestKey:'b017-read'})})")
        self.wait(lambda: self.js("return Page?.Name==='FleetsPageClass'&&Page.$Content.Show&&WS.requestCallbacks.size===0"))
        self.record('real-fleet-page-response-without-full-message-dump')
        self.js("window.__credentialOldSocket=WS.ws;WS.ws.close(1000,'B017 reconnect check')")
        self.wait(lambda: self.js("return WS.ws!==__credentialOldSocket&&WS.ws.readyState===1&&Page?.Name==='FleetsPageClass'&&WS.requestCallbacks.size===0"), 40)
        self.record('real-spa-reconnect-preserves-token', self.js('return Data.token') == self.values[-1])
        self.capture_console()
        self.js("Page.setActiveMode('logout')")
        self.wait(lambda: self.js("return Page?.Name==='LoginPageClass'&&WS.requestCallbacks.size===0"))
        self.record('spa-logout-opens-login-existing-b040-not-changed')
        self.browser_login(); self.record('native-spa-relogin')
        self.capture_console()
        self.call('/refresh', {})
        self.wait(lambda: self.js("return Page?.Name==='OverViewPageClass'&&WS.ws.readyState===1&&WS.requestCallbacks.size===0&&Page.$Content.Show"))
        self.record('reload-keeps-authorized-session', self.js('return Data.token') == self.values[-1])
        self.capture_console()

        fresh_file = self.php("""
            $d=json_decode(stream_get_contents(STDIN),true);clearstatcache(true,$d['file']);
            if(filesize($d['file'])<$d['offset'])throw new RuntimeException('Log rotated: retry bounds required');
            echo json_encode(['text'=>file_get_contents($d['file'],false,null,$d['offset'])]);
        """, {'file': probe['file'], 'offset': probe['offset']})['text']
        p = subprocess.run(['docker', 'logs', '--timestamps', '--tail', '20000', 'swoole_app'], capture_output=True, text=True)
        docker_lines = [line for line in (p.stdout + p.stderr).splitlines() if line.split(' ', 1)[0] >= self.start]
        self.record('fresh-file-log-contains-no-known-credentials', self.clean(fresh_file))
        self.record('fresh-docker-log-contains-no-known-credentials', self.clean('\n'.join(docker_lines)))
        self.record('auth-and-error-diagnostics-remain', all(label in fresh_file for label in [
            'Авторизация', 'Invalid token', 'Invalid JSON received', 'Unhandled exception in WS handler']))
        unexpected = [line for line in docker_lines if any(x in line for x in ['[ERROR]', '[CRITICAL]', 'Fatal error'])
            and not ('Unhandled exception in WS handler' in line and self.expected_exception_id in line)]
        self.record('no-unexpected-server-errors', not unexpected)
        print(json.dumps({'passed': len(self.results), 'consoleEntries': len(self.console), 'dockerLines': len(docker_lines),
            'knownCredentialMatches': 0, 'loggerFields': probe['fields'], 'loggerLevels': probe['levels'],
            'expectedHandlerErrors': sum('Unhandled exception in WS handler' in line for line in docker_lines),
            'existingUndefinedKeyWarnings': sum('Undefined array key' in line for line in docker_lines)}, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dev', action='store_true', required=True)
    parser.add_argument('--session', required=True)
    args = parser.parse_args()
    check = None
    try:
        check = CredentialCheck(args.session)
        check.run()
    except Exception as error:
        # Ни traceback с локальными данными, ни сырой ответ Selenium не выводятся.
        print(json.dumps({'passed': False, 'phase': check.phase if check else 'init', 'errorType': type(error).__name__}), flush=True)
        raise SystemExit(1)
    finally:
        if check and check.ws:
            check.ws.close()
