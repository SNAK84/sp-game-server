<?php

declare(strict_types=1);

/**
 * Изолированные проверки Этапа 3.
 *
 * CLI окружение проекта не содержит ext-swoole, поэтому здесь используется
 * минимальный in-memory Table double. Production repositories не меняются.
 */
namespace Swoole {
    if (!class_exists('Swoole\\Table')) {
        final class Table implements \IteratorAggregate
        {
            public const TYPE_INT = 1;
            public const TYPE_FLOAT = 2;
            public const TYPE_STRING = 3;

            private array $rows = [];

            public function __construct(int $size = 0) {}
            public function column(string $name, int $type, int $size = 0): void {}
            public function create(): bool { return true; }
            public function set(string $key, array $value): bool
            {
                $this->rows[$key] = $value;
                return true;
            }
            public function get(string $key): array|false { return $this->rows[$key] ?? false; }
            public function del(string $key): bool
            {
                unset($this->rows[$key]);
                return true;
            }
            public function incr(string $key, string $column, int $by): int|false
            {
                if (!isset($this->rows[$key])) return false;
                $this->rows[$key][$column] = (int)($this->rows[$key][$column] ?? 0) + $by;
                return $this->rows[$key][$column];
            }
            public function count(): int { return count($this->rows); }
            public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';

    use SPGame\Core\Message;
    use SPGame\Game\Enums\FleetCommandType;
    use SPGame\Game\Fleet\Fleet;
    use SPGame\Game\Fleet\FleetShip;
    use SPGame\Game\Fleet\FleetTarget;
    use SPGame\Game\Repositories\Config;
    use SPGame\Game\Repositories\FleetCommands;
    use SPGame\Game\Repositories\Galaxy;
    use SPGame\Game\Repositories\GalaxyOrbits;
    use SPGame\Game\Repositories\Planets;
    use SPGame\Game\Repositories\Vars;
    use SPGame\Game\Pages\FleetsPage;
    use SPGame\Game\Services\AccountData;
    use SPGame\Game\Services\Data;
    use SPGame\Game\Services\FleetCommandsService;
    use SPGame\Game\Services\FleetEventScheduler;
    use SPGame\Game\Services\FleetFlightService;
    use SPGame\Game\Services\FleetMissionBuilder;
    use SPGame\Game\Services\FleetMissionService;
    use SPGame\Game\Services\GalaxyGenerator;
    use SPGame\Game\Space\Coordinate;
    use SPGame\Game\Space\ObjectType;
    use Swoole\Table;

    function stage3Assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function installRepositoryTables(string $class, Table $table, ?Table $sync = null, array $indexes = []): void
    {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('table');
        $property->setAccessible(true);
        $property->setValue(null, $table);

        if ($sync !== null) {
            $syncProperty = $reflection->getProperty('syncTable');
            $syncProperty->setAccessible(true);
            $syncProperty->setValue(null, $sync);
        }

        if ($indexes !== []) {
            $indexProperty = $reflection->getProperty('indexTables');
            $indexProperty->setAccessible(true);
            $indexProperty->setValue(null, $indexes);
        }
    }

    function commandRow(
        int $id,
        int $fleetId,
        float $executeTime,
        int $executed = 0,
        FleetCommandType $type = FleetCommandType::MOVE_IN_SYSTEM
    ): array
    {
        $target = Coordinate::point(1, 1, 100.0, 0.0)->toArray();

        return [
            'id' => $id,
            'fleet_id' => $fleetId,
            'command_type' => $type->value,
            'execute_time' => $executeTime,
            'params' => json_encode(['target' => $target], JSON_THROW_ON_ERROR),
            'executed' => $executed,
            'create_time' => 1.0,
        ];
    }

    function installFleetCommands(array $rows): void
    {
        $table = new Table();
        foreach ($rows as $row) {
            $table->set((string)$row['id'], $row);
        }

        $sync = new Table();
        $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
        installRepositoryTables(FleetCommands::class, $table, $sync);
    }

    function testLocalAndGlobalQueueOrder(): void
    {
        installFleetCommands([
            commandRow(12, 1, 100.0),
            commandRow(10, 1, 0.0),
            commandRow(11, 1, 90.0),
            commandRow(20, 2, 90.0),
        ]);

        $local = FleetCommands::getFleetQueue(1);
        stage3Assert(
            array_column($local, 'id') === [10, 11, 12],
            'Локальная очередь Fleet должна быть отсортирована по id ASC.'
        );

        $global = FleetCommands::getPendingTimeCommands(110.0);
        stage3Assert(
            array_column($global, 'id') === [11, 20, 12],
            'Глобальная очередь должна быть отсортирована по execute_time ASC, id ASC.'
        );

        stage3Assert(
            !in_array(10, array_column($global, 'id'), true),
            'Pending execute_time=0 не должен попасть в timed events.'
        );
    }

    function testSingleActiveInvariant(): void
    {
        installFleetCommands([
            commandRow(1, 1, 90.0),
            commandRow(2, 1, 0.0),
        ]);

        $thrown = false;
        try {
            FleetCommandsService::activateCommand(2, 100.0);
        } catch (LogicException) {
            $thrown = true;
        }

        stage3Assert($thrown, 'FleetCommandsService должен запретить вторую ACTIVE команду.');

        FleetCommands::markExecuted(1);
        $activated = FleetCommandsService::activateCommand(2, 100.0);
        stage3Assert(($activated['execute_time'] ?? 0.0) === 100.0, 'Pending команда должна активироваться.');

        installFleetCommands([
            commandRow(5, 1, 90.0),
            commandRow(6, 1, 95.0),
            commandRow(7, 1, 0.0),
        ]);
        $thrown = false;
        try {
            FleetCommandsService::activateCommand(7, 120.0);
        } catch (LogicException) {
            $thrown = true;
        }
        stage3Assert($thrown, 'Сервис должен обнаруживать несколько ACTIVE команд, оставшихся в legacy state.');
    }

    function testDelayedSchedulerRequeriesGlobalQueue(): void
    {
        $events = [
            ['id' => 1, 'fleet_id' => 1, 'execute_time' => 100.0],
            ['id' => 2, 'fleet_id' => 2, 'execute_time' => 90.0],
            ['id' => 4, 'fleet_id' => 2, 'execute_time' => 120.0],
        ];
        $processed = [];
        $scheduler = new FleetEventScheduler();

        $scheduler->process(
            110.0,
            static function (float $now) use (&$events): ?array {
                $due = array_values(array_filter(
                    $events,
                    static fn(array $event): bool => $event['execute_time'] > 0 && $event['execute_time'] <= $now
                ));
                usort($due, static fn(array $a, array $b): int =>
                    ($a['execute_time'] <=> $b['execute_time']) ?: ($a['id'] <=> $b['id'])
                );
                return $due[0] ?? null;
            },
            static function (array $event) use (&$events, &$processed): bool {
                $processed[] = $event['id'];
                $events = array_values(array_filter(
                    $events,
                    static fn(array $candidate): bool => $candidate['id'] !== $event['id']
                ));

                if ($event['id'] === 2) {
                    $events[] = ['id' => 3, 'fleet_id' => 2, 'execute_time' => 95.0];
                }
                return true;
            }
        );

        stage3Assert($processed === [2, 3, 1], 'Scheduler должен выполнить B@90, B@95, A@100.');
        stage3Assert(
            count(array_filter($events, static fn(array $event): bool => $event['id'] === 4)) === 1,
            'B@120 не должен выполняться в тике now=110.'
        );
    }

    function makeStage3Fleet(Coordinate $coordinate, float $positionTime): Fleet
    {
        $fleet = (new ReflectionClass(Fleet::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(Fleet::class);

        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($fleet, 1);

        $rawDataProperty = $reflection->getProperty('rawData');
        $rawDataProperty->setAccessible(true);
        $rawDataProperty->setValue($fleet, [
            'id' => 1,
            'current_position_time' => $positionTime,
        ]);

        $coordinateProperty = $reflection->getProperty('currentCoordinate');
        $coordinateProperty->setAccessible(true);
        $coordinateProperty->setValue($fleet, $coordinate);

        return $fleet;
    }

    function activeMoveCommand(
        Coordinate $target,
        float $executeTime,
        FleetCommandType $type = FleetCommandType::MOVE_IN_SYSTEM,
        array $extraParams = []
    ): array {
        return [
            'id' => 100,
            'fleet_id' => 1,
            'command_type' => $type->value,
            'execute_time' => $executeTime,
            'params' => array_merge(
                ['target' => $target->toArray()],
                $extraParams
            ),
            'executed' => 0,
        ];
    }

    function installFlightConfig(): void
    {
        $configTable = new Table();
        $configIndex = new Table();
        foreach ([
            ['id' => 1, 'name' => 'FleetGameSpeed', 'value' => '10'],
            ['id' => 2, 'name' => 'FleetSpeedFactor', 'value' => '1'],
            ['id' => 3, 'name' => 'SpeedPlanets', 'value' => '24'],
        ] as $row) {
            $configTable->set((string)$row['id'], $row);
            $configIndex->set(md5($row['name']), ['id' => $row['id']]);
        }
        installRepositoryTables(Config::class, $configTable, null, ['name' => $configIndex]);
    }

    function installPlanetFixture(int $speed): void
    {
        $planetTable = new Table();
        $planetTable->set('1', [
            'id' => 1,
            'owner_id' => 0,
            'galaxy' => 1,
            'system' => 1,
            'planet' => 1,
            'planet_type' => ObjectType::PLANET->value,
            'update_time' => 0.0,
            // Намеренно устаревшее значение: физический radius обязан брать
            // authoritative distance=200 из GalaxyOrbits fixture ниже.
            'distance' => 999,
            'deg' => 0.0,
            'speed' => $speed,
            'rotation' => 1,
        ]);

        $planetIndex = new Table();
        $planetIndex->set(md5('1_1_1_1'), ['id' => 1]);
        installRepositoryTables(
            Planets::class,
            $planetTable,
            null,
            ['galaxy_system_planet_type' => $planetIndex]
        );
        installGalaxyOrbitsFixture([[
            'id' => 1,
            'galaxy' => 1,
            'system' => 1,
            'orbit' => 1,
            'distance' => 200,
            'type' => GalaxyOrbits::PLANET,
        ]]);
    }

    function installGalaxyOrbitsFixture(array $rows): void
    {
        $table = new Table();
        $sync = new Table();
        $byCoordinate = new Table();
        $bySystem = new Table();
        $systemIds = [];
        $lastId = 0;

        foreach ($rows as $row) {
            $lastId = max($lastId, (int)$row['id']);
            $table->set((string)$row['id'], $row);
            $byCoordinate->set(
                md5($row['galaxy'] . '_' . $row['system'] . '_' . $row['orbit']),
                ['id' => $row['id']]
            );
            $key = $row['galaxy'] . '_' . $row['system'];
            $systemIds[$key][] = $row['id'];
        }

        foreach ($systemIds as $key => $ids) {
            $bySystem->set(md5($key), [
                'id' => $ids[0],
                'ids' => serialize($ids),
            ]);
        }
        $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => $lastId]);

        installRepositoryTables(
            GalaxyOrbits::class,
            $table,
            $sync,
            [
                'galaxy_system_orbit' => $byCoordinate,
                'galaxy_system' => $bySystem,
            ]
        );
    }

    function testCurrentCoordinateBeforeStartAndAtStart(): void
    {
        $flight = new FleetFlightService();
        $fleet = makeStage3Fleet(Coordinate::point(1, 1, 100.0, 0.0), 100.0);
        $command = activeMoveCommand(Coordinate::point(1, 1, 200.0, 0.0), 200.0);

        foreach ([50.0, 100.0] as $atTime) {
            $position = $flight->calculateCurrentCoordinate($fleet, $command, $atTime);
            stage3Assert($position->getType() === \SPGame\Game\Space\CoordinateType::POINT, 'Anchor position должна быть POINT.');
            stage3Assert($position->getDistance() === 100.0, 'До старта должен возвращаться anchor.');
        }
    }

    function testCurrentCoordinateDuringAndAfterMove(): void
    {
        $flight = new FleetFlightService();
        $fleet = makeStage3Fleet(Coordinate::point(1, 1, 100.0, 0.0), 100.0);
        $command = activeMoveCommand(Coordinate::point(1, 1, 200.0, 0.0), 200.0);
        $fleetBefore = $fleet->getCurrentCoordinate()->toArray();
        $commandBefore = $command;

        $middle = $flight->calculateCurrentCoordinate($fleet, $command, 150.0);
        stage3Assert($middle->getType() === \SPGame\Game\Space\CoordinateType::POINT, 'Промежуточная позиция должна быть POINT.');
        stage3Assert(abs($middle->getDistance() - 150.0) < 0.000001, 'Промежуточная позиция должна интерполироваться по геометрии.');

        foreach ([200.0, 250.0] as $atTime) {
            $position = $flight->calculateCurrentCoordinate($fleet, $command, $atTime);
            stage3Assert($position->getType() === \SPGame\Game\Space\CoordinateType::POINT, 'Конечная физическая позиция должна быть POINT.');
            stage3Assert($position->getDistance() === 200.0, 'После завершения должен возвращаться физический endpoint.');
        }

        stage3Assert($fleet->getCurrentCoordinate()->toArray() === $fleetBefore, 'Расчёт позиции не должен изменять Fleet.');
        stage3Assert($command === $commandBefore, 'Расчёт позиции не должен изменять FleetCommand.');
    }

    function testTransitionCurrentCoordinateUsesPersistedPoint(): void
    {
        $flight = new FleetFlightService();
        $fleet = makeStage3Fleet(Coordinate::point(1, 10, 2500.0, 15.0), 100.0);
        $transitionPoint = Coordinate::point(1, 11, 3500.0, 217.0);
        $command = activeMoveCommand(
            Coordinate::point(1, 11, 100.0, 0.0),
            200.0,
            FleetCommandType::MOVE_BETWEEN_SYSTEMS,
            ['destination' => ['galaxy' => 1, 'system' => 11], 'transitionPoint' => $transitionPoint->toArray()]
        );

        $position = $flight->calculateCurrentCoordinate($fleet, $command, 200.0);
        stage3Assert($position->toArray() === $transitionPoint->toArray(), 'Transition должен использовать сохранённый transitionPoint.');
    }

    function testDynamicObjectConvergentAndDeterministic(): void
    {
        installFlightConfig();
        installPlanetFixture(1);
        $flight = new FleetFlightService();
        $start = Coordinate::point(1, 1, 100.0, 0.0);
        $target = Coordinate::object(1, 1, 1, ObjectType::PLANET);

        $first = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            0.0,
            1000.0
        );
        $second = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            0.0,
            1000.0
        );

        stage3Assert($first > 0.0 && is_finite($first), 'Convergent dynamic OBJECT должен дать конечную duration.');
        stage3Assert($first === $second, 'Одинаковый dynamic OBJECT расчёт должен быть deterministic.');

        $startPoint = $flight->resolvePhysicalPoint($start, 0.0);
        $arrivalPoint = $flight->resolvePhysicalPoint($target, $first);
        $nextDuration = $flight->calculateGameDuration(
            $flight->calculateInSystemGameDistance(
                $flight->calculateDistance($startPoint, $arrivalPoint)
            ),
            1000.0
        );
        stage3Assert($nextDuration === $first, 'Convergent dynamic OBJECT должен удовлетворять fixed-point расчёту.');
    }

