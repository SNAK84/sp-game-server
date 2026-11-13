<?php

declare(strict_types=1);

// Изолированные Fleet/repositories; ни MySQL, ни работающий сервер не меняются.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Game\Fleet\FleetShip;
use SPGame\Game\Repositories\{Config, Vars};
use SPGame\Game\Services\FleetFlightService;

$fixture = installTransportFixture();
$fleet = $fixture['fleet'];
$account = $fixture['account'];
Vars::$attributes[202] = array_replace(Vars::$attributes[202], [
    'speed' => 30000.0, 'consumption' => 75.0, 'consumption2' => 75.0, 'tech' => 1,
]);
$fleet->setShipCount(202, 199);
$fleet->save();
$flight = new FleetFlightService();

// Реальные игровые дистанции последнего участка Fleet #17 и верхней границы preview.
$actualDistance = 1104.2598144307522;
$maximumDistance = 1106.4666666666667;
$actualFuel = $flight->calculateFuelConsumption($fleet, $account, [$actualDistance], 10.0);
$maximumFuel = $flight->calculateFuelConsumption($fleet, $account, [$maximumDistance], 10.0);
transportAssert($actualFuel === 1885.0 && $maximumFuel === 1888.0,
    'Fleet #17: округлённый таймер не должен увеличивать расход короткого участка сверх максимума.');
transportAssert($actualFuel <= 1885.0, 'Fleet #17 должен завершить возврат без добавления топлива.');
echo "OK Fleet #17 actual fuel 1885 <= upper bound 1888\n";

foreach ([0.1, 0.75, 1.0, 4.35, 7.0, 10.0] as $factor) {
    $previous = 0.0;
    // Плотный проход пересекает границы округления времени и топлива.
    for ($i = 0; $i <= 12000; ++$i) {
        $distance = $i / 10.0;
        $fuel = $flight->calculateFuelConsumption($fleet, $account, [$distance], $factor);
        transportAssert($fuel >= $previous, "Расход не монотонен: factor={$factor}, distance={$distance}.");
        $previous = $fuel;
    }
}
echo "OK monotonic fuel over 72006 distance/speed combinations\n";

Vars::$resource[204] = 'light_hunter';
Vars::$attributes[204] = array_replace(Vars::$attributes[202], ['speed' => 60000.0, 'consumption' => 20.0]);
$ships = [new FleetShip(202, 'small_cargo', 199), new FleetShip(204, 'light_hunter', 100)];
$factor = 4.35;
$distance = 2795.0;
$expected = round((199 * 75.0 * ($factor / 10 + 1) ** 2
    + 100 * 20.0 * ($factor * sqrt(30000 / 60000) / 10 + 1) ** 2) * $distance / 35000) + 1;
transportAssert($flight->calculateFuelConsumption($fleet, $account, [$distance], $factor, $ships) === $expected,
    'Смешанный состав должен учитывать скорость каждой группы и дробный SpeedFactor.');
echo "OK mixed composition and fractional SpeedFactor match continuous Nova formula\n";

transportAssert($flight->calculateFuelConsumption($fleet, $account, [$distance])
    === $flight->calculateFuelConsumption($fleet, $account, [$distance], 1.0), 'Потерян default SpeedFactor.');
$before = $flight->calculateFuelConsumption($fleet, $account, [$distance], 10.0);
(new ReflectionProperty(Config::class, 'syncTable'))->setValue(null, transportSyncTable(3));
Config::update(['id' => 1, 'value' => '1000']);
transportAssert($flight->calculateFuelConsumption($fleet, $account, [$distance], 10.0) === $before,
    'Календарный FleetGameSpeed не должен менять стоимость того же перелёта.');
transportAssert($flight->calculateFuelConsumption($fleet, $account, [0.0], 10.0) === 0.0,
    'Нулевая дистанция не должна расходовать топливо.');
Config::update(['id' => 1, 'value' => '10']);
echo "OK default factor, zero distance and game speed invariance\n";

// Минимальные 5 секунд — ограничение таймера, не скачок в цене перелёта.
transportAssert($flight->calculateGameDuration(5.0, 30000.0, 10.0) >= 5.0, 'Изменён минимум времени.');
transportAssert($flight->calculateGameDuration($actualDistance, 30000.0, 10.0) === 213.0
    && $flight->calculateGameDuration($maximumDistance, 30000.0, 10.0) === 214.0,
    'Исправление топлива не должно менять время полёта.');
echo "OK rounded arrival times unchanged\n";
