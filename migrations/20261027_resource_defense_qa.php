<?php

declare(strict_types=1);

// QA на основном dev-сервере: подготовка друга, прибывшей атаки и границы бака.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as C, FleetMissionType as M};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate,ObjectType};

Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$check=static function(bool $ok,string $why):void{if(!$ok)throw new LogicException($why);};
$mode=$argv[1]??'';$check(in_array($mode,['--prepare','--attack','--attack-push','--reserve'],true),'Требуется --prepare/--attack/--attack-push/--reserve.');
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')==='qaown_260924'
 &&($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924','Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Незавершённый бой.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
 R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
 R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
 R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
 R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class]as $r)$r::init();
R\MiningCheckpoints::init();
$check((int)(R\ResourceBases::findExistingById(1)['owner_id']??0)===9,'Сектор1 уже не QA9.');
$at=S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById(1));
$now=(float)sprintf('%.6F',microtime(true));$result=['mode'=>$mode,'at'=>$now];
$named=static function(string $name):?array{foreach(R\Fleets::getAll()??[]as $row)if($row['name']===$name)return $row;return null;};
if($mode==='--prepare'){
 $check($named('QA Resource defense')===null,'Охрана уже подготовлена.');
 $pair=R\Friends::pair(9,10);
 if($pair===null)R\Friends::add(['user_low'=>9,'user_high'=>10,'requester_id'=>10,'status'=>R\Friends::ACCEPTED,'created_at'=>time()]);
 else R\Friends::update(['id'=>$pair['id'],'status'=>R\Friends::ACCEPTED]);
 $p=R\Planets::findExistingById(27);$check((int)$p['owner_id']===10,'Другая база27.');
 $row=['owner_id'=>10,'name'=>'QA Resource defense','home_planet_id'=>27,'fallback_planet_id'=>27,'current_position_time'=>$now];
 foreach(['current','start']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$p['galaxy'],'system'=>$p['system'],'orbit'=>$p['planet'],'object_type'=>1]as $key=>$value)$row[$prefix.'_'.$key]=$value;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>10,R\Vars::$resource[204].'_count'=>2]);
 $result+=['guard'=>$id,'siteId'=>1,'friendshipBefore'=>$pair['status']??null];
}else{
 $guard=$named('QA Resource defense');$check($guard!==null,'Нет QA-охраны.');
 $fleet=S\FleetsData::getInstance()->get((int)$guard['id']);$command=R\FleetCommands::getActiveCommand($fleet->getId());
 $check(S\FleetResourceDefendService::isCommand($command)&&isset(S\FleetControlService::params($command)['hold'])&&$fleet->getBaseFuelState()!==null,'Охрана ещё не удерживается.');
 $result+=['guard'=>$fleet->getId(),'guardFuel'=>$fleet->getFuelCurrent(),'guardClock'=>$fleet->getBaseFuelState(),
  'originalUntil'=>S\FleetControlService::params($command)['hold']['until']];
 if(in_array($mode,['--attack','--attack-push'],true)){
  $enemyName=$mode==='--attack'?'QA Resource enemy':'QA Resource enemy push';
  $check($named($enemyName)===null,'Атака уже подготовлена.');
  $p=R\Planets::findExistingById(30);$check((int)($p['owner_id']??0)===5,'Нет тестовой базы snak4 в1:10.');
  $tech=R\Techs::findExistingById(5);$key=R\Vars::$resource[136];R\Techs::update(['id'=>5,$key=>max(1,(int)$tech[$key])]);
  $arrival=$now+90;$row=['owner_id'=>5,'name'=>$enemyName,'home_planet_id'=>30,'fallback_planet_id'=>30,
   'status'=>1,'mission'=>M::CAPTURE->value,'current_position_time'=>$arrival,'fuel_current'=>1000,'fuel_total'=>1000];
  foreach(['current','target']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$at->getGalaxy(),'system'=>$at->getSystem(),'orbit'=>$at->getOrbit(),'object_type'=>$at->getObjectType()->value,'site_id'=>1]as $key=>$value)$row[$prefix.'_'.$key]=$value;
  foreach(['coordinate_type'=>1,'galaxy'=>$p['galaxy'],'system'=>$p['system'],'orbit'=>$p['planet'],'object_type'=>1]as $key=>$value)$row['start_'.$key]=$value;
  $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>5,R\Vars::$resource[203].'_count'=>4]);
  $attack=R\FleetCommands::addCommand($id,C::ATTACK,['target'=>$at->toArray(),'resourceCapture'=>true,'speedFactor'=>10]);
  R\FleetCommands::update(['id'=>$attack['id'],'execute_time'=>$arrival]);
  R\FleetCommands::addCommand($id,C::CAPTURE,['target'=>$at->toArray(),'combatCapture'=>true,'speedFactor'=>10]);
  R\FleetCommands::addCommand($id,C::STAY,['at'=>$at->toArray(),'speedFactor'=>10]);
  $result+=['attacker'=>$id,'arrival'=>$arrival,'miningTechBefore'=>$tech[R\Vars::$resource[136]]];
 }else{
  $params=S\FleetControlService::params($command);S\ResourceBaseService::settleStanding($fleet,$now);$clock=$fleet->getBaseFuelState();
  $check($clock['rate']>0 && (float)$params['hold']['until']>$now+120,'Нет времени/расхода для проверки резерва.');
  $before=$fleet->getFuelCurrent();$fleet->setFuelCurrent($clock['reserve']+.05);$fleet->save();
  $due=S\FleetResourceDefendService::holdEnd((float)$params['hold']['until'],$fleet->getFuelCurrent(),$clock);
  R\FleetCommands::update(['id'=>$command['id'],'execute_time'=>$due]);
  $result+=['guard'=>$fleet->getId(),'fuelBefore'=>$before,'fuel'=>$fleet->getFuelCurrent(),'clock'=>$clock,'due'=>$due];
 }
}
foreach([R\Friends::class,R\Techs::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class]as $r){$r::syncToDatabase();$p=$r::getPendingSync();$check($p['update']===[]&&$p['delete']===[],'Не сохранено:'.$r);}
echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
