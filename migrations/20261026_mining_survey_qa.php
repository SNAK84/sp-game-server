<?php

declare(strict_types=1);

// Только QA9 при остановленном main: внутренний MINING и стоящий survey-fixture.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories\{Vars, Fleets, FleetsShips, FleetCommands, ResourceSurveys, ResourceBases};
use SPGame\Game\Services\{FleetSurveyService, ResourceBaseService};

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$repair = ($argv[1] ?? '') === '--repair';
$check($repair || ($argv[1] ?? '') === '--prepare', 'Требуется явный --prepare/--repair.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924', 'Другой QA-аккаунт.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Есть незавершённая добыча.');
$oldSurveyor = $db->fetchOne("SELECT id FROM fleets WHERE owner_id=9 AND name='QA Mining survey'");
$check($repair ? $oldSurveyor !== null : $oldSurveyor === null, 'Другое состояние survey-fixture.');
$miner = $db->fetchOne('SELECT * FROM fleets WHERE id=47');
$check($miner !== null && (int)$miner['owner_id'] === 9 && ($repair
    ? (int)$miner['mission'] === Mission::MINING->value && (int)$miner['home_site_id'] === 0
    : (int)$miner['status'] === 0 && (int)$miner['home_site_id'] === 19),
    'Добытчик QA47 занят/изменён.');
$check($repair || $db->fetchOne('SELECT id FROM fleet_commands WHERE fleet_id=47') === null, 'У QA47 есть команды.');
$base = $db->fetchOne('SELECT owner_id FROM resource_bases WHERE id=19');
$check($repair ? $base === null : (int)($base['owner_id'] ?? 0) === 9, 'Другое состояние QA-базы.');
$site = $db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=19');
$check($site !== null && (int)$site['site_type'] === 2, 'Нет QA-сектора.');
Vars::init(); Fleets::init(); FleetsShips::init(); FleetCommands::init(); ResourceSurveys::init(); ResourceBases::init();
$known = ResourceSurveys::findByIndex('owner_site', [9, 19]);
$check($known !== null && $known['coverage'] === ($repair ? .25 : 1.0), 'Неожиданное исходное обследование.');
$personal = json_decode($known['state'], true, 512, JSON_THROW_ON_ERROR);
foreach ($personal['reserve'] as &$reserve) if (!$repair) $reserve *= .25;
unset($reserve);
ResourceSurveys::update(['id' => $known['id'], 'coverage' => .25, 'state' => json_encode($personal, JSON_THROW_ON_ERROR)]);
$now = (float)sprintf('%.6F', microtime(true)); $at = $now + 60;
if ($repair) {
    ResourceBases::add(['id' => 19, 'owner_id' => 9, 'created_at' => $now, 'updated_at' => $now]);
    foreach ([47, (int)$oldSurveyor['id']] as $fixtureId) FleetCommands::clearFleetQueue($fixtureId);
}
$clock = ['time' => $miner['base_fuel_time'], 'rate' => $miner['base_fuel_rate'], 'reserve' => $miner['base_fuel_reserve']];
$projection = $repair ? ['fuel' => (float)$miner['fuel_current']] : ResourceBaseService::standingProjection((float)$miner['fuel_current'], $clock, $now);
Fleets::update(['id' => 47, 'status' => 1, 'mission' => Mission::MINING->value, 'is_return' => 0,
    'current_position_time' => $at, 'fuel_current' => $projection['fuel'], 'base_fuel_time' => $repair ? 0 : $now,
    'home_site_id' => 19, 'home_planet_id' => 0, 'fallback_planet_id' => 26]);
$target = FleetSurveyService::coordinate($site)->toArray();
$mining = FleetCommands::addCommand(47, Command::MINING, ['target' => $target, 'speedFactor' => 10,
    'miningRequest' => ['mode' => 'mix', 'duration' => 14400, 'finish' => 'stay', 'speedFactor' => 10]]);
FleetCommands::update(['id' => $mining['id'], 'execute_time' => $at]);
FleetCommands::addCommand(47, Command::STAY, ['at' => $target, 'homeSiteId' => 19, 'speedFactor' => 10]);
$row = ['owner_id' => 9, 'name' => 'QA Mining survey', 'home_planet_id' => 0, 'home_site_id' => 19,
    'fallback_planet_id' => 26, 'status' => 1, 'mission' => Mission::RESEARCH->value,
    'current_position_time' => $now, 'fuel_current' => 5000.0, 'fuel_total' => 5000.0];
$coords = ['coordinate_type' => $target['type'], 'galaxy' => $target['galaxy'], 'system' => $target['system'],
    'orbit' => $target['orbit'], 'object_type' => $target['objectType'], 'site_id' => 19];
foreach (['start', 'target', 'current'] as $prefix) foreach ($coords as $field => $value) $row[$prefix . '_' . $field] = $value;
$id = $repair ? (int)$oldSurveyor['id'] : Fleets::add($row); $check($id !== null, 'Не создан QA-сюрвейер.');
if ($repair) {
    $row['id'] = $id; $row['fuel_current'] = Fleets::findExistingById($id)['fuel_current'];
    Fleets::update($row);
} else FleetsShips::add(['id' => $id, 'fleet_id' => $id, 'owner_id' => 9, 'geological_surveyor_count' => 16]);
FleetCommands::addCommand($id, Command::RESEARCH, ['target' => $target, 'speedFactor' => 10, 'pause' => 'manual']);
FleetCommands::addCommand($id, Command::STAY, ['at' => $target, 'homeSiteId' => 19, 'speedFactor' => 10]);
$check($db->fetchOne("SELECT id FROM fleets WHERE owner_id=9 AND name='QA Survey guard'") === null, 'Охрана уже создана.');
$guardRow = $row; unset($guardRow['id']);
$guardRow['name'] = 'QA Survey guard'; $guardRow['status'] = 0; $guardRow['mission'] = 0;
$guardRow['fuel_current'] = $guardRow['fuel_total'] = 1000.0;
$guardId = Fleets::add($guardRow);
FleetsShips::add(['id' => $guardId, 'fleet_id' => $guardId, 'owner_id' => 9, 'small_ship_cargo_count' => 1]);
foreach ([Fleets::class, FleetsShips::class, FleetCommands::class, ResourceSurveys::class, ResourceBases::class] as $repository) {
    $repository::syncToDatabase(); $check($repository::getPendingSync()['update'] === [], 'Fixture не сохранён.');
}
echo json_encode(['ownerId' => 9, 'siteId' => 19, 'minerId' => 47, 'surveyorId' => $id, 'guardId' => $guardId, 'miningAt' => $at,
    'coverageBefore' => $repair ? .25 : 1, 'coverageFixture' => .25,
    'issued' => $repair ? ['smallCargo' => 1, 'guardFuel' => 1000] : ['geologicalSurveyors' => 16, 'fuel' => 5000, 'smallCargo' => 1, 'guardFuel' => 1000],
    'minerCargoPreserved' => [$miner['resource_metal'], $miner['resource_crystal']]], JSON_UNESCAPED_UNICODE), PHP_EOL;
