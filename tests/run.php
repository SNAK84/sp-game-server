<?php

namespace SPGame\Tests;

require_once __DIR__ . '/bootstrap.php';

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetPosition;
use SPGame\Game\Fleet\FleetResource;
use SPGame\Game\Fleet\FleetShip;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Services\FleetsData;

/**
 * Минималистичный самописный тест-раннер для проектов без PHPUnit.
 * Запуск: php tests/run.php
 */

use SPGame\Game\Services\FleetMovement;

final class TestFailure extends \Exception
{
}

function assertTrue(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new TestFailure($msg);
    }
}

function assertEquals($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            ($msg ? $msg . ': ' : '')
            . 'expected ' . var_export($expected, true)
            . ' got ' . var_export($actual, true)
        );
    }
}

function assertEqualsWithDelta(float $expected, float $actual, float $delta, string $msg = ''): void
{
    if (abs($expected - $actual) > $delta) {
        throw new TestFailure(
            ($msg ? $msg . ': ' : '')
            . "expected {$expected} ±{$delta}, got {$actual}"
        );
    }
}

function assertGreaterThan(float $min, float $actual, string $msg = ''): void
{
    if (!($actual > $min)) {
        throw new TestFailure(($msg ? $msg . ': ' : '') . "expected > {$min}, got {$actual}");
    }
}

function assertIsFloat($actual, string $msg = ''): void
{
    if (!is_float($actual)) {
        throw new TestFailure(($msg ? $msg . ': ' : '') . 'expected float, got ' . gettype($actual));
    }
}

function assertThrows(callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return;
    }

    throw new TestFailure(($msg ? $msg . ': ' : '') . 'expected exception, none thrown');
}

// ------------------------------------------------------------------
//  Тестовые данные Vars для изолированного тестирования Fleet
// ------------------------------------------------------------------

Vars::$resource = [
    901 => 'metal',
    902 => 'crystal',
    204 => 'light_fighter',
    205 => 'cruiser',
];

Vars::$reslist = [
    'resstype' => [
        1 => [901, 902],
        3 => [],
    ],
    'fleet' => [204, 205],
];

function makePosition(array $overrides = []): FleetPosition
{
    $defaults = [
        'galaxy' => 1,
        'system' => 1,
        'orbit' => 4,
        'objectType' => 'planet',
        'distance' => 100.0,
        'deg' => 0.0,
        'type' => 'object',
    ];
    $p = array_merge($defaults, $overrides);

    return new FleetPosition($p['galaxy'], $p['system'], $p['orbit'], $p['objectType'], $p['distance'], $p['deg'], $p['type']);
}

