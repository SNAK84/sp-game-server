#!/usr/bin/env python3
"""Команды v2 на настоящем dev: QA-переименование, повторы, потеря ответа и restart.

python3 qa/check_protocol_v2_commands.py --execute [--restart-game]
Меняет только имя одной планеты qaown_260924 и возвращает исходное имя.
"""
import argparse
import json
from pathlib import Path
import subprocess

from jsonschema import Draft202012Validator
from referencing import Registry, Resource

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--execute', action='store_true', required=True)
parser.add_argument('--restart-game', action='store_true')
args = parser.parse_args()


def grant(login):
    source = r'''<?php
require 'src/bootstrap.php';
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$q=$pdo->prepare("SELECT account_id FROM legacy_account_links WHERE source_key='sp-game' AND source_login=?");
$q->execute([LOGIN]);$id=$q->fetchColumn();
$a=(new SPGame\Cabinet\Repositories\SessionRepository($pdo))->create($id,'native');
echo json_encode((new SPGame\Cabinet\Services\WorldService($pdo))->start($a['accessToken'],'sp-game-dev'));
'''.replace('LOGIN', json.dumps(login))
    return json.loads(subprocess.run(['docker', 'exec', '-i', 'sp_game_cabinet', 'php'],
                                    input=source, text=True, capture_output=True, check=True).stdout)


