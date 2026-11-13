<?php
declare(strict_types=1);

// Одноразовый cutover основного dev: верхняя команда старого main + QA-корабль.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\RepositoryIdRanges;
Environment::init(__DIR__ . '/../.env');
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--prepare', '--prepare-slots', '--restore-slots'], true)
    || ($mode === '--prepare' && !ctype_digit($argv[2] ?? ''))) {
    throw new LogicException('Нужны --prepare ID, --prepare-slots или --restore-slots.');
}
$socket = @stream_socket_client('tcp://127.0.0.1:9501', $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$db = Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') !== 'qaown_260924'
    || (int)($db->fetchOne('SELECT owner_id FROM planets WHERE id=26')['owner_id'] ?? 0) !== 9) {
    throw new LogicException('Другой QA-контекст.');
}
if ($mode !== '--prepare') {
    $from = $mode === '--prepare-slots' ? 10 : 30;
    $to = $mode === '--prepare-slots' ? 30 : 10;
    $current = (int)$db->fetchOne('SELECT fleet_logistics_tech FROM techs WHERE id=9')['fleet_logistics_tech'];
    if ($current !== $from) throw new LogicException('QA-технология уже изменена.');
    $db->query('UPDATE techs SET fleet_logistics_tech=? WHERE id=9 AND fleet_logistics_tech=?', [$to, $from]);
    echo json_encode(['qaLogisticsBefore'=>$from,'qaLogisticsAfter'=>$to]), PHP_EOL;
    exit;
}
$floor = (int)$argv[2];
$max = (int)$db->fetchOne('SELECT MAX(id) n FROM fleet_commands')['n'];
if ($floor < $max || $floor > $max + 4096) throw new LogicException('Неожиданная нижняя граница команд.');
new RepositoryIdRanges('fleet_commands', $floor);
$db->beginTransaction();
try {
    $count = (int)$db->fetchOne('SELECT small_ship_cargo FROM ships WHERE id=26 FOR UPDATE')['small_ship_cargo'];
    if ($count !== 0) throw new LogicException('QA-ангар уже изменён, не выдаём корабль повторно.');
    $db->query('UPDATE ships SET small_ship_cargo=1 WHERE id=26 AND small_ship_cargo=0');
    $db->commit();
} catch (Throwable $e) { $db->rollBack(); throw $e; }
echo json_encode(['commandFloor'=>$floor,'qaSmallCargo'=>1,'fleetCount'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM fleets')['n'],
    'fleetIdsHash'=>hash('sha256',json_encode($db->fetchAll('SELECT id FROM fleets ORDER BY id')))]), PHP_EOL;