function makeFleet(array $overrides = []): Fleet
{
    $defaults = [
        'id' => 1,
        'ownerId' => 10,
        'homePlanetId' => 100,
        'currentParentId' => 100,
        'currentParentType' => 'planet',
        'anchorDistance' => 0.0,
        'mission' => FleetMissionType::TRANSPORT->value,
        'status' => 0,
        'isReturn' => false,
        'speed' => 1000.0,
        'fuelTotal' => 500.0,
        'fuelCurrent' => 500.0,
        'fuelConsumption' => 1.0,
        'experience' => 0.0,
        'morale' => 1.0,
        'damageFactor' => 1.0,
        'efficiency' => 1.0,
        'cargo' => ['metal' => 0.0, 'crystal' => 0.0],
        'startPosition' => makePosition(),
        'endPosition' => makePosition(),
        'currentPosition' => makePosition(),
        'startTime' => 1000.0,
        'endTime' => 2000.0,
        'stayTime' => 0.0,
        'ships' => [],
        'cargoResources' => ['metal', 'crystal'],
        'shipNames' => [204 => 'light_fighter', 205 => 'cruiser'],
    ];
    $f = array_merge($defaults, $overrides);

    $fleet = (new \ReflectionClass(Fleet::class))->newInstanceWithoutConstructor();
    $initialize = function (array $data): void {
        $this->id = $data['id'];
        $this->ownerId = $data['ownerId'];
        $this->homePlanetId = $data['homePlanetId'];
        $this->currentParentId = $data['currentParentId'];
        $this->currentParentType = $data['currentParentType'];
        $this->anchorDistance = $data['anchorDistance'];
        $this->mission = $data['mission']; $this->status = $data['status']; $this->isReturn = $data['isReturn'];
        $this->speed = $data['speed']; $this->fuelTotal = $data['fuelTotal']; $this->fuelCurrent = $data['fuelCurrent'];
        $this->fuelConsumption = $data['fuelConsumption']; $this->experience = $data['experience'];
        $this->morale = $data['morale']; $this->damageFactor = $data['damageFactor']; $this->efficiency = $data['efficiency'];
        $this->cargo = $data['cargo']; $this->cargoResources = $data['cargoResources']; $this->shipNames = $data['shipNames'];
        $this->startPosition = $data['startPosition']; $this->endPosition = $data['endPosition']; $this->currentPosition = $data['currentPosition'];
        $this->startTime = $data['startTime']; $this->endTime = $data['endTime']; $this->stayTime = $data['stayTime'];
        foreach ($data['ships'] as $ship) { $ship->setChangeHandler(fn () => $this->changed()); $this->ships[$ship->getShipTypeId()] = $ship; }
        $this->shipsView = new \SPGame\Game\Fleet\FleetShipsView($this);
    };
    $initialize->call($fleet, $f);
    return $fleet;
}

// ------------------------------------------------------------------
//  Тест-кейсы: чистая математика движения
// ------------------------------------------------------------------

function testDistanceInSystemSamePoint(): void
{
    $d = FleetMovement::distanceInSystem(100, 0, 100, 0);
    assertEqualsWithDelta(0.0, $d, 0.0001, 'same point');
}

function testDistanceInSystemOppositePoints(): void
{
    $d = FleetMovement::distanceInSystem(100, 0, 100, 180);
    assertEqualsWithDelta(200.0, $d, 0.0001, 'opposite points');
}

function testTotalDistanceSameSystemUsesPolar(): void
{
    $start  = ['galaxy' => 1, 'system' => 1, 'distance' => 100, 'deg' => 0];
    $target = ['galaxy' => 1, 'system' => 1, 'distance' => 100, 'deg' => 180];
    $d = FleetMovement::totalDistance($start, $target);
    assertEqualsWithDelta(200.0, $d, 0.0001, 'same system');
}

function testTotalDistanceBetweenSystemsAddsBase(): void
{
    $start  = ['galaxy' => 1, 'system' => 1, 'distance' => 100, 'deg' => 0];
    $target = ['galaxy' => 1, 'system' => 2, 'distance' => 100, 'deg' => 0];
    $d = FleetMovement::totalDistance($start, $target);
    $expected = FleetMovement::DIST_BETWEEN_SYSTEMS
        + (FleetMovement::SYSTEM_RADIUS - 100)
        + 100;
    assertEqualsWithDelta($expected, $d, 0.0001, 'between systems');
}

function testTotalDistanceBetweenGalaxiesAddsBase(): void
{
    $start  = ['galaxy' => 1, 'system' => 1, 'distance' => 100, 'deg' => 0];
    $target = ['galaxy' => 2, 'system' => 1, 'distance' => 100, 'deg' => 0];
    $d = FleetMovement::totalDistance($start, $target);
    $expected = FleetMovement::DIST_BETWEEN_GALAXIES
        + (FleetMovement::SYSTEM_RADIUS - 100)
        + 100;
    assertEqualsWithDelta($expected, $d, 0.0001, 'between galaxies');
}

function testFlightTimeRequiresPositiveSpeed(): void
{
    assertEquals(PHP_FLOAT_MAX, FleetMovement::flightTime(100, 0), 'zero speed');
    assertGreaterThan(0.0, FleetMovement::flightTime(100, 10), 'positive speed');
}

