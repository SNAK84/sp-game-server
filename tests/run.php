<?php

declare(strict_types=1);

namespace SPGame\Tests;

require_once __DIR__ . '/bootstrap.php';

use SPGame\Game\Fleet\{Fleet, FleetResource, FleetShip, FleetTarget};
use SPGame\Game\Repositories\{BaseRepository, Vars};
use SPGame\Game\Services\{AccountData, FleetFlightService, FleetMiningService, FleetMissionService, SystemCoordinate};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/**
 * Быстрые проверки текущих контрактов без MySQL, Swoole и запуска сервера.
 * Runtime Fleet/save/reload проверяются отдельными наборами: см. tests/README.md.
 * Запуск: php tests/run.php
 */
function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException('Ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

function assertNear(float $expected, float $actual): void
{
    if (!is_finite($actual) || abs($expected - $actual) > max(1e-8, abs($expected) * 1e-10)) {
        throw new \RuntimeException("Ожидалось {$expected}, получено {$actual}");
    }
}

/** TypeError/ошибка автозагрузки не заменяют ожидаемую доменную ошибку. */
function assertRejects(callable $action, string $expected = \InvalidArgumentException::class): void
{
    try {
        $action();
    } catch (\Throwable $error) {
        if ($error instanceof $expected) return;
        throw new \RuntimeException('Ожидалось ' . $expected . ', получено ' . $error::class, 0, $error);
    }
    throw new \RuntimeException('Ожидалось исключение ' . $expected);
}

$tests = [];
foreach (ObjectType::cases() as $type) {
    $tests['coordinate object ' . $type->name] = static function () use ($type): void {
        $siteId = in_array($type, [ObjectType::GAS_GIANT, ObjectType::ASTEROID_SECTOR], true) ? 1 : null;
        $coordinate = Coordinate::object(1, 10, 7, $type, siteId: $siteId);
        assertSame(CoordinateType::OBJECT, $coordinate->getType());
        assertSame($type, $coordinate->getObjectType());
        assertSame(['type' => 1, 'galaxy' => 1, 'system' => 10, 'orbit' => 7, 'objectType' => $type->value]
            + ($siteId === null ? [] : ['siteId' => $siteId]), $coordinate->toArray());
        assertSame($siteId, $coordinate->getSiteId());
        assertSame(null, $coordinate->getDistance());
        assertSame(null, $coordinate->getFleetId());
    };
}
$tests['coordinate point keeps fractional radius and angle'] = static function (): void {
    $coordinate = Coordinate::point(1, 10, 2500.5, 135.25);
    assertSame(CoordinateType::POINT, $coordinate->getType());
    assertSame(2500.5, $coordinate->getDistance());
    assertSame(135.25, $coordinate->getAngle());
    assertSame(null, $coordinate->getOrbit());
    assertSame(null, $coordinate->getObjectType());
};
$tests['stationary orbit preserves materialized angle'] = static function (): void {
    assertSame(['type' => 1, 'galaxy' => 1, 'system' => 10, 'orbit' => 17, 'angle' => 135.25],
        Coordinate::object(1, 10, 17, null, 135.25)->toArray());
};
$tests['fleet coordinate keeps server-side point snapshot'] = static function (): void {
    $point = Coordinate::point(1, 10, 2500.5, 135.25);
    $coordinate = Coordinate::fleet(123, $point);
    assertSame(CoordinateType::FLEET, $coordinate->getType());
    assertSame(123, $coordinate->getFleetId());
    assertSame($point, $coordinate->getLastKnownPosition());
    assertSame(null, $coordinate->getGalaxy());
};
$tests['all coordinate variants survive array and JSON roundtrip'] = static function (): void {
    foreach ([Coordinate::object(1, 10, 7, ObjectType::PLANET), Coordinate::object(1, 10, 17, null, 90),
        Coordinate::object(1, 10, 7, ObjectType::GAS_GIANT, siteId: 1),
        Coordinate::object(1, 10, 8, ObjectType::ASTEROID_SECTOR, siteId: 2),
        Coordinate::point(1, 10, 0, 0), Coordinate::point(1, 10, 2500.5, 359.999),
        Coordinate::fleet(123, Coordinate::point(1, 10, 2500.5, 135.25))] as $coordinate) {
        $array = $coordinate->toArray();
        assertSame($array, Coordinate::fromArray($array)->toArray());
        $decoded = json_decode(json_encode($coordinate, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        assertSame($array, Coordinate::fromArray($decoded)->toArray());
    }
};
$tests['object coordinate rejects nonpositive addresses'] = static function (): void {
    foreach ([[0, 1, 1], [1, 0, 1], [1, 1, 0], [-1, 1, 1]] as $args) {
        assertRejects(static fn() => Coordinate::object(...$args));
    }
};
$tests['point coordinate rejects invalid distance and angle'] = static function (): void {
    foreach ([[-.01, 0], [INF, 0], [NAN, 0], [0, -.01], [0, 360], [0, INF], [0, NAN]] as [$distance, $angle]) {
        assertRejects(static fn() => Coordinate::point(1, 1, $distance, $angle));
    }
    assertRejects(static fn() => Coordinate::point(0, 1, 1, 0));
    assertRejects(static fn() => Coordinate::point(1, 0, 1, 0));
};
$tests['fleet coordinate rejects invalid id and non-point snapshot'] = static function (): void {
    foreach ([0, -1] as $id) assertRejects(static fn() => Coordinate::fleet($id, Coordinate::point(1, 1, 0, 0)));
    assertRejects(static fn() => Coordinate::fleet(1, Coordinate::object(1, 1, 1, ObjectType::PLANET)));
};
$tests['serialized coordinate rejects unknown types and missing fleet snapshot'] = static function (): void {
    foreach ([[], ['type' => 99], ['type' => 3, 'fleetId' => 1],
        ['type' => 1, 'galaxy' => 1, 'system' => 1, 'orbit' => 1, 'objectType' => 99],
        ['type' => 1, 'galaxy' => 1, 'system' => 1, 'orbit' => 1, 'objectType' => ObjectType::ASTEROID_SECTOR->value, 'siteId' => 0],
        ['type' => 1, 'galaxy' => 1, 'system' => 1, 'orbit' => 1, 'objectType' => ObjectType::PLANET->value, 'siteId' => 1]] as $row) {
        assertRejects(static fn() => Coordinate::fromArray($row));
    }
};
$tests['FleetTarget preserves current object and point payloads'] = static function (): void {
    foreach ([Coordinate::object(1, 10, 7, ObjectType::MOON), Coordinate::point(1, 10, 2500.5, 135.25),
        Coordinate::object(1, 10, 7, ObjectType::GAS_GIANT, siteId: 1),
        Coordinate::object(1, 10, 8, ObjectType::ASTEROID_SECTOR, siteId: 2)] as $coordinate) {
        $target = FleetTarget::fromCoordinates($coordinate->toArray());
        assertSame($coordinate->toArray(), $target->toCoordinates());
        assertSame($coordinate->getType(), $target->getCoordinateType());
        assertSame($coordinate->getObjectType(), $target->getObjectType());
    }
};
$tests['FleetTarget fleet identity does not forward a client position'] = static function (): void {
    $target = FleetTarget::fromCoordinates(['type' => 3, 'fleetId' => 123, 'lastKnownPosition' => ['type' => 2, 'distance' => 999]]);
    assertSame(CoordinateType::FLEET, $target->getCoordinateType());
    assertSame(123, $target->getFleetId());
    assertSame(['type' => 3, 'fleetId' => 123], $target->toCoordinates());
};
$tests['FleetTarget retains supported internal legacy payload'] = static function (): void {
    $row = ['galaxy' => 1, 'system' => 10, 'orbit' => 4, 'type' => 1, 'distance' => 125.5, 'deg' => 270.0];
    assertSame($row, FleetTarget::fromCoordinates($row)->toCoordinates());
};
$tests['polar geometry same point and opposite points'] = static function (): void {
    $start = new SystemCoordinate(1, 10, 100, 0);
    $flight = new FleetFlightService();
    assertNear(0, $flight->calculateDistance($start, new SystemCoordinate(1, 10, 100, 0)));
    assertNear(200, $flight->calculateDistance($start, new SystemCoordinate(1, 10, 100, 180)));
    assertNear(sqrt(20000), $flight->calculateDistance($start, new SystemCoordinate(1, 10, 100, 90)));
};
$tests['polar geometry is symmetric and cannot cross a system implicitly'] = static function (): void {
    $a = new SystemCoordinate(1, 10, 100, 25);
    $b = new SystemCoordinate(1, 10, 350, 275);
    assertNear($a->distanceTo($b), $b->distanceTo($a));
    assertRejects(static fn() => $a->distanceTo(new SystemCoordinate(1, 11, 100, 0)), \LogicException::class);
};
$tests['planet moon debris share anchor but retain local transfer distance'] = static function (): void {
    $flight = new FleetFlightService();
    $planet = Coordinate::object(1, 10, 7, ObjectType::PLANET);
    foreach ([ObjectType::MOON, ObjectType::DEBRIS] as $type) {
        $other = Coordinate::object(1, 10, 7, $type);
        assertSame(5.0, $flight->getLocalObjectTransferDistance($planet, $other));
        assertSame(5.0, $flight->getLocalObjectTransferDistance($other, $planet));
    }
    assertSame(null, $flight->getLocalObjectTransferDistance($planet, $planet));
    assertSame(null, $flight->getLocalObjectTransferDistance($planet, Coordinate::object(1, 10, 8, ObjectType::MOON)));
};
$tests['FleetShip current constructor and mutation callbacks'] = static function (): void {
    $changes = [];
    $ship = new FleetShip(204, 'light_fighter', 10,
        onChange: static function (string $field, int|float $value) use (&$changes): void { $changes[$field] = $value; });
    assertSame(204, $ship->getShipTypeId());
    assertSame('light_fighter', $ship->getShipTypeName());
    $ship->setCount(12); $ship->setDamage(2.5); $ship->setExperience(3.5); $ship->setMorale(.5); $ship->setStatus(1);
    assertSame(['count' => 12, 'damage' => 2.5, 'experience' => 3.5, 'morale' => .5, 'status' => 1], $changes);
    assertSame([12, 2.5, 3.5, .5, 1], [$ship->getCount(), $ship->getDamage(), $ship->getExperience(), $ship->getMorale(), $ship->getStatus()]);
};
$tests['FleetShip no-op does not notify and negative count is clamped'] = static function (): void {
    $calls = 0;
    $ship = new FleetShip(204, 'light_fighter', 10, onChange: static function () use (&$calls): void { ++$calls; });
    $ship->setCount(10); $ship->setDamage(0); $ship->setExperience(0); $ship->setMorale(1); $ship->setStatus(0);
    assertSame(0, $calls);
    $ship->setCount(-5);
    assertSame(0, $ship->getCount()); assertSame(1, $calls);
};
$tests['FleetShip rejects invalid status and nonfinite XP without mutation'] = static function (): void {
    $calls = 0;
    $ship = new FleetShip(204, 'light_fighter', 10, experience: 25,
        onChange: static function () use (&$calls): void { ++$calls; });
    assertRejects(static fn() => $ship->setStatus(99));
    foreach ([INF, -INF, NAN] as $experience) assertRejects(static fn() => $ship->setExperience($experience));
    assertSame(25.0, $ship->getExperience()); assertSame(0, $ship->getStatus()); assertSame(0, $calls);
};
$tests['FleetShip capacity is per ship and per group'] = static function (): void {
    Vars::$attributes = [204 => ['capacity' => 50.0]];
    $ship = new FleetShip(204, 'light_fighter', 10);
    assertSame(50.0, $ship->getCapacity()); assertSame(500.0, $ship->getCapacityAll());
    $ship->setCount(0); assertSame(0.0, $ship->getCapacityAll());
};
$tests['FleetResource returns overflow and shortage without negative stock'] = static function (): void {
    $resource = new FleetResource(901, 'metal', 30, maxCountProvider: static fn(): float => 70);
    assertSame(30.0, $resource->setCount(100)); assertSame(70.0, $resource->getCount());
    assertSame(0.0, $resource->setCount(-5)); assertSame(0.0, $resource->getCount());
    $resource->setCount(20);
    assertSame(5.0, $resource->subtract(25)); assertSame(0.0, $resource->getCount());
};
$tests['FleetResource add and no-op preserve callback field contract'] = static function (): void {
    $changes = [];
    $resource = new FleetResource(903, 'deuterium', 10,
        onChange: static function (string $field, float $value) use (&$changes): void { $changes[] = [$field, $value]; },
        maxCountProvider: static fn(): float => 20);
    assertSame(15.0, $resource->add(25));
    assertSame([['resource_deuterium', 20.0]], $changes);
    $resource->setCount(20); $resource->subtract(0);
    assertSame(1, count($changes));
    assertSame(0.0, $resource->subtract(5)); assertSame(['resource_deuterium', 15.0], $changes[1]);
};
$tests['FleetResource partial unload cannot discard cargo when capacity shrinks'] = static function (): void {
    $limit = 100.0;
    $resource = new FleetResource(901, 'metal', 100, maxCountProvider: static function () use (&$limit): float { return $limit; });
    $limit = 20;
    assertSame(0.0, $resource->subtract(25)); assertSame(75.0, $resource->getCount());
};
$tests['composite repository index preserves field order'] = static function (): void {
    $key = new \ReflectionMethod(BaseRepository::class, 'buildIndexKey');
    $keys = [];
    foreach ([[1, 2, 3], [1, 3, 2], [2, 1, 3], [2, 3, 1], [3, 1, 2], [3, 2, 1]] as $fields) {
        $keys[] = $key->invoke(null, $fields);
    }
    assertSame(6, count(array_unique($keys)));
    assertSame($keys[0], $key->invoke(null, [1, 2, 3]));
};
$tests['repository index splits long ID lists without losing order'] = static function (): void {
    $encode = new \ReflectionMethod(BaseRepository::class, 'encodeIndexIds');
    foreach ([0, 1, 64, 65, 156, 512, 8192] as $count) {
        $ids = $count === 0 ? [] : range(100000000, 100000000 + $count - 1);
        $parts = $encode->invoke(null, $ids);
        assertSame((int)ceil($count / 64), count($parts));
        $decoded = [];
        foreach ($parts as $part) {
            if (strlen($part['ids']) > 2048) throw new \RuntimeException('Переполнение части индекса');
            $chunk = unserialize($part['ids'], ['allowed_classes' => false]);
            assertSame($chunk[0], $part['id']);
            array_push($decoded, ...$chunk);
        }
        assertSame($ids, $decoded);
    }
};
$tests['repository index parts fit maximum 64-bit IDs'] = static function (): void {
    $ids = array_map(static fn($i) => PHP_INT_MAX - $i, range(0, 130));
    $parts = (new \ReflectionMethod(BaseRepository::class, 'encodeIndexIds'))->invoke(null, $ids);
    $decoded = [];
    foreach ($parts as $part) {
        if (strlen($part['ids']) > 2048) throw new \RuntimeException('Переполнение int64 части');
        array_push($decoded, ...unserialize($part['ids'], ['allowed_classes' => false]));
    }
    assertSame($ids, $decoded);
};
$tests['repository index refuses oversized encoded parts'] = static function (): void {
    $encode = new \ReflectionMethod(BaseRepository::class, 'encodeIndexIds');
    assertRejects(static fn() => $encode->invoke(null, [str_repeat('x', 2048)]), \LengthException::class);
};
$tests['mission selection accepts current Fleet Target AccountData contract'] = static function (): void {
    $method = new \ReflectionMethod(FleetMissionService::class, 'getAvailableMissions');
    $types = array_map(static fn(\ReflectionParameter $p): string => $p->getType()->getName(), array_slice($method->getParameters(), 0, 3));
    assertSame([Fleet::class, FleetTarget::class, AccountData::class], $types);
};
$tests['startup wires fleet repositories to ordinary RepositorySaver'] = static function (): void {
    $main = file_get_contents(__DIR__ . '/../src/main.php');
    foreach (['Fleets', 'FleetsShips', 'FleetCommands'] as $name) {
        if (!str_contains($main, $name . '::init($saver);')) throw new \RuntimeException($name . ' не зарегистрирован при startup');
    }
};
$tests['interception contact-loss stop unlocks only at computer level 6'] = static function (): void {
    foreach ([0, 3, 5, 6, 7, 12] as $level) {
        $caps = \SPGame\Game\Services\FleetControlService::capabilitiesForLevels($level, 0);
        assertSame($level >= 6, $caps['interceptStop']);
        assertSame(6, $caps['interceptStopLevel']);
    }
};
$tests['mining start only defers base loss on its exact boundary'] = static function (): void {
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $command = ['command_type' => 61, 'execute_time' => 100.0, 'executed' => 0,
        'params' => json_encode(['target' => $target], JSON_THROW_ON_ERROR)];
    $starts = [\SPGame\Game\Services\ResourceBaseService::class, 'miningStartsAt'];
    assertSame(true, $starts($command, $target, 100.0));
    foreach ([0.0, 99.0, 100.000001, 101.0] as $time) assertSame(false, $starts($command, $target, $time));
    foreach (['executed' => 1, 'execute_time' => 0, 'command_type' => 60] as $key => $value) {
        assertSame(false, $starts(array_replace($command, [$key => $value]), $target, 100.0));
    }
};
$tests['paused working and wrong-target mining do not defer base loss'] = static function (): void {
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $command = ['command_type' => 61, 'execute_time' => 100.0, 'executed' => 0];
    $starts = [\SPGame\Game\Services\ResourceBaseService::class, 'miningStartsAt'];
    foreach ([['target' => $target, 'pause' => 'manual'], ['target' => $target, 'mining' => ['phase' => 'working']],
        ['target' => array_replace($target, ['siteId' => 23])], []] as $params) {
        assertSame(false, $starts($command + ['params' => json_encode($params, JSON_THROW_ON_ERROR)], $target, 100.0));
    }
};
$tests['zero resource return yields to MOVE then STAY on the same boundary'] = static function (): void {
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $continues = [\SPGame\Game\Services\ResourceBaseService::class, 'arrivalContinuesAt'];
    $command = ['command_type' => 10, 'execute_time' => 100.0, 'executed' => 0,
        'params' => ['target' => $target, 'homeSiteId' => 19, 'returnLeg' => true]];
    assertSame(true, $continues($command, $target, 100.0, 100.0));
    $command['command_type'] = 52;
    $command['params'] = json_encode(['at' => $target], JSON_THROW_ON_ERROR);
    assertSame(true, $continues($command, $target, 100.0, 100.0));
};
$tests['outbound nonzero and stale resource returns cannot defer base loss'] = static function (): void {
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $continues = [\SPGame\Game\Services\ResourceBaseService::class, 'arrivalContinuesAt'];
    $params = ['target' => $target, 'homeSiteId' => 19, 'returnLeg' => true];
    $command = ['command_type' => 10, 'execute_time' => 100.0, 'executed' => 0, 'params' => $params];
    foreach ([[99.0, 99.0], [100.0, 99.0], [101.0, 101.0], [0.0, 0.0]] as [$time, $positionTime]) {
        assertSame(false, $continues($command, $target, $time, $positionTime));
    }
    foreach (['executed' => 1, 'execute_time' => 0, 'command_type' => 11] as $key => $value) {
        assertSame(false, $continues(array_replace($command, [$key => $value]), $target, 100.0, 100.0));
    }
    foreach ([['returnLeg' => false], ['homeSiteId' => 23], ['pause' => 'manual'],
        ['target' => array_replace($target, ['siteId' => 23])]] as $change) {
        assertSame(false, $continues(array_replace($command, ['params' => array_replace($params, $change)]), $target, 100.0, 100.0));
    }
};
$tests['paused and wrong-target STAY cannot defer resource base loss'] = static function (): void {
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $continues = [\SPGame\Game\Services\ResourceBaseService::class, 'arrivalContinuesAt'];
    foreach ([['at' => $target, 'pause' => 'plan'], ['at' => array_replace($target, ['siteId' => 23])], []] as $params) {
        assertSame(false, $continues(['command_type' => 52, 'execute_time' => 100.0, 'executed' => 0,
            'params' => $params], $target, 100.0, 100.0));
    }
};
$tests['partial recall still rejects zero duration and invalid fuel metadata'] = static function (): void {
    // Только чтение часов для ранней валидации; репозитории/Swoole не запускаются.
    $reflection = new \ReflectionClass(Fleet::class);
    $fleet = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('rawData')->setValue($fleet, ['current_position_time' => 100.0]);
    $flight = new FleetFlightService();
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19);
    foreach ([[100.0, 0], [99.0, 0], [101.0, null], [101.0, -1], [101.0, NAN]] as [$until, $fuel]) {
        assertRejects(static fn() => $flight->prepareRecallFlight($fleet,
            ['command_type' => 10, 'execute_time' => $until, 'params' => ['fuelCost' => $fuel]], $target, 100.0), \LogicException::class);
    }
};
$tests['resource standing consumes only fuel above evacuation reserve'] = static function (): void {
    $clock = ['time' => 100.0, 'rate' => .5, 'reserve' => 30.0];
    $next = \SPGame\Game\Services\ResourceBaseService::standingProjection(100, $clock, 160);
    assertNear(70, $next['fuel']);
    assertNear(240, $next['due']);
    $late = \SPGame\Game\Services\ResourceBaseService::standingProjection(100, $clock, 10000);
    assertNear(30, $late['fuel']);
    assertNear(240, $late['time']);
};
$tests['resource standing checkpoint does not charge an interval twice'] = static function (): void {
    $clock = ['time' => 100.0, 'rate' => .003, 'reserve' => 5.0];
    $project = [\SPGame\Game\Services\ResourceBaseService::class, 'standingProjection'];
    $first = $project(100, $clock, 200);
    $split = $project($first['fuel'], $first, 1000);
    $whole = $project(100, $clock, 1000);
    assertNear($whole['fuel'], $split['fuel']);
    assertNear($whole['due'], $split['due']);
    assertNear($first['fuel'], $project($first['fuel'], $first, 200)['fuel']);
};
$tests['resource reserve never creates fuel and zero consumption stays zero'] = static function (): void {
    $project = [\SPGame\Game\Services\ResourceBaseService::class, 'standingProjection'];
    $short = $project(2, ['time' => 100, 'rate' => 1, 'reserve' => 30], 200);
    assertNear(2, $short['fuel']);
    assertNear(100, $short['due']);
    $free = $project(2, ['time' => 100, 'rate' => 0, 'reserve' => 30], 200);
    assertNear(2, $free['fuel']);
    assertSame(null, $free['due']);
    assertNear(200, $free['time']);
};
$tests['resource standing rejects invalid or backwards checkpoint input'] = static function (): void {
    $project = [\SPGame\Game\Services\ResourceBaseService::class, 'standingProjection'];
    $clock = ['time' => 100, 'rate' => 1, 'reserve' => 30];
    assertRejects(static fn() => $project(100, $clock, 99), \LogicException::class);
    foreach ([NAN, INF, -1] as $value) {
        assertRejects(static fn() => $project($value, $clock, 200));
        assertRejects(static fn() => $project(100, $clock, $value));
        foreach (array_keys($clock) as $key) {
            assertRejects(static fn() => $project(100, array_replace($clock, [$key => $value]), 200));
        }
    }
    assertRejects(static fn() => $project(100, [], 200));
};

/** Расчётные строки, без репозиториев/авторизации/запуска второго сервера. */
function miningRows(int $type = 1, float $coverage = 1): array
{
    $site = ['id' => 23, 'site_type' => $type, 'resource_update_time' => 1000.0];
    $reserve = [];
    foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $id => $name) {
        $active = $type === 1 ? $id === 903 : $id !== 903;
        $site[$name . '_capacity'] = $active ? 20000.0 : 0.0;
        $site[$name . '_stock'] = $active ? 10000.0 : 0.0;
        $site[$name . '_rate'] = !$active ? 0.0 : match ($id) { 901 => 600.0, 902 => 400.0, 903 => 1000.0 };
        $reserve[$id] = $coverage * $site[$name . '_stock'];
    }
    return [$site, ['owner_id' => 9, 'site_id' => 23, 'coverage' => $coverage, 'reserve_time' => 1000.0,
        'state' => json_encode(['reserve' => $reserve], JSON_THROW_ON_ERROR)]];
}

function miningWorker(float $rate = 1000, float $free = 10000): array
{
    return ['rates' => [901 => 0.0, 902 => 0.0, 903 => $rate], 'free' => $free,
        'fuel' => 10000.0, 'fuelRate' => 0.0, 'fuelReserve' => 0.0, 'endsAt' => 100000.0];
}

$tests['mining keeps approved duration modes and completion choices'] = static function (): void {
    $balance = require __DIR__ . '/../resources/fleet-mining.php';
    assertSame(['metal', 'crystal', 'mix'], $balance['belt_modes']);
    assertSame([3600, 7200, 14400, 28800, 43200, 57600, 115200], $balance['durations']);
    assertSame(3600, $balance['default_duration']);
    assertSame(['return', 'stay'], $balance['finish_options']);
};
$tests['mining production uses compatible hulls and completed technology once'] = static function (): void {
    [$gas] = miningRows(); [$belt] = miningRows(2);
    assertNear(5200, FleetMiningService::rates($gas, [224 => 4, 202 => 1000000, 223 => 100], 3, 'deuterium')[903]);
    $mix = FleetMiningService::rates($belt, [223 => 2, 224 => 100], 1, 'mix');
    assertNear(1980, $mix[901]); assertNear(1320, $mix[902]); assertNear(0, $mix[903]);
    assertNear(3300, array_sum($mix));
    assertNear(3300, FleetMiningService::rates($belt, [223 => 2], 1, 'metal')[901]);
    assertNear(3300, FleetMiningService::rates($belt, [223 => 2], 1, 'crystal')[902]);
    assertNear(0, array_sum(FleetMiningService::rates($gas, [223 => 100], 3, 'deuterium')));
    assertNear(2000000000, FleetMiningService::rates($gas, [224 => 1000000], 10, 'deuterium')[903]);
    assertRejects(static fn() => FleetMiningService::rates($gas, [224 => 1], 2, 'deuterium'));
    assertRejects(static fn() => FleetMiningService::rates($belt, [223 => 1.5], 1, 'metal'));
    assertRejects(static fn() => FleetMiningService::rates($gas, [224 => 1], 3, 'metal'));
};
$tests['mining baseline regenerates global and personal reserves separately'] = static function (): void {
    [$site, $known] = miningRows(1, .25);
    $state = FleetMiningService::baseline($site, $known, 4600);
    assertNear(11000, $state['resources'][903]['stock']);
    assertNear(2750, $state['resources'][903]['reserve']);
    $late = FleetMiningService::baseline($site, $known, 1000000);
    assertNear(20000, $late['resources'][903]['stock']);
    assertNear(5000, $late['resources'][903]['reserve']);
    assertSame(9, $state['ownerId']); assertSame(23, $state['siteId']);
};
$tests['mining full survey never resets depleted personal reserve'] = static function (): void {
    [$site, $known] = miningRows();
    $known['state'] = json_encode(['reserve' => [901 => 0, 902 => 0, 903 => 100]]);
    assertNear(100, FleetMiningService::baseline($site, $known, 1000)['resources'][903]['reserve']);
    assertNear(1100, FleetMiningService::baseline($site, $known, 4600)['resources'][903]['reserve']);
};
$tests['mining scarce common reserve is shared by rates not iteration order'] = static function (): void {
    [$site, $known] = miningRows();
    $site['deuterium_stock'] = 100.0; $site['deuterium_rate'] = 0.0;
    $known['state'] = json_encode(['reserve' => [901 => 0, 902 => 0, 903 => 100]]);
    $state = FleetMiningService::baseline($site, $known, 1000);
    $a = FleetMiningService::phase($state, [1 => miningWorker(100), 2 => miningWorker(300)], 4600);
    $b = FleetMiningService::phase($state, [2 => miningWorker(300), 1 => miningWorker(100)], 4600);
    assertSame($a, $b); assertNear(900, $a['elapsed']);
    assertNear(25, $a['cargo'][1][903]); assertNear(75, $a['cargo'][2][903]);
    assertNear(0, $a['state']['resources'][903]['stock']);
    assertNear(0, $a['state']['resources'][903]['reserve']);
    assertSame(['stock', 'reserve'], array_column($a['events'], 'kind'));
};
$tests['mining cannot use regeneration that happens after exhaustion'] = static function (): void {
    [$site, $known] = miningRows();
    $site['deuterium_stock'] = 10.0; $site['deuterium_rate'] = 10.0;
    $known['state'] = json_encode(['reserve' => [901 => 0, 902 => 0, 903 => 10]]);
    $state = FleetMiningService::baseline($site, $known, 1000);
    $result = FleetMiningService::phase($state, [1 => miningWorker(110)], 100000);
    assertNear(360, $result['elapsed']); assertNear(11, $result['cargo'][1][903]);
    assertNear(0, $result['state']['resources'][903]['stock']);
};
$tests['mining personal knowledge can limit extraction before physical stock'] = static function (): void {
    [$site, $known] = miningRows(1, .1);
    $state = FleetMiningService::baseline($site, $known, 1000);
    $result = FleetMiningService::phase($state, [1 => miningWorker(1100)], 100000);
    assertNear(3600, $result['elapsed']); assertNear(1100, $result['cargo'][1][903]);
    assertNear(9900, $result['state']['resources'][903]['stock']);
    assertNear(0, $result['state']['resources'][903]['reserve']);
    assertSame([['kind' => 'reserve', 'resourceId' => 903]], $result['events']);
};
$tests['mining burned tank fuel frees cargo space but is not extracted cargo'] = static function (): void {
    [$site, $known] = miningRows(); $state = FleetMiningService::baseline($site, $known, 1000);
    $worker = array_replace(miningWorker(1000, 50), ['fuel' => 200.0, 'fuelRate' => 100.0, 'fuelReserve' => 150.0]);
    $result = FleetMiningService::phase($state, [1 => $worker], 100000);
    assertNear(200, $result['elapsed']); assertNear(500 / 9, $result['cargo'][1][903]);
    assertNear(1750 / 9, $result['fleets'][1]['fuel']); assertNear(0, $result['fleets'][1]['free']);
    assertSame([['kind' => 'cargo', 'fleetId' => 1]], $result['events']);
};
$tests['mining stops at return fuel reserve without consuming cargo deuterium'] = static function (): void {
    [$site, $known] = miningRows(); $state = FleetMiningService::baseline($site, $known, 1000);
    $worker = array_replace(miningWorker(), ['fuel' => 200.0, 'fuelRate' => 100.0, 'fuelReserve' => 150.0]);
    $result = FleetMiningService::phase($state, [1 => $worker], 100000);
    assertNear(1800, $result['elapsed']); assertNear(500, $result['cargo'][1][903]);
    assertNear(150, $result['fleets'][1]['fuel']);
    assertSame([['kind' => 'fuel', 'fleetId' => 1]], $result['events']);
};
$tests['mining protects the larger alternative return reserve not their sum'] = static function (): void {
    assertSame(140.0, FleetMiningService::returnFuelReserve(30, 140));
    assertSame(141.0, FleetMiningService::returnFuelReserve(140.1, 30));
    assertSame(31.0, FleetMiningService::returnFuelReserve(30.1));
    assertSame(0.0, FleetMiningService::returnFuelReserve(0));
};
$tests['mining return reserve rejects invalid route budgets'] = static function (): void {
    foreach ([NAN, INF, -INF, -1] as $value) {
        assertRejects(static fn() => FleetMiningService::returnFuelReserve($value, 140));
        assertRejects(static fn() => FleetMiningService::returnFuelReserve(30, $value));
    }
};
$tests['mining cannot consume the resource base evacuation reserve'] = static function (): void {
    [$site, $known] = miningRows(); $state = FleetMiningService::baseline($site, $known, 1000);
    $worker = array_replace(miningWorker(), ['fuel' => 140.6, 'fuelRate' => 32.0,
        'fuelReserve' => FleetMiningService::returnFuelReserve(30, 140)]);
    $result = FleetMiningService::phase($state, [1 => $worker], 4600);
    assertNear(67.5, $result['elapsed']); assertNear(140, $result['fleets'][1]['fuel']);
    assertSame([['kind' => 'fuel', 'fleetId' => 1]], $result['events']);
    $repeat = FleetMiningService::phase($result['state'], $result['fleets'], 4600);
    assertNear(0, $repeat['elapsed']); assertNear(0, array_sum($repeat['cargo'][1]));
    assertNear(140, $repeat['fleets'][1]['fuel']);
};
$tests['mining duration zero interval and zero fuel consumption are exact'] = static function (): void {
    [$site, $known] = miningRows(); $state = FleetMiningService::baseline($site, $known, 1000);
    $worker = array_replace(miningWorker(), ['endsAt' => 1360.0, 'fuel' => 0.0]);
    $result = FleetMiningService::phase($state, [1 => $worker], 4600);
    assertNear(360, $result['elapsed']); assertNear(100, $result['cargo'][1][903]);
    assertNear(0, $result['fleets'][1]['fuel']);
    assertSame([['kind' => 'duration', 'fleetId' => 1]], $result['events']);
    $repeat = FleetMiningService::phase($result['state'], $result['fleets'], 1360);
    assertNear(0, $repeat['elapsed']); assertNear(0, $repeat['cargo'][1][903]);
};
$tests['mining phase checkpoint split preserves stock knowledge fuel and cargo'] = static function (): void {
    [$site, $known] = miningRows(1, .25); $state = FleetMiningService::baseline($site, $known, 1000);
    $fleet = array_replace(miningWorker(500), ['fuelRate' => 10.0]);
    $whole = FleetMiningService::phase($state, [1 => $fleet], 2000);
    $first = FleetMiningService::phase($state, [1 => $fleet], 1450);
    $split = FleetMiningService::phase($first['state'], $first['fleets'], 2000);
    foreach (['stock', 'reserve'] as $field) assertNear($whole['state']['resources'][903][$field], $split['state']['resources'][903][$field]);
    foreach (['fuel', 'free'] as $field) assertNear($whole['fleets'][1][$field], $split['fleets'][1][$field]);
    assertNear($whole['cargo'][1][903], $first['cargo'][1][903] + $split['cargo'][1][903]);
};
$tests['mining full fleet does not donate production to remaining fleet'] = static function (): void {
    [$site, $known] = miningRows(); $site['deuterium_rate'] = 0.0;
    $state = FleetMiningService::baseline($site, $known, 1000);
    $first = FleetMiningService::phase($state, [1 => miningWorker(100, 10), 2 => miningWorker(300)], 4600);
    assertNear(360, $first['elapsed']); assertNear(10, $first['cargo'][1][903]);
    assertNear(30, $first['cargo'][2][903]);
    unset($first['fleets'][1]); // Обработчик завершил только флот с полным трюмом.
    $next = FleetMiningService::phase($first['state'], $first['fleets'], 4600);
    assertNear(300, $first['cargo'][2][903] + $next['cargo'][2][903]);
    assertNear(300, $next['fleets'][2]['rates'][903]);
};
$tests['mining mix exposes exact exhaustion boundary for explicit redistribution'] = static function (): void {
    [$site, $known] = miningRows(2);
    $site['metal_stock'] = 10.0;
    $known['state'] = json_encode(['reserve' => [901 => 10, 902 => 10000, 903 => 0]]);
    $state = FleetMiningService::baseline($site, $known, 1000);
    $worker = array_replace(miningWorker(), ['rates' => FleetMiningService::rates($site, [223 => 2], 1, 'mix')]);
    $first = FleetMiningService::phase($state, [1 => $worker], 4600);
    assertNear(10 / 1380 * 3600, $first['elapsed']);
    assertNear(0, $first['state']['resources'][901]['stock']);
    assertSame([901, 901], array_column($first['events'], 'resourceId'));
    // Следующая фаза использует только оставшийся компонент, не обе полные мощности.
    $first['fleets'][1]['rates'] = FleetMiningService::rates($site, [223 => 2], 1, 'crystal');
    $next = FleetMiningService::phase($first['state'], $first['fleets'], 4600);
    assertNear(3300, array_sum($first['cargo'][1]) + array_sum($next['cargo'][1]));
    assertNear(0, $next['cargo'][1][901]);
};
$tests['mining empty knowledge tank or cargo never creates resources'] = static function (): void {
    [$site, $known] = miningRows(1, 0);
    $unknown = FleetMiningService::baseline($site, $known, 1000);
    $result = FleetMiningService::phase($unknown, [1 => miningWorker()], 4600);
    assertNear(0, $result['elapsed']); assertNear(0, array_sum($result['cargo'][1]));
    [$site, $known] = miningRows(); $state = FleetMiningService::baseline($site, $known, 1000);
    foreach ([['fuel' => 5.0, 'fuelReserve' => 30.0], ['free' => 0.0]] as $change) {
        $worker = array_replace(miningWorker(), $change);
        $result = FleetMiningService::phase($state, [1 => $worker], 4600);
        assertNear(0, $result['elapsed']); assertNear(0, array_sum($result['cargo'][1]));
        assertNear($worker['fuel'], $result['fleets'][1]['fuel']);
    }
};
$tests['mining rejects invalid numbers identities and backwards checkpoints'] = static function (): void {
    [$site, $known] = miningRows(); $state = FleetMiningService::baseline($site, $known, 1000);
    assertRejects(static fn() => FleetMiningService::baseline($site, array_replace($known, ['site_id' => 24]), 1000));
    assertRejects(static fn() => FleetMiningService::baseline($site, array_replace($known, ['coverage' => 1.1]), 1000));
    assertRejects(static fn() => FleetMiningService::baseline($site, $known, 999), \LogicException::class);
    assertRejects(static fn() => FleetMiningService::phase($state, [1 => miningWorker()], 999), \LogicException::class);
    foreach ([NAN, INF, -1] as $value) {
        foreach (['free', 'fuel', 'fuelRate', 'fuelReserve', 'endsAt'] as $field) {
            assertRejects(static fn() => FleetMiningService::phase($state, [1 => array_replace(miningWorker(), [$field => $value])], 2000));
        }
        assertRejects(static fn() => FleetMiningService::baseline($site, $known, $value));
    }
    assertRejects(static fn() => FleetMiningService::phase($state, [1 => array_replace(miningWorker(), ['rates' => [921 => 100]])], 2000));
};
$tests['mining projections do not mutate authoritative input arrays'] = static function (): void {
    [$site, $known] = miningRows(); $fleet = [1 => miningWorker()];
    $before = serialize([$site, $known, $fleet]);
    $state = FleetMiningService::baseline($site, $known, 1000); $copy = serialize($state);
    FleetMiningService::phase($state, $fleet, 2000);
    assertSame($before, serialize([$site, $known, $fleet])); assertSame($copy, serialize($state));
};
$tests['mining deterministic samples conserve common reserves cargo and fuel'] = static function (): void {
    mt_srand(20261025);
    for ($case = 0; $case < 2000; ++$case) {
        $coverage = mt_rand(1, 100) / 100;
        [$site, $known] = miningRows(1, $coverage);
        $site['deuterium_capacity'] = 100000.0;
        $site['deuterium_stock'] = mt_rand(1, 100000) / 10;
        $site['deuterium_rate'] = mt_rand(0, 10000) / 10;
        $reserve = mt_rand(1, 100000) / 10 * $coverage;
        $known['state'] = json_encode(['reserve' => [901 => 0, 902 => 0, 903 => $reserve]]);
        $state = FleetMiningService::baseline($site, $known, 1000);
        $fleets = []; $count = mt_rand(1, 6);
        for ($id = 1; $id <= $count; ++$id) {
            $fleets[$id] = array_replace(miningWorker(mt_rand(1, 10000) / 10, mt_rand(1, 10000) / 10),
                ['fuelReserve' => 500.0, 'fuelRate' => mt_rand(0, 1000) / 10, 'endsAt' => 1000.0 + mt_rand(1, 10000)]);
        }
        $result = FleetMiningService::phase($state, $fleets, 1000 + mt_rand(1, 20000));
        $hours = $result['elapsed'] / 3600;
        $extracted = array_sum(array_column($result['cargo'], 903));
        $expectedG = min(100000.0, $site['deuterium_stock'] + $site['deuterium_rate'] * $hours - $extracted);
        $expectedK = min($coverage * 100000, $reserve + $coverage * $site['deuterium_rate'] * $hours - $extracted);
        assertNear($expectedG, $result['state']['resources'][903]['stock']);
        assertNear($expectedK, $result['state']['resources'][903]['reserve']);
        if (min($expectedG, $expectedK) < -1e-8) throw new \RuntimeException('Перерасход общего/личного запаса.');
        foreach ($result['fleets'] as $id => $after) {
            $cargo = array_sum($result['cargo'][$id]);
            $burned = $fleets[$id]['fuel'] - $after['fuel'];
            assertNear($fleets[$id]['free'] + $burned - $cargo, $after['free']);
            if ($after['fuel'] < $after['fuelReserve'] - 1e-8 || $cargo - $burned > $fleets[$id]['free'] + 1e-8) {
                throw new \RuntimeException('Нарушен резерв топлива или вместимость.');
            }
        }
    }
};

function miningJournalEntry(int $id = 42): array
{
    return ['kind' => 'fleet', 'id' => $id, 'before' => ['fuel_current' => 100.0, 'resource_metal' => 0.0],
        'after' => ['fuel_current' => 90.0, 'resource_metal' => 25.0], 'guard' => ['owner_id' => 9, 'status' => 1]];
}
$tests['mining journal canonical parts preserve values and entity identity'] = static function (): void {
    $class = \SPGame\Game\Repositories\MiningCheckpoints::class;
    $a = miningJournalEntry(); $b = miningJournalEntry(43);
    $encoded = $class::encodeEntries([$b, $a]);
    assertSame(['fleet:42', 'fleet:43'], array_keys($encoded));
    assertSame($encoded, $class::encodeEntries([$a, $b]));
    assertSame($encoded, $class::encodeEntries($class::decodeEntries($encoded)));
    assertSame(90.0, $class::decodeEntries($encoded)[0]['after']['fuel_current']);
};
$tests['mining journal refuses duplicate unknown indexed or incomplete changes'] = static function (): void {
    $class = \SPGame\Game\Repositories\MiningCheckpoints::class;
    $entry = miningJournalEntry();
    assertRejects(static fn() => $class::encodeEntries([$entry, $entry]));
    assertRejects(static fn() => $class::encodeEntries(['not an entity']));
    foreach ([['kind' => 'accounts'], ['kind' => []], ['id' => 0], ['guard' => []],
        ['after' => ['fuel_current' => 90.0]], ['before' => ['owner_id' => 9], 'after' => ['owner_id' => 10]],
        ['before' => ['fuel_current' => NAN, 'resource_metal' => 0.0]], ['unexpected' => 'ignored?']] as $patch) {
        assertRejects(static fn() => $class::encodeEntries([array_replace($entry, $patch)]));
    }
};
$tests['mining journal limits encoded entities and rejects tampered payloads'] = static function (): void {
    $class = \SPGame\Game\Repositories\MiningCheckpoints::class;
    $entry = miningJournalEntry();
    assertRejects(static fn() => $class::encodeEntries(array_fill(0, $class::MAX_ENTRIES + 1, $entry)), \OverflowException::class);
    $large = $entry; $large['guard']['padding'] = str_repeat('x', $class::MAX_ENTRY_BYTES);
    assertRejects(static fn() => $class::encodeEntries([$large]), \OverflowException::class);
    $parts = $class::encodeEntries([$entry]); $parts['fleet:42']['payload'] .= ' ';
    assertRejects(static fn() => $class::decodeEntries($parts), \LogicException::class);
    $parts = $class::encodeEntries([$entry]); $parts['fleet:43'] = $parts['fleet:42']; unset($parts['fleet:42']);
    assertRejects(static fn() => $class::decodeEntries($parts), \LogicException::class);
};
$tests['mining recovery writes absolute after once and recognizes durable after'] = static function (): void {
    $entry = miningJournalEntry();
    $before = ['id' => 42] + $entry['guard'] + $entry['before'];
    $patch = FleetMiningService::recoveryPatch($before, $entry);
    assertSame(['id' => 42] + $entry['after'], $patch);
    $after = array_replace($before, $patch);
    assertSame(null, FleetMiningService::recoveryPatch($after, $entry));
    assertNear(25, $after['resource_metal']);
};
$tests['mining recovery supports mixed saved entities without a second delta'] = static function (): void {
    $first = miningJournalEntry(); $second = miningJournalEntry(43);
    $after = ['id' => 42] + $first['guard'] + $first['after'];
    $before = ['id' => 43] + $second['guard'] + $second['before'];
    assertSame(null, FleetMiningService::recoveryPatch($after, $first));
    assertSame(['id' => 43] + $second['after'], FleetMiningService::recoveryPatch($before, $second));
};
$tests['mining recovery refuses unknown state owner or reused entity id'] = static function (): void {
    $entry = miningJournalEntry(); $before = ['id' => 42] + $entry['guard'] + $entry['before'];
    foreach ([['id' => 43], ['owner_id' => 10], ['status' => 0], ['fuel_current' => 91.0]] as $patch) {
        $current = array_replace($before, $patch); $snapshot = serialize($current);
        assertRejects(static fn() => FleetMiningService::recoveryPatch($current, $entry), \LogicException::class);
        assertSame($snapshot, serialize($current));
    }
};
$tests['mining recovery never uses a relative epsilon to hide lost resources'] = static function (): void {
    $entry = miningJournalEntry(); $entry['after']['resource_metal'] = 1000000000000.0;
    $current = ['id' => 42] + $entry['guard'] + $entry['after'];
    $current['resource_metal'] += 1;
    assertRejects(static fn() => FleetMiningService::recoveryPatch($current, $entry), \LogicException::class);
    $current['resource_metal'] = $entry['after']['resource_metal']; $current['fuel_current'] += 1e-9;
    assertRejects(static fn() => FleetMiningService::recoveryPatch($current, $entry), \LogicException::class);
};

$tests['mining checkpoint rejects implicit fixed scale rounding before publication'] = static function (): void {
    BaseRepository::assertCheckpointNumber(99.123456, 'DOUBLE(17,6) NOT NULL');
    BaseRepository::assertCheckpointNumber(1000000000000.125, 'DOUBLE NOT NULL');
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber(99.1234567, 'DOUBLE(17,6)'));
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber(100000000000.0, 'DOUBLE(17,6)'));
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber(NAN, 'DOUBLE'));
};
$tests['mining checkpoint preserves epoch microseconds without a relative tolerance'] = static function (): void {
    BaseRepository::assertCheckpointNumber(1792902898.731146, 'DOUBLE(17,6)');
    BaseRepository::assertCheckpointNumber(1792902898.731146, 'DOUBLE');
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber(1792902898.731146, 'DOUBLE(17,4)'));
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber(INF, 'DOUBLE'));
};
$tests['mining checkpoint SQL image is explicit rounding and never relative tolerance'] = static function (): void {
    assertSame(100.123457, BaseRepository::checkpointStorageNumber(100.123456789, 'DOUBLE(17,6)'));
    assertSame(100.123456789, BaseRepository::checkpointStorageNumber(100.123456789, 'DOUBLE'));
    assertSame(1000000000000.0, BaseRepository::checkpointStorageNumber(1000000000000.0, 'DOUBLE'));
    assertSame(1000000000001.0, BaseRepository::checkpointStorageNumber(1000000000001.0, 'DOUBLE'));
    assertSame(1792916730.123457, BaseRepository::checkpointStorageNumber(1792916730.1234567, 'DOUBLE(17,6)'));
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber(1792916730.1234567, 'DOUBLE(17,6)'));
    assertRejects(static fn() => BaseRepository::checkpointStorageNumber(100000000000.0, 'DOUBLE(17,6)'));
};

