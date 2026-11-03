<?php
declare(strict_types=1);

// Offline подготовка QA-сцены. Оба перехвата отправляются через обычный SPA.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Enums\{FleetCommandType as C,FleetMissionType as M};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate,ObjectType};
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$check=static function(bool$ok,string$why):void{if(!$ok)throw new LogicException($why);};
$mode=$argv[1]??'';$check(in_array($mode,['--prepare','--verify'],true),'Нужен режим.');
if($mode==='--verify'){
 $rows=$db->fetchAll("SELECT * FROM fleets WHERE name IN ('QA Dock host','QA Dock target','QA Dock last','QA Dock stop','QA Dock sensor')");
 $named=[];foreach($rows as$r)$named[$r['name']]=$r;$check(count($named)===5,'Не все QA-флоты сохранились.');
 $target=$named['QA Dock target'];$id=(int)$target['id'];
 $check((int)$target['current_coordinate_type']===1&&(int)$target['current_site_id']===5,'Цель не прибыла к объекту.');
 $mine=$db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id='.$id.' AND executed=0 ORDER BY id LIMIT 1');
 $p=json_decode($mine['params'],true,512,JSON_THROW_ON_ERROR);
 $check((int)$mine['command_type']===C::MINING->value&&($p['mining']['phase']??null)==='working','Цель не работает в защищённом объекте.');
 $check((int)$db->fetchOne('SELECT owner_id FROM resource_bases WHERE id=5')['owner_id']===9,'Изменён контроль базы.');
 $summary=['target'=>$id,'site'=>5];
 foreach(['QA Dock last'=>'target_missing','QA Dock stop'=>'contact_lost']as$name=>$reason){
  $f=$named[$name];$id=(int)$f['id'];$c=$db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id='.$id.' AND executed=0 ORDER BY id LIMIT 1');
  $p=json_decode($c['params'],true,512,JSON_THROW_ON_ERROR);
  $check((float)$c['execute_time']===0.0&&($p['pause']??null)==='target'&&($p['interceptionStop']??null)===$reason,'Перехват не остановлен по контракту.');
  $check((int)$f['current_coordinate_type']===2,'Перехватчик оказался у скрытого OBJECT.');
  if($name==='QA Dock last'){
   $move=$db->fetchOne('SELECT params FROM fleet_commands WHERE fleet_id='.$id.' AND command_type='.C::MOVE_IN_SYSTEM->value.' AND executed=1 ORDER BY id DESC LIMIT 1');
   $end=json_decode($move['params'],true,512,JSON_THROW_ON_ERROR)['motionEnd'];
   $check(abs((float)$f['current_distance']-$end['distance'])<.000001&&abs((float)$f['current_angle']-$end['angle'])<.000001,'Скрытая цель подменила последнюю точку встречи.');
  }
  $messages=$db->fetchAll("SELECT data FROM messages WHERE sample='FleetInterceptionStopped' AND data LIKE '%".$name."%'");
  $episodes=array_map(static fn($row)=>json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)['InterceptionEpisode'],$messages);
  $check(count($messages)===($name==='QA Dock stop'?2:1)&&count(array_unique($episodes))===count($episodes),'Повторные/пропавшие сообщения остановок.');
  $summary['interceptors'][$id]=['reason'=>$reason,'fuel'=>(float)$f['fuel_current'],'command'=>(int)$c['command_type']];
 }
 $check($db->fetchOne("SELECT id FROM battle_reports WHERE payload LIKE '%QA Dock target%' OR payload LIKE '%QA Dock host%'")===null,'Произошёл бой со скрытой целью.');
 $check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied' AND (state LIKE '%QA Dock target%' OR state LIKE '%QA Dock host%')")===null,'Остался незавершённый бой со скрытой целью.');
 $check($db->fetchOne('SELECT id FROM fleet_commands WHERE fleet_id=57 AND executed=0')===null,'Отрицательная отправка изменила свободный флот57.');
 echo json_encode($summary,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924','Другой QA.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Не завершён бой.');
$check($db->fetchOne("SELECT id FROM fleets WHERE name LIKE 'QA Dock %'")===null,'Сцена уже подготовлена.');
$check($db->fetchOne('SELECT id FROM resource_bases WHERE id=5')===null,'Тестовый гигант занят.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,R\Galaxy::class,
 R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,R\Resources::class,
 R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,
 R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,R\SpaceBattles::class,
 R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class]as$r)$r::init();