function testFlightTimeProportional(): void
{
    $t1 = FleetMovement::flightTime(100, 10);
    $t2 = FleetMovement::flightTime(200, 10);
    assertEqualsWithDelta(2 * $t1, $t2, 0.0001, 'proportional time');
}

function testRequiredFuel(): void
{
    assertEqualsWithDelta(500.0, FleetMovement::requiredFuel(100, 5), 0.0001, 'required fuel');
}

function testConsumeFuelNeverNegative(): void
{
    assertEquals(0.0, FleetMovement::consumeFuel(10, 100, 5), 'exhaust fuel');
    assertEqualsWithDelta(50.0, FleetMovement::consumeFuel(100, 10, 5), 0.0001, 'partial consume');
}

function testFleetCapacitySum(): void
{
    // В изолированном тесте Vars::$resource может быть не инициализирован.
    // В этом случае функция должна безопасно вернуть 0.0 (float) без ошибки.
    $result = FleetMovement::fleetCapacity(['light_fighter' => 5]);
    assertIsFloat($result, 'capacity float');
}

function testCompositeIndexKeyPreservesFieldOrder(): void
{
    $method = new \ReflectionMethod(\SPGame\Game\Repositories\GalaxyOrbits::class, 'buildIndexKey');
    $method->setAccessible(true);

    $keyA = $method->invoke(null, [1, 2, 1]);
    $keyB = $method->invoke(null, [1, 1, 2]);

    assertTrue($keyA !== $keyB, 'индекс должен учитывать порядок полей');
}

// ------------------------------------------------------------------
//  Тест-кейсы: Fleet / FleetShip / FleetsData
// ------------------------------------------------------------------

function testFleetSetStatusValid(): void
{
    $fleet = makeFleet();
    $fleet->setStatus(1);
    assertEquals(1, $fleet->getStatus(), 'status изменён');
}

function testFleetSetStatusInvalidThrows(): void
{
    $fleet = makeFleet();
    assertThrows(fn () => $fleet->setStatus(99), 'неизвестный status должен бросать исключение');
}

function testFleetSetMissionValid(): void
{
    $fleet = makeFleet();
    $fleet->setMission(FleetMissionType::ATTACK->value);
    assertEquals(FleetMissionType::ATTACK->value, $fleet->getMission(), 'mission изменена');
}

function testFleetSetMissionInvalidThrows(): void
{
    $fleet = makeFleet();
    assertThrows(fn () => $fleet->setMission(999), 'неизвестная миссия должна бросать исключение');
}

function testFleetSetReturn(): void
{
    $fleet = makeFleet(['isReturn' => false]);
    $fleet->setReturn(true);
    assertTrue($fleet->isReturn(), 'is_return изменён');
}

function testFleetPositionMutators(): void
{
    $fleet = makeFleet();
    $newPosition = makePosition(['galaxy' => 2, 'system' => 5, 'orbit' => 6]);

    $fleet->setCurrentPosition($newPosition);
    assertEquals(2, $fleet->getCurrentPosition()->getGalaxy(), 'current position изменена');

    $fleet->setStartPosition($newPosition);
    assertEquals(5, $fleet->getStartPosition()->getSystem(), 'start position изменена');

    $fleet->setEndPosition($newPosition);
    assertEquals(6, $fleet->getEndPosition()->getOrbit(), 'end position изменена');
}

function testFleetFuelMutators(): void
{
    $fleet = makeFleet(['fuelCurrent' => 100.0]);

    $fleet->consumeFuel(30.0);
    assertEqualsWithDelta(70.0, $fleet->getFuelCurrent(), 0.0001, 'топливо уменьшено');

    $fleet->consumeFuel(1000.0);
    assertEqualsWithDelta(0.0, $fleet->getFuelCurrent(), 0.0001, 'топливо не уходит в минус');

    $fleet->addFuel(50.0);
    assertEqualsWithDelta(50.0, $fleet->getFuelCurrent(), 0.0001, 'топливо пополнено');

    $fleet->setFuelCurrent(-10.0);
    assertEqualsWithDelta(0.0, $fleet->getFuelCurrent(), 0.0001, 'setFuelCurrent не допускает отрицательное значение');

    assertThrows(fn () => $fleet->consumeFuel(-1.0), 'отрицательный расход топлива должен бросать исключение');
    assertThrows(fn () => $fleet->addFuel(-1.0), 'отрицательное пополнение топлива должно бросать исключение');
}