/** Снимок входа новой связки; нет runtime/SQL/авторизации. */
function miningPhaseFixture(): array
{
    [$site, $known] = miningRows(2); $known['id'] = 5;
    $snapshot = ['site' => $site, 'knowledge' => $known,
        'fleets' => [42 => ['id' => 42, 'owner_id' => 9, 'status' => 1, 'mission' => 10,
            'fuel_current' => 1000.123456789, 'fuel_total' => 2000.0, 'resource_metal' => 1.1, 'resource_crystal' => 2.2,
            'resource_deuterium' => 3.3, 'current_position_time' => 1000.0]],
        'commands' => [42 => ['id' => 71, 'fleet_id' => 42, 'command_type' => 43, 'execute_time' => 0.0,
            'executed' => 0, 'params' => '{"target":{"siteId":23}}']]];
    $workers = [42 => ['rates' => [901 => 1000.123456789, 902 => 0.0, 903 => 0.0], 'capacity' => 100000.0,
        'fuelRate' => 12.3456789, 'fuelReserve' => 100.0, 'endsAt' => 100000.0]];
    return [$snapshot, $workers];
}

function applyMiningPhase(array $snapshot, array $plan): array
{
    foreach ($plan['entries'] as $entry) {
        if ($entry['kind'] === 'site') $snapshot['site'] = array_replace($snapshot['site'], $entry['after']);
        elseif ($entry['kind'] === 'knowledge') $snapshot['knowledge'] = array_replace($snapshot['knowledge'], $entry['after']);
        elseif ($entry['kind'] === 'fleet') $snapshot['fleets'][$entry['id']] = array_replace($snapshot['fleets'][$entry['id']], $entry['after']);
        elseif ($entry['kind'] === 'ships') $snapshot['ships'][$entry['id']] = array_replace($snapshot['ships'][$entry['id']], $entry['after']);
        elseif ($entry['kind'] === 'survey') $snapshot['survey'] = array_replace($snapshot['survey'], $entry['after']);
        else $snapshot['commands'][$entry['guard']['fleet_id']] = array_replace($snapshot['commands'][$entry['guard']['fleet_id']], $entry['after']);
    }
    $previous = ['head' => ['site_id' => $snapshot['site']['id'], 'owner_id' => 9, 'phase' => 'applied', 'token' => str_repeat('a', 32),
        'checkpoint_time' => $plan['phase']['state']['time'] ?? $plan['metadata']['miningCheckpoint']['at'],
        'metadata' => json_encode($plan['metadata'], JSON_PRESERVE_ZERO_FRACTION)],
        'entries' => $plan['entries']];
    return [$snapshot, $previous];
}

