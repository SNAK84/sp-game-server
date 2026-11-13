<?php

declare(strict_types=1);

// Настоящие MoveShip/Fleet/services с изолированными repositories, без MySQL.
require __DIR__ . '/FleetManagementTest.php';

use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{Fleets, FleetsShips, Vars};
use SPGame\Game\Services\{FleetFlightService, FleetsServices};
use SPGame\Game\Space\{Coordinate, ObjectType};
use Swoole\Table;

function speedFixture(): array
{
    $f = installManagementFixture();
    Vars::$resource[204] = 'light_hunter';
    Vars::$resource[212] = 'solar_satellite';
    Vars::$reslist['fleet'] = [202, 204, 212];
    Vars::$attributes[202] = array_replace(Vars::$attributes[202], ['tech' => 4, 'capacity' => 5000]);
    Vars::$attributes[204] = array_replace(Vars::$attributes[202], ['tech' => 1, 'speed' => 12500, 'capacity' => 50]);
    Vars::$attributes[212] = array_replace(Vars::$attributes[202], ['tech' => 0, 'speed' => 0, 'consumption' => 0, 'consumption2' => 0, 'capacity' => 0]);
    Vars::$pricelist = [202 => [901 => 2000, 902 => 2000], 204 => [901 => 3000, 902 => 1000], 212 => [901 => 0, 902 => 2000]];
    $columns = [];
    foreach (['light_hunter', 'solar_satellite'] as $name) {
        foreach (['count' => 0, 'damage' => 0.0, 'experience' => 0.0, 'morale' => 1.0, 'status' => 0] as $suffix => $default) {
            $columns[$name . '_' . $suffix] = ['swoole' => [is_int($default) ? Table::TYPE_INT : Table::TYPE_FLOAT], 'default' => $default];
        }
    }
    appendTransportSchemaColumns(FleetsShips::class, $columns);
    FleetsShips::update(FleetsShips::castRowToSchema(FleetsShips::findExistingById(1), true));
    $f['fleet']->reloadFromRuntime();
    $f['account']['Techs'] = ['combustion_tech' => 6, 'impulse_motor_tech' => 5, 'hyperspace_motor_tech' => 0];
    $f['account']['Ships'] = ['small_cargo' => 0, 'light_hunter' => 508];
    $f['fleet']->setShipCount(202, 50);
    $f['fleet']->save();
    FleetsServices::MoveShip(1, 0, 204, 508, $f['account'], 100);
    FleetsServices::MoveShip(0, 1, 202, 50, $f['account'], 100);
    return $f;
}

function speedMetrics(array $f): array
{
    $flight = new FleetFlightService();
    $speed = $f['fleet']->getTotalSpeed($f['account']);
    $jump = $flight->calculateTransitionDistance(1, 8, 1, 7);
    return [
        'speed' => $speed,
        'duration' => $flight->calculateGameDuration($jump, $speed, 10),
        'fuel' => $flight->calculateFuelConsumption($f['fleet'], $f['account'], [$jump], 10),
        'preview' => $f['fleet']->getTravelInfo(
            FleetTarget::fromCoordinates(Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray()),
            $f['account'], 10, FleetMissionType::RELOCATE
        ),
    ];
}

$f = speedFixture();
transportAssert($f['fleet']->getShip(202)->getCount() === 0 && $f['fleet']->getShip(204)->getCount() === 508,
    'MoveShip оставляет пустую группу в кэше и сохраняет 508 истребителей.');
$before = speedMetrics($f);
transportAssert($before['speed'] === 20000.0, 'Нулевой транспорт не должен замедлять истребители.');
transportAssert($before['fuel'] === 3246.0 && $before['duration'] === 415.0,
    'Регрессия #11: переход рассчитывается по настоящему составу.');
$rows = [Fleets::getById(1), FleetsShips::findExistingById(1)];
$markers = [Fleets::getPendingSync(), FleetsShips::getPendingSync()];
$f['fleet']->reloadFromRuntime();
transportAssert($f['fleet']->getShip(202) === null, 'Загрузчик по-прежнему пропускает нулевые группы.');
transportAssert(speedMetrics($f) === $before, 'Скорость, время, топливо и preview одинаковы после загрузки сохранённых строк.');
transportAssert([Fleets::getById(1), FleetsShips::findExistingById(1)] === $rows
    && [Fleets::getPendingSync(), FleetsShips::getPendingSync()] === $markers, 'Чтение/загрузка не меняют записи и markers.');
echo "OK speed zero-count transport cannot change speed duration fuel or preview after reload\n";

FleetsServices::MoveShip(1, 0, 202, 1, $f['account'], 100);
transportAssert($f['fleet']->getTotalSpeed($f['account']) === 10000.0, 'Настоящий транспорт должен ограничивать скорость.');
$withTransport = speedMetrics($f);
$f['fleet']->reloadFromRuntime();
transportAssert(speedMetrics($f) === $withTransport, 'Настоящий медленный корабль одинаково учитывается после загрузки.');
FleetsServices::MoveShip(0, 1, 202, 1, $f['account'], 100);
transportAssert(speedMetrics($f) === $before, 'Повторное удаление всех транспортов сразу восстанавливает расчёт, без restart.');
echo "OK speed real slower ships still limit fleet and removal updates calculations immediately\n";

$f['fleet']->setShipCount(212, 0);
transportAssert(speedMetrics($f) === $before, 'Пустая группа неподвижных кораблей не должна обнулять скорость.');
$f['fleet']->setShipCount(212, 1);
transportAssert($f['fleet']->getTotalSpeed($f['account']) === 0.0, 'Нельзя игнорировать настоящий неподвижный корабль.');
$f['fleet']->setShipCount(212, 0);
echo "OK speed zero-count stationary group is ignored but positive stationary group is preserved\n";

$f['fleet']->setShipCount(204, 0);
transportAssert($f['fleet']->getTotalSpeed($f['account']) === 0.0, 'Пустой флот имеет нулевую скорость.');
transportAssert((new FleetFlightService())->calculateFuelConsumption($f['fleet'], $f['account'], [2795], 10) === 0.0,
    'Пустой флот не расходует топливо.');
$f['fleet']->save();
$f['fleet']->reloadFromRuntime();
transportAssert($f['fleet']->getTotalSpeed($f['account']) === 0.0, 'Пустой флот остаётся пустым после загрузки.');
echo "OK speed empty cached and reloaded fleet both have zero speed and fuel\n";