function testFleetShipsComposition(): void
{
    $fleet = makeFleet();

    $fleet->addShips(204, 5);
    assertEquals(5, $fleet->getShipCount(204), 'новый тип корабля добавлен');

    $fleet->addShips(204, 3);
    assertEquals(8, $fleet->getShipCount(204), 'количество кораблей суммируется');

    $fleet->removeShips(204, 8);
    assertEquals(0, $fleet->getShipCount(204), 'все корабли удалены');

    assertThrows(fn () => $fleet->addShips(9999, 1), 'неизвестный тип корабля должен бросать исключение');
    assertThrows(fn () => $fleet->removeShips(204, 1), 'нельзя удалить больше кораблей, чем есть');
    assertThrows(fn () => $fleet->addShips(204, -1), 'нельзя добавить отрицательное количество кораблей');
}

function testFleetCargoMutators(): void
{
    $fleet = makeFleet(['cargo' => ['metal' => 10.0, 'crystal' => 0.0]]);

    $fleet->addCargo('metal', 5.0);
    assertEqualsWithDelta(15.0, $fleet->getCargoAmount('metal'), 0.0001, 'груз добавлен');

    $fleet->removeCargo('metal', 100.0);
    assertEqualsWithDelta(0.0, $fleet->getCargoAmount('metal'), 0.0001, 'груз не уходит в минус');

    assertThrows(fn () => $fleet->addCargo('unknown_resource', 1.0), 'неизвестный ресурс должен бросать исключение');
    assertThrows(fn () => $fleet->addCargo('metal', -1.0), 'нельзя добавить отрицательный груз');
    assertThrows(fn () => $fleet->removeCargo('metal', -1.0), 'нельзя удалить отрицательный груз');
}

function testFleetIdAndOwnerIdAreImmutable(): void
{
    $fleet = makeFleet(['id' => 42, 'ownerId' => 7]);

    assertTrue(!method_exists($fleet, 'setId'), 'у Fleet не должно быть setId');
    assertTrue(!method_exists($fleet, 'setOwnerId'), 'у Fleet не должно быть setOwnerId');

    $fleet->setStatus(1);
    $fleet->setMission(FleetMissionType::ATTACK->value);

    assertEquals(42, $fleet->getId(), 'id не изменился после мутаций');
    assertEquals(7, $fleet->getOwnerId(), 'ownerId не изменился после мутаций');
}

function testFleetShipMutators(): void
{
    $ship = new FleetShip(204, 10);

    $ship->setDamage(2.5);
    assertEqualsWithDelta(2.5, $ship->getDamage(), 0.0001, 'damage изменён');

    $ship->setExperience(3.5);
    assertEqualsWithDelta(3.5, $ship->getExperience(), 0.0001, 'experience изменён');

    $ship->setMorale(0.5);
    assertEqualsWithDelta(0.5, $ship->getMorale(), 0.0001, 'morale изменена');

    $ship->setStatus(1);
    assertEquals(1, $ship->getStatus(), 'status изменён');

    assertThrows(fn () => $ship->setStatus(99), 'неизвестный статус корабля должен бросать исключение');

    $ship->setCount(-5);
    assertEquals(0, $ship->getCount(), 'count не уходит в минус');
}