$tests['mining phase builds bounded economic records and preserves source'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture(); $original = serialize([$snapshot, $workers]);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1643.1234567);
    assertSame(['site', 'knowledge', 'fleet', 'command'], array_column($plan['entries'], 'kind'));
    assertSame($original, serialize([$snapshot, $workers]));
    [$after] = applyMiningPhase($snapshot, $plan);
    $ledger = json_decode($after['commands'][42]['params'], true)['miningCheckpoint'];
    assertNear($plan['phase']['fleets'][42]['fuel'], $after['fleets'][42]['fuel_current'] + $ledger['fuelCarry']);
    assertSame($after['fleets'][42]['fuel_current'], round($after['fleets'][42]['fuel_current'], 6));
    assertSame($after['site']['metal_stock'], round($after['site']['metal_stock'], 6));
    assertNear($plan['phase']['state']['resources'][901]['stock'], $after['site']['metal_stock'] + $plan['metadata']['miningCheckpoint']['stockCarry'][901]);
};
$tests['mining fractional stock fuel and exact time survive split checkpoints'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $whole = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 4600.1234567);
    $previous = null; $from = 1000.0;
    for ($i = 1; $i <= 100; ++$i) {
        $until = 1000.0 + 3600.1234567 * ($i / 100);
        $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, $from, $until, $previous);
        [$snapshot, $previous] = applyMiningPhase($snapshot, $plan); $from = $until;
    }
    assertNear($whole['phase']['fleets'][42]['fuel'], $plan['phase']['fleets'][42]['fuel']);
    assertNear($whole['phase']['state']['resources'][901]['stock'], $plan['phase']['state']['resources'][901]['stock']);
    assertNear($whole['phase']['state']['resources'][901]['reserve'], $plan['phase']['state']['resources'][901]['reserve']);
    assertNear(1.1 + $whole['phase']['cargo'][42][901], $snapshot['fleets'][42]['resource_metal']);
};
$tests['mining no elapsed phase never replays rounded time regeneration'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1001.0000004);
    [$snapshot, $previous] = applyMiningPhase($snapshot, $plan);
    $stock = $plan['phase']['state']['resources'][901]['stock']; $fuel = $plan['phase']['fleets'][42]['fuel'];
    for ($i = 0; $i < 10; ++$i) {
        $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1001.0000004, 1001.0000004, $previous);
        assertSame($stock, $plan['phase']['state']['resources'][901]['stock']);
        assertSame($fuel, $plan['phase']['fleets'][42]['fuel']);
        [$snapshot, $previous] = applyMiningPhase($snapshot, $plan);
    }
};
$tests['mining carry refuses unrelated stock fuel clocks or command owner'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1100.0);
    [$snapshot, $previous] = applyMiningPhase($snapshot, $plan);
    foreach (['stock', 'fuel', 'owner', 'clock', 'command'] as $case) {
        $changed = $snapshot;
        if ($case === 'stock') $changed['site']['metal_stock'] += .000000001;
        if ($case === 'fuel') $changed['fleets'][42]['fuel_current'] += .000000001;
        if ($case === 'owner') $changed['fleets'][42]['owner_id'] = 10;
        if ($case === 'clock') $changed['knowledge']['reserve_time'] += 1;
        if ($case === 'command') $changed['commands'][42]['executed'] = 1;
        assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::build($changed, $workers, 1100.0, 1200.0, $previous),
            in_array($case, ['owner', 'command'], true) ? \InvalidArgumentException::class : \LogicException::class);
    }
};
$tests['mining phase rejects missing rows overflow and caller balance overrides'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $changed = $snapshot; unset($changed['commands'][42]);
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::build($changed, $workers, 1000.0, 1100.0));
    $changed = $snapshot; $changed['commands'][42]['params'] = json_encode(['large' => str_repeat('x', 1800)]);
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::build($changed, $workers, 1000.0, 1100.0));
    $workers[42]['fuel'] = 999999.0; $workers[42]['free'] = 999999.0;
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1100.0);
    assertNear(1000.123456789 - 12.3456789 * (100 / 3600), $plan['phase']['fleets'][42]['fuel']);
};
$tests['mining phase SQL timestamps remain representable at actual epoch magnitude'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture(); $from = 1792916730.1234567;
    $snapshot['site']['resource_update_time'] = $from;
    $snapshot['knowledge']['reserve_time'] = $from;
    $snapshot['fleets'][42]['current_position_time'] = $from;
    $workers[42]['endsAt'] = $from + 3600;
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, $from, $from + 1.23456789);
    foreach ($plan['entries'] as $entry) foreach (['resource_update_time', 'current_position_time', 'fuel_current'] as $key) {
        if (isset($entry['after'][$key])) BaseRepository::assertCheckpointNumber($entry['after'][$key], 'DOUBLE(17,6)');
    }
    [$snapshot, $previous] = applyMiningPhase($snapshot, $plan);
    $end = $plan['phase']['state']['time'];
    $replay = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, $end, $end, $previous);
    assertSame($plan['phase']['state']['resources'][901], $replay['phase']['state']['resources'][901]);
};
$tests['mining phase persists first boundary and never exceeds logical cargo capacity'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $used = 1000.123456789 + 1.1 + 2.2 + 3.3;
    $workers[42]['capacity'] = $used + .1; $workers[42]['fuelRate'] = 0.0;
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 4600.0);
    assertSame('cargo', $plan['phase']['events'][0]['kind']);
    [$after] = applyMiningPhase($snapshot, $plan);
    $ledger = json_decode($after['commands'][42]['params'], true)['miningCheckpoint'];
    assertSame(['cargo'], $ledger['events']);
    assertNear($workers[42]['capacity'], $after['fleets'][42]['fuel_current'] + $ledger['fuelCarry']
        + $after['fleets'][42]['resource_metal'] + $after['fleets'][42]['resource_crystal'] + $after['fleets'][42]['resource_deuterium']);
};
$tests['mining fractional split is conservative from small tanks to billion unit groups'] = static function (): void {
    $split = new \ReflectionMethod(\SPGame\Game\Services\FleetMiningPhaseService::class, 'split');
    mt_srand(20261025);
    foreach ([1e3, 1e6, 1e8, 1e9, 1e10] as $base) for ($i = 0; $i < 200; ++$i) {
        $value = $base + mt_rand(0, 10000000) / 10000000;
        [$stored, $carry] = $split->invoke(null, $value);
        assertSame(true, $stored <= $value && $carry >= 0 && $carry < .0000011);
        assertSame($value, $stored + $carry);
        BaseRepository::assertCheckpointNumber($stored, 'DOUBLE(17,6)');
    }
};

