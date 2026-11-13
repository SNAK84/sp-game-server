<?php

declare(strict_types=1);

// Только подготовка QA. Оба CAPTURE отправляются через настоящее окно миссии.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env'); $db=Database::getInstance();
$check=static function(bool $ok,string $why):void {if(!$ok)throw new LogicException($why);};
$check(($argv[1]??'')==='--prepare','Требуется --prepare.');
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
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class] as $repository)$repository::init();
R\MiningCheckpoints::init();
$check(R\ResourceBases::findExistingById(1)===null,'Сектор1 уже занят.');
foreach(R\Fleets::getAll()??[] as $row)$check(!str_starts_with($row['name'],'QA Reinforce'),'Fixture уже существует.');
$first=R\Fleets::findExistingById(79);
$check($first!==null && (int)$first['owner_id']===10 && $first['name']==='QA Capture draw'
    && (int)$first['status']===0 && R\FleetCommands::getFleetQueue(79)===[],'QA79 не готов.');
$now=(float)sprintf('%.6F',microtime(true)); $at=S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById(1));
R\ResourceBases::restoreDeletedRow(['id'=>1,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
$row=['owner_id'=>9,'name'=>'QA Reinforce guard','home_site_id'=>1,'fallback_planet_id'=>26,
    'current_position_time'=>$now,'fuel_current'=>90,'fuel_total'=>90];
foreach(['start','current','target']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$at->getGalaxy(),
    'system'=>$at->getSystem(),'orbit'=>$at->getOrbit(),'object_type'=>$at->getObjectType()->value,'site_id'=>1]as $key=>$value)$row[$prefix.'_'.$key]=$value;
$guard=R\Fleets::add($row);
R\FleetsShips::add(['id'=>$guard,'fleet_id'=>$guard,'owner_id'=>9,R\Vars::$resource[204].'_count'=>2]);
$guardFleet=S\FleetsData::getInstance()->get($guard);
S\ResourceBaseService::beginStanding($guardFleet,new S\AccountData(15),$now,10,$at);$guardFleet->save();
$planet=R\Planets::findExistingById(27);$check((int)($planet['owner_id']??0)===10,'Потеряна база QA10.');
$home=Coordinate::object((int)$planet['galaxy'],(int)$planet['system'],(int)$planet['planet'],ObjectType::PLANET);
$row=['owner_id'=>10,'name'=>'QA Reinforce second','home_planet_id'=>27,'fallback_planet_id'=>27,'current_position_time'=>$now];
foreach(['current','start']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$home->getGalaxy(),
    'system'=>$home->getSystem(),'orbit'=>$home->getOrbit(),'object_type'=>ObjectType::PLANET->value]as $key=>$value)$row[$prefix.'_'.$key]=$value;
$second=R\Fleets::add($row);
R\FleetsShips::add(['id'=>$second,'fleet_id'=>$second,'owner_id'=>10,R\Vars::$resource[203].'_count'=>1]);
foreach([R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class]as $repository){
    $repository::syncToDatabase();$pending=$repository::getPendingSync();
    $check($pending['update']===[] && $pending['delete']===[],'Не сохранён fixture:'.$repository);
}
echo json_encode(compact('guard','second')+['first'=>79,'siteId'=>1,'preparedAt'=>$now],JSON_THROW_ON_ERROR),PHP_EOL;