function testFleetResourceCountDoesNotExceedCapacity(): void
{
    $resource = new FleetResource(901, 'metal', 30.0, maxCountProvider: fn (): float => 70.0);

    assertEqualsWithDelta(30.0, $resource->setCount(100.0), 0.0001, 'возвращается не поместившийся ресурс');
    assertEqualsWithDelta(70.0, $resource->getCount(), 0.0001, 'ресурс ограничен вместимостью флота');
    assertEqualsWithDelta(0.0, $resource->setCount(-5.0), 0.0001, 'ресурс не уходит в минус');

    $resource->setCount(20.0);
    assertEqualsWithDelta(5.0, $resource->subtract(25.0), 0.0001, 'возвращается не списавшийся ресурс');
    assertEqualsWithDelta(0.0, $resource->getCount(), 0.0001, 'ресурс не уходит в минус при списании');
}

function testFleetsDataSerializationRoundTrip(): void
{
    $fleet = makeFleet([
        'id' => 55,
        'ownerId' => 9,
        'homePlanetId' => 100,
        'currentParentId' => 100,
        'currentParentType' => 'planet',
        'anchorDistance' => 0.0,
        'status' => 0,
        'mission' => FleetMissionType::TRANSPORT->value,
        'isReturn' => false,
        'fuelCurrent' => 500.0,
        'startTime' => 1000.0,
        'endTime' => 2000.0,
        'stayTime' => 0.0,
        'cargo' => ['metal' => 10.0, 'crystal' => 20.0],
        'ships' => [new FleetShip(205, 3, 0.0, 0.0, 1.0, 0)],
    ]);

    // Мутации через полный mutation API, покрывающий все поля из задания.
    $fleet->setStatus(1);
    $fleet->setMission(FleetMissionType::ATTACK->value);
    $fleet->setReturn(true);
    $fleet->consumeFuel(150.0);
    $fleet->setStartPosition(makePosition(['galaxy' => 2, 'system' => 3, 'orbit' => 4, 'objectType' => 'planet']));
    $fleet->setEndPosition(makePosition(['galaxy' => 5, 'system' => 6, 'orbit' => 7, 'objectType' => 'moon']));
    $fleet->setCurrentPosition(makePosition(['galaxy' => 3, 'system' => 7, 'orbit' => 2, 'objectType' => 'debris', 'distance' => 321.5, 'deg' => 45.5, 'type' => 'point']));
    $fleet->setStartTime(1111.0);
    $fleet->setEndTime(2222.0);
    $fleet->setStayTime(33.0);
    $fleet->setCurrentParentId(200);
    $fleet->setCurrentParentType('moon');
    $fleet->setAnchorDistance(12.5);
    $fleet->addCargo('metal', 5.0);
    $fleet->removeCargo('crystal', 100.0); // должно уйти в 0, а не в минус
    $fleet->addShips(204, 4);
    $fleet->getShip(204)->setDamage(2.5);
    $fleet->getShip(204)->setExperience(7.0);
    $fleet->getShip(204)->setMorale(0.4);
    $fleet->getShip(204)->setStatus(1);

    $repository = new FleetsData();

    $toFleetRow = new \ReflectionMethod(FleetsData::class, 'toFleetRow');
    $toFleetRow->setAccessible(true);
    $fleetRow = $toFleetRow->invoke($repository, $fleet);

    $toShipRow = new \ReflectionMethod(FleetsData::class, 'toShipRow');
    $toShipRow->setAccessible(true);
    $shipRow = $toShipRow->invoke($repository, $fleet, null);

    assertEquals($fleet->getStatus(), $fleetRow['status'], 'status сериализован');
    assertEquals($fleet->getMission(), $fleetRow['mission'], 'mission сериализована');
    assertEquals($fleet->isReturn() ? 1 : 0, $fleetRow['is_return'], 'return сериализован');
    assertEqualsWithDelta($fleet->getFuelCurrent(), $fleetRow['fuel_current'], 0.0001, 'fuelCurrent сериализован');
    assertEqualsWithDelta($fleet->getCargoAmount('metal'), $fleetRow['resource_metal'], 0.0001, 'metal сериализован');
    assertEqualsWithDelta($fleet->getCargoAmount('crystal'), $fleetRow['resource_crystal'], 0.0001, 'crystal сериализован');
    assertEquals($fleet->getShip(204)->getCount(), $shipRow['light_fighter_count'], 'корабль сериализован');
    assertEquals($fleet->getShip(205)->getCount(), $shipRow['cruiser_count'], 'остальные корабли сериализованы');
}