function miningCloseFixture(): array
{
    [$snapshot, $workers] = miningPhaseFixture();
    $phase = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1001.23456789);
    [$snapshot, $batch] = applyMiningPhase($snapshot, $phase);
    $rows = ['site' => [$snapshot['site']['id'] => $snapshot['site']],
        'knowledge' => [$snapshot['knowledge']['id'] => $snapshot['knowledge']],
        'fleet' => $snapshot['fleets'], 'command' => [71 => $snapshot['commands'][42]]];
    return [$snapshot, $batch, $rows, $phase];
}

function miningBattleFixture(int $homeSite = 0, string $finish = 'return', bool $second = false): array
{
    [$snapshot, $workers] = miningPhaseFixture();
    $snapshot['fleets'][42] += ['home_site_id' => $homeSite, 'home_planet_id' => 26];
    $snapshot['commands'][42]['command_type'] = 61;
    $snapshot['commands'][42]['execute_time'] = 5000.0;
    $snapshot['commands'][42]['params'] = json_encode(['target' => ['siteId' => 23],
        'mining' => ['phase' => 'working', 'finish' => $finish, 'siteId' => 23, 'ownerId' => 9],
        'miningRequest' => ['finish' => $finish]], JSON_THROW_ON_ERROR);
    if ($second) {
        $snapshot['fleets'][43] = array_replace($snapshot['fleets'][42], ['id' => 43]);
        $snapshot['commands'][43] = array_replace($snapshot['commands'][42], ['id' => 72, 'fleet_id' => 43]);
        $workers[43] = $workers[42];
    }
    $phase = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1001.23456789);
    [$snapshot, $batch] = applyMiningPhase($snapshot, $phase);
    $rows = ['site' => [23 => $snapshot['site']], 'knowledge' => [5 => $snapshot['knowledge']],
        'fleet' => $snapshot['fleets'], 'command' => array_column($snapshot['commands'], null, 'id')];
    return [$snapshot, $batch, $rows, $phase, $workers];
}
$tests['battle closes mining and persists paused OBSERVE in the same economic batch'] = static function (): void {
    [$snapshot, $batch, $rows, $phase] = miningBattleFixture();
    $original = serialize([$batch, $rows]);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'battle');
    [$after] = applyMiningPhase($snapshot, $plan);
    assertSame($original, serialize([$batch, $rows]));
    assertSame(43, $after['commands'][42]['command_type']); assertSame(0.0, $after['commands'][42]['execute_time']);
    $params = json_decode($after['commands'][42]['params'], true);
    assertSame('battle', $params['pause']); assertSame('battle', $params['miningClosed']['reason']);
    assertSame('finished', $params['mining']['phase']);
    assertSame(false, isset($params['miningCheckpoint'])); assertSame(false, isset($params['miningRequest']));
    assertSame($phase['phase']['fleets'][42]['fuel'], $after['fleets'][42]['fuel_current']);
    assertSame($snapshot['fleets'][42]['resource_metal'], $after['fleets'][42]['resource_metal']);
};
$tests['battle mining transition recovery is absolute including the command type'] = static function (): void {
    [$snapshot, $batch, $rows] = miningBattleFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'battle');
    [$after] = applyMiningPhase($snapshot, $plan);
    foreach ($plan['entries'] as $entry) {
        $row = match ($entry['kind']) { 'site' => $snapshot['site'], 'knowledge' => $snapshot['knowledge'],
            'fleet' => $snapshot['fleets'][$entry['id']], 'command' => $snapshot['commands'][$entry['guard']['fleet_id']] };
        $patch = FleetMiningService::recoveryPatch($row, $entry);
        if ($patch !== null) $row = array_replace($row, $patch);
        assertSame(null, FleetMiningService::recoveryPatch($row, $entry));
    }
    assertSame(43, $after['commands'][42]['command_type']);
};
$tests['battle of one miner does not stop or double-account the other miner'] = static function (): void {
    [$snapshot, $batch, $rows, , $workers] = miningBattleFixture(second: true);
    $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'battle');
    [$after, $previous] = applyMiningPhase($snapshot, $close);
    assertSame(61, $after['commands'][43]['command_type']);
    $next = $after; unset($next['fleets'][42], $next['commands'][42]);
    $at = $previous['head']['checkpoint_time'];
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($next, [43 => $workers[43]], $at, $at + 1, $previous);
    [$continued] = applyMiningPhase($next, $plan);
    assertNear($workers[43]['rates'][901] / 3600,
        $continued['fleets'][43]['resource_metal'] - $after['fleets'][43]['resource_metal']);
    assertSame(43, $after['commands'][42]['command_type']);
};
$tests['battle closes resource-home and stay mining without changing home or continuation'] = static function (): void {
    foreach ([[23, 'return'], [0, 'stay'], [23, 'stay']] as [$home, $finish]) {
        [$snapshot, $batch, $rows] = miningBattleFixture($home, $finish);
        $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'battle');
        [$after] = applyMiningPhase($snapshot, $close);
        assertSame($home, $after['fleets'][42]['home_site_id']);
        assertSame($finish, json_decode($after['commands'][42]['params'], true)['mining']['finish']);
        assertSame(43, $after['commands'][42]['command_type']);
        assertSame(0.0, $after['commands'][42]['execute_time']);
        assertSame($snapshot['fleets'][42]['resource_metal'], $after['fleets'][42]['resource_metal']);
    }
    assertSame(false, \SPGame\Game\Services\FleetMiningRuntimeService::canInterruptForBattle(null));
};
$tests['post-battle guard requires paid clock canonical site and explicit battle pause'] = static function (): void {
    $at = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $params = ['target' => $at, 'pause' => 'battle', 'siteGuard' => 19];
    $command = ['command_type' => 43, 'execute_time' => 0.0, 'executed' => 0, 'params' => json_encode($params)];
    $clock = ['time' => 1000.0, 'rate' => .01, 'reserve' => 50.0, 'speed' => 10.0];
    $check = [\SPGame\Game\Services\ResourceBaseService::class, 'postBattleGuard'];
    assertSame(true, $check($at, $command, $clock));
    assertSame(false, $check($at, $command, null));
    assertSame(false, $check($at, null, $clock));
    foreach ([['pause' => 'manual'], ['siteGuard' => 18], ['siteGuard' => 0], ['target' => ['siteId' => 18]]] as $patch) {
        assertSame(false, $check($at, array_replace($command, ['params' => json_encode(array_replace($params, $patch))]), $clock));
    }
    foreach ([['command_type' => 61], ['execute_time' => 1001.0], ['executed' => 1]] as $patch) {
        assertSame(false, $check($at, array_replace($command, $patch), $clock));
    }
    assertSame(false, $check(array_replace($at, ['siteId' => 18]), $command, $clock));
};
$tests['post-battle paid clock exhausts only excess fuel and survives a repeated snapshot'] = static function (): void {
    $clock = ['time' => 1000.0, 'rate' => .05, 'reserve' => 140.0, 'speed' => 10.0];
    $project = [\SPGame\Game\Services\ResourceBaseService::class, 'standingProjection'];
    $first = $project(143.0, $clock, 1030.0);
    assertNear(141.5, $first['fuel']); assertNear(1060.0, $first['due']);
    $again = $project($first['fuel'], array_replace($clock, ['time' => $first['time']]), 1065.0);
    assertNear(140.0, $again['fuel']); assertNear(1060.0, $again['time']);
};
$tests['site battle closes all participating miners together without another interval'] = static function (): void {
    [$snapshot, $batch, $rows] = miningBattleFixture(second: true);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42, 43], 'battle');
    [$after] = applyMiningPhase($snapshot, $plan);
    foreach ([42, 43] as $id) {
        assertSame(43, $after['commands'][$id]['command_type']);
        assertSame(0.0, $after['commands'][$id]['execute_time']);
        $params = json_decode($after['commands'][$id]['params'], true);
        assertSame('battle', $params['pause']); assertSame(false, isset($params['miningCheckpoint']));
        assertSame($snapshot['fleets'][$id]['resource_metal'], $after['fleets'][$id]['resource_metal']);
    }
};
$tests['site defense uses exact sector identity not shared orbit or home assignment'] = static function (): void {
    $at = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $other = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 18)->toArray();
    assertSame(true, \SPGame\Game\Services\ResourceBaseService::defendsSite(9, $at, null, $at, 9, false, 1000));
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::defendsSite(9, $other, null, $at, 9, false, 1000));
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::defendsSite(9, Coordinate::point(1, 7, 1000, 10)->toArray(), null, $at, 9, false, 1000));
};
$tests['departed site fleet is not a defender merely because current coordinate stores the start'] = static function (): void {
    $at = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $move = ['command_type' => 10, 'execute_time' => 1100.0, 'params' => '{}'];
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::defendsSite(9, $at, $move, $at, 9, false, 1000));
    $move['params'] = '{"pause":"manual"}';
    assertSame(true, \SPGame\Game\Services\ResourceBaseService::defendsSite(9, $at, $move, $at, 9, false, 1000));
};
$tests['only confirmed friendly defense joins a site not an unrelated visitor'] = static function (): void {
    $at = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19)->toArray();
    $guard = ['command_type' => \SPGame\Game\Enums\FleetCommandType::DEFEND->value,
        'execute_time' => 1100.0, 'params' => json_encode(['target' => $at, 'hold' => ['rate' => 1]])];
    assertSame(true, \SPGame\Game\Services\ResourceBaseService::defendsSite(10, $at, $guard, $at, 9, true, 1000));
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::defendsSite(10, $at, $guard, $at, 9, false, 1000));
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::defendsSite(10, $at, $guard, $at, 9, true, 1100));
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::defendsSite(10, $at, null, $at, 9, true, 1000));
};
$tests['resource stationing mining and guarding are sheltered from live contacts'] = static function (): void {
    foreach ([ObjectType::GAS_GIANT, ObjectType::ASTEROID_SECTOR] as $type) {
        $at = Coordinate::object(1, 7, 3, $type, siteId: 19);
        foreach ([null, ['command_type' => 61, 'execute_time' => 2000, 'params' => '{}'],
            ['command_type' => 43, 'execute_time' => 2000, 'params' => '{"hold":{"rate":1}}'],
            ['command_type' => \SPGame\Game\Enums\FleetCommandType::DEFEND->value, 'execute_time' => 2000, 'params' => '{"hold":{"rate":1}}']] as $command) {
            assertSame(true, \SPGame\Game\Services\ResourceBaseService::sheltered($at, $command));
        }
    }
};
$tests['resource departure stays observable despite the stored OBJECT origin'] = static function (): void {
    $at = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19);
    $move = ['command_type' => 10, 'execute_time' => 2000, 'params' => '{}'];
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::sheltered($at, $move));
    $move['execute_time'] = 0;
    assertSame(true, \SPGame\Game\Services\ResourceBaseService::sheltered($at, $move));
    $move['execute_time'] = 2000; $move['params'] = '{"pause":"manual"}';
    assertSame(true, \SPGame\Game\Services\ResourceBaseService::sheltered($at, $move));
};
$tests['free point holding is not sheltered merely by its system or orbital radius'] = static function (): void {
    $hold = ['command_type' => 43, 'execute_time' => 2000, 'params' => '{"hold":{"rate":1}}'];
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::sheltered(Coordinate::point(1, 7, 2821, 100), $hold));
    assertSame(false, \SPGame\Game\Services\ResourceBaseService::sheltered(Coordinate::object(1, 7, 3, ObjectType::PLANET), $hold));
};
$tests['sensor route disclosure starts at score eight and preserves object identity'] = static function (): void {
    $start = Coordinate::object(1, 7, 3, ObjectType::PLANET);
    $end = Coordinate::object(1, 7, 5, ObjectType::GAS_GIANT, siteId: 23);
    $a = Coordinate::point(1, 7, 1000, 0); $b = Coordinate::point(1, 7, 3000, 90);
    $args = [$start, $end, $a, $b, 1000.0, 2000.0, 1500.0];
    assertSame(null, \SPGame\Game\Services\FleetSensorService::routeSnapshot(7, ...$args));
    $route = \SPGame\Game\Services\FleetSensorService::routeSnapshot(8, ...$args);
    assertSame(['start', 'end', 'motionStart', 'motionEnd', 'startsAt', 'endsAt'], array_keys($route));
    assertSame($start->toArray(), $route['start']); assertSame($end->toArray(), $route['end']);
    assertSame($a->toArray(), $route['motionStart']); assertSame($b->toArray(), $route['motionEnd']);
};
$tests['sensor route never reveals another fleet identity or lastKnownPosition'] = static function (): void {
    $a = Coordinate::point(1, 7, 1000, 0); $b = Coordinate::point(1, 7, 3000, 90);
    $route = \SPGame\Game\Services\FleetSensorService::routeSnapshot(8,
        Coordinate::fleet(999, $a), Coordinate::fleet(1000, $b), $a, $b, 1000, 2000, 1500);
    assertSame($a->toArray(), $route['start']); assertSame($b->toArray(), $route['end']);
    assertSame(false, str_contains(json_encode($route), 'fleetId'));
    assertSame(false, str_contains(json_encode($route), 'lastKnownPosition'));
};
$tests['sensor route rejects elapsed future invalid and cross-system snapshots'] = static function (): void {
    $a = Coordinate::point(1, 7, 1000, 0); $b = Coordinate::point(1, 7, 3000, 90);
    $call = static fn($from, $until, $now) => \SPGame\Game\Services\FleetSensorService::routeSnapshot(8, $a, $b, $a, $b, $from, $until, $now);
    foreach ([[1000, 1000, 1000], [1000, 2000, 2000], [1000, 2000, 999], [NAN, 2000, 1500]] as $times) {
        assertRejects(static fn() => $call(...$times));
    }
    assertRejects(static fn() => \SPGame\Game\Services\FleetSensorService::routeSnapshot(8,
        $a, Coordinate::object(1, 8, 2, ObjectType::PLANET), $a, $b, 1000, 2000, 1500));
};
$tests['mining close materializes exact fuel without another production interval'] = static function (): void {
    [$snapshot, $batch, $rows, $phase] = miningCloseFixture(); $original = serialize([$batch, $rows]);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall');
    [$after] = applyMiningPhase($snapshot, $plan);
    assertSame($original, serialize([$batch, $rows]));
    assertSame($phase['phase']['fleets'][42]['fuel'], $after['fleets'][42]['fuel_current']);
    assertSame($snapshot['site'], $after['site']); assertSame($snapshot['knowledge'], $after['knowledge']);
    foreach (['resource_metal', 'resource_crystal', 'resource_deuterium', 'current_position_time'] as $field) {
        assertSame($snapshot['fleets'][42][$field], $after['fleets'][42][$field]);
    }
    $params = json_decode($after['commands'][42]['params'], true);
    assertSame(false, isset($params['miningCheckpoint'])); assertSame('recall', $params['miningClosed']['reason']);
    assertSame(['siteId' => 23], $params['target']);
    assertSame(0, $after['commands'][42]['executed']); assertSame(0.0, $after['commands'][42]['execute_time']);
    assertSame($phase['metadata']['miningCheckpoint'], $plan['metadata']['miningCheckpoint']);
    BaseRepository::assertCheckpointNumber($after['fleets'][42]['fuel_current'], 'DOUBLE');
    assertRejects(static fn() => BaseRepository::assertCheckpointNumber($after['fleets'][42]['fuel_current'], 'DOUBLE(17,6)'));
};
$tests['mining close recovery is absolute and cannot materialize fuel twice'] = static function (): void {
    [$snapshot, $batch, $rows] = miningCloseFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'duration');
    [$after, $closed] = applyMiningPhase($snapshot, $plan);
    foreach ($plan['entries'] as $entry) {
        $row = $rows[$entry['kind']][$entry['id']];
        $patch = FleetMiningService::recoveryPatch($row, $entry);
        $row = array_replace($row, $patch ?? []);
        assertSame(null, FleetMiningService::recoveryPatch($row, $entry));
        $rows[$entry['kind']][$entry['id']] = $row;
    }
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($closed, $rows, [42], 'duration'), \LogicException::class);
};
$tests['mining close of one worker keeps the other worker ledger and global stock carry'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $snapshot['fleets'][43] = array_replace($snapshot['fleets'][42], ['id' => 43]);
    $snapshot['commands'][43] = array_replace($snapshot['commands'][42], ['id' => 72, 'fleet_id' => 43]);
    $workers[43] = $workers[42];
    $phase = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1001.23456789);
    [$snapshot, $batch] = applyMiningPhase($snapshot, $phase);
    $rows = ['site' => [$snapshot['site']['id'] => $snapshot['site']], 'knowledge' => [$snapshot['knowledge']['id'] => $snapshot['knowledge']],
        'fleet' => $snapshot['fleets'], 'command' => [71 => $snapshot['commands'][42], 72 => $snapshot['commands'][43]]];
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'cargo');
    [$after] = applyMiningPhase($snapshot, $plan);
    assertSame($snapshot['fleets'][43], $after['fleets'][43]);
    assertSame($snapshot['commands'][43], $after['commands'][43]);
    assertSame($phase['metadata']['miningCheckpoint'], $plan['metadata']['miningCheckpoint']);
};
$tests['mining restart after close uses materialized fuel once and clears the old close receipt'] = static function (): void {
    [$snapshot, $batch, $rows, $phase] = miningCloseFixture();
    $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall');
    [$snapshot, $closed] = applyMiningPhase($snapshot, $close);
    [, $workers] = miningPhaseFixture(); $at = $closed['head']['checkpoint_time'];
    $next = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, $at, $at, $closed);
    [$after] = applyMiningPhase($snapshot, $next);
    $params = json_decode($after['commands'][42]['params'], true);
    assertSame(false, isset($params['miningClosed']));
    assertSame($snapshot['fleets'][42]['fuel_current'], $after['fleets'][42]['fuel_current'] + $params['miningCheckpoint']['fuelCarry']);
    assertSame($snapshot['fleets'][42]['resource_metal'], $after['fleets'][42]['resource_metal']);
    assertSame($phase['metadata']['miningCheckpoint']['stockCarry'], $next['metadata']['miningCheckpoint']['stockCarry']);
};
$tests['mining close refuses unknown source fuel cargo command or stock'] = static function (): void {
    [, $batch, $rows] = miningCloseFixture();
    foreach ([['fleet', 42, 'fuel_current'], ['fleet', 42, 'resource_metal'],
        ['site', $batch['head']['site_id'], 'metal_stock'], ['command', 71, 'execute_time']] as [$kind, $id, $field]) {
        $changed = $rows; $changed[$kind][$id][$field] += .000001;
        assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $changed, [42], 'recall'), \LogicException::class);
    }
};
$tests['mining close validates participants reason and applied checkpoint'] = static function (): void {
    [, $batch, $rows] = miningCloseFixture();
    foreach ([[], [42,42], ['42'], [0], [-1]] as $ids) {
        assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, $ids, 'recall'));
    }
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [43], 'recall'), \LogicException::class);
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'unknown'));
    $batch['head']['phase'] = 'prepared';
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall'));
};
$tests['mining close requires the loaded tank to contain the materialized remainder'] = static function (): void {
    [, $batch, $rows] = miningCloseFixture();
    $rows['fleet'][42]['fuel_total'] = $rows['fleet'][42]['fuel_current'];
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall'), \LogicException::class);
};
$tests['unscaled DOUBLE parameter uses a roundtrip string without changing fixed scale policy'] = static function (): void {
    $method = new \ReflectionMethod(BaseRepository::class, 'checkpointParameter');
    foreach ([150.79671540536978, 1792927000.5413675, .00000040536977508054406] as $value) {
        assertSame($value, (float)$method->invoke(null, $value));
    }
};
$tests['ordinary saver SQL image cannot truncate a confirmed epoch checkpoint to precision14'] = static function (): void {
    $method = new \ReflectionMethod(BaseRepository::class, 'checkpointParameter');
    foreach ([1792927000.541368, 1792927000.5413675, 1792916730.1234567] as $time) {
        $expected = (float)sprintf('%.6F', $time);
        $parameter = $method->invoke(null, BaseRepository::checkpointStorageNumber($time, 'DOUBLE(17,6)'));
        assertSame($expected, (float)$parameter);
        assertSame(true, $expected !== (float)sprintf('%.14g', $time));
    }
};

