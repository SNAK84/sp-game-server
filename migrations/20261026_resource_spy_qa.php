<?php

declare(strict_types=1);

// Одноразовая подготовка только QA9/10 при остановленном основном сервере.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories\{Vars, Config, Techs, Galaxy, GalaxyOrbits, Planets, OrbitalResourceSites,
    ResourceBases, ResourceSurveys, Fleets, FleetsShips, FleetCommands};
use SPGame\Game\Services\{FleetSurveyService, FleetMissionBuilder};
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$check(($argv[1] ?? '') === '--prepare', 'Требуется --prepare.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924'
    && ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase<>'applied'") === null, 'Незавершённый journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") === null, 'Незавершённый бой.');
$check($db->fetchOne("SELECT id FROM fleets WHERE name LIKE 'QA Spy %'") === null, 'Fixture уже подготовлен.');
$check((int)($db->fetchOne('SELECT owner_id FROM resource_bases WHERE id=23')['owner_id'] ?? 0) === 9, 'Нет своей тестовой базы23.');
Vars::init(); Config::init(); Techs::init(); Galaxy::init(); GalaxyOrbits::init(); Planets::init();
OrbitalResourceSites::init(); ResourceBases::init(); ResourceSurveys::init(); Fleets::init(); FleetsShips::init(); FleetCommands::init();
$miner = Fleets::findExistingById(54); $ships = FleetsShips::findExistingById(54);
$check($miner !== null && (int)$miner['owner_id'] === 9 && $miner['name'] === 'QA Battle miner A'
    && (int)$miner['status'] === 0 && (int)$miner['home_planet_id'] === 26 && FleetCommands::getFleetQueue(54) === []
    && (int)($ships[Vars::$resource[224] . '_count'] ?? 0) === 2, 'Другой/занятый QA-добытчик54.');
$home = Planets::findExistingById(26); $enemy = Planets::findExistingById(27);
$check((int)$home['owner_id'] === 9 && (int)$enemy['owner_id'] === 10, 'Другие домашние объекты.');
$site = OrbitalResourceSites::findExistingById(23); $at = FleetSurveyService::coordinate($site);
$now = (float)sprintf('%.6F', microtime(true)); $begin = $now + 45;
$positionFields = static function (Coordinate $position): array {
    return ['coordinate_type' => $position->getType()->value, 'galaxy' => $position->getGalaxy(),
        'system' => $position->getSystem(), 'orbit' => $position->getOrbit() ?? 0,
        'object_type' => $position->getObjectType()?->value ?? 0, 'site_id' => $position->getSiteId() ?? 0,
        'distance' => $position->getDistance() ?? 0, 'angle' => $position->getAngle() ?? 0];
};
$create = static function (string $name, int $owner, int $planet, Coordinate $position, float $fuel, array $counts, bool $idle)
    use ($now, $positionFields, $check): int {
    $row = ['owner_id' => $owner, 'name' => $name, 'home_planet_id' => $planet, 'fallback_planet_id' => $planet,
        'status' => $idle ? 0 : 1, 'mission' => $idle ? 0 : Mission::OBSERVE->value,
        'current_position_time' => $now, 'fuel_current' => $fuel, 'fuel_total' => $fuel];
    foreach (['start', 'current', 'target'] as $prefix) foreach ($positionFields($position) as $field => $value) $row[$prefix . '_' . $field] = $value;
    $id = Fleets::add($row); $check($id !== null, 'Не создан QA-флот.');
    $group = ['id' => $id, 'fleet_id' => $id, 'owner_id' => $owner];
    foreach ($counts as $type => $count) $group[Vars::$resource[$type] . '_count'] = $count;
    FleetsShips::add($group); return $id;
};
$patch = ['id' => 54, 'status' => 1, 'mission' => Mission::MINING->value,
    'fuel_total' => 5000, 'fuel_current' => 5000, 'current_position_time' => $begin];
foreach (['start', 'current', 'target'] as $prefix) foreach ($positionFields($at) as $field => $value) $patch[$prefix . '_' . $field] = $value;
Fleets::update($patch);
$c = FleetCommands::addCommand(54, Command::MINING, ['target' => $at->toArray(), 'speedFactor' => 10,
    'miningRequest' => ['mode' => 'deuterium', 'duration' => 3600, 'finish' => 'return', 'speedFactor' => 10]]);
FleetCommands::update(['id' => $c['id'], 'execute_time' => $begin]);
foreach (FleetMissionBuilder::buildReturn($at, Coordinate::object(1, 6, 2, ObjectType::PLANET)) as $step) {
    FleetCommands::addCommand(54, $step['type'], $step['params'] + ['returnLeg' => true, 'speedFactor' => 10]);
}
$control = $create('QA Spy free control', 9, 26, Coordinate::point(1, 7, 1500, 20), 1000, [202 => 1], false);
$sensor = $create('QA Spy sensor', 10, 27, Coordinate::point(1, 7, 1800, 30), 1000, [221 => 1], false);
foreach ([$control => 1.0, $sensor => 5.0] as $id => $hourly) {
    $row = Fleets::findExistingById($id);
    $point = Coordinate::point((int)$row['current_galaxy'], (int)$row['current_system'], $row['current_distance'], $row['current_angle']);
    $c = FleetCommands::addCommand($id, Command::OBSERVE, ['target' => $point->toArray(), 'sensorsEnabled' => false,
        'hold' => ['startedAt' => $now, 'lastAt' => $now, 'rate' => $hourly / 3600], 'speedFactor' => 10]);
    FleetCommands::update(['id' => $c['id'], 'execute_time' => $now + 3600]);
}
$spy = $create('QA Spy probes', 10, 27, Coordinate::object(1, 10, 5, ObjectType::PLANET), 0, [210 => 20], true);
$tech = Techs::findExistingById(10); $oldSpy = (int)$tech[Vars::$resource[106]];
Techs::update(['id' => 10, Vars::$resource[106] => max(10, $oldSpy)]);
foreach ([Techs::class, Fleets::class, FleetsShips::class, FleetCommands::class] as $repository) {
    $repository::syncToDatabase(); $sync = $repository::getPendingSync();
    $check($sync['update'] === [] && $sync['delete'] === [], 'Fixture не сохранён.');
}
echo json_encode(['miner' => 54, 'guard' => 53, 'control' => $control, 'sensor' => $sensor, 'spy' => $spy,
    'siteId' => 23, 'begin' => $begin, 'spyTechnologyBefore' => $oldSpy, 'spyTechnologyAfter' => max(10, $oldSpy),
    'issued' => ['smallCargo' => 1, 'sensorScout' => 1, 'probes' => 20, 'fuel' => 7000],
    'minerCargoAndShipsPreserved' => true], JSON_UNESCAPED_UNICODE), PHP_EOL;