R\MiningCheckpoints::init();$now=(float)sprintf('%.6F',microtime(true));
$site=R\OrbitalResourceSites::findExistingById(5);$at=S\FleetSurveyService::coordinate($site);
$tech=R\Techs::findExistingById(9);$key=R\Vars::$resource[136];$before=(int)$tech[$key];
R\Techs::update(['id'=>9,$key=>max(4,$before)]);
R\ResourceBases::restoreDeletedRow(['id'=>5,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
$known=R\ResourceSurveys::findByIndex('owner_site',[9,5]);
$reveal=S\FleetSurveyService::reveal($site,$known,S\FleetSurveyService::stock($site,$now),$now,100,16,bin2hex(random_bytes(16)));
if($known)R\ResourceSurveys::update(['id'=>$known['id']]+$reveal);else R\ResourceSurveys::add(['owner_id'=>9,'site_id'=>5]+$reveal);
$create=static function(string$name,int$owner,int$home,int$base,Coordinate$position,array$ships,float$fuel)use($now):int{
 $row=['name'=>$name,'owner_id'=>$owner,'home_planet_id'=>$base?0:$home,'home_site_id'=>$base,
  'fallback_planet_id'=>$home,'current_position_time'=>$now,'fuel_current'=>$fuel,'fuel_total'=>$fuel];
 foreach(['coordinate_type'=>$position->getType()->value,'galaxy'=>$position->getGalaxy(),'system'=>$position->getSystem(),
  'orbit'=>$position->getOrbit()??0,'object_type'=>$position->getObjectType()?->value??0,'site_id'=>$position->getSiteId()??0,
  'distance'=>$position->getDistance()??0,'angle'=>$position->getAngle()??0]as$k=>$v)$row['current_'.$k]=$v;
 $id=R\Fleets::add($row);$groups=['id'=>$id,'fleet_id'=>$id,'owner_id'=>$owner];
 foreach($ships as$type=>$count)$groups[R\Vars::$resource[$type].'_count']=$count;R\FleetsShips::add($groups);return$id;
};
$ids=[];$ids['host']=$create('QA Dock host',9,26,5,$at,[203=>2],5000);
$host=S\FleetsData::getInstance()->get($ids['host']);S\ResourceBaseService::beginStanding($host,new S\AccountData(15),$now,10,$at);$host->save();
$flight=new S\FleetFlightService();$physical=$flight->resolvePhysicalPoint($at,$now+120);
$point=Coordinate::point(1,10,$physical->radius-10,$physical->angle);
$ids['target']=$create('QA Dock target',9,26,0,$point,[224=>1,203=>1],5000);
$home=R\Planets::findExistingById(27);$check((int)$home['owner_id']===10,'Другая база27.');
$homeAt=Coordinate::object(1,10,(int)$home['planet'],ObjectType::PLANET);
foreach(['last','stop']as$policy)$ids[$policy]=$create('QA Dock '.$policy,10,27,0,$homeAt,[203=>2],0);
$homePoint=$flight->resolvePhysicalPoint($homeAt,$now);
$ids['sensor']=$create('QA Dock sensor',10,27,0,Coordinate::point(1,10,$homePoint->radius,fmod($homePoint->angle+90,360)),[221=>1],500);
foreach(['target'=>15,'sensor'=>16]as$name=>$account){
 $id=$ids[$name];R\Fleets::update(['id'=>$id,'status'=>1,'mission'=>M::OBSERVE->value]);
 $f=S\FleetsData::getInstance()->get($id);$c=R\FleetCommands::addCommand($id,C::OBSERVE,['target'=>$f->getCurrentCoordinate()->toArray()]);
 S\FleetControlService::beginHold($c,$f,new S\AccountData($account),$now);
 if($name==='sensor')S\FleetSensorService::setMode($f,new S\AccountData($account),true,$now);
}
foreach([R\Techs::class,R\ResourceSurveys::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class]as$r){
 $r::syncToDatabase();$p=$r::getPendingSync();$check($p['update']===[]&&$p['delete']===[],'Не сохранено:'.$r);
}
echo json_encode(['preparedAt'=>$now,'fleets'=>$ids,'point'=>$point->toArray(),'miningTechBefore'=>$before,'miningTechAfter'=>max(4,$before)],JSON_THROW_ON_ERROR),PHP_EOL;
