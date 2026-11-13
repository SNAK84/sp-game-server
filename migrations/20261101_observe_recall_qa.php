<?php
declare(strict_types=1);

// Только новые QA P001 при остановленном main. Отзыв и возврат исполняет main.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as C;
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

if (($argv[1] ?? '') !== '--prepare') throw new LogicException('Нужен --prepare.');
$socket = @stream_socket_client('tcp://127.0.0.1:9501', $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') !== 'qaown_260924'
    || $db->fetchOne('SELECT id FROM fleets WHERE name LIKE ?', ['QA P001 %']) !== null
    || $db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") !== null
    || $db->fetchOne("SELECT id FROM planet_battles WHERE phase<>'applied'") !== null
    || $db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") !== null) {
    throw new LogicException('Другой QA-контекст или сцена уже существует/занята.');
}
R\Vars::init();
foreach ([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
    R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
    R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
    R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class,
    R\SensorContacts::class,R\PhalanxScans::class] as $repo) $repo::init();
R\MiningCheckpoints::init();
$owner = new S\AccountData(15); $now = microtime(true);
$planet = R\Planets::findExistingById(26);
if ((int)($planet['owner_id'] ?? 0) !== 9 || (int)$owner['Techs']['sensor_tech'] < 3) throw new LogicException('Изменена QA-база/сенсорная технология.');
$home = Coordinate::object((int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'], ObjectType::PLANET);
$at = (new S\FleetFlightService())->resolvePhysicalPoint($home, $now);
$point = Coordinate::point($at->galaxy, $at->system, $at->radius + 100, fmod($at->angle + .2, 360));
$ids = [];
foreach (['ordinary' => [203,1000.0,5.0], 'sensor' => [221,1000.0,10.0], 'low' => [203,2.0,10.0]] as $role => [$ship,$fuel,$speed]) {
    $id = R\Fleets::add(['owner_id'=>9, 'name'=>'QA P001 '.$role, 'home_planet_id'=>26, 'fallback_planet_id'=>26,
        'status'=>1, 'mission'=>30, 'fuel_current'=>$fuel, 'fuel_total'=>$fuel, 'current_position_time'=>$now]);
    R\FleetsShips::add(['id'=>$id, 'fleet_id'=>$id, 'owner_id'=>9, R\Vars::$resource[$ship].'_count'=>1]);
    $fleet = S\FleetsData::getInstance()->get($id);
    $fleet->setCurrentCoordinate($point,$now); $fleet->setStartCoordinate($home); $fleet->setTargetCoordinate($point);
    // У разведчика бак занимает весь трюм1000; груз ему не задаём.
    foreach ([901=>$role==='sensor'?0:11,902=>$role==='sensor'?0:7,903=>$role==='sensor'?0:($role==='low'?100:3)] as $resource=>$count) {
        $fleet->getResources()[$resource]->setCount($count);
    }
    $fleet->save();
    R\FleetCommands::addCommand($id,C::OBSERVE,['target'=>$point->toArray(),'speedFactor'=>$speed]);
    foreach (S\FleetMissionBuilder::buildReturn($point,$home) as $step) {
        R\FleetCommands::addCommand($id,$step['type'],$step['params']+['speedFactor'=>$speed,'returnLeg'=>true]);
    }
    S\FleetCommandsService::activateNextCommand($fleet,$owner);
    if ($role==='sensor') S\FleetSensorService::setMode($fleet,$owner,true,$now);
    $ids[$role]=$id;
}
foreach ([R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class] as $repo) {
    $repo::syncToDatabase(); $pending=$repo::getPendingSync();
    if ($pending['update']!==[]||$pending['delete']!==[]) throw new RuntimeException('Не сохранена QA-сцена.');
}
echo json_encode(['fleets'=>$ids,'point'=>$point->toArray(),'home'=>$home->toArray()]),PHP_EOL;
