<?php
declare(strict_types=1);

// Только offline подготовка main; передислокация отправляется обычным SPA.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Enums\{FleetCommandType as C,FleetMissionType as M};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
Environment::init(__DIR__.'/../.env'); $db=Database::getInstance();
$check=static function(bool $ok,string $why):void { if(!$ok) throw new LogicException($why); };
$mode=$argv[1]??''; $check(in_array($mode,['--prepare','--attack','--pending'],true),'Нужен режим.');
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924','Другой QA.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending mining journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Незавершённый бой.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,R\Galaxy::class,
 R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,R\Resources::class,
 R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,
 R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,R\SpaceBattles::class,
 R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class]as $r)$r::init();
R\MiningCheckpoints::init();
$now=(float)sprintf('%.6F',microtime(true));
$named=static function(string $name):?array {foreach(R\Fleets::getAll()??[]as $r)if($r['name']===$name)return $r;return null;};
$result=['mode'=>$mode,'now'=>$now];
if($mode==='--prepare'){
 $check($named('QA Relocate reinforcement')===null,'Уже подготовлено.');
 foreach([3,4]as $site){
  $check(R\ResourceBases::findExistingById($site)===null,'QA-объект занят.');
  R\ResourceBases::restoreDeletedRow(['id'=>$site,'owner_id'=>10,'created_at'=>$now,'updated_at'=>$now]);
 }
 foreach([['QA Relocate host',3],['QA Relocate source',4],['QA Relocate reinforcement',4]]as [$name,$site]){
  $at=S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById($site));
  $row=['owner_id'=>10,'name'=>$name,'home_site_id'=>$site,'fallback_planet_id'=>27,
   'fuel_current'=>80,'fuel_total'=>80,'current_position_time'=>$now,'resource_metal'=>$name==='QA Relocate reinforcement'?7:0];
  foreach(['coordinate_type'=>1,'galaxy'=>$at->getGalaxy(),'system'=>$at->getSystem(),'orbit'=>$at->getOrbit(),
   'object_type'=>$at->getObjectType()->value,'site_id'=>$site]as $k=>$v)$row['current_'.$k]=$v;
  $id=R\Fleets::add($row); R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>10,R\Vars::$resource[204].'_count'=>2]);
  $f=S\FleetsData::getInstance()->get($id); S\ResourceBaseService::beginStanding($f,new S\AccountData(16),$now,10,$at);$f->save();
  $result['fleets'][$name]=$id;
 }
}else{
 $row=$named('QA Relocate reinforcement');$check($row!==null&&(int)$row['owner_id']===10,'Нет QA-флота.');
 $f=S\FleetsData::getInstance()->get((int)$row['id']);$move=R\FleetCommands::getActiveCommand($f->getId());
 $check($move!==null&&(int)$move['command_type']===C::MOVE_IN_SYSTEM->value,'Дождитесь сохранённого MOVE.');
 $target=S\FleetControlService::params($move)['target'];$site=(int)($target['siteId']??0);
 $at=S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById($site));
 $check($at->toArray()===$target&&(int)(R\ResourceBases::findExistingById($site)['owner_id']??0)===10,'Другая цель/база.');
 $arrival=(float)$move['execute_time'];$check($arrival>$now+15,'Слишком близко прибытие.');
 $check($mode!=='--pending'||$arrival<$now+50,'Для lost worker нужно окно 15–50с.');
 $name=$mode==='--attack'?'QA Relocate enemy':'QA Relocate pending enemy';$check($named($name)===null,'Враг уже создан.');
 $enemyAt=$mode==='--attack'?$arrival-4:$now;
 $home=R\Planets::findExistingById(30);$check((int)$home['owner_id']===5,'Другая база врага.');
 $enemy=['owner_id'=>5,'name'=>$name,'home_planet_id'=>30,'fallback_planet_id'=>30,'status'=>1,
  'mission'=>M::CAPTURE->value,'current_position_time'=>$enemyAt,'fuel_current'=>$mode==='--attack'?1000:100000,'fuel_total'=>$mode==='--attack'?1000:100000];
 foreach(['current','target']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$at->getGalaxy(),'system'=>$at->getSystem(),
  'orbit'=>$at->getOrbit(),'object_type'=>$at->getObjectType()->value,'site_id'=>$site]as $k=>$v)$enemy[$prefix.'_'.$k]=$v;
 foreach(['coordinate_type'=>1,'galaxy'=>$home['galaxy'],'system'=>$home['system'],'orbit'=>$home['planet'],'object_type'=>1]as $k=>$v)$enemy['start_'.$k]=$v;
 $id=R\Fleets::add($enemy);$type=$mode==='--attack'?203:207;
 R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>5,R\Vars::$resource[$type].'_count'=>$mode==='--attack'?4:500]);
 $attack=R\FleetCommands::addCommand($id,C::ATTACK,['target'=>$target,'resourceCapture'=>true,'speedFactor'=>10]);
 R\FleetCommands::update(['id'=>$attack['id'],'execute_time'=>$enemyAt]);
 R\FleetCommands::addCommand($id,C::CAPTURE,['target'=>$target,'combatCapture'=>true,'speedFactor'=>10]);
 R\FleetCommands::addCommand($id,C::STAY,['at'=>$target,'speedFactor'=>10]);
 $result+=['site'=>$site,'reinforcement'=>$f->getId(),'arrival'=>$arrival,'attacker'=>$id,'enemyAt'=>$enemyAt];
 if($mode==='--pending'){
  S\FleetSpaceBattleService::arriveResource(R\FleetCommands::getCommand((int)$attack['id']),S\FleetsData::getInstance()->get($id),new S\AccountData(10));
  $battle=S\ResourceBaseService::battleAt($site);$check(($battle['state']['phase']??null)==='running','Не начат бой.');
  $state=$battle['state'];$state['job']=['token'=>bin2hex(random_bytes(16)),'round'=>1,'submittedAt'=>$now];$state['attempts']=1;
  R\SpaceBattles::store((int)$battle['key'],$state);
  $result+=['battle'=>$state['id'],'lostWorkerTimeout'=>$now+S\FleetBattleService::JOB_TIMEOUT];
 }
}
foreach([R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,R\SpaceBattles::class]as $r){
 $r::syncToDatabase();$p=$r::getPendingSync();$check($p['update']===[]&&$p['delete']===[],'Не сохранено:'.$r);
}
echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
