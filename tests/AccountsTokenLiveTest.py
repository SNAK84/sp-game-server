"""B009: обычные login/logout двух QA на основном dev-сервере, без SQL-записи.

python3 tests/AccountsTokenLiveTest.py --dev [after|restart]
restart ждёт Enter после сообщения READY_FOR_RESTART: сервер перезапускается
отдельно штатным менеджером. Токены/пароли остаются только в памяти процесса.
Новый мир или второй игровой сервер не создаются; игровые маршруты не меняются.
"""
import datetime
import json
import subprocess
import sys
import time
import uuid
import websocket

SQL = r'''
require 'vendor/autoload.php'; SPGame\Core\Environment::init('.env');
$expected=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=SPGame\Core\Database::getInstance(); $result=[];
foreach($expected as $login=>$token){
 if(!in_array($login,['qaown_260924','qafor_260924'],true))throw new LogicException('Not QA');
 $r=$db->fetchOne('SELECT id,token FROM accounts WHERE login=?',[$login]);
 $result[]=['id'=>$r['id'],'null'=>$r['token']===null,'empty'=>$r['token']==='',
   'length'=>strlen($r['token']??''),'matches'=>$token!==''&&hash_equals((string)$r['token'],$token)];
}
echo json_encode($result),PHP_EOL;
'''


def state(clients):
    p=subprocess.run(['docker','exec','-i','-w','/var/www/html','swoole_app','php','-r',SQL],
        input=json.dumps({c.login:c.token for c in clients}),text=True,capture_output=True)
    if p.returncode: raise RuntimeError('SQL snapshot failed')
    return json.loads(p.stdout)


def wait_state(clients, predicate):
    end=time.monotonic()+45
    while time.monotonic()<end:
        s=state(clients)
        if predicate(s): return s
        time.sleep(1)
    raise RuntimeError('Saver did not confirm expected state')


class Client:
    def __init__(self, container):
        p=subprocess.check_output(['docker','exec',container,'python3','-c',
            "import os,json;print(json.dumps([os.environ['SP_GAME_LOGIN'],os.environ['SP_GAME_PASSWORD']]))"],text=True)
        self.login,self.password=json.loads(p)
        if self.login not in ['qaown_260924','qafor_260924']: raise RuntimeError('Not QA')
        self.token=''
        self.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=10)

    def request(self, mode, action='', data=None, token=None):
        rid=str(uuid.uuid4())
        self.ws.send(json.dumps({'status':'ok','requestId':rid,'mode':mode,'action':action,
            'token':self.token if token is None else token,'data':data or {}}))
        end=time.monotonic()+15
        while time.monotonic()<end:
            answer=json.loads(self.ws.recv())
            if answer.get('requestId')==rid:return answer
        raise RuntimeError('Missing reply')

    def enter(self):
        answer=self.request('login','login',{'login':self.login,'password':self.password},token='')
        if answer.get('status')!='ok' or len(answer.get('token',''))!=128: raise RuntimeError('QA login failed')
        self.token=answer['token']

    def leave(self):
        answer=self.request('logout')
        if answer.get('status')!='ok' or answer.get('token'): raise RuntimeError('QA logout failed')

    def valid(self, token):
        answer=self.request('overview',token=token)
        return answer.get('status')=='ok' and answer.get('data',{}).get('Page',{}).get('page')=='overview'


def safe_log(start):
    p=subprocess.run(['docker','logs','--timestamps','--tail','12000','swoole_app'],capture_output=True,text=True)
    lines=(p.stdout+p.stderr).splitlines()
    errors=[line for line in lines if line.split(' ',1)[0]>=start and 'Accounts syncToDatabase error' in line]
    return [{'at':line.split(' ',1)[0],'emptyUnique':'uniq_token' in line and "Duplicate entry ''" in line} for line in errors]


if len(sys.argv) != 3 or sys.argv[1] != '--dev' or sys.argv[2] not in ['after', 'restart']:
    raise SystemExit('Use --dev after|restart (только основной dev-сервер и QA-аккаунты)')
phase=sys.argv[2]
clients=[]
start=datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')
try:
    for container in ['sp_game_qa_bot','sp_game_qa_foreign_bot']:
        c=Client(container);clients.append(c);c.enter()
    wait_state(clients,lambda rows:all(r['matches'] for r in rows))
    print(json.dumps({'phase':'logged-in','sql':state(clients)}),flush=True)
    old=[c.token for c in clients]
    for c in clients:c.leave()
    assert all(not c.valid(token) for c,token in zip(clients,old))
    assert not clients[0].valid('')
    rows=wait_state(clients,lambda rows:all(r['null'] for r in rows))
    assert not safe_log(start)
    print(json.dumps({'phase':'logout-saved','sql':rows,'oldRejected':True,'emptyRejected':True}),flush=True)
    if phase=='restart':
        for c in clients:c.ws.close()
        print('READY_FOR_RESTART',flush=True)
        input()
        for c,token in zip(clients,old):
            c.ws=websocket.create_connection('ws://127.0.0.1:9501',timeout=10)
            assert not c.valid(token) and not c.valid('')
        assert all(r['null'] for r in state(clients))
        print(json.dumps({'phase':'after-restart','nullPreserved':True,'oldRejected':True,'emptyRejected':True}),flush=True)
    for c in clients:
        previous=c.token;c.enter();assert c.token!=previous and c.valid(c.token) and not c.valid(previous)
        rotated=c.token;c.enter();assert c.token!=rotated and c.valid(c.token) and not c.valid(rotated)
    rows=wait_state(clients,lambda rows:all(r['matches'] for r in rows))
    assert not safe_log(start)
    print(json.dumps({'phase':'relogin','sql':rows,'tokensDistinct':clients[0].token!=clients[1].token}),flush=True)
finally:
    for c in clients:c.ws.close()
