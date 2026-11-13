#!/usr/bin/env python3
"""Живая проверка кабинета/мира. Меняет только свой QA-аккаунт; --restart-game перезапускает игру.

Запуск с хоста разработки: python3 qa/check_cabinet_world.py --execute --restart-game
Пароли и игровые разрешения не выводятся. QA-аккаунт остаётся для повторной проверки.
"""
import argparse
import json
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--execute', action='store_true', required=True)
parser.add_argument('--restart-game', action='store_true')
args = parser.parse_args()


def cabinet(php):
    result = subprocess.run(['docker', 'exec', '-i', 'sp_game_cabinet', 'php'],
                            input="<?php require 'src/bootstrap.php';\n" + php,
                            capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


fixture = cabinet(r'''
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$repo=new SPGame\Cabinet\Repositories\AccountRepository($pdo);
$email='qa-cabinet-world-entry@example.invalid';
$password='Qa1!'.bin2hex(random_bytes(12));
$account=$repo->create($email,password_hash($password,PASSWORD_ARGON2ID));
$repo->setPassword($account['id'],password_hash($password,PASSWORD_ARGON2ID));
$repo->verifyEmail($account['id']);
echo json_encode(['email'=>$email,'password'=>$password,'accountId'=>$account['id']]);
''')


def binding():
    return cabinet(r'''
$pdo=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$q=$pdo->prepare('SELECT local_account_id,player_id FROM world_players WHERE world_id=? AND account_id=?');
$q->execute(['sp-game-dev',ACCOUNT]); echo json_encode($q->fetch());
'''.replace('ACCOUNT', json.dumps(fixture['accountId'])))


code = r'''
import asyncio,tempfile,os
from pathlib import Path
from app.ws_client import GameClient,GameClientError,GameSessionEndedError
cfg=FIXTURE
async def main():
 with tempfile.TemporaryDirectory() as d:
  path=Path(d)/'grant.json'
  def client(saved=True):
   return GameClient(os.environ['GAME_WS_URL'],cabinet_email=cfg['email'],session_file=path if saved else None)
  c=client();await c.connect();assert (await c.login(cfg['email'],cfg['password']))['status']=='ok'
  await c.close()
  c=client();await c.connect();assert (await c.login(cfg['email'],cfg['password']))['status']=='ok'
  print('NEW_PLAYER_READY_FOR_RESTART',flush=True)
  if __RESTART__:
   disconnected=False
   for i in range(90):
    try:
     assert (await c.request('overview','ActualData',timeout=2))['status']=='ok'
     if disconnected:break
    except GameSessionEndedError:raise
    except GameClientError:
     disconnected=True;await c.close()
     try:await c.connect();await c.login(cfg['email'],cfg['password'])
     except GameSessionEndedError:raise
     except GameClientError:await c.close()
    await asyncio.sleep(1)
   assert disconnected
   assert (await c.request('overview','ActualData'))['status']=='ok'
   print('LIVE_GAME_RESTART_RESUME_OK',flush=True)
  newer=client(False);await newer.connect();await newer.login(cfg['email'],cfg['password'])
  await asyncio.sleep(2)
  try:await c.request('overview','ActualData')
  except GameSessionEndedError:pass
  else:raise AssertionError('Revoked bot tried to continue')
  assert c.token is None
  await c.close()
  assert (await newer.request('overview','ActualData'))['status']=='ok'
  await newer.request('logout','logout');await newer.close()
 print('BOT_CABINET_ENTRY_REENTRY_AND_REPLACEMENT_STOP_OK',flush=True)
asyncio.run(main())
'''.replace('FIXTURE', repr(fixture)).replace('__RESTART__', repr(args.restart_game))

process = subprocess.Popen(['docker', 'exec', '-i', 'sp_game_qa_bot', 'python', '-'],
                           stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
process.stdin.write(code)
process.stdin.close()
line = process.stdout.readline().strip()
assert line == 'NEW_PLAYER_READY_FOR_RESTART', process.stderr.read()[-1800:] if not line else line
print(line, flush=True)
before = binding()
assert before and int(before['player_id']) > 0
if args.restart_game:
    # Менеджер после рестарта следует за docker logs; ограничиваем только этот follower.
    subprocess.run(['timeout', '50', './swoole_manager.sh', 'restart'], cwd='/opt/sp-game',
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=55)
output = process.stdout.read()
error = process.stderr.read()
assert process.wait(timeout=110) == 0, error[-1800:]
print(output.strip())
assert before == binding(), 'Повторный вход изменил локальные ID'
sql = r'''<?php
require 'vendor/autoload.php';SPGame\Core\Environment::init('/var/www/html/.env');
$db=SPGame\Core\Database::independent();
$u=$db->fetchOne('SELECT id,main_planet FROM users WHERE account_id=?',[__ACCOUNT__]);
$p=$db->fetchOne('SELECT COUNT(*) AS n FROM planets WHERE owner_id=?',[__PLAYER__]);
if(!$u || (int)$u['id']!==__PLAYER__ || (int)$u['main_planet']<1 || (int)$p['n']!==1) throw new RuntimeException('Duplicate/missing player or planet');
echo 'DURABLE_SINGLE_PLAYER_AND_HOMEWORLD_OK';
'''.replace('__ACCOUNT__', str(before['local_account_id'])).replace('__PLAYER__', str(before['player_id']))
result = subprocess.run(['docker', 'exec', '-i', 'swoole_app', 'php'], input=sql,
                        capture_output=True, text=True, check=True)
print(result.stdout)