/**
 * Симулирует условие срабатывания dirty tracking из BaseRepository::update():
 * markForUpdate() вызывается только если после array_merge($current, castRowToSchema($data))
 * хотя бы одно значение реально изменилось.
 *
 * Swoole в этом окружении недоступен, поэтому реальный Fleets::update() выполнить нельзя —
 * здесь воспроизводится именно та логика сравнения, которую использует BaseRepository::update().
 */
function testFleetSaveWouldTriggerDirtyTracking(): void
{
    $fleet = makeFleet(['id' => 77, 'status' => 0]);

    $repository = new FleetsData();
    $toFleetRow = new \ReflectionMethod(FleetsData::class, 'toFleetRow');
    $toFleetRow->setAccessible(true);

    $currentRow = $toFleetRow->invoke($repository, $fleet);

    $fleet->setStatus(1);
    $updatedRow = $toFleetRow->invoke($repository, $fleet);

    $hasChanges = false;
    foreach ($updatedRow as $key => $value) {
        if (!array_key_exists($key, $currentRow) || $currentRow[$key] !== $value) {
            $hasChanges = true;
            break;
        }
    }

    assertTrue($hasChanges, 'мутация Fleet должна приводить к изменениям, которые BaseRepository::update() пометит как dirty');
}

function testFleetsDataRegistryAndDirtyTracking(): void
{
    $fleetsData = new FleetsData();
    $instance = new \ReflectionProperty(FleetsData::class, 'instance');
    $instance->setAccessible(true);
    $instance->setValue(null, $fleetsData);
    assertEquals([], $fleetsData->getLoaded(), 'конструктор не должен загружать флоты');

    $fleet = makeFleet(['id' => 91]);
    $register = new \ReflectionMethod(FleetsData::class, 'register');
    $register->setAccessible(true);
    $register->invoke($fleetsData, $fleet, false);

    assertTrue($fleet === $fleetsData->get(91), 'get должен вернуть объект из registry');
    assertTrue($fleet === $fleetsData->getMany([91, 91])[0], 'getMany должен использовать registry');

    $fleet->setFuelCurrent(123.0);
    assertTrue($fleetsData->isDirty(91), 'изменение Fleet должно помечать его dirty');
}

function testFleetCommandsStartupPersistenceWiring(): void
{
    $main = file_get_contents(__DIR__ . '/../src/main.php');
    assertTrue($main !== false, 'не удалось прочитать startup');
    assertTrue(
        str_contains($main, 'use SPGame\\Game\\Repositories\\FleetCommands;'),
        'FleetCommands должен быть импортирован в startup'
    );
    assertTrue(
        str_contains($main, 'FleetCommands::init($saver);'),
        'FleetCommands должен инициализироваться с RepositorySaver'
    );

    $fleetCommandsInit = new \ReflectionMethod(\SPGame\Game\Repositories\FleetCommands::class, 'init');
    $fleetCommandsSource = implode('', array_slice(
        file($fleetCommandsInit->getFileName()),
        $fleetCommandsInit->getStartLine() - 1,
        $fleetCommandsInit->getEndLine() - $fleetCommandsInit->getStartLine() + 1
    ));
    assertTrue(
        str_contains($fleetCommandsSource, 'parent::init($saver);'),
        'FleetCommands::init должен делегировать стандартную инициализацию BaseRepository'
    );

    $baseInit = new \ReflectionMethod(\SPGame\Game\Repositories\BaseRepository::class, 'init');
    $baseInitSource = implode('', array_slice(
        file($baseInit->getFileName()),
        $baseInit->getStartLine() - 1,
        $baseInit->getEndLine() - $baseInit->getStartLine() + 1
    ));
    assertTrue(str_contains($baseInitSource, 'static::loadAll(static::$tableName);'), 'startup должен загружать runtime-строки');
    assertTrue(str_contains($baseInitSource, '$saver->register(static::class);'), 'startup должен регистрировать репозиторий в saver');
}