    function testDynamicObjectOscillationUsesBoundedFallback(): void
    {
        installFlightConfig();
        installPlanetFixture(360);
        $flight = new FleetFlightService();
        $start = Coordinate::point(1, 1, 100.0, 0.0);
        $target = Coordinate::object(1, 1, 1, ObjectType::PLANET);

        $first = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            0.0,
            1000.0
        );
        $second = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            0.0,
            1000.0
        );

        stage3Assert($first > 0.0 && is_finite($first), 'Oscillating dynamic OBJECT должен получить конечный fallback.');
        stage3Assert($first === $second, 'Fallback oscillation должен быть deterministic.');

        $startPoint = $flight->resolvePhysicalPoint($start, 0.0);
        $arrivalPoint = $flight->resolvePhysicalPoint($target, $first);
        $nextDuration = $flight->calculateGameDuration(
            $flight->calculateInSystemGameDistance(
                $flight->calculateDistance($startPoint, $arrivalPoint)
            ),
            1000.0
        );
        stage3Assert(abs($nextDuration - $first) <= 1.0, 'Oscillation fallback должен хранить лучший найденный residual.');
    }

    function testUnsupportedDueEventIsNotSilentlyCompleted(): void
    {
        $scheduler = new FleetEventScheduler();
        $thrown = false;

        try {
            $scheduler->process(
                100.0,
                static fn(float $now): ?array => [
                    'id' => 1,
                    'command_type' => FleetCommandType::ATTACK->value,
                    'execute_time' => 90.0,
                ],
                static fn(array $event): bool => false
            );
        } catch (LogicException $exception) {
            $thrown = str_contains($exception->getMessage(), 'due-команду #1');
        }

        stage3Assert($thrown, 'Unsupported due event должен завершать scheduler диагностируемой ошибкой.');
    }

    function testUnsupportedFutureEventDoesNotBlockScheduler(): void
    {
        $scheduler = new FleetEventScheduler();
        $processed = $scheduler->process(
            100.0,
            static fn(float $now): ?array => null,
            static function (array $event): bool {
                throw new RuntimeException('Future event не должен быть передан handler.');
            }
        );

        stage3Assert($processed === [], 'Future unsupported command не должна попадать в due scheduler.');
    }

    function testLogicalCurrentPositionTimeAndTransitionPersistence(): void
    {
        $fleet = (new ReflectionClass(Fleet::class))->newInstanceWithoutConstructor();
        $fleetReflection = new ReflectionClass(Fleet::class);
        $idProperty = $fleetReflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($fleet, 1);
        $rawDataProperty = $fleetReflection->getProperty('rawData');
        $rawDataProperty->setAccessible(true);
        $rawDataProperty->setValue($fleet, ['current_position_time' => 90.0]);

        $point = Coordinate::point(1, 1, 200.0, 45.0);
        $fleet->setCurrentCoordinate($point, 100.0);
        stage3Assert($fleet->getCurrentCoordinate()->toArray() === $point->toArray(), 'MOVE должен обновить currentCoordinate.');
        stage3Assert($fleet->getCurrentPositionTime() === 100.0, 'current_position_time должен быть равен event time.');

        $galaxyTable = new Table();
        $galaxyTable->set('1', [
            'id' => 1,
            'galaxy' => 1,
            'system' => 11,
            'max_distance' => 2000,
            'max_orbits' => 7,
        ]);
        $galaxyTable->set('2', [
            'id' => 2,
            'galaxy' => 1,
            'system' => 10,
            'max_distance' => 1800,
            'max_orbits' => 7,
        ]);
        $galaxyIndex = new Table();
        $galaxyIndex->set(md5('1_11'), ['id' => 1, 'ids' => serialize([1])]);
        $galaxyIndex->set(md5('1_10'), ['id' => 2, 'ids' => serialize([2])]);
        installRepositoryTables(
            Galaxy::class,
            $galaxyTable,
            null,
            ['galaxy_system' => $galaxyIndex]
        );
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 1,
                'system' => 11,
                'orbit' => 8,
                'distance' => 2500,
                'type' => GalaxyOrbits::SYSTEM_EDGE,
            ],
            [
                'id' => 2,
                'galaxy' => 1,
                'system' => 11,
                'orbit' => 9,
                'distance' => 3000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
            [
                'id' => 3,
                'galaxy' => 1,
                'system' => 10,
                'orbit' => 8,
                'distance' => 2300,
                'type' => GalaxyOrbits::SYSTEM_EDGE,
            ],
        ]);

        $flight = new FleetFlightService();
        $start = Coordinate::point(1, 10, 1800.0, 45.0);
        $target = Coordinate::point(1, 11, 100.0, 0.0);
        $params = ['target' => $target->toArray(), 'destination' => ['galaxy' => 1, 'system' => 11]];
        $materialized = $flight->materializeCommandCoordinates(
            FleetCommandType::MOVE_BETWEEN_SYSTEMS,
            $start,
            $target,
            $params,
            100.0
        );
        $again = $flight->materializeCommandCoordinates(
            FleetCommandType::MOVE_BETWEEN_SYSTEMS,
            $start,
            $target,
            $materialized,
            100.0
        );

        stage3Assert(
            $materialized['transitionObject'] === $again['transitionObject'],
            'Materialized transition OBJECT не должен генерироваться повторно.'
        );
        stage3Assert(
            $materialized['transitionObject'] === Coordinate::object(
                1,
                11,
                8,
                null,
                45.0
            )->toArray(),
            'Transition OBJECT должен сохранять орбиту границы и angle Fleet.'
        );

        $exitParams = $flight->materializeCommandCoordinates(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            ['target' => $target->toArray()],
            100.0
        );
        stage3Assert(
            $exitParams['resolvedTarget'] === Coordinate::object(1, 10, 8, null, 45.0)->toArray(),
            'Граница исходной системы должна назначаться только внутри MOVE-команды.'
        );
    }

    function testStationaryOrbitKeepsFleetAngle(): void
    {
        installFlightConfig();
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 1,
                'system' => 1,
                'orbit' => 9,
                'distance' => 3000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
        ]);

        $flight = new FleetFlightService();
        $start = Coordinate::point(1, 1, 1000.0, 73.5);
        $logicalTarget = Coordinate::object(1, 1, 9);
        $params = $flight->materializeCommandCoordinates(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $logicalTarget,
            ['target' => $logicalTarget->toArray()],
            100.0
        );
        $resolved = Coordinate::fromArray($params['resolvedTarget']);
        $pointAtStart = $flight->resolvePhysicalPoint($resolved, 100.0);
        $pointLater = $flight->resolvePhysicalPoint($resolved, 10000.0);

        stage3Assert($resolved->getType() === \SPGame\Game\Space\CoordinateType::OBJECT, 'Экспедиция должна оставаться OBJECT.');
        stage3Assert($resolved->getAngle() === 73.5, 'Угол экспедиционного OBJECT должен равняться angle Fleet.');
        stage3Assert($pointAtStart->radius === 3000.0 && $pointAtStart->angle === 73.5, 'Неверная физическая точка экспедиции.');
        stage3Assert($pointLater->angle === $pointAtStart->angle, 'Служебный OBJECT не должен вращаться.');

        $fleet = makeStage3Fleet($start, 100.0);
        $fleet->setCurrentCoordinate($resolved, 200.0);
        $coordinateProperty = (new ReflectionClass(Fleet::class))->getProperty('currentCoordinate');
        $coordinateProperty->setAccessible(true);
        $coordinateProperty->setValue($fleet, null);
        stage3Assert(
            $fleet->getCurrentCoordinate()->toArray() === $resolved->toArray(),
            'Угол неподвижного OBJECT должен переживать Fleet persistence round-trip.'
        );
    }

    function testSpecialOrbitsAreAddedIdempotently(): void
    {
        installGalaxyOrbitsFixture([]);
        $system = [
            'galaxy' => 2,
            'system' => 7,
            'star_type' => 'G',
            'star_size' => 16,
            'max_orbits' => 11,
            'max_distance' => 9000,
        ];

        GalaxyGenerator::ensureSpecialOrbits($system);
        GalaxyGenerator::ensureSpecialOrbits($system);

        $edge = GalaxyOrbits::findByIndex('galaxy_system_orbit', [2, 7, 12]);
        $expedition = GalaxyOrbits::findByIndex('galaxy_system_orbit', [2, 7, 13]);
        $all = GalaxyOrbits::findByIndex('galaxy_system', [2, 7]);

        stage3Assert((int)$edge['type'] === GalaxyOrbits::SYSTEM_EDGE, 'Первая служебная орбита должна быть границей системы.');
        stage3Assert((int)$edge['distance'] === 9500, 'Граница G-системы должна сохранить шаг 500.');
        stage3Assert((int)$expedition['type'] === GalaxyOrbits::EXPEDITION, 'Вторая служебная орбита должна быть экспедиционной.');
        stage3Assert((int)$expedition['distance'] === 10000, 'Экспедиция G-системы должна сохранить смещение 1000.');
        stage3Assert(count($all) === 2, 'Повторный ensure не должен дублировать служебные орбиты.');
    }

    function testSpecialOrbitDistancesScaleWithStarAndReconcileExistingRows(): void
    {
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 2,
                'system' => 8,
                'orbit' => 16,
                'distance' => 20500,
                'type' => GalaxyOrbits::SYSTEM_EDGE,
            ],
            [
                'id' => 2,
                'galaxy' => 2,
                'system' => 8,
                'orbit' => 17,
                'distance' => 21000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
            [
                'id' => 3,
                'galaxy' => 2,
                'system' => 9,
                'orbit' => 6,
                'distance' => 4000,
                'type' => GalaxyOrbits::SYSTEM_EDGE,
            ],
            [
                'id' => 4,
                'galaxy' => 2,
                'system' => 9,
                'orbit' => 7,
                'distance' => 4500,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
        ]);

        GalaxyGenerator::ensureSpecialOrbits([
            'galaxy' => 2,
            'system' => 8,
            'star_type' => 'O',
            'star_size' => 27,
            'max_orbits' => 15,
            'max_distance' => 20000,
        ]);
        GalaxyGenerator::ensureSpecialOrbits([
            'galaxy' => 2,
            'system' => 9,
            'star_type' => 'M',
            'star_size' => 12,
            'max_orbits' => 5,
            'max_distance' => 3500,
        ]);

        $largeEdge = GalaxyOrbits::findByIndex('galaxy_system_orbit', [2, 8, 16]);
        $largeExpedition = GalaxyOrbits::findByIndex('galaxy_system_orbit', [2, 8, 17]);
        $smallEdge = GalaxyOrbits::findByIndex('galaxy_system_orbit', [2, 9, 6]);
        $smallExpedition = GalaxyOrbits::findByIndex('galaxy_system_orbit', [2, 9, 7]);

        stage3Assert((int)$largeEdge['id'] === 1, 'Коррекция границы не должна заменять существующую строку.');
        stage3Assert((int)$largeEdge['distance'] === 22150, 'Граница O-системы должна использовать звёздный шаг 2150.');
        stage3Assert((int)$largeExpedition['distance'] === 24300, 'Экспедиция O-системы должна находиться через два звёздных шага.');
        stage3Assert((int)$smallEdge['id'] === 3, 'Коррекция малой границы не должна заменять существующую строку.');
        stage3Assert((int)$smallEdge['distance'] === 3800, 'Граница M-системы должна использовать звёздный шаг 300.');
        stage3Assert((int)$smallExpedition['distance'] === 4100, 'Экспедиция M-системы должна находиться через два звёздных шага.');
        stage3Assert(
            ((int)$largeEdge['distance'] - 20000) > ((int)$smallEdge['distance'] - 3500),
            'Внешний шаг большой звезды должен быть больше шага малой звезды.'
        );
        stage3Assert(
            GalaxyOrbits::getPendingSync()['update'] === [1, 2, 3, 4],
            'Скорректированные существующие орбиты должны быть помечены для persistence.'
        );
    }

    function testExpeditionTargetIsResolvedAsObject(): void
    {
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 3,
                'system' => 4,
                'orbit' => 9,
                'distance' => 8000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
        ]);
        $target = FleetTarget::fromCoordinates([
            'type' => \SPGame\Game\Space\CoordinateType::OBJECT->value,
            'galaxy' => 3,
            'system' => 4,
            'orbit' => 9,
        ]);
        $method = new ReflectionMethod(FleetMissionService::class, 'resolveTarget');
        $method->setAccessible(true);
        $resolved = $method->invoke(new FleetMissionService(), $target);

        stage3Assert($target->getCoordinateType() === \SPGame\Game\Space\CoordinateType::OBJECT, 'Экспедиция не должна превращаться в POINT в FleetTarget.');
        stage3Assert($target->getObjectType() === null, 'Экспедиционная орбита не должна иметь ObjectType.');
        stage3Assert($resolved['valid'] === true && $resolved['type'] === 'object', 'Экспедиционная орбита должна разрешаться как OBJECT.');
        stage3Assert($resolved['distance'] === 8000.0, 'Радиус экспедиции должен браться из GalaxyOrbits.');

        Vars::$attributes[999] = ['missions' => [\SPGame\Game\Enums\FleetMissionType::EXPEDITION->value]];
        $fleet = makeStage3Fleet(Coordinate::point(3, 4, 1000.0, 10.0), 1.0);
        $shipsProperty = (new ReflectionClass(Fleet::class))->getProperty('ships');
        $shipsProperty->setAccessible(true);
        $shipsProperty->setValue($fleet, [999 => new FleetShip(999, 'expedition_test_ship', 1)]);
        $missionsMethod = new ReflectionMethod(FleetMissionService::class, 'determineMissions');
        $missionsMethod->setAccessible(true);
        $missions = $missionsMethod->invoke(new FleetMissionService(), [
            'owner_id' => 1,
            'fleet' => $fleet,
            'coordinates' => $target->toCoordinates(),
            'target' => $resolved,
        ]);
        stage3Assert(
            $missions === [\SPGame\Game\Enums\FleetMissionType::EXPEDITION->value],
            'Миссия EXPEDITION должна быть доступна только для экспедиционной орбиты.'
        );

        $boundaryTarget = FleetTarget::fromCoordinates([
            'type' => \SPGame\Game\Space\CoordinateType::OBJECT->value,
            'galaxy' => 3,
            'system' => 4,
            'orbit' => 8,
        ]);
        installGalaxyOrbitsFixture([
            [
                'id' => 2,
                'galaxy' => 3,
                'system' => 4,
                'orbit' => 8,
                'distance' => 7500,
                'type' => GalaxyOrbits::SYSTEM_EDGE,
            ],
        ]);
        $boundary = $method->invoke(new FleetMissionService(), $boundaryTarget);
        stage3Assert($boundary['valid'] === false, 'Граница системы не должна приниматься как пользовательская цель.');

        $differentSystem = Coordinate::object(3, 5, 9);
        $rejected = false;
        try {
            FleetMissionBuilder::buildExpedition(
                Coordinate::point(3, 4, 1000.0, 10.0),
                $differentSystem
            );
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        stage3Assert($rejected, 'Экспедиция в другой системе должна отклоняться при построении команд.');
    }

    function testExpeditionIsAvailableOnlyInFleetSystem(): void
    {
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 3,
                'system' => 4,
                'orbit' => 9,
                'distance' => 8000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
            [
                'id' => 2,
                'galaxy' => 3,
                'system' => 5,
                'orbit' => 9,
                'distance' => 8000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
        ]);

        $fleet = makeStage3Fleet(Coordinate::point(3, 4, 1000.0, 10.0), 1.0);
        $accountData = (new ReflectionClass(AccountData::class))->newInstanceWithoutConstructor();
        $accountReflection = new ReflectionClass(AccountData::class);
        $userProperty = $accountReflection->getProperty('User');
        $userProperty->setAccessible(true);
        $dirty = false;
        $userProperty->setValue($accountData, new Data(['id' => 1], $dirty));

        $buildContext = new ReflectionMethod(FleetMissionService::class, 'buildContext');
        $buildContext->setAccessible(true);
        $sameSystem = $buildContext->invoke(
            new FleetMissionService(),
            $accountData,
            $fleet,
            FleetTarget::fromCoordinates([
                'type' => \SPGame\Game\Space\CoordinateType::OBJECT->value,
                'galaxy' => 3,
                'system' => 4,
                'orbit' => 9,
            ])
        );
        $otherSystem = $buildContext->invoke(
            new FleetMissionService(),
            $accountData,
            $fleet,
            FleetTarget::fromCoordinates([
                'type' => \SPGame\Game\Space\CoordinateType::OBJECT->value,
                'galaxy' => 3,
                'system' => 5,
                'orbit' => 9,
            ])
        );

        stage3Assert($sameSystem['valid'] === true, 'Экспедиция в текущей системе Fleet должна быть доступной целью.');
        stage3Assert($otherSystem['valid'] === false, 'Экспедиция в другой системе Fleet должна быть отклонена.');
    }

    function testOrdinaryTargetCannotExceedSystemOrbitLimit(): void
    {
        $galaxyTable = new Table();
        $galaxyTable->set('1', [
            'id' => 1,
            'galaxy' => 4,
            'system' => 2,
            'max_distance' => 5000,
            'max_orbits' => 5,
        ]);
        $galaxyIndex = new Table();
        $galaxyIndex->set(md5('4_2'), ['id' => 1, 'ids' => serialize([1])]);
        installRepositoryTables(
            Galaxy::class,
            $galaxyTable,
            null,
            ['galaxy_system' => $galaxyIndex]
        );
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 4,
                'system' => 2,
                'orbit' => 6,
                'distance' => 5500,
                'type' => GalaxyOrbits::EMPTY,
            ],
        ]);

        $target = FleetTarget::fromCoordinates([
            'type' => \SPGame\Game\Space\CoordinateType::OBJECT->value,
            'galaxy' => 4,
            'system' => 2,
            'orbit' => 6,
            'objectType' => ObjectType::PLANET->value,
        ]);
        $resolveTarget = new ReflectionMethod(FleetMissionService::class, 'resolveTarget');
        $resolveTarget->setAccessible(true);
        $resolved = $resolveTarget->invoke(new FleetMissionService(), $target);

        stage3Assert($resolved['valid'] === false, 'Обычная цель выше max_orbits должна отклоняться сервером.');
    }

    function testNavigationInfoExposesOnlyOrdinaryAndExpeditionOrbits(): void
    {
        $configTable = new Table();
        $configIndex = new Table();
        foreach ([
            ['id' => 1, 'name' => 'MaxGalaxy', 'value' => '9'],
            ['id' => 2, 'name' => 'MaxSystem', 'value' => '128'],
        ] as $row) {
            $configTable->set((string)$row['id'], $row);
            $configIndex->set(md5($row['name']), ['id' => $row['id']]);
        }
        installRepositoryTables(Config::class, $configTable, null, ['name' => $configIndex]);

        $galaxyTable = new Table();
        $galaxyTable->set('1', [
            'id' => 1,
            'galaxy' => 4,
            'system' => 2,
            'max_distance' => 5000,
            'max_orbits' => 5,
        ]);
        $galaxyIndex = new Table();
        $galaxyIndex->set(md5('4_2'), ['id' => 1, 'ids' => serialize([1])]);
        installRepositoryTables(Galaxy::class, $galaxyTable, null, ['galaxy_system' => $galaxyIndex]);
        installGalaxyOrbitsFixture([
            [
                'id' => 1,
                'galaxy' => 4,
                'system' => 2,
                'orbit' => 6,
                'distance' => 5500,
                'type' => GalaxyOrbits::SYSTEM_EDGE,
            ],
            [
                'id' => 2,
                'galaxy' => 4,
                'system' => 2,
                'orbit' => 7,
                'distance' => 6000,
                'type' => GalaxyOrbits::EXPEDITION,
            ],
        ]);

        $accountData = (new ReflectionClass(AccountData::class))->newInstanceWithoutConstructor();
        $accountReflection = new ReflectionClass(AccountData::class);
        $userProperty = $accountReflection->getProperty('User');
        $userProperty->setAccessible(true);
        $dirty = false;
        $userProperty->setValue($accountData, new Data(['id' => 1], $dirty));

        $page = new FleetsPage(new Message([
            'mode' => 'fleets',
            'action' => 'GetSystemNavigationInfo',
            'data' => [
                'galaxy' => 4,
                'system' => 2,
                'fleetId' => 0,
            ],
        ]));
        $method = new ReflectionMethod(FleetsPage::class, 'buildNavigationSystemInfo');
        $method->setAccessible(true);
        $info = $method->invoke($page, $accountData);

        stage3Assert($info['valid'] === true, 'Навигационные данные существующей системы должны быть доступны.');
        stage3Assert($info['maxOrbit'] === 5, 'Клиент должен получить max_orbits обычных орбит.');
        stage3Assert($info['expeditionOrbit'] === null, 'Без выбранного Fleet экспедиционная орбита не должна выдаваться как цель.');
        stage3Assert(!array_key_exists('systemEdgeOrbit', $info), 'Граница системы не должна передаваться клиенту.');
    }

    function testMoveDurationUsesExistingFormulaFromLogicalStart(): void
    {
        $configTable = new Table();
        $configIndex = new Table();
        foreach ([
            ['id' => 1, 'name' => 'FleetGameSpeed', 'value' => '10'],
            ['id' => 2, 'name' => 'FleetSpeedFactor', 'value' => '1'],
            ['id' => 3, 'name' => 'SpeedPlanets', 'value' => '24'],
        ] as $row) {
            $configTable->set((string)$row['id'], $row);
            $configIndex->set(md5($row['name']), ['id' => $row['id']]);
        }
        installRepositoryTables(Config::class, $configTable, null, ['name' => $configIndex]);

        $flight = new FleetFlightService();
        $start = Coordinate::point(1, 1, 100.0, 0.0);
        $target = Coordinate::point(1, 1, 200.0, 0.0);
        $duration = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            90.0,
            100.0
        );

        stage3Assert($duration > 0.0, 'MOVE_IN_SYSTEM не должен получать fake zero/+1 duration.');
        stage3Assert(90.0 + $duration > 90.0, 'execute_time должен рассчитываться от logical startTime.');
    }

    function testMissionPreviewUsesRadialSourceExitDistance(): void
    {
        $service = new FleetMissionService();
        $method = new ReflectionMethod($service, 'calculateInterSystemDistanceRange');
        $method->setAccessible(true);

        $range = $method->invoke(
            $service,
            1000.0,
            2500.0,
            500.0,
            3000.0,
            100000.0
        );

        stage3Assert($range['min'] === 104000.0, 'Минимум должен учитывать точный радиальный выход.');
        stage3Assert($range['max'] === 105000.0, 'Максимум не должен прокладывать выход через противоположную сторону системы.');
    }

    function testNovaTransitionDistanceAndDuration(): void
    {
        installFlightConfig();
        $flight = new FleetFlightService();

        stage3Assert(
            $flight->calculateTransitionDistance(1, 1, 1, 2) === 2795.0,
            'Соседняя система должна считаться по Nova: 1 * 95 + 2700.'
        );
        stage3Assert(
            $flight->calculateTransitionDistance(1, 1, 2, 1) === 2795.0,
            'Соседняя галактика должна считаться по Nova: 1 * 95 + 2700.'
        );
        stage3Assert(
            abs($flight->calculateTransitionDistance(1, 1, 2, 2) - hypot(2795.0, 2795.0)) < 0.000001,
            'Одновременная смена galaxy/system должна быть диагональным переходом Nova.'
        );

        $distance = 1000.0;
        $speed = 100.0;
        $expected = (float)round(max(
            5.0,
            ((3500.0 / (1.0 * 0.1)) * sqrt($distance * 10.0 / $speed) + 10.0) / 10.0
        ));
        stage3Assert(
            abs($flight->calculateGameDuration($distance, $speed) - $expected) < 0.000001,
            'Длительность MOVE должна соответствовать формуле Nova.'
        );

        $expectedAtFullSpeed = (float)round(max(
            5.0,
            ((3500.0 / (10.0 * 0.1)) * sqrt($distance * 10.0 / $speed) + 10.0) / 10.0
        ));
        stage3Assert(
            abs($flight->calculateGameDuration($distance, $speed, 10.0) - $expectedAtFullSpeed) < 0.000001,
            'Явно выбранные 100% скорости должны участвовать в серверном preview.'
        );
    }

    function testInSystemPhysicalDistanceUsesNovaScale(): void
    {
        installFlightConfig();
        $flight = new FleetFlightService();

        stage3Assert(
            $flight->calculateInSystemGameDistance(1500.0) === 1005.0,
            'Одна условная AU должна соответствовать внутрисистемной дистанции Nova 1005.'
        );
        stage3Assert(
            abs($flight->calculateInSystemGameDistance(6377.0) - 1021.2566666666667) < 0.000001,
            'Средняя физическая дистанция должна попадать в баланс внутрисистемных расстояний Nova.'
        );

        $start = Coordinate::point(1, 1, 1000.0, 0.0);
        $target = Coordinate::point(1, 1, 2500.0, 0.0);
        $duration = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            100.0,
            5000.0
        );
        stage3Assert(
            $duration === $flight->calculateGameDuration(1005.0, 5000.0),
            'Время внутрисистемной команды должно использовать преобразованную дистанцию 1005.'
        );

        $gameDistance = $flight->calculateCommandGameDistance(
            FleetCommandType::MOVE_IN_SYSTEM,
            $start,
            $target,
            ['target' => $target->toArray()],
            100.0,
            100.0 + $duration
        );
        stage3Assert(
            $gameDistance === 1005.0,
            'Authoritative списание топлива должно использовать ту же дистанцию, что и время.'
        );
    }

    function testLocalObjectTransferUsesSharedAnchorAndNovaDistance(): void
    {
        installFlightConfig();
        installPlanetFixture(12);

        $flight = new FleetFlightService();
        $planet = Coordinate::object(1, 1, 1, ObjectType::PLANET);
        $moon = Coordinate::object(1, 1, 1, ObjectType::MOON);
        $debris = Coordinate::object(1, 1, 1, ObjectType::DEBRIS);
        $otherOrbit = Coordinate::object(1, 1, 2, ObjectType::MOON);

        stage3Assert(
            $flight->getLocalObjectTransferDistance($planet, $moon) === 5.0,
            'Планета и Луна одной орбиты должны использовать локальную дистанцию Nova 5.'
        );
        stage3Assert(
            $flight->getLocalObjectTransferDistance($planet, $debris) === 5.0,
            'Планета и обломки одной орбиты должны использовать локальную дистанцию Nova 5.'
        );
        stage3Assert(
            $flight->getLocalObjectTransferDistance($planet, $planet) === null,
            'Один и тот же объект не является локальным переходом между объектами.'
        );
        stage3Assert(
            $flight->getLocalObjectTransferDistance($planet, $otherOrbit) === null,
            'Объекты разных орбит не должны использовать локальную дистанцию.'
        );

        $atTime = 120.0;
        $planetPoint = $flight->resolvePhysicalPoint($planet, $atTime);
        $moonPoint = $flight->resolvePhysicalPoint($moon, $atTime);
        $debrisPoint = $flight->resolvePhysicalPoint($debris, $atTime);
        stage3Assert(
            $planetPoint->radius === $moonPoint->radius
                && $planetPoint->angle === $moonPoint->angle
                && $planetPoint->radius === $debrisPoint->radius
                && $planetPoint->angle === $debrisPoint->angle,
            'PLANET/MOON/DEBRIS одной орбиты должны разрешаться в общий физический anchor.'
        );

        $duration = $flight->calculateCommandDuration(
            FleetCommandType::MOVE_IN_SYSTEM,
            $planet,
            $moon,
            $atTime,
            5000.0
        );
        stage3Assert(
            $duration === $flight->calculateGameDuration(5.0, 5000.0),
            'Локальный переход должен получать точное время по условной дистанции 5.'
        );
    }

    function testNovaFuelConsumptionForGameSegment(): void
    {
        installFlightConfig();

        Vars::$attributes[202] = [
            'speed' => 5000.0,
            'consumption' => 20.0,
            'consumption2' => 20.0,
            'tech' => 1,
        ];

        $fleet = makeStage3Fleet(Coordinate::point(1, 1, 100.0, 0.0), 0.0);
        $fleetReflection = new ReflectionClass(Fleet::class);
        $shipsProperty = $fleetReflection->getProperty('ships');
        $shipsProperty->setAccessible(true);
        $shipsProperty->setValue($fleet, [
            202 => new FleetShip(202, 'small_cargo', 2),
        ]);

        $accountData = (new ReflectionClass(AccountData::class))->newInstanceWithoutConstructor();
        $accountReflection = new ReflectionClass(AccountData::class);
        $techsProperty = $accountReflection->getProperty('Techs');
        $techsProperty->setAccessible(true);
        $dirty = false;
        $techsProperty->setValue($accountData, new Data([
            'combustion_tech' => 0,
            'impulse_motor_tech' => 0,
            'hyperspace_motor_tech' => 0,
        ], $dirty));

        $flight = new FleetFlightService();
        $distance = 2795.0;
        $duration = $flight->calculateGameDuration($distance, 5000.0);
        $calculatedSpeed = 35000.0 / (round($duration, 0) * 10.0 - 10.0)
            * sqrt($distance * 10.0 / 5000.0);
        $expected = round(
            40.0 * $distance / 35000.0 * (($calculatedSpeed / 10.0) + 1.0) ** 2
        ) + 1;

        stage3Assert(
            $flight->calculateFuelConsumption($fleet, $accountData, [$distance]) === (float)$expected,
            'Расход топлива игрового сегмента должен соответствовать формуле Nova.'
        );
    }

    function testBuilderUsesSingleDiagonalInterGalaxyTransition(): void
    {
        $start = Coordinate::point(1, 10, 100.0, 0.0);
        $target = Coordinate::point(2, 11, 100.0, 180.0);
        $commands = FleetMissionBuilder::buildTransport($start, $target, ['return' => false]);

        $galaxyJump = array_values(array_filter(
            $commands,
            static fn(array $command): bool => $command['type'] === FleetCommandType::MOVE_BETWEEN_GALAXIES
        ))[0] ?? null;
        $systemJump = array_values(array_filter(
            $commands,
            static fn(array $command): bool => $command['type'] === FleetCommandType::MOVE_BETWEEN_SYSTEMS
        ))[0] ?? null;

        stage3Assert($galaxyJump !== null, 'Межгалактический маршрут должен иметь galaxy transition.');
        stage3Assert(
            $galaxyJump['params']['destination'] === ['galaxy' => 2, 'system' => 11],
            'Galaxy transition должна завершаться непосредственно в целевой системе.'
        );
        stage3Assert(
            $systemJump === null,
            'После диагонального galaxy transition нельзя повторно начислять system transition.'
        );
    }

    $tests = [
        'testLocalAndGlobalQueueOrder',
        'testSingleActiveInvariant',
        'testDelayedSchedulerRequeriesGlobalQueue',
        'testLogicalCurrentPositionTimeAndTransitionPersistence',
        'testStationaryOrbitKeepsFleetAngle',
        'testSpecialOrbitsAreAddedIdempotently',
        'testSpecialOrbitDistancesScaleWithStarAndReconcileExistingRows',
        'testExpeditionTargetIsResolvedAsObject',
        'testExpeditionIsAvailableOnlyInFleetSystem',
        'testOrdinaryTargetCannotExceedSystemOrbitLimit',
        'testNavigationInfoExposesOnlyOrdinaryAndExpeditionOrbits',
        'testMoveDurationUsesExistingFormulaFromLogicalStart',
        'testMissionPreviewUsesRadialSourceExitDistance',
        'testNovaTransitionDistanceAndDuration',
        'testInSystemPhysicalDistanceUsesNovaScale',
        'testLocalObjectTransferUsesSharedAnchorAndNovaDistance',
        'testNovaFuelConsumptionForGameSegment',
        'testBuilderUsesSingleDiagonalInterGalaxyTransition',
        'testCurrentCoordinateBeforeStartAndAtStart',
        'testCurrentCoordinateDuringAndAfterMove',
        'testTransitionCurrentCoordinateUsesPersistedPoint',
        'testDynamicObjectConvergentAndDeterministic',
        'testDynamicObjectOscillationUsesBoundedFallback',
        'testUnsupportedDueEventIsNotSilentlyCompleted',
        'testUnsupportedFutureEventDoesNotBlockScheduler',
    ];

    foreach ($tests as $test) {
        $test();
        echo "OK {$test}\n";
    }
}
