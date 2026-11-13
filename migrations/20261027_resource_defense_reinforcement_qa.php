<?php

declare(strict_types=1);

// Offline подготовка основного dev: обычная DEFEND отправляется затем через SPA.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Enums\{FleetCommandType as C,FleetMissionType as M};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;

Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$check=static function(bool $ok,string $why):void{if(!$ok)throw new LogicException($why);};
$mode=$argv[1]??'';$check(in_array($mode,['--prepare','--prepare-late','--prepare-pending','--attack','--pending'],true),'Требуется режим подготовки или атаки.');
$lostWorker=in_array('--lost-worker',$argv,true);$check(!$lostWorker||$mode==='--pending','Recovery только для pending.');
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')==='qaown_260924'
 &&($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924','Другие QA.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Незавершённый бой.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
 R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
 R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
 R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
 R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class]as $r)$r::init();
R\MiningCheckpoints::init();
$check(R\Friends::areFriends(9,10),'QA ещё не друзья.');
$siteId=in_array($mode,['--prepare-pending','--pending'],true)?3:1;
$now=(float)sprintf('%.6F',microtime(true));$at=S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById($siteId));
$named=static function(string $name):?array{foreach(R\Fleets::getAll()??[]as $row)if($row['name']===$name)return $row;return null;};
$guard=$named($mode==='--pending'?'QA Resource defense pending':'QA Resource defense');$check($guard!==null&&(int)$guard['owner_id']===10,'Нет QA-охраны.');
$result=['mode'=>$mode,'at'=>$now,'guard'=>$guard['id'],'siteId'=>$siteId];
if($mode==='--prepare'){
 $check(R\ResourceBases::findExistingById(1)===null,'Сектор1 уже занят.');
 $check($named('QA Def reinforcement host')===null,'Подготовка уже сделана.');
 $check((int)$guard['status']===0&&(int)$guard['home_planet_id']===27,'Охрана не на базе.');
 R\ResourceBases::restoreDeletedRow(['id'=>1,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
 $row=['owner_id'=>9,'name'=>'QA Def reinforcement host','home_site_id'=>1,'fallback_planet_id'=>26,
  'current_position_time'=>$now,'fuel_current'=>90,'fuel_total'=>90];
 foreach(['current','target','start']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>1,'system'=>10,'orbit'=>3,'object_type'=>5,'site_id'=>1]as $key=>$value)$row[$prefix.'_'.$key]=$value;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>9,R\Vars::$resource[204].'_count'=>2]);
 $f=S\FleetsData::getInstance()->get($id);S\ResourceBaseService::beginStanding($f,new S\AccountData(15),$now,10,$at);$f->save();
 $result+=['hostFleet'=>$id];
}elseif($mode==='--prepare-pending'){
 $check(R\ResourceBases::findExistingById($siteId)===null,'Сектор повторного прогона занят.');
 $check($named('QA Resource defense pending')===null,'Повторный прогон уже подготовлен.');
 R\ResourceBases::restoreDeletedRow(['id'=>$siteId,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
 $row=['owner_id'=>9,'name'=>'QA Def pending host','home_site_id'=>$siteId,'fallback_planet_id'=>26,
  'current_position_time'=>$now,'fuel_current'=>90,'fuel_total'=>90];
 foreach(['current','target','start']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>1,'system'=>10,'orbit'=>3,'object_type'=>5,'site_id'=>$siteId]as $key=>$value)$row[$prefix.'_'.$key]=$value;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>9,R\Vars::$resource[204].'_count'=>2]);
 $f=S\FleetsData::getInstance()->get($id);S\ResourceBaseService::beginStanding($f,new S\AccountData(15),$now,10,$at);$f->save();
 $result['hostFleet']=$id;
 $home=R\Planets::findExistingById(27);$check((int)$home['owner_id']===10,'Другая база27.');
 $row=['owner_id'=>10,'name'=>'QA Resource defense pending','home_planet_id'=>27,'fallback_planet_id'=>27,'current_position_time'=>$now];
 foreach(['current','start']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$home['galaxy'],'system'=>$home['system'],'orbit'=>$home['planet'],'object_type'=>1]as $key=>$value)$row[$prefix.'_'.$key]=$value;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>10,R\Vars::$resource[204].'_count'=>2]);
 $result['guard']=$id;
}elseif($mode==='--prepare-late'){
 $check($named('QA Resource defense late')===null,'Поздняя охрана уже создана.');
 $home=R\Planets::findExistingById(27);$check((int)$home['owner_id']===10,'Другая база27.');
 $row=['owner_id'=>10,'name'=>'QA Resource defense late','home_planet_id'=>27,'fallback_planet_id'=>27,'current_position_time'=>$now];
 foreach(['current','start']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$home['galaxy'],'system'=>$home['system'],'orbit'=>$home['planet'],'object_type'=>1]as $key=>$value)$row[$prefix.'_'.$key]=$value;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>10,R\Vars::$resource[204].'_count'=>2]);
 $result['guard']=$id;
}else{
 $check((int)(R\ResourceBases::findExistingById($siteId)['owner_id']??0)===9,'Другая база.');
 $move=R\FleetCommands::getActiveCommand((int)$guard['id']);$check($move!==null,'В MySQL нет активного перелёта: дождитесь saver перед остановкой.');$p=S\FleetControlService::params($move);
 $check((int)($move['command_type']??0)===C::MOVE_IN_SYSTEM->value&&($p['target']??null)===$at->toArray(),'Охрана ещё не летит к1.');
 $arrival=(float)$move['execute_time'];
 $check($lostWorker?($arrival>$now+5&&$arrival<$now+45):$arrival>$now+30,'Неподходящее окно прибытия.');
 $name=$mode==='--attack'?'QA Def reinforcement enemy':'QA Def pending retry';
 $check($named($name)===null,'Атака уже подготовлена.');
 $home=R\Planets::findExistingById(30);$check((int)$home['owner_id']===5,'Другая база30.');
 $enemyAt=$lostWorker?$now:$arrival-($mode==='--attack'?4.0:2.0);
 if($mode==='--pending'){
  $tech=R\Techs::findExistingById(5);$key=R\Vars::$resource[136];
  R\Techs::update(['id'=>5,$key=>max(3,(int)$tech[$key])]);$result['miningTechBefore']=$tech[$key];
 }
 $row=['owner_id'=>5,'name'=>$name,'home_planet_id'=>30,'fallback_planet_id'=>30,'status'=>1,
  'mission'=>M::CAPTURE->value,'current_position_time'=>$enemyAt,'fuel_current'=>1000,'fuel_total'=>1000];
 if($mode==='--pending')$row['fuel_current']=$row['fuel_total']=100000;
 foreach(['current','target']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>1,'system'=>10,'orbit'=>3,'object_type'=>5,'site_id'=>$siteId]as $key=>$value)$row[$prefix.'_'.$key]=$value;
 foreach(['coordinate_type'=>1,'galaxy'=>$home['galaxy'],'system'=>$home['system'],'orbit'=>$home['planet'],'object_type'=>1]as $key=>$value)$row['start_'.$key]=$value;
 $id=R\Fleets::add($row);$type=$mode==='--attack'?203:207;$count=$mode==='--attack'?4:500;
 R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>5,R\Vars::$resource[$type].'_count'=>$count]);
 $attack=R\FleetCommands::addCommand($id,C::ATTACK,['target'=>$at->toArray(),'resourceCapture'=>true,'speedFactor'=>10]);
 R\FleetCommands::update(['id'=>$attack['id'],'execute_time'=>$enemyAt]);
 R\FleetCommands::addCommand($id,C::CAPTURE,['target'=>$at->toArray(),'combatCapture'=>true,'speedFactor'=>10]);
 R\FleetCommands::addCommand($id,C::STAY,['at'=>$at->toArray(),'speedFactor'=>10]);
 $result+=['attacker'=>$id,'enemyAt'=>$enemyAt,'guardArrival'=>$arrival,'type'=>$type,'count'=>$count];
 if($lostWorker){
  $enemy=S\FleetsData::getInstance()->get($id);
  S\FleetSpaceBattleService::arriveResource(R\FleetCommands::getCommand((int)$attack['id']),$enemy,new S\AccountData(10));
  $battle=S\ResourceBaseService::battleAt($siteId);$check(($battle['state']['phase']??null)==='running','Бой не начался.');
  $state=$battle['state'];$state['job']=['token'=>bin2hex(random_bytes(16)),'round'=>1,'submittedAt'=>$now];$state['attempts']=1;
  R\SpaceBattles::store((int)$battle['key'],$state);
  $result+=['battle'=>$state['id'],'lostWorkerSubmittedAt'=>$now,'workerTimeoutAt'=>$now+S\FleetBattleService::JOB_TIMEOUT];
 }
}
foreach([R\Techs::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,R\SpaceBattles::class]as $r){$r::syncToDatabase();$pending=$r::getPendingSync();$check($pending['update']===[]&&$pending['delete']===[],'Не сохранено:'.$r);}
echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
