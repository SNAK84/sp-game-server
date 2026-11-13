<?php

declare(strict_types=1);

// Контролируемая сцена только в основном dev-мире, не отдельный игровой сервер.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Repositories as R;

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$mode = $argv[1] ?? '';
if ($mode === '--inspect') {
    echo json_encode($db->fetchAll("SELECT f.id,f.name,f.owner_id,f.status,f.fuel_current,
        c.id command_id,c.command_type,c.executed,c.execute_time,c.params
        FROM fleets f LEFT JOIN fleet_commands c ON c.fleet_id=f.id
        WHERE f.name LIKE 'QA B004 %' ORDER BY f.id,c.id"), JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if ($mode !== '--prepare') throw new LogicException('Нужен --prepare или --inspect.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
foreach ([15 => 'qaown_260924', 16 => 'qafor_260924'] as $id => $login) {
    if (($db->fetchOne('SELECT login FROM accounts WHERE id=?', [$id])['login'] ?? '') !== $login) {
        throw new LogicException('Другой QA-аккаунт.');
    }
}
if ($db->fetchOne("SELECT id FROM fleets WHERE name LIKE 'QA B004 %'") !== null) {
    throw new LogicException('Сцена уже существует.');
}
if ((int)($db->fetchOne('SELECT sensor_tech FROM techs WHERE id=9')['sensor_tech'] ?? -1) !== 10
    || (int)($db->fetchOne('SELECT spy_tech FROM techs WHERE id=10')['spy_tech'] ?? -1) !== 10) {
    throw new LogicException('Для порога 6/8 нужны S=E=10. Технологии не менять автоматически.');
}
R\Vars::init();
foreach ([R\Fleets::class, R\FleetsShips::class, R\FleetCommands::class] as $repo) $repo::init();
$now = (float)sprintf('%.6F', microtime(true));
$ids = [];
foreach (['source', 'pending', 'manual', 'fuel', 'moving', 'completed'] as $index => $kind) {
    $source = $kind === 'source';
    $owner = $source ? 9 : 10;
    $point = ['type' => 2, 'galaxy' => 1, 'system' => 10, 'distance' => 2000 + 200 * $index, 'angle' => 30.0];
    $end = array_replace($point, ['distance' => $point['distance'] + 1000]);
    $row = ['owner_id' => $owner, 'name' => 'QA B004 ' . $kind, 'home_planet_id' => $source ? 26 : 27,
        'fallback_planet_id' => $source ? 26 : 27, 'status' => 1, 'mission' => 30,
        'current_position_time' => $now, 'fuel_total' => $source ? 100.0 : 1000.0,
        'fuel_current' => $source ? 100.0 : 1000.0];
    foreach (['start', 'current', 'target'] as $prefix) {
        foreach ($point as $key => $value) $row[$prefix . '_' . ($key === 'type' ? 'coordinate_type' : $key)] = $value;
    }
    $id = R\Fleets::add($row);
    if ($id === null) throw new RuntimeException('Не создан QA-флот.');
    R\FleetsShips::add(['id' => $id, 'fleet_id' => $id, 'owner_id' => $owner,
        R\Vars::$resource[$source ? 221 : 202] . '_count' => $source ? 1 : 100]);
    if (!$source) {
        $params = ['target' => $end, 'motionStart' => $point, 'motionEnd' => $end, 'speedFactor' => 10];
        if (in_array($kind, ['manual', 'fuel'], true)) $params['pause'] = $kind;
        $move = R\FleetCommands::addCommand($id, Command::MOVE_IN_SYSTEM, $params);
        if ($kind === 'moving') R\FleetCommands::update(['id' => $move['id'], 'execute_time' => $now + 3600]);
        if ($kind === 'completed') R\FleetCommands::update(['id' => $move['id'], 'execute_time' => $now - 1, 'executed' => 1]);
    }
    if ($source || $kind === 'completed') {
        $hold = ['target' => $point, 'hold' => ['rate' => .001, 'startedAt' => $now, 'lastAt' => $now]];
        if ($source) $hold['sensorsEnabled'] = true;
        $command = R\FleetCommands::addCommand($id, Command::OBSERVE, $hold);
        R\FleetCommands::update(['id' => $command['id'], 'execute_time' => $now + 86400]);
    }
    $ids[$kind] = $id;
}
foreach ([R\Fleets::class, R\FleetsShips::class, R\FleetCommands::class] as $repo) {
    $repo::syncToDatabase();
    $pending = $repo::getPendingSync();
    if ($pending['update'] !== [] || $pending['delete'] !== []) throw new RuntimeException('Не сохранена QA-сцена.');
}
echo json_encode($ids, JSON_THROW_ON_ERROR), PHP_EOL;
