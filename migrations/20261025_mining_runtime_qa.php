<?php

declare(strict_types=1);

// Только подготовка QA9 на ОСТАНОВЛЕННОМ dev-сервере. Не игровой процесс.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{Vars, Techs, Fleets, FleetsShips, FleetCommands, ResourceBases};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Services\{FleetSurveyService, ResourceBaseService, FleetMissionBuilder};

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $message): void { if (!$ok) throw new LogicException($message); };
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924', 'Другой QA-аккаунт.');
if (($argv[1] ?? '--inspect') === '--inspect') {
    echo json_encode(['fleets' => $db->fetchAll("SELECT id,name,status,fuel_current,resource_metal,current_position_time FROM fleets WHERE owner_id=9 AND name LIKE 'QA Min%'"),
        'commands' => $db->fetchAll('SELECT id,fleet_id,command_type,execute_time,executed,params FROM fleet_commands WHERE command_type=61'),
        'checkpoint' => $db->fetchOne('SELECT site_id,phase,revision FROM mining_checkpoints WHERE site_id=19')], JSON_UNESCAPED_UNICODE), PHP_EOL;
    return;
}
$merge = ($argv[1] ?? '') === '--merge';
$delivery = ($argv[1] ?? '') === '--delivery' || $merge;
$courierCapacity = ($argv[1] ?? '') === '--courier-capacity';
$finishReturn = ($argv[1] ?? '') === '--finish-return';
$repeat = ($argv[1] ?? '') === '--repeat' || $finishReturn || $delivery || $courierCapacity;
$check($repeat || ($argv[1] ?? '') === '--prepare', 'Неизвестный режим.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Есть незавершённый checkpoint.');
if (!$repeat) {
    $check($db->fetchOne("SELECT id FROM fleets WHERE owner_id=9 AND name LIKE 'QA Min%'") === null, 'QA-подготовка уже была выполнена.');
    $check($db->fetchOne('SELECT id FROM resource_bases WHERE id=19') === null, 'Тестовый сектор уже занят.');
}
$site = $db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=19');
$check($site !== null && (int)$site['site_type'] === 2 && (int)$site['galaxy'] === 1 && (int)$site['system'] === 7, 'Другой QA-сектор.');
$check((float)($db->fetchOne('SELECT coverage FROM resource_surveys WHERE owner_id=9 AND site_id=19')['coverage'] ?? 0) > 0, 'Нет QA-обследования.');
$check(count($db->fetchAll('SELECT elementID FROM vars WHERE elementID IN (223,224)')) === 2, 'Сначала примените миграцию корпусов.');
Vars::init(); Techs::init(); Fleets::init(); FleetsShips::init(); FleetCommands::init(); ResourceBases::init();
if ($courierCapacity) {
    $guard = Fleets::findExistingById(46); $ships = FleetsShips::findExistingById(46);
    $check($guard !== null && (int)$guard['owner_id'] === 9 && $guard['name'] === 'QA Mining guard'
        && (int)$guard['status'] === 0 && (int)$guard['home_site_id'] === 19 && FleetCommands::getFleetCommandChain(46) === []
        && (int)$ships['small_ship_cargo_count'] === 1 && (float)$guard['resource_deuterium'] === 3000.0, 'Другой/занятый QA-доставщик.');
    $now = microtime(true);
    $projection = ResourceBaseService::standingProjection((float)$guard['fuel_current'],
        ['time' => (float)$guard['base_fuel_time'], 'rate' => (float)$guard['base_fuel_rate'], 'reserve' => (float)$guard['base_fuel_reserve']], $now);
    FleetsShips::update(['id' => 46, 'small_ship_cargo_count' => 2]);
    Fleets::update(['id' => 46, 'fuel_current' => $projection['fuel'], 'base_fuel_time' => $now,
        'base_fuel_rate' => $guard['base_fuel_rate'] * 2, 'base_fuel_reserve' => $guard['base_fuel_reserve'] * 2]);
    Fleets::syncToDatabase(); FleetsShips::syncToDatabase();
    $check(Fleets::getPendingSync()['update'] === [] && FleetsShips::getPendingSync()['update'] === [], 'Не сохранена вместимость доставщика.');
    echo "QA_COURIER_CAPACITY +1 малый транспорт; груз сохранён.\n";
    return;
}
if ($repeat) {
    $old = $db->fetchOne('SELECT id FROM fleets WHERE owner_id=9 AND name=?', [$finishReturn ? 'QA Miner B' : 'QA Miner A']);
    $check($old !== null && (int)(ResourceBases::findExistingById(19)['owner_id'] ?? 0) === 9, 'Нет своей QA-базы/добытчика.');
    $id = (int)$old['id']; $fleet = Fleets::findExistingById($id); $ships = FleetsShips::findExistingById($id);
    $check((int)$fleet['status'] === 0 && (int)$fleet['home_site_id'] === 19 && FleetCommands::getFleetCommandChain($id) === [], 'QA-добытчик ещё занят.');
    $check((int)$ships['asteroid_miner_count'] === ($delivery ? 2 : 1), 'Неожиданный состав QA-добытчика.');
    if ($merge) {
        $donor = Fleets::findExistingById(48); $donorShips = FleetsShips::findExistingById(48);
        $check($donor !== null && (int)$donor['owner_id'] === 9 && $donor['name'] === 'QA Miner B'
            && (int)$donor['status'] === 0 && (int)$donor['home_site_id'] === 19
            && FleetCommands::getFleetCommandChain(48) === [] && (int)$donorShips['asteroid_miner_count'] === 1,
            'QA-донор слияния занят/изменён.');
        $check($ships['asteroid_miner_experience'] === 0.0 && $donorShips['asteroid_miner_experience'] === 0.0,
            'Тестовый XP уже был выдан; повтор подготовки запрещён.');
        FleetsShips::update(['id' => $id, 'asteroid_miner_experience' => 60.0, 'asteroid_miner_damage' => .2, 'asteroid_miner_morale' => .9]);
        FleetsShips::update(['id' => 48, 'asteroid_miner_experience' => 40.0, 'asteroid_miner_damage' => .8, 'asteroid_miner_morale' => .6]);
    } elseif ($delivery) {
        $guard = Fleets::findExistingById(46);
        $check($guard !== null && (int)$guard['owner_id'] === 9 && $guard['name'] === 'QA Mining guard'
            && (int)$guard['status'] === 0 && (int)$guard['home_site_id'] === 19 && FleetCommands::getFleetCommandChain(46) === [], 'QA-доставщик занят.');
        $check($guard['resource_metal'] + $guard['resource_crystal'] + $guard['resource_deuterium'] === 0.0, 'У доставщика остался груз.');
        $guardCount = (int)FleetsShips::findExistingById(46)['small_ship_cargo_count'];
        $check(in_array($guardCount, [1, 2], true), 'Другой состав QA-доставщика.');
        $guardAfter = ['id' => 46, 'resource_metal' => 40.0, 'resource_crystal' => 30.0, 'resource_deuterium' => 3000.0];
        if ($guardCount === 1) {
            // Один малый транспорт имеет5000, а не7500: груз с баком не поместится.
            $preparedAt = microtime(true);
            $guardFuel = ResourceBaseService::standingProjection((float)$guard['fuel_current'],
                ['time' => (float)$guard['base_fuel_time'], 'rate' => (float)$guard['base_fuel_rate'], 'reserve' => (float)$guard['base_fuel_reserve']], $preparedAt);
            FleetsShips::update(['id' => 46, 'small_ship_cargo_count' => 2]);
            $guardAfter += ['fuel_current' => $guardFuel['fuel'], 'base_fuel_time' => $preparedAt,
                'base_fuel_rate' => $guard['base_fuel_rate'] * 2, 'base_fuel_reserve' => $guard['base_fuel_reserve'] * 2];
        }
        Fleets::update($guardAfter);
    }
    $now = (float)sprintf('%.6F', microtime(true)); $at = $now + 60;
    $projection = ResourceBaseService::standingProjection((float)$fleet['fuel_current'],
        ['time' => (float)$fleet['base_fuel_time'], 'rate' => (float)$fleet['base_fuel_rate'], 'reserve' => (float)$fleet['base_fuel_reserve']], $now);
    if (!$finishReturn && !$delivery) FleetsShips::update(['id' => $id, 'asteroid_miner_count' => 2]);
    Fleets::update(['id' => $id, 'status' => 1, 'mission' => Mission::MINING->value, 'is_return' => 0,
        'current_position_time' => $at, 'fuel_current' => $projection['fuel'], 'base_fuel_time' => $now,
        'base_fuel_rate' => $fleet['base_fuel_rate'] * ($finishReturn || $delivery ? 1 : 2),
        'base_fuel_reserve' => $fleet['base_fuel_reserve'] * ($finishReturn || $delivery ? 1 : 2)]);
    $target = FleetSurveyService::coordinate($site);
    $command = FleetCommands::addCommand($id, Command::MINING, ['target' => $target->toArray(),
        'miningRequest' => ['mode' => $finishReturn ? 'metal' : 'mix', 'duration' => 3600, 'finish' => $delivery ? 'stay' : 'return', 'speedFactor' => 10]]);
    FleetCommands::update(['id' => $command['id'], 'execute_time' => $at]);
    foreach ($delivery ? [['type' => Command::STAY, 'params' => ['at' => $target->toArray()]]]
        : FleetMissionBuilder::buildReturn($target, $target) as $row) {
        $row['params']['returnLeg'] = true; $row['params']['speedFactor'] = 10;
        FleetCommands::addCommand($id, $row['type'], $row['params']);
    }
    foreach ([Fleets::class, FleetsShips::class, FleetCommands::class] as $repository) {
        $repository::syncToDatabase(); $check($repository::getPendingSync()['update'] === [], 'Повторный QA не сохранён.');
    }
    echo 'QA_MINING_REPEAT ', json_encode(['fleetId' => $id, 'startsAt' => $at, 'oneExtraMiner' => !$finishReturn && !$delivery,
        'courierFixture' => $delivery && !$merge ? ['fleetId' => 46, 'cargo' => [901 => 40, 902 => 30, 903 => 3000]] : null,
        'mergeFixture' => $merge ? ['sourceId' => 48, 'receiverId' => $id, 'groupXP' => [60, 40]] : null,
        'cargoPreserved' => $fleet['resource_metal'], 'fuelAfterStanding' => $projection['fuel']]), PHP_EOL;
    return;
}
$tech = Techs::findExistingById(9);
$check((int)$tech['fleet_logistics_tech'] >= 1 && (int)$tech['extraterrestrial_mining_tech'] >= 1, 'Нет QA-технологий.');
Techs::update(['id' => 9, 'computer_tech' => max(2, (int)$tech['computer_tech'])]);
$now = (float)sprintf('%.6F', microtime(true));
ResourceBases::add(['id' => 19, 'owner_id' => 9, 'created_at' => $now, 'updated_at' => $now]);
$target = FleetSurveyService::coordinate($site)->toArray();
$coords = ['coordinate_type' => $target['type'], 'galaxy' => $target['galaxy'], 'system' => $target['system'],
    'orbit' => $target['orbit'], 'object_type' => $target['objectType'], 'site_id' => 19];
