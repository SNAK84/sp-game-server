<?php

declare(strict_types=1);

// Прибывшие QA-fixtures на остановленном main. Не второй игровой сервер и не публичная отправка.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories\{Vars, Config, Techs, Galaxy, GalaxyOrbits, Planets, OrbitalResourceSites,
    Fleets, FleetsShips, FleetCommands, ResourceBases, ResourceSurveys};
use SPGame\Game\Services\{FleetSurveyService, FleetFlightService, FleetMissionBuilder};
use SPGame\Game\Space\Coordinate;

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$repair = ($argv[1] ?? '') === '--repair-attack';
$shared = ($argv[1] ?? '') === '--shared-belt';
$testSite = $shared ? 19 : 23;
$namePrefix = $shared ? 'QA Site ' : 'QA Battle ';
$check($repair || $shared || ($argv[1] ?? '') === '--prepare', 'Требуется --prepare/--repair-attack/--shared-belt.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924'
    && ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Незавершённый journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") === null, 'Есть незавершённый бой.');
if (!$repair) {
    $check($db->fetchOne('SELECT id FROM resource_bases WHERE id=' . $testSite) === null, 'Тестовый объект уже занят.');
    $check($db->fetchOne("SELECT id FROM fleets WHERE name LIKE '" . $namePrefix . "%'") === null, 'Боевой fixture уже создан.');
}
$check((float)($db->fetchOne('SELECT coverage FROM resource_surveys WHERE owner_id=9 AND site_id=' . $testSite)['coverage'] ?? 0) > 0,
    'Нет личного обследования QA9.');
Vars::init(); Config::init(); Techs::init(); Galaxy::init(); GalaxyOrbits::init(); Planets::init(); OrbitalResourceSites::init();
Fleets::init(); FleetsShips::init(); FleetCommands::init(); ResourceBases::init(); ResourceSurveys::init();
$site = OrbitalResourceSites::findExistingById($testSite);
$check($site !== null && (int)$site['site_type'] === ($shared ? 2 : 1), 'Нет тестового ресурсного объекта.');
$home = Planets::findExistingById(26); $enemyHome = Planets::findExistingById(27);
$check((int)($home['owner_id'] ?? 0) === 9 && (int)($enemyHome['owner_id'] ?? 0) === 10, 'Другие домашние планеты QA.');
$now = (float)sprintf('%.6F', microtime(true)); $begin = $now + 45; $fight = $now + ($repair ? 45 : 120);
$at = FleetSurveyService::coordinate($site);
$physical = (new FleetFlightService())->resolvePhysicalPoint($at, $fight);
$point = Coordinate::point($physical->galaxy, $physical->system, $physical->radius, $physical->angle);
if ($repair) {
    $a = $db->fetchOne("SELECT * FROM fleets WHERE owner_id=10 AND name='QA Battle interceptor'");
    $target = $db->fetchOne("SELECT id FROM fleets WHERE owner_id=9 AND name='QA Battle miner A'");
    $check($a !== null && $target !== null, 'Нет своего QA-fixture.');
    $commands = FleetCommands::getFleetQueue((int)$a['id']); $command = $commands[0] ?? null;
    $check($command !== null && (int)$command['command_type'] === Command::ATTACK->value, 'Другой этап перехватчика.');
    $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
    $check((int)($params['target']['fleetId'] ?? 0) === (int)$target['id'] && !isset($params['battleWait']), 'Другой/начатый бой.');
    $check(count($commands) === 6, 'Изменена цепочка тестового перехватчика.');
    Fleets::update(['id' => $a['id'], 'current_position_time' => $fight,
        'current_distance' => $point->getDistance(), 'current_angle' => $point->getAngle()]);
    // ATTACK запускается активатором после MOVE, а не как самостоятельная timed-команда.
    // Удаляется только проверенная неначатая цепочка собственного QA-fixture.
    FleetCommands::clearFleetQueue((int)$a['id']);
    $move = FleetCommands::addCommand((int)$a['id'], Command::MOVE_IN_SYSTEM, ['target' => $point->toArray(), 'fuelCost' => 0, 'speedFactor' => 10]);
    FleetCommands::update(['id' => $move['id'], 'execute_time' => $fight]);
    FleetCommands::addCommand((int)$a['id'], Command::ATTACK,
        ['target' => Coordinate::fleet((int)$target['id'], $point)->toArray(), 'speedFactor' => 10]);
    $enemyCoordinate = Coordinate::object((int)$enemyHome['galaxy'], (int)$enemyHome['system'], (int)$enemyHome['planet'],
        \SPGame\Game\Space\ObjectType::PLANET);
    foreach (FleetMissionBuilder::buildReturn($point, $enemyCoordinate) as $step) {
        FleetCommands::addCommand((int)$a['id'], $step['type'], $step['params'] + ['returnLeg' => true, 'speedFactor' => 10]);
    }
    foreach ([Fleets::class, FleetCommands::class] as $repository) {
        $repository::syncToDatabase(); $check($repository::getPendingSync()['update'] === [], 'Repair не сохранён.');
    }
    echo json_encode(['repairedAttacker' => $a['id'], 'target' => $target['id'], 'fight' => $fight,
        'issuedShips' => 0, 'qa47Untouched' => true]), PHP_EOL;
    return;
}
$techBefore = [];
foreach ([9 => [136 => 3], 10 => [135 => 20, 108 => 6]] as $owner => $levels) {
    $row = Techs::findExistingById($owner); $patch = ['id' => $owner];
    foreach ($levels as $id => $level) {
        $field = Vars::$resource[$id]; $techBefore[$owner][$field] = $row[$field];
        $patch[$field] = max((int)$row[$field], $level);
    }
    Techs::update($patch);
}
$create = static function (string $name, int $owner, int $planet, Coordinate $position, float $time, float $fuel, array $ships,
    int $status, int $mission, int $homeSite = 0) use ($check): int {
    $row = ['owner_id' => $owner, 'name' => $name, 'home_planet_id' => $homeSite > 0 ? 0 : $planet,
        'home_site_id' => $homeSite, 'fallback_planet_id' => $planet, 'status' => $status, 'mission' => $mission,
        'current_position_time' => $time, 'fuel_current' => $fuel, 'fuel_total' => $fuel];
    foreach (['start', 'current', 'target'] as $prefix) {
        foreach (['coordinate_type' => $position->getType()->value, 'galaxy' => $position->getGalaxy(),
            'system' => $position->getSystem(), 'orbit' => $position->getOrbit() ?? 0,
            'object_type' => $position->getObjectType()?->value ?? 0, 'site_id' => $position->getSiteId() ?? 0,
            'distance' => $position->getDistance() ?? 0, 'angle' => $position->getAngle() ?? 0] as $field => $value) $row[$prefix . '_' . $field] = $value;
    }
    $id = Fleets::add($row); $check($id !== null, 'Не создан QA-флот.');
    $groups = ['id' => $id, 'fleet_id' => $id, 'owner_id' => $owner];
    foreach ($ships as $type => $count) $groups[Vars::$resource[$type] . '_count'] = $count;
    FleetsShips::add($groups);
    return $id;
};
ResourceBases::add(['id' => $testSite, 'owner_id' => 9, 'created_at' => $now, 'updated_at' => $now]);
$guard = $create($namePrefix . 'guard', 9, 26, $at, $now, 5000, [202 => 1], 0, 0, $testSite);
$excluded = [];
if ($shared) {
    $neighbor = OrbitalResourceSites::findExistingById(18);
    $check($neighbor !== null && $neighbor['orbit'] === $site['orbit'] && $neighbor['id'] !== $site['id'], 'Нет соседнего сектора.');
    foreach (['neighbor' => FleetSurveyService::coordinate($neighbor), 'away' => Coordinate::point(1, 6, 1000, 10)] as $label => $position) {
        $id = $create($namePrefix . $label, 9, 26, $position, $now, 1000, [202 => 1], 1, Mission::OBSERVE->value,
            $label === 'away' ? $testSite : 0);
        FleetCommands::addCommand($id, Command::OBSERVE, ['target' => $position->toArray(), 'pause' => 'manual']);
        $excluded[$label] = $id;
    }
}
$miners = [];
foreach (['A', 'B'] as $label) {
    $id = $create($namePrefix . 'miner ' . $label, 9, 26, $at, $begin, 5000, [$shared ? 223 : 224 => 2], 1, Mission::MINING->value);
    Fleets::update(['id' => $id, 'resource_metal' => 17, 'resource_crystal' => 19, 'resource_deuterium' => 23]);
    $c = FleetCommands::addCommand($id, Command::MINING, ['target' => $at->toArray(), 'speedFactor' => 10,
        'miningRequest' => ['mode' => $shared ? 'mix' : 'deuterium', 'duration' => 3600, 'finish' => 'return', 'speedFactor' => 10]]);
    FleetCommands::update(['id' => $c['id'], 'execute_time' => $begin]);
    $homeCoordinate = Coordinate::object((int)$home['galaxy'], (int)$home['system'], (int)$home['planet'],
        \SPGame\Game\Space\ObjectType::PLANET);
    foreach (FleetMissionBuilder::buildReturn($at, $homeCoordinate) as $step) {
        FleetCommands::addCommand($id, $step['type'], $step['params'] + ['returnLeg' => true, 'speedFactor' => 10]);
    }
    $miners[] = $id;
}
$sensor = $create($namePrefix . 'sensor', 10, 27, $point, $now, 500, [221 => 1], 1, Mission::OBSERVE->value);
$c = FleetCommands::addCommand($sensor, Command::OBSERVE, ['target' => $point->toArray(), 'sensorsEnabled' => true,
    'hold' => ['startedAt' => $now, 'lastAt' => $now, 'rate' => 5 / 3600], 'speedFactor' => 10]);
FleetCommands::update(['id' => $c['id'], 'execute_time' => $now + 3600]);
$attacker = $create($namePrefix . 'interceptor', 10, 27, $point, $fight, 1000, [204 => 10, 202 => 2], 1, Mission::ATTACK->value);
$c = FleetCommands::addCommand($attacker, Command::MOVE_IN_SYSTEM, ['target' => $point->toArray(), 'fuelCost' => 0, 'speedFactor' => 10]);
FleetCommands::update(['id' => $c['id'], 'execute_time' => $fight]);
FleetCommands::addCommand($attacker, Command::ATTACK, ['target' => Coordinate::fleet($miners[0], $point)->toArray(), 'speedFactor' => 10]);
$enemyCoordinate = Coordinate::object((int)$enemyHome['galaxy'], (int)$enemyHome['system'], (int)$enemyHome['planet'],
    \SPGame\Game\Space\ObjectType::PLANET);
foreach (FleetMissionBuilder::buildReturn($point, $enemyCoordinate) as $step) {
    FleetCommands::addCommand($attacker, $step['type'], $step['params'] + ['returnLeg' => true, 'speedFactor' => 10]);
}
foreach ([Techs::class, Fleets::class, FleetsShips::class, FleetCommands::class, ResourceBases::class] as $repository) {
    $repository::syncToDatabase(); $sync = $repository::getPendingSync();
    $check($sync['update'] === [] && $sync['delete'] === [], 'Fixture не сохранён.');
}
echo json_encode(['guard' => $guard, 'miners' => $miners, 'sensor' => $sensor, 'attacker' => $attacker,
    'siteId' => $testSite, 'excluded' => $excluded,
    'begin' => $begin, 'fight' => $fight, 'techBefore' => $techBefore,
    'issued' => ['smallCargo' => $shared ? 5 : 3, 'gasExtractor' => $shared ? 0 : 4, 'oreExtractor' => $shared ? 4 : 0,
        'sensorScout' => 1, 'lightFighter' => 10,
        'fuel' => $shared ? 18500 : 16500, 'cargoPerMiner' => [17, 19, 23]], 'qa47Untouched' => true], JSON_UNESCAPED_UNICODE), PHP_EOL;
