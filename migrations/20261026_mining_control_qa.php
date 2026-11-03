<?php

declare(strict_types=1);

// Только подготовка QA9 при остановленном main, не второй игровой процесс.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories\{Vars, Fleets, FleetCommands, ResourceBases};
use SPGame\Game\Services\{FleetSurveyService, ResourceBaseService};

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$mode = $argv[1] ?? '';
$check(in_array($mode, ['--start', '--repeat', '--fuel-test', '--invalid', '--restore'], true), 'Требуется --start/--repeat/--fuel-test/--invalid/--restore.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924', 'Другой QA-аккаунт.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Есть незавершённый checkpoint.');
$miner = $db->fetchOne('SELECT * FROM fleets WHERE id=47');
$check($miner !== null && (int)$miner['owner_id'] === 9 && $miner['name'] === 'QA Miner A', 'Другой QA-добытчик.');
$site = $db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=19');
$check($site !== null && (int)$site['site_type'] === 2, 'Другой тестовый сектор.');
$fallback = $db->fetchOne('SELECT owner_id,galaxy,system,planet,planet_type FROM planets WHERE id=26');
$check($fallback !== null && array_map('intval', array_values($fallback)) === [9, 1, 6, 2, 1], 'Другая резервная планета QA.');
$target = FleetSurveyService::coordinate($site)->toArray();
$check((int)$miner['current_site_id'] === 19, 'QA47 не у тестового объекта.');
$base = $db->fetchOne('SELECT * FROM resource_bases WHERE id=19');
$chain = $db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=47 ORDER BY id');
$now = (float)sprintf('%.6F', microtime(true));
$patch = ['id' => 47, 'base_fuel_time' => 0.0, 'base_fuel_rate' => 0.0, 'base_fuel_reserve' => 0.0, 'base_fuel_speed' => 0.0];
$relocated = [];
if ($mode === '--restore') {
    $check($base === null && (int)$miner['home_site_id'] === 0 && (int)$miner['home_planet_id'] === 26,
        'Отрицательная проверка не сняла базу.');
    $check(count($chain) === 2 && (int)$chain[0]['command_type'] === Command::MINING->value
        && (int)$chain[1]['command_type'] === Command::STAY->value, 'Другая очередь QA47.');
    $params = json_decode($chain[0]['params'], true, 512, JSON_THROW_ON_ERROR);
    $check(($params['qaMiningControl'] ?? null) === 'invalid' && !isset($params['mining']), 'Другая/начавшаяся добыча.');
    $base = $params['qaBase'];
    $check((int)$base['id'] === 19 && (int)$base['owner_id'] === 9, 'Другая база восстановления.');
} else {
    $check((int)($base['owner_id'] ?? 0) === 9 && (int)$miner['status'] === 0
        && (int)$miner['home_site_id'] === 19 && $chain === [], 'QA47 занят или база изменилась.');
    $members = $db->fetchAll('SELECT * FROM fleets WHERE owner_id=9 AND home_site_id=19 ORDER BY id');
    $check(array_column($members, 'id') === ($mode === '--start' ? [47, 51, 52] : [47]), 'Другая охрана базы.');
    if ($mode === '--start') foreach ($members as $member) if ((int)$member['id'] !== 47) {
        $id = (int)$member['id'];
        $check((int)$member['status'] === 0 && $db->fetchOne('SELECT id FROM fleet_commands WHERE fleet_id=?', [$id]) === null
            && $member['name'] === ($id === 51 ? 'QA Mining survey' : 'QA Survey guard'), 'Другой/занятый QA-охранник.');
        $relocated[] = $member;
    }
    $clock = ['time' => $miner['base_fuel_time'], 'rate' => $miner['base_fuel_rate'], 'reserve' => $miner['base_fuel_reserve']];
    $patch['fuel_current'] = ResourceBaseService::standingProjection((float)$miner['fuel_current'], $clock, $now)['fuel'];
    // Только QA: короткий запас над известным резервом140 для реальной due-границы.
    if ($mode === '--fuel-test') {
        $check(abs((float)$miner['base_fuel_reserve'] - 140.0) < 1e-6, 'Изменился резерв QA-эвакуации.');
        $patch['fuel_current'] = 141.0;
    }
    $patch['base_fuel_time'] = $now;
    foreach (['rate', 'reserve', 'speed'] as $key) $patch['base_fuel_' . $key] = $miner['base_fuel_' . $key];
}
Vars::init(); Fleets::init(); FleetCommands::init(); ResourceBases::init();
foreach ($relocated as $member) {
    $clock = ['time' => $member['base_fuel_time'], 'rate' => $member['base_fuel_rate'], 'reserve' => $member['base_fuel_reserve']];
    $row = ['id' => $member['id'], 'home_planet_id' => 26, 'home_site_id' => 0, 'current_position_time' => $now,
        'base_fuel_time' => 0.0, 'base_fuel_rate' => 0.0, 'base_fuel_reserve' => 0.0, 'base_fuel_speed' => 0.0,
        'fuel_current' => ResourceBaseService::standingProjection((float)$member['fuel_current'], $clock, $now)['fuel']];
    foreach (['start', 'target', 'current'] as $prefix) {
        foreach (['coordinate_type' => 1, 'galaxy' => 1, 'system' => 6, 'orbit' => 2, 'object_type' => 1,
            'site_id' => 0, 'distance' => 0.0, 'angle' => 0.0, 'fleet_id' => 0] as $field => $value) $row[$prefix . '_' . $field] = $value;
    }
    Fleets::update($row);
}
if ($mode === '--restore') {
    // Удаляются только две проверенные выше команды собственного отрицательного fixture.
    FleetCommands::clearFleetQueue(47);
    ResourceBases::add($base);
    Fleets::update($patch + ['status' => 0, 'mission' => 0, 'is_return' => 0,
        'home_planet_id' => 0, 'home_site_id' => 19, 'current_position_time' => $now]);
    $at = null;
} else {
    $invalid = $mode === '--invalid';
    $at = $now + ($invalid ? 10 : 45);
    Fleets::update($patch + ['status' => 1, 'mission' => Mission::MINING->value, 'is_return' => 0, 'current_position_time' => $at]);
    $command = FleetCommands::addCommand(47, Command::MINING, ['target' => $target, 'speedFactor' => 10,
        'qaMiningControl' => $invalid ? 'invalid' : 'start', 'qaBase' => $base,
        'miningRequest' => ['mode' => $invalid ? 'invalid' : 'mix', 'duration' => 3600, 'finish' => 'stay', 'speedFactor' => 10]]);
    FleetCommands::update(['id' => $command['id'], 'execute_time' => $at]);
    FleetCommands::addCommand(47, Command::STAY, ['at' => $target, 'homeSiteId' => 19, 'speedFactor' => 10]);
}
foreach ([Fleets::class, FleetCommands::class, ResourceBases::class] as $repository) {
    $repository::syncToDatabase();
    $check($repository::getPendingSync()['update'] === [] && $repository::getPendingSync()['delete'] === [], 'Fixture не сохранён.');
}
echo json_encode(['mode' => $mode, 'siteId' => 19, 'minerId' => 47, 'miningAt' => $at,
    'relocatedToPlanet26' => array_column($relocated, 'id'), 'issuedShips' => 0, 'issuedResources' => 0,
    'negativeBaseRemovalConfirmed' => $mode === '--restore', 'homeBefore' => [$miner['home_site_id'], $miner['home_planet_id']],
    'fuelBefore' => $miner['fuel_current'], 'fixtureFuel' => $patch['fuel_current'] ?? $miner['fuel_current'],
    'standingReserveBefore' => $miner['base_fuel_reserve'],
    'journal' => $db->fetchOne('SELECT revision,phase FROM mining_checkpoints WHERE site_id=19'),
    'cargoPreserved' => [$miner['resource_metal'], $miner['resource_crystal']]], JSON_UNESCAPED_UNICODE), PHP_EOL;