$created = [];
foreach (['QA Mining guard', 'QA Miner A', 'QA Miner B'] as $i => $name) {
    $at = $i === 0 ? $now : $now + 90 + ($i - 1) * 4;
    $row = ['owner_id' => 9, 'name' => $name, 'home_planet_id' => 0, 'home_site_id' => 19, 'fallback_planet_id' => 26,
        'current_position_time' => $at, 'status' => $i === 0 ? 0 : 1, 'mission' => $i === 0 ? 0 : Mission::MINING->value,
        'fuel_total' => 2000.0, 'fuel_current' => 2000.0, 'resource_metal' => $i === 0 ? 0.0 : 17995.0];
    foreach (['start', 'target', 'current'] as $prefix) foreach ($coords as $field => $value) $row[$prefix . '_' . $field] = $value;
    $id = Fleets::add($row); $check($id !== null, 'Не создан QA-флот.');
    FleetsShips::add(['id' => $id, 'fleet_id' => $id, 'owner_id' => 9,
        ($i === 0 ? 'small_ship_cargo_count' : 'asteroid_miner_count') => 1]);
    if ($i > 0) {
        $command = FleetCommands::addCommand($id, Command::MINING, ['target' => $target,
            'miningRequest' => ['mode' => 'metal', 'duration' => 3600, 'finish' => 'stay', 'speedFactor' => 10]]);
        FleetCommands::update(['id' => $command['id'], 'execute_time' => $at]);
        FleetCommands::addCommand($id, Command::STAY, ['at' => $target, 'speedFactor' => 10]);
    }
    $created[] = ['id' => $id, 'name' => $name, 'startsAt' => $at];
}
foreach ([Techs::class, Fleets::class, FleetsShips::class, ResourceBases::class, FleetCommands::class] as $repository) {
    $repository::syncToDatabase();
    $check($repository::getPendingSync()['update'] === [], 'QA не сохранён: ' . $repository);
}
echo 'QA_MINING_SETUP ', json_encode(['ownerId' => 9, 'siteId' => 19, 'created' => $created,
    'computerBefore' => $tech['computer_tech'], 'computerAfter' => max(2, (int)$tech['computer_tech']),
    'fixtureCargoAndFuel' => true], JSON_UNESCAPED_UNICODE), PHP_EOL;
