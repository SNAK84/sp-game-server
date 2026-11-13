<?php
declare(strict_types=1);

// --prepare только при остановленном main; --verify читает согласованный SQL snapshot.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Repositories as R;
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$mode=$argv[1]??'';
if($mode==='--verify'){
 $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
 R\Vars::init();$out=[];
 foreach(['QA Shutdown flight'=>40.0,'QA Offline flight'=>60.0]as$name=>$xp){
  $f=$db->fetchOne('SELECT * FROM fleets WHERE name=?',[$name]);if($f===null)throw new LogicException('Нет QA-флота.');$id=(int)$f['id'];
  if((int)$f['status']!==0||(int)$f['home_planet_id']!==27||(int)$f['home_site_id']!==0
   ||(int)$f['current_coordinate_type']!==1||(int)$f['current_orbit']!==5)throw new LogicException('Рейс не завершён на своей базе.');
  if($db->fetchOne('SELECT id FROM fleet_commands WHERE fleet_id='.$id.' AND executed=0')!==null)throw new LogicException('Остались команды.');
  foreach(['fuel_current','fuel_total','resource_metal','resource_crystal','resource_deuterium']as$key)if((float)$f[$key]!==0.0)throw new LogicException('Остался груз/бак.');
  $field=R\Vars::$resource[202];$ships=$db->fetchOne('SELECT * FROM fleets_ships WHERE id='.$id);
  if((int)$ships[$field.'_count']!==1||(float)$ships[$field.'_experience']!==$xp)throw new LogicException('Изменился состав/опыт.');
  $out[$id]=['name'=>$name,'idle'=>true,'xp'=>$xp];
 }
 $messages=$db->fetchAll("SELECT id,data FROM messages WHERE sample='FleetReturnedWithFuel' AND data LIKE '%QA Offline flight%'");
 if(count($messages)!==1)throw new LogicException('Повтор/отсутствие сообщения возврата OBSERVE.');
 $data=json_decode($messages[0]['data'],true,512,JSON_THROW_ON_ERROR);
 if(($data['FleetName']??null)!=='QA Offline flight'||($data['PlanetCoordinate']??null)!=='[1:10:5]'
  ||($data['ResourcesFuelReturned'][903]??0)<=0)throw new LogicException('Неверный отчёт о возврате.');
 $out['returnMessage']=['id'=>(int)$messages[0]['id'],'fuel'=>$data['ResourcesFuelReturned'][903]];
 $db->rollBack();echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($mode!=='--prepare')throw new LogicException('Нужен --prepare или --verify.');
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
if(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')!=='qafor_260924')throw new LogicException('Другой QA.');
if($db->fetchOne("SELECT id FROM fleets WHERE name IN ('QA Shutdown flight','QA Offline flight')")!==null)throw new LogicException('Сцена уже существует.');
R\Vars::init();foreach([R\Fleets::class,R\FleetsShips::class,R\Planets::class]as$r)$r::init();
$planet=R\Planets::findExistingById(27);if((int)$planet['owner_id']!==10)throw new LogicException('Другая планета27.');
$now=(float)sprintf('%.6F',microtime(true));$ids=[];
foreach(['QA Shutdown flight'=>40.0,'QA Offline flight'=>60.0]as$name=>$xp){
 $id=R\Fleets::add(['name'=>$name,'owner_id'=>10,'home_planet_id'=>27,'fallback_planet_id'=>27,
  'current_coordinate_type'=>1,'current_galaxy'=>(int)$planet['galaxy'],'current_system'=>(int)$planet['system'],
  'current_orbit'=>(int)$planet['planet'],'current_object_type'=>1,'current_position_time'=>$now]);
 R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>10,R\Vars::$resource[202].'_count'=>1,R\Vars::$resource[202].'_experience'=>$xp]);
 $ids[$name]=$id;
}
foreach([R\Fleets::class,R\FleetsShips::class]as$r){$r::syncToDatabase();$p=$r::getPendingSync();if($p['update']!==[]||$p['delete']!==[])throw new LogicException('Не сохранена QA-подготовка.');}
echo json_encode($ids,JSON_THROW_ON_ERROR),PHP_EOL;
