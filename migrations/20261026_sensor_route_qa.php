<?php

declare(strict_types=1);

// Одноразовая подготовка быстрого QA-флота, не игровой сервер и не smoke.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{Vars, Fleets, FleetsShips};

Environment::init(__DIR__ . '/../.env');
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--prepare', '--repair-capacity'], true)) throw new LogicException('Требуется --prepare.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) throw new LogicException('Сначала остановите основной сервер.');
$db = Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') !== 'qaown_260924'
    || (int)($db->fetchOne('SELECT owner_id FROM planets WHERE id=26')['owner_id'] ?? 0) !== 9
    ) {
    throw new LogicException('Другой QA-аккаунт/планета.');
}
Vars::init(); Fleets::init(); FleetsShips::init();
if ($mode === '--repair-capacity') {
    $row = $db->fetchOne("SELECT id FROM fleets WHERE owner_id=9 AND name='QA Sensor route' AND status=0");
    $id = (int)($row['id'] ?? 0);
    $probe = Vars::$resource[210] . '_count';
    $group = FleetsShips::findExistingById($id);
    if ($id <= 0 || (int)($group[$probe] ?? 0) !== 1
        || $db->fetchOne('SELECT id FROM fleet_commands WHERE fleet_id=? AND executed=0', [$id]) !== null) {
        throw new LogicException('Другой или уже отправленный QA-флот.');
    }
    FleetsShips::update(['id' => $id, $probe => 2]); FleetsShips::syncToDatabase();
    echo json_encode(['fleetId' => $id, 'issuedProbes' => 1, 'totalProbes' => 2]), PHP_EOL;
    exit;
}
if ($db->fetchOne("SELECT id FROM fleets WHERE name='QA Sensor route'") !== null) throw new LogicException('Fixture уже подготовлен.');
$now = microtime(true);
$row = ['owner_id' => 9, 'name' => 'QA Sensor route', 'home_planet_id' => 26,
    'fallback_planet_id' => 26, 'status' => 0, 'mission' => 0, 'current_position_time' => $now];
foreach (['start', 'current', 'target'] as $prefix) $row += [
    $prefix . '_coordinate_type' => 1, $prefix . '_galaxy' => 1, $prefix . '_system' => 6,
    $prefix . '_orbit' => 2, $prefix . '_object_type' => 1];
$id = Fleets::add($row);
if ($id === null) throw new RuntimeException('Не создан QA-флот.');
FleetsShips::add(['id' => $id, 'fleet_id' => $id, 'owner_id' => 9, Vars::$resource[210] . '_count' => 2]);
foreach ([Fleets::class, FleetsShips::class] as $repository) {
    $repository::syncToDatabase(); $sync = $repository::getPendingSync();
    if ($sync['update'] !== [] || $sync['delete'] !== []) throw new RuntimeException('QA-флот не сохранён.');
}
echo json_encode(['fleetId' => $id, 'ownerId' => 9, 'planetId' => 26, 'issuedProbes' => 2]), PHP_EOL;