$tests['mining runtime options enforce approved modes durations and finish without client rates'] = static function (): void {
    $class = \SPGame\Game\Services\FleetMiningRuntimeService::class;
    assertSame(['mode' => 'mix', 'duration' => 3600, 'finish' => 'return', 'speedFactor' => 10.0], $class::options([], false));
    assertSame('deuterium', $class::options([], true)['mode']);
    foreach ([3600,7200,14400,28800,43200,57600,115200] as $duration) {
        assertSame($duration, $class::options(['duration' => $duration, 'finish' => 'stay'], false)['duration']);
    }
    foreach ([['duration' => '3600'], ['duration' => 3], ['duration' => 0], ['mode' => 'deuterium'],
        ['finish' => 'unknown'], ['speedFactor' => INF], ['speedFactor' => .5], ['speedFactor' => true]] as $bad) {
        assertRejects(static fn() => $class::options($bad, false));
    }
    assertRejects(static fn() => $class::options(['mode' => 'metal'], true));
    assertSame(false, isset($class::options(['rates' => [901 => 1e9]], false)['rates']));
};
$tests['mining due time never schedules before its exact microsecond boundary'] = static function (): void {
    $class = \SPGame\Game\Services\FleetMiningRuntimeService::class;
    foreach ([1000.0,1000.0000001,1792928915.202005,1792928915.2020055] as $time) {
        $due = $class::due($time);
        assertSame(true, $due >= $time && $due - $time < .0000011);
        BaseRepository::assertCheckpointNumber($due, 'DOUBLE(17,6)');
    }
    foreach ([0.0, -1.0, INF, NAN] as $time) assertRejects(static fn() => $class::due($time));
};
$tests['mining positive sub-ULP cargo boundary cannot repeat a zero-time scheduler event'] = static function (): void {
    $class = \SPGame\Game\Services\FleetMiningRuntimeService::class;
    [$site, $known] = miningRows();
    $state = FleetMiningService::baseline($site, $known, 1000);
    $at = $state['time'] = 1792932223.312826;
    $worker = miningWorker(100); $worker['free'] = 1e-9;
    $worker['fuelRate'] = 10.0; $worker['endsAt'] = $at + 3600;
    $forecast = FleetMiningService::phase($state, [48 => $worker], $at + 3600);
    assertSame(true, $forecast['elapsed'] > 0); assertSame($at, $forecast['state']['time']);
    $due = $class::due($forecast['state']['time'], $at);
    assertSame(true, $due > $at && $due - $at < .0000013); // SQL-квант плюс ULP epoch.
    $actual = FleetMiningService::phase($state, [48 => $worker], $due);
    assertSame(['cargo'], array_column($actual['events'], 'kind'));
    assertSame($forecast['elapsed'], $actual['elapsed']); // Не начислять целую SQL-микросекунду.
    assertSame($forecast['cargo'], $actual['cargo']);
    foreach ([0.0, -1.0, INF, NAN, $at + 1] as $after) assertRejects(static fn() => $class::due($at, $after));
};
$tests['mining mixed exhaustion switches full group power once without giving power to another worker'] = static function (): void {
    $class = \SPGame\Game\Services\FleetMiningRuntimeService::class;
    $site = ['metal_rate' => 30.0, 'crystal_rate' => 10.0];
    $run = ['phase' => 'working', 'mode' => 'mix', 'blocked' => [], 'production' => 100.0];
    assertSame([901 => 75.0,902 => 25.0,903 => 0.0], $class::distribution($run, $site));
    $runs = $class::boundaries([42 => $run, 43 => $run], ['events' => [['kind' => 'stock', 'resourceId' => 901],
        ['kind' => 'cargo', 'fleetId' => 43]]], $site);
    assertSame([901 => 0.0,902 => 100.0,903 => 0.0], $class::distribution($runs[42], $site));
    assertSame('working', $runs[42]['phase']); assertSame('closing', $runs[43]['phase']);
    $runs = $class::boundaries($runs, ['events' => [['kind' => 'reserve', 'resourceId' => 902]]], $site);
    assertSame('closing', $runs[42]['phase']); assertSame('reserve', $runs[42]['reason']);
    assertSame('cargo', $runs[43]['reason']);
};
$tests['mining fixed resource exhaustion closes only the affected worker'] = static function (): void {
    $class = \SPGame\Game\Services\FleetMiningRuntimeService::class;
    $site = ['metal_rate' => 30.0, 'crystal_rate' => 10.0];
    $runs = [42 => ['phase' => 'working', 'mode' => 'metal', 'blocked' => [], 'production' => 100.0],
        43 => ['phase' => 'working', 'mode' => 'crystal', 'blocked' => [], 'production' => 300.0]];
    $after = $class::boundaries($runs, ['events' => [['kind' => 'stock', 'resourceId' => 901]]], $site);
    assertSame('closing', $after[42]['phase']); assertSame('working', $after[43]['phase']);
    assertSame([901 => 0.0,902 => 300.0,903 => 0.0], $class::distribution($after[43], $site));
};
$tests['mining close makes its own command due for terminal dispatch but never marks it executed early'] = static function (): void {
    [$snapshot, $workers] = miningPhaseFixture();
    $snapshot['commands'][42]['params'] = '{"mining":{"phase":"closing"},"target":{"siteId":23}}';
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1001.23456789);
    [$snapshot, $batch] = applyMiningPhase($snapshot, $plan);
    $rows = ['site' => [$snapshot['site']['id'] => $snapshot['site']], 'knowledge' => [$snapshot['knowledge']['id'] => $snapshot['knowledge']],
        'fleet' => $snapshot['fleets'], 'command' => [71 => $snapshot['commands'][42]]];
    $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'cargo');
    [$after] = applyMiningPhase($snapshot, $close);
    $params = json_decode($after['commands'][42]['params'], true);
    assertSame('finished', $params['mining']['phase']); assertSame('cargo', $params['mining']['reason']);
    assertSame(0, $after['commands'][42]['executed']);
    assertSame(true, $after['commands'][42]['execute_time'] >= $batch['head']['checkpoint_time']);
};

