<?php
declare(strict_types=1);

// Контролируемая QA-очередь основного dev-сервера, НЕ доказательство browser ACK.
// Подготовка/активация — только offline; проверки — SELECT-only после flush,
// без новых Tables. До периодического/final saver SQL может отставать от SPA.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();$mode=$argv[1]??'';
$fleet=$db->fetchOne('SELECT * FROM fleets WHERE id=120');
if($fleet===null||(int)$fleet['owner_id']!==10||(int)$fleet['home_planet_id']!==27
 ||($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')!=='qafor_260924')throw new LogicException('Другая QA-сцена.');
if(in_array($mode,['--prepare','--activate'],true)){
 $socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
 if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
 $db->beginTransaction();
 try{
  $rows=$db->fetchAll('SELECT id,event_data FROM player_queue_restart ORDER BY id FOR UPDATE');
  if($mode==='--prepare'){
   if($rows!==[]||(int)$fleet['status']!==0||(float)$fleet['fuel_current']!==0.0
    ||$db->fetchOne('SELECT id FROM accounts WHERE id=65535')!==null)throw new LogicException('QA-сцена не свободна.');
   // Только QA-владелец: слот нужен сверх уже идущих параллельных сценариев.
   $db->query('UPDATE techs SET computer_tech=GREATEST(computer_tech,12) WHERE id=10');
   if($fleet['name']==='QA Restart restored')$db->query('UPDATE fleets SET name=? WHERE id=120',['QA Shutdown ack']);
   $plan=['version'=>hash('sha256','[]'),'steps'=>[
    ['kind'=>'move','coordinates'=>['type'=>2,'galaxy'=>1,'system'=>10,'distance'=>5000,'angle'=>90],'speedFactor'=>10],
    ['kind'=>'move','coordinates'=>['type'=>2,'galaxy'=>1,'system'=>10,'distance'=>4500,'angle'=>180],'speedFactor'=>5],
    ['kind'=>'return','speedFactor'=>10]],'fuel'=>100,'resources'=>[901=>71,902=>37,903=>19]];
   foreach([1001=>['action'=>'ActionFleetRename','data'=>['FleetId'=>120,'Name'=>'QA Restart restored']],
    1002=>['action'=>'ActionFleetControl','data'=>['action'=>'SetFleetCommands','fleetId'=>120,'plan'=>$plan]]]as$id=>$action){
    $event=['id'=>$id,'account_id'=>65535,'user_id'=>10,'planet_id'=>27,'priority'=>1,
     'added_at'=>microtime(true)+($id-1001)*.000001,'ready'=>1]+$action;
    $event['payload_ttl']=$id===1002?90.0:null;
    $db->query('INSERT INTO player_queue_restart(id,event_data) VALUES(?,?)',[$id,json_encode($event,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]);
   }
  }else{
   if(array_column($rows,'id')!==[1001,1002]||(int)$fleet['status']!==0||$fleet['name']==='QA Restart restored')throw new LogicException('Снимок уже исполнился/не сохранился.');
   foreach($rows as$row){
    $event=json_decode($row['event_data'],true,64,JSON_THROW_ON_ERROR);
    if($event['account_id']!==65535||$event['user_id']!==10||$event['planet_id']!==27)throw new LogicException('Другой snapshot.');
    $event['account_id']=16;
    $db->query('UPDATE player_queue_restart SET event_data=? WHERE id=?',[json_encode($event,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),$row['id']]);
   }
  }
  $db->commit();
 }catch(Throwable$e){$db->rollBack();throw$e;}
 echo json_encode(['mode'=>$mode,'queueIds'=>[1001,1002],'fleetId'=>120]),PHP_EOL;exit;
}
if($mode!=='--verify')throw new LogicException('Нужен --prepare, --activate или --verify.');
$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
$fleet=$db->fetchOne('SELECT * FROM fleets WHERE id=120');
if($db->fetchOne('SELECT id FROM player_queue_restart LIMIT 1')!==null||$fleet['name']!=='QA Restart restored'
 ||(int)$fleet['status']!==1)throw new LogicException('Снимок не исполнен.');
foreach(['resource_metal'=>71,'resource_crystal'=>37,'resource_deuterium'=>19]as$field=>$count){
 if((float)$fleet[$field]!== (float)$count)throw new LogicException('Неправильный груз.');
}
$loads=$db->fetchAll('SELECT id,params FROM fleet_commands WHERE fleet_id=120 AND command_type=31 AND executed=1');
if(count($loads)!==1)throw new LogicException('Повтор/отсутствие погрузки.');
$last=end($loads);$params=json_decode($last['params'],true,512,JSON_THROW_ON_ERROR);
if((float)($params['fuel']??0)!==100.0)throw new LogicException('Другая погрузка.');
\SPGame\Game\Repositories\Vars::init();$field=\SPGame\Game\Repositories\Vars::$resource[202];
$ships=$db->fetchOne('SELECT * FROM fleets_ships WHERE id=120');
if((int)$ships[$field.'_count']!==1||(float)$ships[$field.'_experience']!==40.0)throw new LogicException('Изменён состав/опыт.');
$out=['fleetId'=>120,'name'=>$fleet['name'],'fuel'=>(float)$fleet['fuel_current'],
 'cargo'=>[71,37,19],'lastLoadId'=>(int)$last['id'],'allLoadCount'=>count($loads),'xp'=>40,'remainingQueue'=>0];
$db->rollBack();echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;