seed = {'owner': grant('qaown_260924'), 'foreign': grant('qafor_260924')}
code = r'''
import asyncio,copy,json,time,uuid
from app.protocol_v2 import ProtocolV2Client
from app.ws_client import GameClientError
seed=__SEED__;records=[];requests=[];checks=0
def check(value):
 global checks
 assert value
 checks+=1
async def log(event,fields):
 if event=='request_sent' and fields['request'].get('v')==2:
  p=copy.deepcopy(fields['request'])
  if p['type']=='auth':p['data']['gameToken']='0'*128
  requests.append(p)
 if event in ('response_received','unsolicited_response'):
  p=fields.get('response',{})
  if p.get('v')==2:records.append(p)
async def expect_error(c,command,expected):
 try:
  response=await c._call('command',**command)
  if expected=='command.invalid_input' and response.get('data',{}).get('state')=='rejected':check(True);return
 except GameClientError as e:check(str(e)==expected)
 else:raise AssertionError('Rejected command was accepted')
async def terminal(c,command,ack):
 if ack['data']['state']!='accepted':return ack['data']
 status=await c.command_status(command['requestId'])
 if status['state'] in ('applied','rejected'):return status
 _,p=await c.wait_for_unsolicited(lambda p:p.get('type')=='commandResult' and p.get('requestId')==command['requestId'],after=0,timeout=8)
 return p['data']
async def main():
 c=ProtocolV2Client(seed['owner']['websocketUrl'],world_id=seed['owner']['worldId'],log=log);c.token=seed['owner']['gameToken']
 f=ProtocolV2Client(seed['foreign']['websocketUrl'],world_id=seed['foreign']['worldId'],log=log);f.token=seed['foreign']['gameToken']
 original=None;planet=None
 try:
  await c.connect();await f.connect()
  first=await c.read_planets();planet=first['value']['mainPlanetId'];original=next(p['name'] for p in first['value']['items'] if p['id']==planet)
  selected=first['value']['selectedPlanetId']
  foreign=(await f.read_planets())['value']['mainPlanetId']
  a=c.prepare_command('planet.rename',{'planetId':planet},{'name':'Protocol QA A'})
  before=c.push_checkpoint();ack=await c.submit_command(a);check(ack['type'] in ('commandAccepted','commandResult'))
  check((await terminal(c,a,ack))['state']=='applied')
  check((await c.command_status(a['requestId']))['outcome']['result']['planetId']==planet)
  check((await f.command_status(a['requestId']))['outcome']['reason']=='not_found')
  b=c.prepare_command('planet.rename',{'planetId':planet},{'name':'Protocol QA B'})
  check((await terminal(c,b,await c.submit_command(b)))['state']=='applied')
  # Повтор A после B не должен вернуть имя A: это проверка реального эффекта, не только ACK.
  check((await c.submit_command(a))['data']['state']=='applied')
  current=await c.read_planets();check(next(p['name'] for p in current['value']['items'] if p['id']==planet)=='Protocol QA B')
  check(current['value']['selectedPlanetId']==selected)
  for changed in [dict(a,data={'name':'Protocol QA C'}),dict(a,context={'planetId':foreign}),dict(a,issuedAt=a['issuedAt']+1)]:
   await expect_error(c,changed,'command.id_conflict')
  for context,expected in [({'planetId':foreign},'resource.unavailable'),({'planetId':'18446744073709551615'},'resource.unavailable'),({'planetId':int(planet)},'command.invalid_input')]:
   await expect_error(c,c.prepare_command('planet.rename',context,{'name':'Protocol QA C'}),expected)
  await expect_error(c,c.prepare_command('planet.rename',{'planetId':planet},{'name':'<bad>'}),'command.invalid_input')
  expired=c.prepare_command('planet.rename',{'planetId':planet},{'name':'Protocol QA C'});expired['issuedAt']-=600
  await expect_error(c,expired,'command.expired')
  wrong_epoch=c.prepare_command('planet.rename',{'planetId':planet},{'name':'Protocol QA C'});wrong_epoch['serverEpoch']='0'*32
  await expect_error(c,wrong_epoch,'command.stale_epoch')
  # Разные ID одного payload — две самостоятельные команды.
  second=dict(b,requestId=str(uuid.uuid4()))
  check((await terminal(c,second,await c.submit_command(second)))['state']=='applied')
  check((await c.command_status(second['requestId']))['commandId']!=b['requestId'])
  # Отправляем и закрываем сокет, не дожидаясь ответа. После reconnect только status, не replay.
  lost=c.prepare_command('planet.rename',{'planetId':planet},{'name':'Protocol QA Lost'})
  await c.ws.send(json.dumps({'v':2,'type':'command','worldId':c.world_id,**lost}))
  await c.close();await c.connect()
  status=await c.command_status(lost['requestId'])
  if status['state']=='accepted':
   _,p=await c.wait_for_unsolicited(lambda p:p.get('type')=='commandResult' and p.get('requestId')==lost['requestId'],after=0,timeout=8);status=p['data']
  check(status['state']=='applied')
  check(next(p['name'] for p in (await c.read_planets())['value']['items'] if p['id']==planet)=='Protocol QA Lost')
  if __RESTART__:
   old_epoch=c.server_epoch;await c.close();await f.close()
   print('READY_FOR_COMMAND_RESTART',flush=True)
   for i in range(90):
    try:
     await c.connect()
     if c.server_epoch!=old_epoch:break
    except GameClientError:pass
    await c.close();await asyncio.sleep(1)
   check(c.server_epoch!=old_epoch)
   status=await c.command_status(lost['requestId']);check(status['state']=='unknown' and status['outcome']['reason']=='runtime_changed')
   check((await c._call('command',**lost))['data']['state']=='unknown')
   check(next(p['name'] for p in (await c.read_planets())['value']['items'] if p['id']==planet)=='Protocol QA Lost')
  restore=c.prepare_command('planet.rename',{'planetId':planet},{'name':original})
  check((await terminal(c,restore,await c.submit_command(restore)))['state']=='applied')
  original=None
  check((await c.command_status(str(uuid.uuid4())))['outcome']['reason']=='not_found')
 finally:
  if original and c.authenticated:
   restore=c.prepare_command('planet.rename',{'planetId':planet},{'name':original})
   await terminal(c,restore,await c.submit_command(restore))
  for client in [c,f]:
   if client.authenticated:
    try:await client.logout()
    except GameClientError:pass
   await client.close()
 print(json.dumps({'checks':checks,'responses':records,'requests':requests}))
asyncio.run(main())
'''.replace('__SEED__', repr(seed)).replace('__RESTART__', repr(args.restart_game))

process = subprocess.Popen(['docker', 'exec', '-i', 'sp_game_qa_bot', 'python', '-'],
                           stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
process.stdin.write(code)
process.stdin.close()
if args.restart_game:
    marker = process.stdout.readline().strip()
    assert marker == 'READY_FOR_COMMAND_RESTART', process.stderr.read()[-2400:] if not marker else marker
    print(marker, flush=True)
    subprocess.run(['timeout', '50', './swoole_manager.sh', 'restart'], cwd='/opt/sp-game',
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=55)
output = process.stdout.read()
error = process.stderr.read()
if process.wait(timeout=180) != 0:
    raise RuntimeError(error[-2400:])
proof = json.loads(output)
schemas = [json.loads(p.read_text()) for p in Path('/opt/sp-game/docs/protocol-v2').glob('*.schema.json')]
registry = Registry().with_resources((s['$id'], Resource.from_contents(s)) for s in schemas)
schema = next(s for s in schemas if s['$id'].endswith(':response'))
validator = Draft202012Validator(schema, registry=registry)
for packet in proof['responses']:
    validator.validate(packet)
print('LIVE_V2_COMMANDS_OK', json.dumps({'checks': proof['checks'], 'validatedResponses': len(proof['responses']),
                                      'restart': args.restart_game, 'restoredQaName': True}))
