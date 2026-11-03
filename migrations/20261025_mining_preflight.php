<?php

declare(strict_types=1);

// Только SELECT и расчёт на копиях сохранённых строк. Не создаёт флоты,
// не включает mining и не запускает второй игровой/Swoole-сервер.
require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Repositories\OrbitalResourceSites as Sites;
use SPGame\Game\Services\FleetMiningService;

if (in_array('--apply', $argv, true)) throw new LogicException('Mining preflight только читает данные.');
$siteId = filter_var($argv[1] ?? '19', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ownerId = filter_var($argv[2] ?? '9', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($siteId === false || $ownerId === false || count($argv) > 3) {
    throw new InvalidArgumentException('Использование: php migrations/20261025_mining_preflight.php [siteId] [ownerId]');
}
Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$read = static fn(): array => [
    $db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=?', [$siteId]),
    $db->fetchOne('SELECT * FROM resource_surveys WHERE owner_id=? AND site_id=?', [$ownerId, $siteId]),
];
[$site, $known] = $before = $read();
if ($site === null || $known === null) throw new LogicException('Нет сохранённого объекта или личного обследования.');
$start = microtime(true);
$state = FleetMiningService::baseline($site, $known, $start);
$gas = (int)$site['site_type'] === Sites::GAS_GIANT;
$hull = $gas ? 224 : 223;
$level = $gas ? 3 : 1;
$fleets = [];
// Это два РАСЧЁТНЫХ примера с мощностями1:3, не выданные игровому аккаунту корабли.
foreach ([1 => 1, 2 => 3] as $id => $count) {
    $fleets[$id] = ['rates' => FleetMiningService::rates($site, [$hull => $count], $level, $gas ? 'deuterium' : 'mix'),
        'free' => 50000.0, 'fuel' => 10000.0, 'fuelRate' => 10.0 * $count,
        'fuelReserve' => 500.0, 'endsAt' => $start + 3600];
}
$result = FleetMiningService::phase($state, $fleets, $start + 3600);
$hours = $result['elapsed'] / 3600;
$errors = $balance = [];
foreach ($state['resources'] as $id => $resource) {
    $extracted = array_sum(array_column($result['cargo'], $id));
    $after = $result['state']['resources'][$id];
    $expectedStock = min($resource['capacity'], $resource['stock'] + $resource['rate'] * $hours - $extracted);
    $expectedReserve = min($state['coverage'] * $resource['capacity'], $resource['reserve']
        + $state['coverage'] * $resource['rate'] * $hours - $extracted);
    if (abs($expectedStock - $after['stock']) > 1e-7 || abs($expectedReserve - $after['reserve']) > 1e-7) {
        $errors[] = 'Не сошёлся баланс ресурса ' . $id;
    }
    $balance[$id] = ['stockBefore' => $resource['stock'], 'stockAfter' => $after['stock'],
        'reserveBefore' => $resource['reserve'], 'reserveAfter' => $after['reserve'], 'extracted' => $extracted];
}
$unchanged = $before === $read();
if (!$unchanged) $errors[] = 'Сохранённые строки изменились во время проверки; результат требует повторной сверки.';
echo json_encode(['readOnly' => true, 'siteId' => $siteId, 'ownerId' => $ownerId,
    'mode' => $gas ? 'deuterium' : 'mix', 'exampleTechnologyLevel' => $level,
    'exampleShipCounts' => [1 => 1, 2 => 3], 'elapsed' => $result['elapsed'], 'events' => $result['events'],
    'examplesNotLiveFleets' => $result['cargo'], 'balance' => $balance, 'sourceRowsUnchanged' => $unchanged,
    // Наличие enum не означает доступность отправки в обычном SendMission.
    'missionRegistered' => FleetMissionType::fromKey('mining') !== null, 'errors' => $errors],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
if ($errors !== []) throw new RuntimeException('Расчётный mining preflight не пройден.');