function miningTransferFixture(): array
{
    [$snapshot, $workers] = miningPhaseFixture();
    [$snapshot, $previous] = applyMiningPhase($snapshot,
        \SPGame\Game\Services\FleetMiningPhaseService::build($snapshot, $workers, 1000.0, 1060.0));
    $source = ['id' => 43, 'owner_id' => 9, 'status' => 1, 'mission' => 10,
        'resource_metal' => 40.0, 'resource_crystal' => 30.0, 'resource_deuterium' => 3000.0,
        'current_position_time' => 1060.0];
    $command = ['id' => 72, 'fleet_id' => 43, 'command_type' => \SPGame\Game\Enums\FleetCommandType::UNLOAD->value,
        'execute_time' => 1060.0, 'executed' => 0, 'create_time' => 1000.0,
        'params' => json_encode(['target' => ['type' => \SPGame\Game\Space\CoordinateType::FLEET->value, 'fleetId' => 42],
            'refuel' => true, 'percent' => 100])];
    return [$snapshot, $workers, $previous, $source, $command];
}
$tests['mining delivery rebases logical tank and cargo without another work interval'] = static function (): void {
    [$snapshot, $workers, $previous, $source, $command] = miningTransferFixture();
    $original = serialize([$snapshot, $workers, $previous, $source, $command]);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::transfer($snapshot, $workers, 1060, $previous, $source, $command);
    assertSame($original, serialize([$snapshot, $workers, $previous, $source, $command]));
    $logical = $snapshot['fleets'][42]['fuel_current'] + json_decode($snapshot['commands'][42]['params'], true)['miningCheckpoint']['fuelCarry'];
    $full = $snapshot; $full['fleets'][43] = $source; $full['commands'][43] = $command;
    [$after, $transferBatch] = applyMiningPhase($full, $plan);
    $ledger = json_decode($after['commands'][42]['params'], true)['miningCheckpoint'];
    assertNear($logical + 3000, $after['fleets'][42]['fuel_current'] + $ledger['fuelCarry'], 1e-9);
    assertSame(0.0, $plan['phase']['elapsed']);
    assertNear($snapshot['fleets'][42]['resource_metal'] + 40, $after['fleets'][42]['resource_metal']);
    assertNear($snapshot['fleets'][42]['resource_crystal'] + 30, $after['fleets'][42]['resource_crystal']);
    assertSame($snapshot['fleets'][42]['resource_deuterium'], $after['fleets'][42]['resource_deuterium']);
    assertSame(0.0, $after['fleets'][43]['resource_deuterium']);
    assertSame(0, $after['commands'][43]['executed']);
    assertSame($plan['delivered'], json_decode($after['commands'][43]['params'], true)['miningTransfer']['resources']);
    assertSame(true, $after['fleets'][42]['fuel_total'] >= $logical + 3000);
    foreach ($plan['entries'] as $entry) if ($entry['kind'] === 'fleet' && $entry['id'] === 42) {
        assertSame($snapshot['fleets'][42]['fuel_current'], $entry['before']['fuel_current']);
        assertSame($snapshot['fleets'][42]['fuel_total'], $entry['before']['fuel_total']);
    }
    unset($after['fleets'][43], $after['commands'][43]);
    $next = \SPGame\Game\Services\FleetMiningPhaseService::build($after, $workers, 1060, 1120, $transferBatch);
    [$nextState] = applyMiningPhase($after, $next);
    $nextLedger = json_decode($nextState['commands'][42]['params'], true)['miningCheckpoint'];
    assertNear($logical + 3000 - $workers[42]['fuelRate'] / 60,
        $nextState['fleets'][42]['fuel_current'] + $nextLedger['fuelCarry'], 1e-9);
    assertNear($after['fleets'][42]['resource_metal'] + $workers[42]['rates'][901] / 60, $nextState['fleets'][42]['resource_metal']);
};
$tests['mining delivery respects percentage free capacity and cargo deuterium mode'] = static function (): void {
    [$snapshot, $workers, $previous, $source, $command] = miningTransferFixture();
    $params = json_decode($command['params'], true); $params['percent'] = 25; $params['refuel'] = false;
    $command['params'] = json_encode($params);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::transfer($snapshot, $workers, 1060, $previous, $source, $command);
    assertSame([901 => 10.0, 902 => 7.5, 903 => 750.0], $plan['delivered']);
    $full = $snapshot; $full['fleets'][43] = $source; $full['commands'][43] = $command;
    [$after] = applyMiningPhase($full, $plan);
    assertNear($snapshot['fleets'][42]['resource_deuterium'] + 750, $after['fleets'][42]['resource_deuterium']);
    $ledger = json_decode($snapshot['commands'][42]['params'], true)['miningCheckpoint'];
    $used = $snapshot['fleets'][42]['fuel_current'] + $ledger['fuelCarry'];
    foreach (['metal', 'crystal', 'deuterium'] as $key) $used += $snapshot['fleets'][42]['resource_' . $key];
    $workers[42]['capacity'] = $used + 10;
    $limited = \SPGame\Game\Services\FleetMiningPhaseService::transfer($snapshot, $workers, 1060, $previous, $source, $command);
    assertNear(10, array_sum($limited['delivered']), 1e-8);
    assertSame(['cargo'], array_column($limited['phase']['events'], 'kind'));
};
$tests['mining delivery rejects foreign source changed identity and repeated receipt'] = static function (): void {
    [$s, $w, $p, $source, $command] = miningTransferFixture();
    $class = \SPGame\Game\Services\FleetMiningPhaseService::class;
    $bad = $source; $bad['owner_id'] = 10; assertRejects(static fn() => $class::transfer($s, $w, 1060, $p, $bad, $command), \LogicException::class);
    $bad = $source; $bad['resource_deuterium'] = NAN; assertRejects(static fn() => $class::transfer($s, $w, 1060, $p, $bad, $command));
    foreach ([['fleet_id' => 44], ['executed' => 1], ['command_type' => 61]] as $change) {
        $badCommand = array_replace($command, $change);
        assertRejects(static fn() => $class::transfer($s, $w, 1060, $p, $source, $badCommand), \LogicException::class);
    }
    $params = json_decode($command['params'], true); $params['miningTransfer'] = ['receiverId' => 42];
    $command['params'] = json_encode($params);
    assertRejects(static fn() => $class::transfer($s, $w, 1060, $p, $source, $command), \LogicException::class);
};
$tests['mining close after delivery does not require a donor that continued its route'] = static function (): void {
    [$snapshot, $workers, $previous, $source, $command] = miningTransferFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::transfer($snapshot, $workers, 1060, $previous, $source, $command);
    $snapshot['fleets'][43] = $source; $snapshot['commands'][43] = $command;
    [$snapshot, $batch] = applyMiningPhase($snapshot, $plan);
    $rows = ['site' => [$snapshot['site']['id'] => $snapshot['site']], 'knowledge' => [$snapshot['knowledge']['id'] => $snapshot['knowledge']],
        'fleet' => [42 => $snapshot['fleets'][42]], 'command' => [71 => $snapshot['commands'][42]]];
    $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall');
    assertSame(['site', 'knowledge', 'fleet', 'command'], array_column($close['entries'], 'kind'));
    $rows['fleet'][42]['fuel_current'] += 1;
    assertRejects(static fn() => \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall'), \LogicException::class);
};

function miningMergeFixture(): array
{
    [$snapshot, $workers, $previous, $source, $command] = miningTransferFixture();
    $snapshot['fleets'][42] += ['experience' => 20.0, 'resource_credit' => 7.0, 'resource_doubloon' => 2.0];
    $source += ['fuel_current' => 1500.123456789, 'fuel_total' => 2000.0, 'experience' => 30.0,
        'resource_credit' => 11.0, 'resource_doubloon' => 3.0];
    $command['command_type'] = \SPGame\Game\Enums\FleetCommandType::MERGE->value;
    $snapshot['ships'] = [];
    foreach ([42 => [2, 60.0, .2, .9], 43 => [1, 40.0, .8, .6]] as $id => [$count, $xp, $damage, $morale]) {
        $snapshot['ships'][$id] = ['id' => $id, 'owner_id' => 9, 'fleet_id' => $id,
            'asteroid_miner_count' => $count, 'asteroid_miner_experience' => $xp, 'asteroid_miner_damage' => $damage,
            'asteroid_miner_morale' => $morale, 'asteroid_miner_status' => 0];
        $after = ['asteroid_miner_count' => $id === 42 ? 3 : 0, 'asteroid_miner_experience' => $id === 42 ? 100.0 : 0.0,
            'asteroid_miner_damage' => $id === 42 ? .4 : 0.0, 'asteroid_miner_morale' => $id === 42 ? .8 : 1.0, 'asteroid_miner_status' => 0];
        $entries[] = ['kind' => 'ships', 'id' => $id, 'group' => 'asteroid_miner',
            'before' => array_intersect_key($snapshot['ships'][$id], $after), 'after' => $after,
            'guard' => ['fleet_id' => $id, 'owner_id' => 9]];
    }
    $newWorker = array_replace($workers[42], ['rates' => [901 => 3000.0, 902 => 0.0, 903 => 0.0], 'capacity' => 120000.0,
        'fuelRate' => 30.0, 'fuelReserve' => 250.0]);
    return [$snapshot, $workers, $previous, $source, $command, $newWorker, $entries];
}
$tests['mining ship codec keeps disjoint groups bounded and backward compatible'] = static function (): void {
    [, , , , , , $entries] = miningMergeFixture();
    $second = $entries[0]; $second['group'] = 'small_ship_cargo';
    foreach (['before', 'after'] as $side) {
        $row = []; foreach ($second[$side] as $key => $value) $row[str_replace('asteroid_miner', 'small_ship_cargo', $key)] = $value;
        $second[$side] = $row;
    }
    $entries[] = $second;
    $codec = \SPGame\Game\Repositories\MiningCheckpoints::class;
    $parts = $codec::encodeEntries($entries);
    assertSame(3, count($parts));
    foreach ($parts as $key => $part) { assertSame(true, strlen($key) <= 64); assertSame(true, strlen($part['payload']) < 8192); }
    assertSame($parts, $codec::encodeEntries($codec::decodeEntries($parts)));
    assertRejects(static fn() => $codec::encodeEntries([$entries[0], $entries[0]]));
    $bad = $entries[0]; $bad['group'] = 'other';
    assertRejects(static fn() => $codec::encodeEntries([$bad]));
    $bad = $entries[0]; unset($bad['after']['asteroid_miner_status']);
    assertRejects(static fn() => $codec::encodeEntries([$bad]));
};
$tests['mining merge journals ships XP full cargo tanks and due receipt without elapsed work'] = static function (): void {
    [$s, $w, $p, $source, $command, $worker, $ships] = miningMergeFixture();
    $original = serialize([$s, $w, $p, $source, $command]);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::merge($s, $w, 1060, $p, $source, $command, $worker, $ships, 33.333333);
    assertSame($original, serialize([$s, $w, $p, $source, $command]));
    $s['fleets'][43] = $source; $s['commands'][43] = $command;
    [$after, $batch] = applyMiningPhase($s, $plan);
    assertSame(0.0, $plan['phase']['elapsed']);
    foreach (['metal', 'crystal', 'deuterium', 'credit', 'doubloon'] as $name) {
        assertNear($s['fleets'][42]['resource_' . $name] + $source['resource_' . $name], $after['fleets'][42]['resource_' . $name]);
        assertSame(0.0, $after['fleets'][43]['resource_' . $name]);
    }
    $oldLedger = json_decode($s['commands'][42]['params'], true)['miningCheckpoint'];
    $ledger = json_decode($after['commands'][42]['params'], true)['miningCheckpoint'];
    assertNear($s['fleets'][42]['fuel_current'] + $oldLedger['fuelCarry'] + $source['fuel_current'],
        $after['fleets'][42]['fuel_current'] + $ledger['fuelCarry']);
    assertSame(0.0, $after['fleets'][43]['fuel_current']);
    assertSame(3, $after['ships'][42]['asteroid_miner_count']);
    assertSame(100.0, $after['ships'][42]['asteroid_miner_experience']);
    assertSame(0, $after['ships'][43]['asteroid_miner_count']);
    assertSame(0, $after['commands'][43]['executed']);
    assertSame(['receiverId' => 42, 'siteId' => 23], json_decode($after['commands'][43]['params'], true)['miningMerge']);
    unset($after['fleets'][43], $after['commands'][43], $after['ships'][43]);
    $next = \SPGame\Game\Services\FleetMiningPhaseService::build($after, [42 => $worker], 1060, 1120, $batch);
    assertNear(50.0, $next['phase']['cargo'][42][901]);
    assertNear($plan['phase']['fleets'][42]['fuel'] - .5, $next['phase']['fleets'][42]['fuel']);
};
$tests['mining close after merge ignores deleted donor and structural ship entries'] = static function (): void {
    [$s, $w, $p, $source, $command, $worker, $ships] = miningMergeFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::merge($s, $w, 1060, $p, $source, $command, $worker, $ships, 33.333333);
    $s['fleets'][43] = $source; $s['commands'][43] = $command;
    [$after, $batch] = applyMiningPhase($s, $plan);
    $rows = ['site' => [23 => $after['site']], 'knowledge' => [5 => $after['knowledge']],
        'fleet' => [42 => $after['fleets'][42]], 'command' => [71 => $after['commands'][42]]];
    // Освободившийся ID команды может уже принадлежать другому живому рейсу.
    $rows['command'][72] = array_replace($command, ['fleet_id' => 99, 'params' => '{}']);
    $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall');
    assertSame(['site', 'knowledge', 'fleet', 'command'], array_column($close['entries'], 'kind'));
};
$tests['mining merge rejects replay foreign participants nonempty donor and overflow'] = static function (): void {
    [$s, $w, $p, $source, $command, $worker, $ships] = miningMergeFixture();
    $class = \SPGame\Game\Services\FleetMiningPhaseService::class;
    $bad = $source; $bad['owner_id'] = 8;
    assertRejects(static fn() => $class::merge($s, $w, 1060, $p, $bad, $command, $worker, $ships, 0), \LogicException::class);
    $bad = $command; $params = json_decode($bad['params'], true); $params['miningMerge'] = ['receiverId' => 42]; $bad['params'] = json_encode($params);
    assertRejects(static fn() => $class::merge($s, $w, 1060, $p, $source, $bad, $worker, $ships, 0), \LogicException::class);
    $bad = $ships; $bad[1]['after']['asteroid_miner_count'] = 1;
    assertRejects(static fn() => $class::merge($s, $w, 1060, $p, $source, $command, $worker, $bad, 0), \LogicException::class);
    $worker['capacity'] = 100.0;
    assertRejects(static fn() => $class::merge($s, $w, 1060, $p, $source, $command, $worker, $ships, 0), \LogicException::class);
};

$tests['merge from resource base uses onboard assets without a fictional planetary LOAD'] = static function (): void {
    $site = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19);
    $target = Coordinate::fleet(47, Coordinate::point(1, 7, 2821, 45));
    $builder = \SPGame\Game\Services\FleetMissionBuilder::class;
    $commands = $builder::buildMerge($site, $target);
    assertSame([10, 53], array_map(static fn($row) => $row['type']->value, $commands));
    $commands = $builder::buildMerge(Coordinate::object(1, 7, 2, ObjectType::PLANET), $target);
    assertSame(\SPGame\Game\Enums\FleetCommandType::LOAD, $commands[0]['type']);
};

function miningLostHomeFixture(): array
{
    [$s, $w] = miningPhaseFixture();
    $s['fleets'][42] += ['home_planet_id' => 0, 'home_site_id' => 2, 'fallback_planet_id' => 26,
        'base_fuel_time' => 0.0, 'base_fuel_rate' => 0.0, 'base_fuel_reserve' => 0.0, 'base_fuel_speed' => 0.0];
    $s['fleets'][43] = array_replace($s['fleets'][42], ['id' => 43, 'home_site_id' => 23]);
    $s['commands'][43] = array_replace($s['commands'][42], ['id' => 72, 'fleet_id' => 43]);
    $w[43] = $w[42];
    [$s, $p] = applyMiningPhase($s, \SPGame\Game\Services\FleetMiningPhaseService::build($s, $w, 1000, 1060));
    return [$s, $w, $p];
}
$tests['remote mining home loss journals home and reserve without accrual or closing its peer'] = static function (): void {
    [$s, $w, $p] = miningLostHomeFixture(); $before = serialize([$s, $w, $p]);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::rehome($s, $w, 1060, $p, 2,
        [42 => ['planetId' => 26, 'fuelReserve' => 200.0]]);
    assertSame($before, serialize([$s, $w, $p]));
    [$after, $batch] = applyMiningPhase($s, $plan);
    assertSame(26, $after['fleets'][42]['home_planet_id']); assertSame(0, $after['fleets'][42]['home_site_id']);
    assertSame($s['fleets'][43], $after['fleets'][43]);
    assertSame($s['site'], $after['site']); assertSame($s['knowledge'], $after['knowledge']);
    foreach ([42,43] as $id) {
        foreach (['fuel_current','resource_metal','resource_crystal','resource_deuterium'] as $field) assertNear($s['fleets'][$id][$field], $after['fleets'][$id][$field]);
    }
    $params = json_decode($after['commands'][42]['params'], true);
    assertSame(200.0, $params['miningCheckpoint']['fuelReserve']);
    $w[42]['fuelReserve'] = 200.0;
    $next = \SPGame\Game\Services\FleetMiningPhaseService::build($after, $w, 1060, 1120, $batch);
    $normal = \SPGame\Game\Services\FleetMiningPhaseService::build($s, $w, 1060, 1120, $p);
    assertSame($normal['phase'], $next['phase']);
};
$tests['remote home rebase recovers whole fleet rows and rejects partial or third values'] = static function (): void {
    [$s, $w, $p] = miningLostHomeFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::rehome($s, $w, 1060, $p, 2,
        [42 => ['planetId' => 26, 'fuelReserve' => 200.0]]);
    $entry = array_values(array_filter($plan['entries'], static fn($e) => $e['kind'] === 'fleet' && $e['id'] === 42))[0];
    $row = $s['fleets'][42];
    $patch = FleetMiningService::recoveryPatch($row, $entry);
    assertSame(0, $patch['home_site_id']);
    assertSame(null, FleetMiningService::recoveryPatch(array_replace($row, $patch), $entry));
    foreach (['home_planet_id' => 26, 'home_site_id' => 777] as $field => $value) {
        assertRejects(static fn() => FleetMiningService::recoveryPatch(array_replace($row, [$field => $value]), $entry), \LogicException::class);
    }
};
$tests['remote home reserve boundary closes only affected miner'] = static function (): void {
    [$s, $w, $p] = miningLostHomeFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::rehome($s, $w, 1060, $p, 2,
        [42 => ['planetId' => 26, 'fuelReserve' => 2000.0]]);
    assertSame([['kind' => 'fuel', 'fleetId' => 42]], $plan['phase']['events']);
    assertSame([], array_values(array_filter($plan['phase']['events'], static fn($e) => ($e['fleetId'] ?? null) === 43)));
};
$tests['remote home rebase refuses wrong source site miner and fallback'] = static function (): void {
    [$s, $w, $p] = miningLostHomeFixture(); $class = \SPGame\Game\Services\FleetMiningPhaseService::class;
    assertRejects(static fn() => $class::rehome($s, $w, 1060, $p, 23, [42 => ['planetId' => 26, 'fuelReserve' => 0.0]]));
    foreach ([[43 => ['planetId' => 26, 'fuelReserve' => 0.0]], [42 => ['planetId' => 0, 'fuelReserve' => 0.0]]] as $homes) {
        assertRejects(static fn() => $class::rehome($s, $w, 1060, $p, 2, $homes), \LogicException::class);
    }
};

