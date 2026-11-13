#!/usr/bin/env python3
"""Полный живой baseline v2 на текущем dev. v1 обязан отклоняться.

python3 qa/check_protocol_v2.py --execute [--restart-game]
Меняет и восстанавливает имя QA-планеты и QA-флота; остальные аккаунты не меняет.
"""
import argparse
import json
import base64
import gzip
from pathlib import Path
import subprocess

from jsonschema import Draft202012Validator
from referencing import Registry, Resource

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--execute',action='store_true',required=True)
parser.add_argument('--restart-game',action='store_true')
parser.add_argument('--direct',action='store_true',help='Только QA: существующий локальный endpoint без внешнего DNS/прокси')
args=parser.parse_args()
seed=r'''<?php
require 'src/bootstrap.php';
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$id=$pdo->query("SELECT account_id FROM legacy_account_links WHERE source_key='sp-game' AND source_login='qaown_260924'")->fetchColumn();
$a=(new SPGame\Cabinet\Repositories\SessionRepository($pdo))->create($id,'native');
echo json_encode((new SPGame\Cabinet\Services\WorldService($pdo))->start($a['accessToken'],'sp-game-dev'));
'''
grant=json.loads(subprocess.run(['docker','exec','-i','sp_game_cabinet','php'],input=seed,text=True,capture_output=True,check=True).stdout)
if args.direct:grant['websocketUrl']='ws://host.docker.internal:9501/'
foreign=r'''<?php
require 'vendor/autoload.php';SPGame\Core\Environment::init('/var/www/html/.env');
$db=SPGame\Core\Database::independent();
$planet=$db->fetchOne('SELECT p.id FROM planets p JOIN users u ON p.owner_id=u.id WHERE u.account_id<>15 AND p.planet_type=1 LIMIT 1');
$planet['fleetId']=$db->fetchOne('SELECT id FROM fleets WHERE owner_id<>9 LIMIT 1')['id']??null;
echo json_encode($planet);
'''
foreign_data=json.loads(subprocess.run(['docker','exec','-i','swoole_app','php'],input=foreign,text=True,capture_output=True,check=True).stdout)
foreign_id=str(foreign_data['id'])
code=r'''
import asyncio,copy,json,uuid,base64,gzip,time
import websockets
from app.protocol_v2 import ProtocolV2Client
from app.ws_client import GameClient,GameClientError,GameSessionEndedError
from app.operations import CATALOG
grant=__GRANT__;foreign=__FOREIGN__;foreign_fleet=__FOREIGN_FLEET__;checks=0;records=[];requests=[];collect=True;queue_checks=0
def check(value):
 global checks
 assert value
 checks+=1
async def log(event,fields):
 if event=='request_sent':
  p=copy.deepcopy(fields['request']);check(p.get('v')==2 and not set(p)&{'mode','action','token','password'})
  if p['type']=='auth':p['data']['gameToken']='0'*128
  if collect:requests.append(p)
 if event in ('response_received','unsolicited_response','resource_received'):
  p=fields.get('response',{})
  if p.get('v')==2:records.append(p)
async def done(c,packet):
 ack=await c.submit_command(packet)
 if ack['data']['state']!='accepted':return ack['data']
 status=await c.command_status(packet['requestId'])
 if status['state'] in ('applied','rejected'):return status
 _,r=await c.wait_for_unsolicited(lambda p:p.get('type')=='commandResult' and p.get('requestId')==packet['requestId'],after=0,timeout=10)
 return r['data']
async def main():
 global collect,queue_checks
 c=ProtocolV2Client(grant['websocketUrl'],world_id=grant['worldId'],log=log);c.token=grant['gameToken']
 original=None;planet=None
 try:
  async with websockets.connect(grant['websocketUrl']) as raw:
   await raw.send(json.dumps({'mode':'overview','requestId':str(uuid.uuid4())}))
   r=json.loads(await raw.recv());records.append(r);check(r['error']['code']=='protocol.unsupported_version' and r['error']['fatal'])
  await c.connect();check(c.authenticated and len(c.capabilities['commands'])==30)
  first=await c.read_planets();planet=first['value']['selectedPlanetId'];home=next(p for p in first['value']['items'] if p['id']==planet);original=home['name']
  second=await c.read_planets();check(second['revision']==first['revision'])
  fields={'fleet.navigation.get':{'galaxy':home['coordinate']['galaxy'],'system':home['coordinate']['system']},
          'galaxy.system.get':{'galaxy':home['coordinate']['galaxy'],'system':home['coordinate']['system']},
          'fleet.missions.get':{'fleetId':'0','coordinates':{'type':1,'galaxy':home['coordinate']['galaxy'],'system':home['coordinate']['system'],'orbit':home['coordinate']['orbit'],'objectType':1}},
          'information.get':{'id':'1'},'combat.report.get':{'id':'0'*32},'combat.reports.get':{'page':1}}
  for name in CATALOG['queries']:
   try:
    response=await c.query(name,{'planetId':planet},fields.get(name,{}))
    check(response['data']['full'] is True and response['data']['context']['planetId']==planet)
   except GameClientError as e:
    check(name=='combat.report.get' and str(e)=='query.unavailable')
  collect=False
  if foreign_fleet:
   try:await c.query('fleet.navigation.get',{'planetId':planet},{'fleetId':foreign_fleet})
   except GameClientError as e:check(str(e)=='resource.unavailable')
   else:raise AssertionError('Foreign source fleet exposed')
   packet=c.prepare_command('fleet.ships.transfer',{'planetId':planet},{'fromFleetId':foreign_fleet,'toFleetId':'0','shipId':'202','count':1})
   try:await c.submit_command(packet)
   except GameClientError as e:check(str(e)=='resource.unavailable')
   else:raise AssertionError('Foreign ships transfer accepted')
  for context,expected in [({'planetId':foreign},'resource.unavailable'),({'planetId':'18446744073709551615'},'resource.unavailable'),({'planetId':int(planet)},'query.invalid_input'),({'playerId':'1'},'query.invalid_input')]:
   try:await c.query('buildings.get',context)
   except GameClientError as e:check(str(e)==expected)
   else:raise AssertionError('Invalid context accepted')
  # Каждая зарегистрированная команда проходит единый барьер владения до публикации.
  for name,(_,_,fields_for_command) in CATALOG['commands'].items():
   values={key:('2147483646' if key in ['id','FleetId','fleetId','shipId','fromFleetId','toFleetId','targetId','groupId'] else
      [] if key in ['ReadId','Ids'] else {'version':'0'*64,'steps':[{'kind':'return','speedFactor':10}]} if key=='plan' else {} if key in ['items','resources','coordinates','group','mining'] else
      False if key in ['enabled','useCargoFuel','refuel'] else str(uuid.uuid4()) if key=='scanToken' else
      1 if key in ['count','missionType','speedFactor','fuel','duration'] else 'QA') for key in fields_for_command}
   if name.startswith('combat.report.'):values['id']='0'*32
   packet=c.prepare_command(name,{'planetId':foreign},values)
   try:await c.submit_command(packet)
   except GameClientError as e:check(str(e)=='resource.unavailable')
   else:raise AssertionError('Foreign command accepted: '+name)
  collect=True
  sub=str(uuid.uuid4())
  await c._call('subscribe',name='overview.get',context={'planetId':planet},data={},subscriptionId=sub)
  change=c.prepare_command('planet.rename',{'planetId':planet},{'name':'Protocol Full QA'})
  check((await done(c,change))['state']=='applied')
  for _ in range(50):
   if any(p.get('type')=='resource' and p.get('subscriptionId')==sub for p in records):break
   await asyncio.sleep(.1)
  check(any(p.get('type')=='resource' and p.get('subscriptionId')==sub for p in records))
  check((await c.submit_command(change))['data']['state']=='applied')
  await c._call('unsubscribe',subscriptionId=sub)
  old_epoch=c.server_epoch;old_session=c.identity['sessionId'];await c.close();await c.connect()
  check(c.identity['sessionId']==old_session and c.server_epoch==old_epoch)
  check((await c.command_status(change['requestId']))['state']=='applied')
  if __RESTART__:
   await c.close();print('READY_FOR_GAME_RESTART',flush=True)
   deadline=time.monotonic()+240
   while time.monotonic()<deadline:
    try:
     await c.connect()
     if c.authenticated and c.server_epoch and c.server_epoch!=old_epoch:break
    except GameSessionEndedError:raise
    except GameClientError:pass
    await c.close();await asyncio.sleep(1)
   else:raise TimeoutError('Dev-сервер не подтвердил новый авторизованный epoch за240с после запроса restart')
   check(c.authenticated and c.server_epoch and c.server_epoch!=old_epoch and c.identity['sessionId']==old_session)
   check((await c.command_status(change['requestId']))['outcome']['reason']=='runtime_changed')
   check((await c._call('command',**change))['data']['state']=='unknown')
   check(next(p['name'] for p in (await c.read_planets())['value']['items'] if p['id']==planet)=='Protocol Full QA')
  restore=c.prepare_command('planet.rename',{'planetId':planet},{'name':original});check((await done(c,restore))['state']=='applied');original=None
  await c.close()
  # Штатный клиент Runner также обязан использовать только v2.
  bot=GameClient(grant['websocketUrl'],world_id=grant['worldId'],log=log);bot.token=grant['gameToken']
  try:
   await bot.connect()
   for mode in ['overview','buildings','researchs','shipyard','defense','galaxy','fleets','messages','friends']:
    data={'galaxy':home['coordinate']['galaxy'],'system':home['coordinate']['system']} if mode=='galaxy' else {}
    r=await bot.request(mode,'ActualData',data);check(r['status']=='ok' and r['data']['PlanetList']['current_planet']==int(planet))
   rows=lambda value:list(value.values()) if isinstance(value,dict) else (value or [])
   for mode,list_key in [('buildings','BuildList'),('researchs','ResearchList')]:
    page=(await bot.request(mode,'ActualData'))['data']['Page']
    candidates=[x for x in rows(page.get(list_key)) if x.get('accessible') and not x.get('working')
      and x.get('elementTime',0)>=60 and all(isinstance(v,(int,float)) and v<=0 for v in rows(x.get('costOverflow')))]
    if page['CountQueue']>=page['MaxQueue'] or not candidates:continue
    if mode=='buildings' and page['field_used']>=page['field_current']:continue
    element=candidates[0];previous={int(x['qid']) for x in rows(page['QueueList'])};qid=None
    try:
     started=(await bot.request(mode,'build',{'id':element['id']}))['data']['Page']
     created=[x for x in rows(started['QueueList']) if int(x['qid']) not in previous]
     check(len(created)==1);qid=int(created[0]['qid'])
     cancelled=(await bot.request(mode,'cancel',{'id':qid}))['data']['Page']
     check(qid not in {int(x['qid']) for x in rows(cancelled['QueueList'])});qid=None;queue_checks+=1
    finally:
     if qid is not None:await bot.request(mode,'cancel',{'id':qid})
   fleet_data=(await bot.request('fleets','ActualData'))['data']['Page']['FleetList']
   fleets=list(fleet_data.values()) if isinstance(fleet_data,dict) else fleet_data
   fleet=next((f for f in fleets if f.get('name')),None)
   if fleet:
    try:
     await bot.request('fleets','RenameFleet',{'FleetId':fleet['id'],'Name':'Protocol Fleet QA'})
     renamed=(await bot.request('fleets','ActualData'))['data']['Page']['FleetList']
     check(any(f.get('id')==fleet['id'] and f.get('name')=='Protocol Fleet QA' for f in (renamed.values() if isinstance(renamed,dict) else renamed)))
    finally:await bot.request('fleets','RenameFleet',{'FleetId':fleet['id'],'Name':fleet['name']})
   await bot.request('logout')
  finally:await bot.close()
 finally:
  if original and c.authenticated:
   restore=c.prepare_command('planet.rename',{'planetId':planet},{'name':original});await done(c,restore)
  await c.close()
 proof={'checks':checks,'responses':records,'requests':requests,'queueChecks':queue_checks}
 print(base64.b64encode(gzip.compress(json.dumps(proof,separators=(',',':')).encode(),mtime=0)).decode(),flush=True)
asyncio.run(main())
'''.replace('__GRANT__',repr(grant)).replace('__FOREIGN__',repr(foreign_id)).replace('__FOREIGN_FLEET__',repr(str(foreign_data['fleetId']) if foreign_data['fleetId'] else None)).replace('__RESTART__',repr(args.restart_game))
process=subprocess.Popen(['docker','exec','-i','sp_game_qa_bot','python','-'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
process.stdin.write(code);process.stdin.close()
if args.restart_game:
 marker=process.stdout.readline().strip()
 assert marker=='READY_FOR_GAME_RESTART',process.stderr.read()[-2500:] if not marker else marker
 print(marker,flush=True)
 subprocess.run(['timeout','50','./swoole_manager.sh','restart'],cwd='/opt/sp-game',stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=55)
output=process.stdout.read();error=process.stderr.read()
if process.wait(timeout=240)!=0:raise RuntimeError(error[-2500:])
proof=json.loads(gzip.decompress(base64.b64decode(output.strip(),validate=True)))
schemas=[json.loads(p.read_text()) for p in Path('/opt/sp-game/docs/protocol-v2').glob('*.schema.json')]
registry=Registry().with_resources((s['$id'],Resource.from_contents(s)) for s in schemas)
validators={s['$id'].rsplit(':',1)[1]:Draft202012Validator(s,registry=registry) for s in schemas}
for direction,packets in [('response',proof['responses']),('request',proof['requests'])]:
 for packet in packets:
  error=next(validators[direction].iter_errors(packet),None)
  if error:raise RuntimeError(f"Schema {direction}: type={packet.get('type')} name={packet.get('name')} path={list(error.path)} validator={error.validator}")
print('LIVE_V2_FULL_BASELINE_OK',json.dumps({'checks':proof['checks'],'validatedResponses':len(proof['responses']),'validatedRequests':len(proof['requests']),'restart':args.restart_game,'v1Rejected':True,'qaNamesRestored':True,'queueRoundTrips':proof['queueChecks']}))