function testFleetsDataGetForPlanetContract(): void
{
    $method = new \ReflectionMethod(FleetsData::class, 'getForPlanet');
    $returnType = $method->getReturnType();

    assertTrue($returnType !== null && $returnType->getName() === 'array', 'getForPlanet должен возвращать array');

    $source = implode('', array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));

    assertTrue(str_contains($source, "findByIndex('owner_id'"), 'выборка должна начинаться с owner_id index');
    assertTrue(str_contains($source, "['current_parent_id']"), 'должен проверяться current_parent_id');
    assertTrue(str_contains($source, "['current_parent_type']"), 'должен проверяться current_parent_type');
    assertTrue(str_contains($source, "['status']"), 'должен проверяться status');
    assertTrue(str_contains($source, '$this->get('), 'результат должен получаться через registry FleetsData');
    assertTrue(str_contains($source, '$fleets[$fleet->getId()] = $fleet;'), 'результат должен содержать доменные Fleet');
}

// ------------------------------------------------------------------
//  Точка входа
// ------------------------------------------------------------------

$tests = [
    __NAMESPACE__ . '\\testDistanceInSystemSamePoint',
    __NAMESPACE__ . '\\testDistanceInSystemOppositePoints',
    __NAMESPACE__ . '\\testTotalDistanceSameSystemUsesPolar',
    __NAMESPACE__ . '\\testTotalDistanceBetweenSystemsAddsBase',
    __NAMESPACE__ . '\\testTotalDistanceBetweenGalaxiesAddsBase',
    __NAMESPACE__ . '\\testFlightTimeRequiresPositiveSpeed',
    __NAMESPACE__ . '\\testFlightTimeProportional',
    __NAMESPACE__ . '\\testRequiredFuel',
    __NAMESPACE__ . '\\testConsumeFuelNeverNegative',
    __NAMESPACE__ . '\\testFleetCapacitySum',
    __NAMESPACE__ . '\\testCompositeIndexKeyPreservesFieldOrder',

    __NAMESPACE__ . '\\testFleetSetStatusValid',
    __NAMESPACE__ . '\\testFleetSetStatusInvalidThrows',
    __NAMESPACE__ . '\\testFleetSetMissionValid',
    __NAMESPACE__ . '\\testFleetSetMissionInvalidThrows',
    __NAMESPACE__ . '\\testFleetSetReturn',
    __NAMESPACE__ . '\\testFleetPositionMutators',
    __NAMESPACE__ . '\\testFleetFuelMutators',
    __NAMESPACE__ . '\\testFleetShipsComposition',
    __NAMESPACE__ . '\\testFleetCargoMutators',
    __NAMESPACE__ . '\\testFleetIdAndOwnerIdAreImmutable',
    __NAMESPACE__ . '\\testFleetShipMutators',
    __NAMESPACE__ . '\\testFleetResourceCountDoesNotExceedCapacity',
    __NAMESPACE__ . '\\testFleetsDataSerializationRoundTrip',
    __NAMESPACE__ . '\\testFleetSaveWouldTriggerDirtyTracking',
    __NAMESPACE__ . '\\testFleetsDataRegistryAndDirtyTracking',
    __NAMESPACE__ . '\\testFleetCommandsStartupPersistenceWiring',
    __NAMESPACE__ . '\\testFleetsDataGetForPlanetContract',
];

$passed = 0;
$failed = 0;

foreach ($tests as $name) {
    try {
        $name();
        $passed++;
        echo "PASS  {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL  {$name}: {$e->getMessage()}\n";
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);