function miningSurveyFixture(): array
{
    [$s, $w] = miningPhaseFixture();
    $s['site']['survey_difficulty'] = 58;
    $s['knowledge']['coverage'] = .25; $s['knowledge']['observed_at'] = 1000.0;
    $personal = json_decode($s['knowledge']['state'], true); foreach ($personal['reserve'] as &$value) $value *= .25; unset($value);
    $s['knowledge']['state'] = json_encode($personal);
    [$s, $previous] = applyMiningPhase($s, \SPGame\Game\Services\FleetMiningPhaseService::build($s, $w, 1000, 1060));
    $stock = \SPGame\Game\Services\FleetMiningPhaseService::stock($s['site'], 1060, $previous);
    $after = array_replace($s['knowledge'], \SPGame\Game\Services\FleetSurveyService::reveal($s['site'], $s['knowledge'], $stock, 1060, 10, 16, str_repeat('c', 32)));
    $receipt = ['id' => 10, 'token' => str_repeat('c', 32), 'state' => json_encode(['phase' => 'prepared',
        'mining' => ['siteId' => 23, 'ownerId' => 9, 'at' => 1060.0], 'knowledge' => $after,
        'message' => ['id' => 30], 'fuel' => 500.0], JSON_PRESERVE_ZERO_FRACTION)];
    $s['survey'] = $receipt;
    return [$s, $w, $previous, $receipt, $stock];
}
$tests['survey reveals only new coverage of actual stock without refilling depleted knowledge'] = static function (): void {
    [$s, , , $receipt, $stock] = miningSurveyFixture();
    $after = json_decode($receipt['state'], true)['knowledge'];
    $old = json_decode($s['knowledge']['state'], true)['reserve']; $new = json_decode($after['state'], true)['reserve'];
    assertSame(1.0, $after['coverage']);
    foreach ([901,902,903] as $id) assertNear($old[$id] + .75 * $stock[$id], $new[$id]);
    $repeat = \SPGame\Game\Services\FleetSurveyService::reveal($s['site'], $after, $stock, 1060, 10, 16, str_repeat('c', 32));
    assertSame($after['state'], $repeat['state']);
    $foreign = $s['knowledge']; $foreign['owner_id'] = 10;
    $result = \SPGame\Game\Services\FleetSurveyService::reveal($s['site'], $foreign, $stock, 1060, 10, 16, str_repeat('d', 32));
    assertSame($s['knowledge']['state'], json_encode(['reserve' => $old], JSON_PRESERVE_ZERO_FRACTION));
    assertSame(true, $result['coverage'] >= $foreign['coverage']);
};
$tests['survey rebases all mining carriers and durable marker with zero additional production'] = static function (): void {
    [$s, $w, $previous, $receipt] = miningSurveyFixture();
    $original = serialize($s);
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::survey($s, $w, 1060, $previous, $receipt);
    assertSame($original, serialize($s));
    assertSame(0.0, $plan['phase']['elapsed']);
    [$after, $batch] = applyMiningPhase($s, $plan);
    assertSame($s['fleets'][42], $after['fleets'][42]);
    assertSame(1.0, $after['knowledge']['coverage']);
    assertSame(true, json_decode($after['survey']['state'], true)['miningApplied']);
    $next = \SPGame\Game\Services\FleetMiningPhaseService::build($after, $w, 1060, 1120, $batch);
    assertNear($w[42]['rates'][901] / 60, $next['phase']['cargo'][42][901]);
    $rows = ['site' => [23 => $after['site']], 'knowledge' => [5 => $after['knowledge']],
        'fleet' => [42 => $after['fleets'][42]], 'command' => [71 => $after['commands'][42]]];
    $close = \SPGame\Game\Services\FleetMiningPhaseService::close($batch, $rows, [42], 'recall');
    assertSame(['site', 'knowledge', 'fleet', 'command'], array_column($close['entries'], 'kind'));
};
$tests['survey phase rejects foreign identity stale time and replayed durable result'] = static function (): void {
    [$s, $w, $p, $receipt] = miningSurveyFixture(); $class = \SPGame\Game\Services\FleetMiningPhaseService::class;
    foreach (['owner', 'site', 'replay', 'time'] as $case) {
        $bad = $receipt; $state = json_decode($bad['state'], true);
        if ($case === 'owner') $state['knowledge']['owner_id'] = 10;
        if ($case === 'site') $state['mining']['siteId'] = 24;
        if ($case === 'replay') $state['miningApplied'] = true;
        if ($case === 'time') $state['knowledge']['reserve_time'] = 1061.0;
        $bad['state'] = json_encode($state);
        assertRejects(static fn() => $class::survey($s, $w, 1060, $p, $bad), \LogicException::class);
    }
};
$tests['survey receipt barrier survives preparation and releases only after economy or final application'] = static function (): void {
    $class = \SPGame\Game\Services\FleetSurveyService::class;
    $state = ['phase' => 'preparing', 'barrier' => ['siteId' => 23]];
    assertSame(true, $class::pendingMining($state, 23)); assertSame(false, $class::pendingMining($state, 24));
    $state['phase'] = 'prepared'; assertSame(true, $class::pendingMining($state, 23));
    $state['miningApplied'] = true; assertSame(false, $class::pendingMining($state, 23));
    unset($state['miningApplied']); $state['phase'] = 'applied'; assertSame(false, $class::pendingMining($state, 23));
};

$tests['survey rebases multiple miners together and preserves their individual fuel and rates'] = static function (): void {
    [$s, $w, $p, $receipt] = miningSurveyFixture();
    $s['fleets'][43] = array_replace($s['fleets'][42], ['id' => 43, 'resource_metal' => 0.0]);
    $s['commands'][43] = array_replace($s['commands'][42], ['id' => 72, 'fleet_id' => 43, 'params' => '{}']);
    $w[43] = array_replace($w[42], ['rates' => [901 => 600.0,902 => 0.0,903 => 0.0], 'fuelRate' => 7.0]);
    [$s, $p] = applyMiningPhase($s, \SPGame\Game\Services\FleetMiningPhaseService::build($s, $w, 1060, 1060, $p));
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::survey($s, $w, 1060, $p, $receipt);
    [$after, $p] = applyMiningPhase($s, $plan);
    assertSame($s['fleets'], $after['fleets']);
    assertSame($s['site'], $after['site']);
    $next = \SPGame\Game\Services\FleetMiningPhaseService::build($after, $w, 1060, 1120, $p);
    assertNear($w[42]['rates'][901] / 60, $next['phase']['cargo'][42][901]);
    assertNear(10.0, $next['phase']['cargo'][43][901]);
    foreach ([42,43] as $id) assertNear($plan['phase']['fleets'][$id]['fuel'] - $w[$id]['fuelRate'] / 60, $next['phase']['fleets'][$id]['fuel']);
};
$tests['survey journal recovery is absolute and cannot grant coverage or reserve twice'] = static function (): void {
    [$s, $w, $p, $receipt] = miningSurveyFixture();
    $plan = \SPGame\Game\Services\FleetMiningPhaseService::survey($s, $w, 1060, $p, $receipt);
    foreach ($plan['entries'] as $entry) {
        $row = match ($entry['kind']) {
            'site' => $s['site'], 'knowledge' => $s['knowledge'], 'fleet' => $s['fleets'][$entry['id']],
            'command' => $s['commands'][$entry['guard']['fleet_id']], 'survey' => $receipt,
        };
        $patch = \SPGame\Game\Services\FleetMiningService::recoveryPatch($row, $entry);
        $after = $patch === null ? $row : array_replace($row, $patch);
        assertSame(null, \SPGame\Game\Services\FleetMiningService::recoveryPatch($after, $entry));
    }
};

$tests['public mining return pipeline loads once works and unloads at home'] = static function (): void {
    $start = Coordinate::object(1, 7, 2, ObjectType::PLANET);
    $target = Coordinate::object(1, 7, 3, ObjectType::ASTEROID_SECTOR, siteId: 19);
    $rows = \SPGame\Game\Services\FleetMissionBuilder::buildMining($start, $target);
    assertSame(['LOAD','MOVE_IN_SYSTEM','MINING','MOVE_IN_SYSTEM','UNLOAD','STAY'], array_column(array_column($rows, 'type'), 'name'));
    assertSame('mix', $rows[2]['params']['miningRequest']['mode']);
    assertSame(3600, $rows[2]['params']['miningRequest']['duration']);
    assertSame(true, $rows[4]['params']['unloadFuel']);
};
$tests['public mining stay pipeline keeps cargo instead of unloading into a fictional site warehouse'] = static function (): void {
    $rows = \SPGame\Game\Services\FleetMissionBuilder::buildMining(Coordinate::object(1, 7, 2, ObjectType::PLANET),
        Coordinate::object(1, 7, 6, ObjectType::GAS_GIANT, siteId: 24), ['finish' => 'stay', 'duration' => 7200]);
    assertSame(['LOAD','MOVE_IN_SYSTEM','MINING','STAY'], array_column(array_column($rows, 'type'), 'name'));
    assertSame('deuterium', $rows[2]['params']['miningRequest']['mode']);
    assertSame(7200, $rows[2]['params']['miningRequest']['duration']);
};
$tests['mining at its resource home has no fictional LOAD or zero length flight'] = static function (): void {
    $at = Coordinate::object(1, 7, 6, ObjectType::GAS_GIANT, siteId: 24);
    foreach (['return', 'stay'] as $finish) {
        $rows = \SPGame\Game\Services\FleetMissionBuilder::buildMining($at, $at, ['finish' => $finish]);
        assertSame(['MINING','STAY'], array_column(array_column($rows, 'type'), 'name'));
    }
};
$tests['mining builder rejects planets points and resource mismatches'] = static function (): void {
    $start = Coordinate::object(1, 7, 2, ObjectType::PLANET);
    foreach ([$start, Coordinate::point(1,7,1000,20)] as $target) assertRejects(static fn() => \SPGame\Game\Services\FleetMissionBuilder::buildMining($start, $target));
    assertRejects(static fn() => \SPGame\Game\Services\FleetMissionBuilder::buildMining($start,
        Coordinate::object(1,7,6,ObjectType::GAS_GIANT,siteId:24), ['mode' => 'metal']));
};
$tests['public mining rules contain only approved selectable settings'] = static function (): void {
    $rules = \SPGame\Game\Services\FleetMiningRuntimeService::rules();
    assertSame([3600,7200,14400,28800,43200,57600,115200], $rules['durations']);
    assertSame(['return','stay'], $rules['finish_options']);
    assertSame(false, isset($rules['ships']));
};
$tests['mining information uses gameplay yield and base limits'] = static function (): void {
    $page = (new \ReflectionClass(\SPGame\Game\Pages\InformationPage::class))->newInstanceWithoutConstructor();
    $effects = new \ReflectionMethod($page, 'levelEffects');
    assertSame(['miningYield','resourceBaseSlots'], array_column($effects->invoke($page, 136, []), 'key'));
    $value = new \ReflectionMethod($page, 'effectValue');
    foreach ([0,1,3,10] as $level) {
        assertNear($level * 10, $value->invoke($page, 'miningYield', 136, $level, []));
        assertSame(\SPGame\Game\Services\ResourceBaseService::controlLimit($level),
            $value->invoke($page, 'resourceBaseSlots', 136, $level, []));
    }
};

function resourceSurveyBattleFixture(): array
{
    $target = Coordinate::object(1,10,10,ObjectType::GAS_GIANT,siteId:5)->toArray();
    $params = ['target'=>$target,'survey'=>['token'=>str_repeat('a',32),'startedAt'=>1000.0,'endsAt'=>1600.0],
        'hold'=>['startedAt'=>1000.0,'lastAt'=>1100.0,'rate'=>.01]];
    return [['command_type'=>\SPGame\Game\Enums\FleetCommandType::RESEARCH->value,
        'execute_time'=>1600.0,'executed'=>0,'params'=>json_encode($params)],$target,$params];
}
$tests['resource battle admits a working survey at the exact gas or belt site'] = static function (): void {
    [$command,$target,$params] = resourceSurveyBattleFixture();
    foreach ([$target,Coordinate::object(1,10,3,ObjectType::ASTEROID_SECTOR,siteId:1)->toArray()] as $at) {
        $params['target']=$at; $params['hold']['rate']=0; $command['params']=json_encode($params);
        assertSame(true,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$at));
    }
};
$tests['resource battle does not turn pending completed or paused surveys into active work'] = static function (): void {
    [$command,$target,$params] = resourceSurveyBattleFixture();
    foreach ([['execute_time'=>0],['executed'=>1],['command_type'=>\SPGame\Game\Enums\FleetCommandType::OBSERVE->value]] as $patch) {
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle(array_replace($command,$patch),$target));
    }
    $params['pause']='battle'; $command['params']=json_encode($params);
    assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$target));
    assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle(null,$target));
};
$tests['resource survey battle admission never matches another sector planet or point'] = static function (): void {
    [$command,$target,$params] = resourceSurveyBattleFixture();
    assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,array_replace($target,['siteId'=>6])));
    foreach ([Coordinate::object(1,10,7,ObjectType::PLANET)->toArray(),Coordinate::point(1,10,1000,90)->toArray()] as $at) {
        $params['target']=$at; $command['params']=json_encode($params);
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$at));
    }
};
$tests['resource survey battle admission requires a complete server survey and hold'] = static function (): void {
    [$command,$target,$params] = resourceSurveyBattleFixture();
    foreach (['survey','hold'] as $key) {
        $copy=$params; unset($copy[$key]); $command['params']=json_encode($copy);
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$target));
    }
    foreach (['',str_repeat('z',32),[]] as $token) {
        $params['survey']['token']=$token; $command['params']=json_encode($params);
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$target));
    }
};
$tests['resource survey battle admission rejects inconsistent clocks and rates'] = static function (): void {
    [$command,$target,$params] = resourceSurveyBattleFixture();
    foreach ([['survey','endsAt',999],['hold','startedAt',1001],['hold','lastAt',999],
        ['hold','lastAt',1700],['hold','rate',-1],['hold','rate','bad']] as [$key,$field,$value]) {
        $copy=$params; $copy[$key][$field]=$value; $command['params']=json_encode($copy);
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$target));
    }
    $command['params']=json_encode($params);
    foreach ([INF,NAN,1700.0,1099.0] as $due) {
        $command['execute_time']=$due;
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::canInterruptForBattle($command,$target));
    }
};

$tests['interrupted survey battle return requires its server marker'] = static function (): void {
    $command = ['command_type'=>\SPGame\Game\Enums\FleetCommandType::OBSERVE->value,
        'execute_time'=>0.0,'executed'=>0,'params'=>json_encode(['pause'=>'battle','surveyBattleReturn'=>true])];
    assertSame(true,\SPGame\Game\Services\FleetSurveyService::isBattleReturn($command));
    foreach ([[],['pause'=>'battle'],['pause'=>'manual','surveyBattleReturn'=>true],
        ['pause'=>'battle','surveyBattleReturn'=>1],['pause'=>'battle','surveyBattleReturn'=>'true']] as $params) {
        $command['params']=json_encode($params);
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::isBattleReturn($command));
    }
};
$tests['interrupted survey battle return does not replay executed or active commands'] = static function (): void {
    $command = ['command_type'=>\SPGame\Game\Enums\FleetCommandType::OBSERVE->value,
        'execute_time'=>0.0,'executed'=>0,'params'=>json_encode(['pause'=>'battle','surveyBattleReturn'=>true])];
    foreach ([['executed'=>1],['execute_time'=>1000],['execute_time'=>-1],['execute_time'=>INF],
        ['execute_time'=>'bad'],['command_type'=>\SPGame\Game\Enums\FleetCommandType::RESEARCH->value]] as $patch) {
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::isBattleReturn(array_replace($command,$patch)));
    }
    assertSame(false,\SPGame\Game\Services\FleetSurveyService::isBattleReturn(null));
};
$tests['interrupted survey battle return never retains a working survey or hold'] = static function (): void {
    $command = ['command_type'=>\SPGame\Game\Enums\FleetCommandType::OBSERVE->value,'execute_time'=>0.0,'executed'=>0];
    foreach (['survey','hold'] as $key) {
        $command['params']=json_encode(['pause'=>'battle','surveyBattleReturn'=>true,$key=>[]]);
        assertSame(false,\SPGame\Game\Services\FleetSurveyService::isBattleReturn($command));
    }
};

