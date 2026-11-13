#!/usr/bin/env python3
"""Реальный рейс v2 только qaown_260924: вылет, stop/resume, отзыв, сообщения.

Игра не подменяется и время не ускоряется. Расход топлива штатный; удаляется
только созданное этим рейсом сообщение. Credentials остаются в памяти.
"""
import argparse
import json
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--execute', action='store_true', required=True)
args = parser.parse_args()
seed = r'''<?php
require 'src/bootstrap.php';
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$id=$pdo->query("SELECT account_id FROM legacy_account_links WHERE source_key='sp-game' AND source_login='qaown_260924'")->fetchColumn();
$a=(new SPGame\Cabinet\Repositories\SessionRepository($pdo))->create($id,'native');
echo json_encode((new SPGame\Cabinet\Services\WorldService($pdo))->start($a['accessToken'],'sp-game-dev'));
'''
grant = json.loads(subprocess.run(['docker', 'exec', '-i', 'sp_game_cabinet', 'php'],
    input=seed, text=True, capture_output=True, check=True).stdout)
grant['websocketUrl'] = 'ws://host.docker.internal:9501/'
code = r'''
import asyncio,json,time,uuid
from app.protocol_v2 import ProtocolV2Client
grant=__GRANT__
async def main():
 c=ProtocolV2Client(grant['websocketUrl'],world_id=grant['worldId']);c.token=grant['gameToken'];await c.connect()
 context={};fid=None;sent=False
 async def query(name,data=None):return (await c.query(name,context,data or {}))['data']['value']
 async def command(name,data,expected='applied'):
  packet=c.prepare_command(name,context,data);r=await c.submit_command(packet)
  for _ in range(60):
   state=await c.command_status(packet['requestId'])
   if state['state']!='accepted':break
   await asyncio.sleep(.2)
  assert state['state']==expected,(name,state)
 async def fleet():
  page=await query('fleets.get')
  for key in ['FleetList','FlyingFleets']:
   rows=page[key]
   for row in (rows.values() if isinstance(rows,dict) else rows):
    if row.get('id',row.get('fleet_id'))==fid:return row
  raise AssertionError('Тестовый флот не найден: '+str(fid))
 try:
  home=(await c.read_planets())['value'];context={'planetId':home['selectedPlanetId']}
  place=next(x for x in home['items'] if x['id']==context['planetId'])['coordinate']
  value=await query('fleets.get')
  flying=value['FlyingFleets'];flying=list(flying.values()) if isinstance(flying,dict) else flying
  # Повтор после ошибки самого harness: только наш прежний рейс из фиксированной точки.
  previous=next((f for f in flying if f['id']=='37' and f['mission']==30 and (f.get('mission_target') or {}).get('angle')==85),None)
  if previous:
   fid=previous['id'];sent=True
   await command('fleet.recall',{'fleetId':fid})
   for _ in range(180):
    if (await fleet()).get('status')==0:break
    await asyncio.sleep(1)
   assert (await fleet()).get('status')==0,'Прежний QA-рейс ещё не вернулся'
   sent=False;value=await query('fleets.get')
  rows=value['FleetList'];rows=list(rows.values()) if isinstance(rows,dict) else rows
  chosen=next(f for f in rows if f['status']==0 and f['home_site_id']=='0' and f['fuel']==0 and '202' in f['ships'] and not f['control']['steps'])
  fid=chosen['id'];counts={k:v['count'] for k,v in chosen['ships'].items()}
  before={m['id'] for m in (await query('messages.get'))['allMessages']}
  nav=(await query('fleet.navigation.get',{'galaxy':place['galaxy'],'system':place['system'],'fleetId':fid}))['NavigationSystem']
  target={'type':2,'galaxy':place['galaxy'],'system':place['system'],'distance':nav['minDistance']+(nav['maxDistance']-nav['minDistance'])*.2,'angle':85.0}
  preview=await query('fleet.missions.get',{'fleetId':fid,'coordinates':target,'speedFactor':10,'missionType':30})
  assert 30 in preview['AvailableMissions']['missions']
  # OBSERVE — миссия в одну сторону: preview не включает будущий ручной отзыв.
  fuel=min(chosen['capacity']-1,max(1000,preview['TravelInfo']['fuel']['max']*3))
  assert 0<fuel<chosen['capacity']
  sub=str(uuid.uuid4());await c._call('subscribe',name='fleets.get',context=context,data={},subscriptionId=sub)
  await command('fleet.send',{'fleetId':fid,'missionType':30,'coordinates':target,'speedFactor':10,'fuel':fuel,'resources':{}});sent=True
  active=await fleet();assert active['actions']['StopFleet']
  await command('fleet.ships.transfer',{'fromFleetId':fid,'toFleetId':'0','shipId':'202','count':1},expected='rejected')
  assert {k:v['count'] for k,v in (await fleet())['ships'].items()}==counts
  print('V2_FLIGHT_SEND_OK',fid,flush=True)
  await asyncio.sleep(2)
  await command('fleet.stop',{'fleetId':fid});paused=await fleet()
  assert (paused['control']['holding'] or paused['control']['paused']) and paused['actions']['ResumeFleet'],paused['control']
  await command('fleet.resume',{'fleetId':fid});resumed=await fleet();assert not resumed['control']['paused'] and not resumed['control']['holding']
  await c.close();await c.connect();assert (await fleet())['actions']['StopFleet']
  print('V2_FLIGHT_STOP_RESUME_RECONNECT_OK',flush=True)
  await command('fleet.recall',{'fleetId':fid});print('V2_FLIGHT_RECALL_OK',flush=True)
  deadline=time.monotonic()+180
  while time.monotonic()<deadline:
   current=await fleet()
   if current.get('status')==0:break
   await asyncio.sleep(1)
  assert current.get('status')==0 and not current['control']['paused'],'Флот не вернулся'
  assert {k:v['count'] for k,v in current['ships'].items()}==counts
  assert current['current']['type']==1 and current['current']['orbit']==place['orbit']
  sent=False
  messages=(await query('messages.get'))['allMessages']
  fresh=[m for m in messages if m['id'] not in before and str(json.loads(m.get('data') or '{}').get('FleetId'))==fid]
  assert fresh,'Не пришло сообщение о возврате'
  mid=fresh[0]['id'];await command('messages.read',{'ReadId':[mid]})
  assert next(m for m in (await query('messages.get'))['allMessages'] if m['id']==mid)['read']
  await command('messages.delete',{'Ids':[mid]})
  assert all(m['id']!=mid for m in (await query('messages.get'))['allMessages'])
  await c.logout();print('LIVE_V2_FLIGHT_AND_MESSAGES_OK',flush=True)
 finally:
  if sent and c.authenticated:
   try:
    f=await fleet()
    if f['control']['paused']:await command('fleet.resume',{'fleetId':fid})
    await command('fleet.recall',{'fleetId':fid})
   except Exception:print('QA_FLIGHT_CLEANUP_REQUIRES_CHECK',fid,flush=True)
  await c.close()
asyncio.run(main())
'''.replace('__GRANT__', repr(grant))
result = subprocess.run(['docker', 'exec', '-i', 'sp_game_qa_bot', 'python', '-'],
    input=code, text=True, capture_output=True, timeout=240)
print(result.stdout, end='')
if result.returncode:
    raise RuntimeError(result.stderr[-3000:])
