<?php
declare(strict_types=1);

// Одноразовая подготовка QA на остановленном основном dev; --verify только SELECT.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Enums\{FleetCommandType as C,FleetMissionType as M};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\Coordinate;
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$check=static function(bool$ok,string$why):void{if(!$ok)throw new LogicException($why);};
$mode=$argv[1]??'';$check(in_array($mode,['--prepare','--verify','--verify-return','--verify-complete'],true),'Нужен режим.');
if($mode!=='--prepare'){
 $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
 $check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Journal применяется; повторите SELECT после checkpoint.');
 $rows=$db->fetchAll("SELECT * FROM fleets WHERE name LIKE 'QA HomeLoss %'");$named=[];
 foreach($rows as$r)$named[$r['name']]=$r;
 // Завершённые результаты добычи не зависят от последующего ухода захватчика:
 // ресурсная база6 уже может освободиться по обычным игровым правилам.
 if($mode!=='--verify-complete')$check((int)($db->fetchOne('SELECT owner_id FROM resource_bases WHERE id=6')['owner_id']??0)===10,'База6 ещё не захвачена.');
 $check(!isset($named['QA HomeLoss old guard']),'Старая охрана не уничтожена.');
 R\Vars::init();$summary=[];
 if($mode==='--verify-complete'){
  foreach(['return'=>120.0,'stay'=>240.0,'peer'=>360.0]as$suffix=>$xp){
   $f=$named['QA HomeLoss '.$suffix];$id=(int)$f['id'];$returned=$suffix==='return';
   $check((int)$f['status']===0,'Флот не завершил рейс.');
   $check($db->fetchOne('SELECT id FROM fleet_commands WHERE fleet_id='.$id.' AND executed=0')===null,'Осталась очередь.');
   $messages=$db->fetchAll("SELECT id,data,time FROM messages WHERE sample='FleetMiningResult' AND data LIKE '%QA HomeLoss ".$suffix."%'");
   $check(count($messages)===1,'Повтор/отсутствие результата добычи.');$result=json_decode($messages[0]['data'],true,512,JSON_THROW_ON_ERROR);
   $field=R\Vars::$resource[224];$ships=$db->fetchOne('SELECT * FROM fleets_ships WHERE id='.$id);
   $check((int)$ships[$field.'_count']===1&&(float)$ships[$field.'_experience']===$xp,'Потерян состав/опыт.');
   if($returned){
    $check((int)$f['home_planet_id']===26&&(int)$f['home_site_id']===0&&(float)$f['fuel_current']===0.0
     &&(float)$f['resource_deuterium']===0.0,'Не выгружен вернувшийся флот.');
    $arrivals=$db->fetchAll("SELECT id,data FROM messages WHERE sample='FleetReturnedWithCargoAndFuel' AND data LIKE '%QA HomeLoss return%'");
    $check(count($arrivals)===1,'Повтор/отсутствие сообщения возврата.');$arrival=json_decode($arrivals[0]['data'],true,512,JSON_THROW_ON_ERROR);
    $check(abs($arrival['Resources'][903]-$result['Resources'][903])<.000001&&$arrival['ResourcesFuelReturned'][903]>0,'Потерян доставленный груз/бак.');
    $summary[$id]=['returned'=>true,'cargo'=>$arrival['Resources'][903],'fuelReturned'=>$arrival['ResourcesFuelReturned'][903]];
   }else{
    $check((int)$f['home_site_id']===5&&(int)$f['current_site_id']===5&&(int)$f['home_planet_id']===0,'Не завершено базирование.');
    $check(abs((float)$f['resource_deuterium']-1600)<.000001&&$result['WorkMinutes']==60&&$result['FuelSpent']==10,'Неверный итог часа добычи.');
    $expected=4990-((float)$f['base_fuel_time']-(float)$messages[0]['time'])*(float)$f['base_fuel_rate'];
    $check(abs((float)$f['fuel_current']-$expected)<.000001,'Стоянка повторно списала расход добычи.');
    $summary[$id]=['homeSite'=>5,'cargo'=>(float)$f['resource_deuterium'],'fuel'=>(float)$f['fuel_current'],'xp'=>$xp];
   }
  }
  $db->rollBack();echo json_encode($summary,JSON_THROW_ON_ERROR),PHP_EOL;exit;
 }
 if($mode==='--verify-return'){
  $f=$named['QA HomeLoss return'];$id=(int)$f['id'];
  $c=$db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id='.$id.' AND command_type=61 AND executed=1 ORDER BY id DESC LIMIT 1');
  $p=json_decode($c['params'],true,512,JSON_THROW_ON_ERROR);$run=$p['mining'];
  $check($run['phase']==='finished'&&$run['reason']==='recall'&&!isset($p['miningCheckpoint']),'Отзыв не закрыл добычу.');
  $check((int)$f['home_site_id']===0&&(int)$f['home_planet_id']===26,'Неверный дом возвращающегося флота.');
  $queue=$db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id='.$id.' AND executed=0');
  foreach($queue as$next){$target=json_decode($next['params'],true);$target=$target['target']??$target['at']??null;
   $check($target===['type'=>1,'galaxy'=>1,'system'=>6,'orbit'=>2,'objectType'=>1],'Будущая команда летит к потерянной базе.');}
  $check($queue!==[],'Этот режим проверяет текущий обратный рейс до прибытия.');
  $check(abs((float)$f['resource_deuterium']-$run['extracted'][903])<.000001,'Груз изменился после отзыва.');
  $messages=$db->fetchAll("SELECT id FROM messages WHERE sample='FleetMiningResult' AND data LIKE '%QA HomeLoss return%'");
  $check(count($messages)===1,'Повтор/отсутствие результата добычи.');
  $summary[$id]=['returnHome'=>26,'cargo'=>(float)$f['resource_deuterium'],'fuel'=>(float)$f['fuel_current'],'message'=>(int)$messages[0]['id']];
 }
 foreach(['return'=>120.0,'stay'=>240.0,'peer'=>360.0]as$suffix=>$xp){
  if($mode==='--verify-return'&&$suffix==='return')continue;
  $f=$named['QA HomeLoss '.$suffix]??null;$check($f!==null,'Нет добытчика.');$id=(int)$f['id'];
  $c=$db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id='.$id.' AND command_type=61 AND executed=0');
  $p=$c===null?[]:json_decode($c['params'],true,512,JSON_THROW_ON_ERROR);$run=$p['mining']??[];
  $check(($run['phase']??null)==='working','Добыча не продолжается.');
  $check((int)$f['current_site_id']===5,'Добытчик покинул удалённый объект.');
  if($suffix==='peer')$check((int)$f['home_site_id']===5&&!isset($p['miningHomeLost']),'Изменён дом соседнего добытчика.');
  else $check((int)$f['home_site_id']===0&&(int)$f['home_planet_id']===26
   &&($p['miningHomeLost']['siteId']??null)===6,'Не заменён потерянный дом.');
  $elapsed=(float)$p['miningCheckpoint']['at']-$run['startedAt'];
  $check(abs($run['extracted'][903]-$run['production']*$elapsed/3600)<.000001,'Повторное/потерянное начисление.');
  $check(abs((float)$f['resource_deuterium']-$run['extracted'][903])<.000001,'Груз не равен добыче.');
  $fuel=(float)$f['fuel_current']+$p['miningCheckpoint']['fuelCarry'];
  $check(abs($fuel-(5000-$run['fuelSpent']))<.000001,'Повторное/неполное списание бака.');
  $field=R\Vars::$resource[224];$ships=$db->fetchOne('SELECT * FROM fleets_ships WHERE id='.$id);
  $check((int)$ships[$field.'_count']===1&&(float)$ships[$field.'_experience']===$xp,'Потерян состав/опыт.');
  $summary[$id]=['name'=>$f['name'],'homePlanet'=>(int)$f['home_planet_id'],'homeSite'=>(int)$f['home_site_id'],
   'fuel'=>$fuel,'cargo'=>(float)$f['resource_deuterium'],'reserve'=>$run['fuelReserve'],'at'=>$p['miningCheckpoint']['at']];
 }
 foreach(['return','stay']as$suffix){$id=(int)$named['QA HomeLoss '.$suffix]['id'];
  $messages=$db->fetchAll("SELECT data FROM messages WHERE sample='FleetResourceBaseChanged' AND data LIKE '%\"FleetId\":".$id.",%'");
  $check(count($messages)===1,'Повтор/отсутствие сообщения о потерянном доме.');
 }
 $check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
 $db->rollBack();echo json_encode($summary,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')==='qaown_260924','Другой QA.');
$check($db->fetchOne("SELECT id FROM fleets WHERE name LIKE 'QA HomeLoss %'")===null,'Сцена уже подготовлена.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Не завершён бой.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,R\Galaxy::class,
 R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,R\Resources::class,
 R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,
 R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,R\SpaceBattles::class,
 R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class]as$r)$r::init();
R\MiningCheckpoints::init();$now=(float)sprintf('%.6F',microtime(true));
$check((int)R\ResourceBases::findExistingById(5)['owner_id']===9,'Другой хозяин удалённого объекта.');
foreach([6=>9,7=>10]as$site=>$owner){
 $check(R\ResourceBases::findExistingById($site)===null,'QA-сектор занят.');
 R\ResourceBases::restoreDeletedRow(['id'=>$site,'owner_id'=>$owner,'created_at'=>$now,'updated_at'=>$now]);
}
$tech=R\Techs::findExistingById(9);$field=R\Vars::$resource[136];$before=$tech[$field];R\Techs::update(['id'=>9,$field=>max(6,$before)]);
$attackerTech=R\Techs::findExistingById(10);R\Techs::update(['id'=>10,$field=>max(6,$attackerTech[$field])]);
$at=static fn(int$id):Coordinate=>S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById($id));
$create=static function(string$name,int$owner,int$home,int$base,int$current,int$type,int$count,float$fuel,float$xp=0)use($now,$at):int{
 $position=$at($current);$row=['name'=>'QA HomeLoss '.$name,'owner_id'=>$owner,'home_planet_id'=>$base?0:$home,
  'home_site_id'=>$base,'fallback_planet_id'=>$home,'current_position_time'=>$now,'fuel_current'=>$fuel,'fuel_total'=>$fuel];
 foreach(['coordinate_type'=>1,'galaxy'=>$position->getGalaxy(),'system'=>$position->getSystem(),
  'orbit'=>$position->getOrbit(),'object_type'=>$position->getObjectType()->value,'site_id'=>$current]as$k=>$v)$row['current_'.$k]=$v;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>$owner,
  R\Vars::$resource[$type].'_count'=>$count,R\Vars::$resource[$type].'_experience'=>$xp]);return$id;
};
$ids=[];
foreach([['old guard',9,26,6,203,1,500],['new guard',10,27,7,203,1,500],['capture',10,27,7,207,20,5000]]as[$n,$u,$h,$s,$t,$c,$fuel]){
 $id=$create($n,$u,$h,$s,$s,$t,$c,$fuel);$ids[$n]=$id;$f=S\FleetsData::getInstance()->get($id);
 S\ResourceBaseService::beginStanding($f,new S\AccountData($u===9?15:16),$now,10,$at($s));$f->save();
}
foreach(['return'=>120,'stay'=>240,'peer'=>360]as$n=>$xp){
 $id=$create($n,9,26,$n==='peer'?5:6,5,224,1,5000,$xp);$ids[$n]=$id;
 $f=S\FleetsData::getInstance()->get($id);$f->setStatus(1);$f->setMission(M::MINING);
 $f->setCurrentCoordinate($at(5),$now+20);$f->setStartCoordinate($at($n==='peer'?5:6));$f->setTargetCoordinate($at(5));$f->save();
 $c=R\FleetCommands::addCommand($id,C::MINING,['target'=>$at(5)->toArray(),
  'miningRequest'=>['mode'=>'deuterium','duration'=>3600,'finish'=>$n==='return'?'return':'stay','speedFactor'=>10],'speedFactor'=>10]);
 R\FleetCommands::update(['id'=>$c['id'],'execute_time'=>$now+20]);
 if($n==='return')foreach(S\FleetMissionBuilder::buildReturn($at(5),$at(6))as$next){
  R\FleetCommands::addCommand($id,$next['type'],$next['params']+['homeSiteId'=>6,'speedFactor'=>10,'returnLeg'=>true]);
 }else R\FleetCommands::addCommand($id,C::STAY,['at'=>$at(5)->toArray(),'speedFactor'=>10]);
}
foreach([R\Techs::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class]as$r){
 $r::syncToDatabase();$pending=$r::getPendingSync();$check($pending['update']===[]&&$pending['delete']===[],'Не сохранено:'.$r);
}
echo json_encode(['preparedAt'=>$now,'miningTechBefore'=>$before,'fleets'=>$ids],JSON_THROW_ON_ERROR),PHP_EOL;