function resourceReinforcementFixture(): array
{
    $at = Coordinate::object(1,10,3,ObjectType::ASTEROID_SECTOR,siteId:1)->toArray();
    return [['phase'=>'running','job'=>null,'combat'=>['rounds'=>[[],[]],'roundLimit'=>10],
        'mission'=>['attackerId'=>10,'defenderId'=>9,'resourceSite'=>['id'=>1,'ownerId'=>9,'coordinate'=>$at]]],$at];
}
$tests['resource reinforcements admit the same attacker at the exact site'] = static function (): void {
    [$state,$at] = resourceReinforcementFixture();
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
    $gas=Coordinate::object(1,10,10,ObjectType::GAS_GIANT,siteId:5)->toArray();
    $copy=$state;$copy['mission']['resourceSite']=['id'=>5,'ownerId'=>9,'coordinate'=>$gas];
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($copy,10,$gas));
    $state['job']=['round'=>3];
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
};
$tests['resource reinforcements do not make third players or defenders allies'] = static function (): void {
    [$state,$at] = resourceReinforcementFixture();
    foreach ([0,9,11] as $owner) assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,$owner,$at));
};
$tests['resource reinforcements never join another sector or a fleet target'] = static function (): void {
    [$state,$at] = resourceReinforcementFixture();
    foreach ([array_replace($at,['siteId'=>2]),Coordinate::point(1,10,1000,90)->toArray(),
        Coordinate::fleet(79,Coordinate::point(1,10,1000,90))->toArray(),array_replace($at,['angle'=>0])] as $target) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$target));
    }
    $state['mission']['resourceSite']['id']=2;
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
};
$tests['resource reinforcements reject finished failed and nonresource battles'] = static function (): void {
    [$state,$at] = resourceReinforcementFixture();
    foreach (['finished','settled','failed','applied'] as $phase) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource(array_replace($state,['phase'=>$phase]),10,$at));
    }
    unset($state['mission']['resourceSite']);
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
};
$tests['resource reinforcements wait for the next unsubmitted round'] = static function (): void {
    [$state,$at] = resourceReinforcementFixture();$state['combat']['rounds']=array_fill(0,9,[]);
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
    $state['job']=['round'=>10];
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
    $state['job']=null;$state['combat']['rounds'][]=[];
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResource($state,10,$at));
};

function resourceDefenseFixture(): array
{
    $at=Coordinate::object(1,10,3,ObjectType::ASTEROID_SECTOR,siteId:1)->toArray();
    $params=['target'=>$at,'hold'=>['siteId'=>1,'ownerId'=>9,'startedAt'=>1000,'until'=>4600,'rate'=>.01]];
    return [['command_type'=>\SPGame\Game\Enums\FleetCommandType::DEFEND->value,'execute_time'=>2000.0,'executed'=>0,
        'params'=>json_encode($params)],$at,$params];
}
$tests['resource defense never classifies ordinary planet defense as onboard supply'] = static function():void {
    [$command,$at,$p]=resourceDefenseFixture();
    assertSame(true,\SPGame\Game\Services\FleetResourceDefendService::isCommand($command));
    foreach ([Coordinate::object(1,10,7,ObjectType::PLANET)->toArray(),Coordinate::point(1,10,1000,90)->toArray()] as $target) {
        $p['target']=$target;$command['params']=json_encode($p);
        assertSame(false,\SPGame\Game\Services\FleetResourceDefendService::isCommand($command));
    }
};
$tests['resource defense battle admission requires exact site and saved host'] = static function():void {
    [$command,$at,$p]=resourceDefenseFixture();
    assertSame(true,\SPGame\Game\Services\FleetResourceDefendService::canInterrupt($command,$at,9));
    assertSame(false,\SPGame\Game\Services\FleetResourceDefendService::canInterrupt($command,$at,10));
    assertSame(false,\SPGame\Game\Services\FleetResourceDefendService::canInterrupt($command,array_replace($at,['siteId'=>2]),9));
    assertSame(false,\SPGame\Game\Services\FleetResourceDefendService::canInterrupt(null,$at,9));
};
$tests['resource defense battle admission rejects paused ended or corrupt holds'] = static function():void {
    [$command,$at,$p]=resourceDefenseFixture();
    foreach ([['executed'=>1],['execute_time'=>0],['execute_time'=>5000],['execute_time'=>INF]] as $patch) {
        assertSame(false,\SPGame\Game\Services\FleetResourceDefendService::canInterrupt(array_replace($command,$patch),$at,9));
    }
    foreach ([['pause'=>'battle'],['hold'=>['siteId'=>1,'ownerId'=>9,'startedAt'=>1000,'until'=>4600,'rate'=>-1]],['hold'=>[]]] as $patch) {
        $copy=$command;$copy['params']=json_encode(array_replace($p,$patch));
        assertSame(false,\SPGame\Game\Services\FleetResourceDefendService::canInterrupt($copy,$at,9));
    }
};
$tests['resource defense stops at return reserve or original deadline'] = static function():void {
    $clock=['time'=>1000.0,'reserve'=>100.0,'rate'=>.01];
    assertNear(1025,\SPGame\Game\Services\FleetResourceDefendService::holdEnd(4600,100.25,$clock));
    assertNear(1010,\SPGame\Game\Services\FleetResourceDefendService::holdEnd(1010,100.25,$clock));
    assertNear(1000,\SPGame\Game\Services\FleetResourceDefendService::holdEnd(4600,99,$clock));
    $clock['rate']=0;
    assertNear(4600,\SPGame\Game\Services\FleetResourceDefendService::holdEnd(4600,100.25,$clock));
};
$tests['resource defense rejects nonfinite or reversed fuel clocks'] = static function():void {
    $clock=['time'=>1000.0,'reserve'=>100.0,'rate'=>.01];
    foreach ([['rate'=>-1],['reserve'=>-1],['time'=>0],['time'=>INF]] as $patch) {
        assertRejects(static fn()=>\SPGame\Game\Services\FleetResourceDefendService::holdEnd(4600,100.25,array_replace($clock,$patch)));
    }
    assertRejects(static fn()=>\SPGame\Game\Services\FleetResourceDefendService::holdEnd(999,100,$clock));
};
$tests['resource defense fuel budget adds onboard work to both travel bounds'] = static function():void {
    $before=['fuel'=>['min'=>10.0,'max'=>20.0]];
    $after=\SPGame\Game\Services\FleetResourceDefendService::travelFuel($before,5/3600,3600);
    assertNear(15,$after['fuel']['min']);assertNear(25,$after['fuel']['max']);
    assertSame(true,$after['resourceDefense']);assertNear(5,$after['defendSupplyPerHour']);
    assertNear(10,$before['fuel']['min']);
};
$tests['resource defense fuel budget validates rate and approved duration'] = static function():void {
    $travel=['fuel'=>['min'=>10.0,'max'=>20.0]];
    foreach ([-1,INF,NAN] as $rate) assertRejects(static fn()=>\SPGame\Game\Services\FleetResourceDefendService::travelFuel($travel,$rate,3600));
    assertRejects(static fn()=>\SPGame\Game\Services\FleetResourceDefendService::travelFuel($travel,1,900));
};
$tests['resource defense selected duration changes the preview budget'] = static function():void {
    $travel=['fuel'=>['min'=>12.0,'max'=>12.0]];
    foreach ([3600=>16,7200=>20,115200=>140] as $duration=>$fuel) {
        $preview=\SPGame\Game\Services\FleetResourceDefendService::travelFuel($travel,4/3600,$duration);
        assertNear($fuel,$preview['fuel']['min']);assertNear($fuel,$preview['fuel']['max']);
    }
};
$tests['resource defense reinforcement admits a confirmed friend at the same site'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,11,9,$at,true));
    $gas=Coordinate::object(1,10,5,ObjectType::GAS_GIANT,siteId:23)->toArray();
    $state['mission']['resourceSite']=['id'=>23,'ownerId'=>9,'coordinate'=>$gas];
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,11,9,$gas,true));
};
$tests['resource defense reinforcement requires friendship and the original host'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    foreach ([[11,9,false],[10,9,true],[9,9,true],[0,9,true],[11,0,true],[11,12,true]] as [$guest,$host,$friend]) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,$guest,$host,$at,$friend));
    }
};
$tests['resource defense reinforcement rejects other sites and nonobject targets'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    foreach ([array_replace($at,['siteId'=>2]),array_replace($at,['angle'=>0]),
        Coordinate::point(1,10,1000,90)->toArray(),Coordinate::object(1,10,3,ObjectType::PLANET)->toArray()] as $target) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,11,9,$target,true));
    }
};
$tests['resource defense reinforcement respects the next unsubmitted round and phase'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    foreach (['finished','settled','failed','applied'] as $phase) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense(array_replace($state,['phase'=>$phase]),11,9,$at,true));
    }
    $state['combat']['rounds']=array_fill(0,9,[]);
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,11,9,$at,true));
    $state['job']=['round'=>10];
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,11,9,$at,true));
};
$tests['resource defense reinforcement never joins both battle sides'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    $state['mission']['participants']=[42=>['fleetId'=>42,'ownerId'=>11,'side'=>'attacker']];
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceDefense($state,11,9,$at,true));
};
$tests['own resource relocation admits the original owner at a canonical sector or giant'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$at));
    $gas=Coordinate::object(1,10,5,ObjectType::GAS_GIANT,siteId:23)->toArray();
    $state['mission']['resourceSite']=['id'=>23,'ownerId'=>9,'coordinate'=>$gas];
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$gas));
};
$tests['own resource relocation rejects other owners sites and noncanonical targets'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    foreach ([0,10,11] as $owner) assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,$owner,$at));
    foreach ([array_replace($at,['siteId'=>2]),array_replace($at,['angle'=>0]),Coordinate::point(1,10,1000,90)->toArray()] as $target) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$target));
    }
    $state['mission']['defenderId']=11;
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$at));
};
$tests['own resource relocation respects phase next unsubmitted round and opposing side'] = static function():void {
    [$state,$at]=resourceReinforcementFixture();
    foreach (['finished','settled','failed','applied'] as $phase) {
        assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation(array_replace($state,['phase'=>$phase]),9,$at));
    }
    $state['combat']['rounds']=array_fill(0,9,[]);
    assertSame(true,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$at));
    $state['job']=['round'=>10];
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$at));
    $state['job']=null; $state['mission']['participants']=[['ownerId'=>9,'side'=>'attacker']];
    assertSame(false,\SPGame\Game\Services\FleetSpaceBattleService::canReinforceResourceRelocation($state,9,$at));
};
$tests['frozen and pending friendly guards keep their capacity slots without duplicates'] = static function():void {
    [$state]=resourceReinforcementFixture();
    $p=['fleetId'=>89,'ownerId'=>11,'side'=>'defender','resourceDefenseCommandId'=>407];
    $state['mission']['participants']=[$p,$p,array_replace($p,['fleetId'=>93,'resourceDefenseCommandId'=>412]),
        array_replace($p,['fleetId'=>87,'ownerId'=>9]),array_replace($p,['fleetId'=>90,'side'=>'attacker']),
        array_replace($p,['fleetId'=>94,'resourceDefenseCommandId'=>null])];
    assertSame([89,93],\SPGame\Game\Services\FleetResourceDefendService::battleGuardIds($state,9));
};
$tests['friendly guard capacity never comes from another host or a nonresource battle'] = static function():void {
    [$state]=resourceReinforcementFixture();
    $state['mission']['participants']=[['fleetId'=>89,'ownerId'=>11,'side'=>'defender','resourceDefenseCommandId'=>407]];
    assertSame([],\SPGame\Game\Services\FleetResourceDefendService::battleGuardIds($state,12));
    unset($state['mission']['resourceSite']);
    assertSame([],\SPGame\Game\Services\FleetResourceDefendService::battleGuardIds($state,9));
};

$tests['player events include disconnected owners without duplicate online processing'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Services\EventLoop::class, 'playerAccountIds');
    assertSame([15,16], $method->invoke(null, [15=>['id'=>15]], [
        ['account_id'=>15,'ready'=>1], ['account_id'=>16,'ready'=>1], ['account_id'=>16,'ready'=>1],
    ]));
};
$tests['player events do not process unpublished or invalid account records'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Services\EventLoop::class, 'playerAccountIds');
    assertSame([15], $method->invoke(null, [], [
        ['account_id'=>15,'ready'=>1], ['account_id'=>16,'ready'=>0], ['account_id'=>0,'ready'=>1],
    ]));
};
$tests['offline accounts without pending commands do not enter the online state pass'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Services\EventLoop::class, 'playerAccountIds');
    assertSame([], $method->invoke(null, [], []));
    assertSame([15], $method->invoke(null, [15=>['id'=>15]], []));
};

$tests['restart queue preserves short and external payload identity and types'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Repositories\PlayerQueue::class, 'decodeRestartEvent');
    foreach ([['name'=>'Тест'], ['text'=>str_repeat('д', 4000),'number'=>1.0,'ids'=>[901=>37]]] as $data) {
        $row=['id'=>27,'account_id'=>16,'user_id'=>10,'planet_id'=>27,
            'action'=>'ActionFleetControl','priority'=>1,'added_at'=>1793100000.25,'data'=>$data,'ready'=>1,
            'payload_ttl'=>strlen(serialize($data))>512 ? 85.25 : null];
        assertSame($row, $method->invoke(null,json_encode($row,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),27));
    }
};
$tests['restart queue rejects unpublished rows mismatched identity and fields'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Repositories\PlayerQueue::class, 'decodeRestartEvent');
    $row=['id'=>27,'account_id'=>16,'user_id'=>10,'planet_id'=>27,
        'action'=>'ActionFleetControl','priority'=>1,'added_at'=>1793100000.25,'data'=>[],'ready'=>1,'payload_ttl'=>null];
    foreach ([['ready'=>0],['id'=>28],['account_id'=>0],['user_id'=>0],['planet_id'=>-1],
        ['priority'=>128],['data'=>['_payloadId'=>27]],['unexpected'=>true],['data'=>'not an array']] as $change) {
        assertRejects(fn()=>$method->invoke(null,json_encode(array_replace($row,$change),JSON_THROW_ON_ERROR),27),\UnexpectedValueException::class);
    }
};
$tests['restart queue keeps payload byte limit and does not revive expired buffers'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Repositories\PlayerQueue::class, 'decodeRestartEvent');
    $row=['id'=>27,'account_id'=>16,'user_id'=>10,'planet_id'=>27,
        'action'=>'ActionFleetControl','priority'=>1,'added_at'=>1793100000.25,
        'data'=>['text'=>str_repeat('x',1000)],'ready'=>1,'payload_ttl'=>30.0];
    foreach ([['payload_ttl'=>null],['payload_ttl'=>0],['payload_ttl'=>121],
        ['data'=>['text'=>str_repeat('x',16384)]],['data'=>[]]] as $change) {
        assertRejects(fn()=>$method->invoke(null,json_encode(array_replace($row,$change),JSON_THROW_ON_ERROR),27),\UnexpectedValueException::class);
    }
};
$tests['restart queue normalizes JSON column order before strict Table verification'] = static function (): void {
    $row=['id'=>27,'account_id'=>16,'user_id'=>10,'planet_id'=>27,'action'=>'ActionFleetRename',
        'priority'=>1,'added_at'=>1793100000.25,'data'=>['FleetId'=>120],'ready'=>1,'payload_ttl'=>null];
    $method = new \ReflectionMethod(\SPGame\Game\Repositories\PlayerQueue::class, 'decodeRestartEvent');
    assertSame($row,$method->invoke(null,json_encode(array_reverse($row,true),JSON_THROW_ON_ERROR),27));
};
$tests['restart queue accepts the exact external payload byte boundary'] = static function (): void {
    $method = new \ReflectionMethod(\SPGame\Game\Repositories\PlayerQueue::class, 'decodeRestartEvent');
    $data=['text'=>''];
    for($i=0;$i<5;++$i){$delta=16384-strlen(serialize($data));if($delta===0)break;$data['text']=str_repeat('x',strlen($data['text'])+$delta);}
    assertSame(16384,strlen(serialize($data)));
    $row=['id'=>27,'account_id'=>16,'user_id'=>10,'planet_id'=>27,'action'=>'ActionFleetControl',
        'priority'=>1,'added_at'=>1793100000.25,'data'=>$data,'ready'=>1,'payload_ttl'=>120.0];
    assertSame($row,$method->invoke(null,json_encode($row,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),27));
};

$passed = $failed = 0;
$tests['resource capture checks common defense before acquiring control'] = static function (): void {
    $start = Coordinate::object(1,7,2,ObjectType::PLANET);
    $target = Coordinate::object(1,7,6,ObjectType::GAS_GIANT,siteId:24);
    $rows = \SPGame\Game\Services\FleetMissionBuilder::buildCapture($start,$target);
    assertSame(['LOAD','MOVE_IN_SYSTEM','ATTACK','CAPTURE','STAY'],array_column(array_column($rows,'type'),'name'));
    assertSame(true,$rows[2]['params']['resourceCapture']);
    assertSame(true,$rows[3]['params']['combatCapture']);
    assertSame($target,$rows[2]['params']['target']);
};
$tests['capture from a resource base does not load from a fictional warehouse'] = static function (): void {
    $rows = \SPGame\Game\Services\FleetMissionBuilder::buildCapture(
        Coordinate::object(1,7,3,ObjectType::ASTEROID_SECTOR,siteId:19),
        Coordinate::object(1,7,6,ObjectType::GAS_GIANT,siteId:24));
    assertSame(['MOVE_IN_SYSTEM','ATTACK','CAPTURE','STAY'],array_column(array_column($rows,'type'),'name'));
};
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
foreach ($tests as $name => $test) {
    try {
        $test();
        ++$passed;
        echo "PASS {$name}\n";
    } catch (\Throwable $error) {
        ++$failed;
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}
restore_error_handler();
echo "RESULT core contracts: {$passed} passed / {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
