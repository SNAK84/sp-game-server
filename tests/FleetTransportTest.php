<?php

declare(strict_types=1);

namespace Swoole {
    if (!class_exists('Swoole\\Table')) {
        final class Table implements \IteratorAggregate
        {
            public const TYPE_INT = 1;
            public const TYPE_FLOAT = 2;
            public const TYPE_STRING = 3;

            private array $rows = [];
            // Узкая failure injection для runtime commit/rollback тестов.
            public int $failedWrites = 0;
            public ?string $failedWriteKey = null;

            public function __construct(int $size = 0) {}
            public function column(string $name, int $type, int $size = 0): void {}
            public function create(): bool { return true; }
            public function exists(string $key): bool { return isset($this->rows[$key]); }
            public function set(string $key, array $value): bool
            {
                if ($this->failedWrites > 0 && ($this->failedWriteKey === null || $this->failedWriteKey === $key)) {
                    --$this->failedWrites;
                    return false;
                }
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

    use SPGame\Core\Logger;
    use SPGame\Core\Message;
    use SPGame\Game\Enums\FleetCommandType;
    use SPGame\Game\Enums\FleetMissionType;
    use SPGame\Game\Fleet\Fleet;
    use SPGame\Game\Pages\FleetsPage;
    use SPGame\Game\Repositories\Accounts;
    use SPGame\Game\Repositories\Config;
    use SPGame\Game\Repositories\FleetCommands;
    use SPGame\Game\Repositories\Fleets;
    use SPGame\Game\Repositories\FleetsShips;
    use SPGame\Game\Repositories\Galaxy;
    use SPGame\Game\Repositories\GalaxyOrbits;
    use SPGame\Game\Repositories\Messages;
    use SPGame\Game\Repositories\Planets;
    use SPGame\Game\Repositories\PlayerQueue;
    use SPGame\Game\Repositories\Techs;
    use SPGame\Game\Repositories\Users;
    use SPGame\Game\Repositories\Vars;
    use SPGame\Game\Services\AccountData;
    use SPGame\Game\Services\FleetCommandsService;
    use SPGame\Game\Services\FleetFlightService;
    use SPGame\Game\Services\FleetsData;
    use SPGame\Game\Services\Notification;
    use SPGame\Game\Space\Coordinate;
    use SPGame\Game\Space\CoordinateType;
    use SPGame\Game\Space\ObjectType;
    use Swoole\Table;

    final class TransportAccountData extends AccountData
    {
        private int $workPlanet;
        private array $testData;

        public function __construct(
            int $userId,
            int $sourcePlanetId,
            array $planetResources,
            array $planets,
            int $accountId = 1000
        )
        {
            $this->workPlanet = $sourcePlanetId;
            $this->testData = [
                'Account' => ['id' => $accountId],
                'User' => ['id' => $userId, 'account_id' => $accountId],
                'Planets' => $planets,
                'Techs' => [
                    'combustion_tech' => 0,
                    'impulse_motor_tech' => 0,
                    'hyperspace_motor_tech' => 0,
                ],
                'Resources' => $planetResources,
            ];
        }

        #[\ReturnTypeWillChange]
        public function offsetExists($offset): bool
        {
            return in_array($offset, ['WorkPlanet', 'Planet'], true)
                || array_key_exists($offset, $this->testData);
        }

        #[\ReturnTypeWillChange]
        public function &offsetGet($offset): mixed
        {
            if ($offset === 'WorkPlanet') {
                return $this->workPlanet;
            }
            if ($offset === 'Resources') {
                return $this->testData['Resources'][$this->workPlanet];
            }
            if ($offset === 'Planet') {
                return $this->testData['Planets'][$this->workPlanet];
            }

            return $this->testData[$offset];
        }

        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value): void
        {
            if ($offset === 'WorkPlanet') {
                $this->workPlanet = (int)$value;
                return;
            }
            $this->testData[$offset] = $value;
        }

        #[\ReturnTypeWillChange]
        public function offsetUnset($offset): void
        {
            unset($this->testData[$offset]);
        }

        public function save(): void {}

        public function resourceCount(int $planetId, int $resourceId): float
        {
            return (float)$this->testData['Resources'][$planetId][$resourceId]['count'];
        }
    }

    function transportAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function transportTable(array $rows = []): Table
    {
        $table = new Table();
        foreach ($rows as $row) {
            $table->set((string)$row['id'], $row);
        }
        return $table;
    }

    function transportSyncTable(int $lastId = 0): Table
    {
        $table = new Table();
        $table->set('meta', [
            'need_update' => 0,
            'need_delete' => 0,
            'last_id' => $lastId,
        ]);
        return $table;
    }

    function installTransportRepository(
        string $class,
        Table $table,
        ?Table $sync = null,
        ?array $indexes = null
    ): void {
        $reflection = new ReflectionClass($class);

        $property = $reflection->getProperty('table');
        $property->setAccessible(true);
        $property->setValue(null, $table);

        if ($sync !== null) {
            $syncProperty = $reflection->getProperty('syncTable');
            $syncProperty->setAccessible(true);
            $syncProperty->setValue(null, $sync);
        }

        if ($indexes !== null) {
            $indexProperty = $reflection->getProperty('indexTables');
            $indexProperty->setAccessible(true);
            $indexProperty->setValue(null, $indexes);
        }

        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue(null, Logger::getInstance());
    }

    function appendTransportSchemaColumns(string $class, array $columns): void
    {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('tableSchema');
        $property->setAccessible(true);
        $schema = $property->getValue();
        foreach ($columns as $name => $definition) {
            $schema['columns'][$name] = $definition;
        }
        $property->setValue(null, $schema);
    }

    function uniqueTransportIndex(array $entries): Table
    {
        $table = new Table();
        foreach ($entries as $values => $id) {
            $table->set(md5((string)$values), ['id' => $id]);
        }
        return $table;
    }

    function nonUniqueTransportIndex(array $entries): Table
    {
        $table = new Table();
        foreach ($entries as $values => $ids) {
            $ids = array_values(array_map('intval', $ids));
            $table->set(md5((string)$values), [
                'id' => $ids[0] ?? 0,
                'ids' => serialize($ids),
            ]);
        }
        return $table;
    }

    /** @return array{fleet:Fleet,account:TransportAccountData,source:array,target:array} */
    function installTransportFixture(): array
    {
        $fleetsDataReflection = new ReflectionClass(FleetsData::class);
        $instanceProperty = $fleetsDataReflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        Vars::$resource = [
            202 => 'small_cargo',
            901 => 'metal',
            902 => 'crystal',
            903 => 'deuterium',
        ];
        Vars::$reslist = [
            'resstype' => [1 => [901, 902, 903], 2 => [], 3 => []],
            'fleet' => [202],
        ];
        Vars::$attributes = [
            202 => [
                'capacity' => 100000,
                'speed' => 5000,
                'consumption' => 20,
                'consumption2' => 20,
                'tech' => 1,
                'missions' => [
                    FleetMissionType::TRANSPORT->value,
                    FleetMissionType::RELOCATE->value,
                ],
            ],
        ];

        $configRows = [
            ['id' => 1, 'name' => 'FleetGameSpeed', 'value' => '10'],
            ['id' => 2, 'name' => 'FleetSpeedFactor', 'value' => '1'],
            ['id' => 3, 'name' => 'SpeedPlanets', 'value' => '24'],
        ];
        installTransportRepository(
            Config::class,
            transportTable($configRows),
            null,
            ['name' => uniqueTransportIndex([
                'FleetGameSpeed' => 1,
                'FleetSpeedFactor' => 2,
                'SpeedPlanets' => 3,
            ])]
        );

        $source = [
            'id' => 10,
            'owner_id' => 100,
            'name' => 'Source',
            'galaxy' => 1,
            'system' => 10,
            'planet' => 7,
            'planet_type' => ObjectType::PLANET->value,
            'create_time' => 1,
            'update_time' => 1.0,
            'size' => 1,
            'fields' => 100,
            'type' => '',
            'image' => '',
            'speed' => 1.0,
            'distance' => 1000,
            'deg' => 0.0,
            'rotation' => 1,
            'temp_min' => 0,
            'temp_max' => 0,
        ];
        $target = $source;
        $target['id'] = 11;
        $target['name'] = 'Target';
        $target['planet'] = 8;
        $target['planet_type'] = ObjectType::PLANET->value;
        $target['distance'] = 1600;

        $planetCoordinateIndex = uniqueTransportIndex([
            '1_10_7_1' => 10,
            '1_10_8_1' => 11,
        ]);
        $planetOrbitIndex = new Table();
        $planetOrbitIndex->set(md5('1_10_7'), [
            'id' => 10,
            'ids' => serialize([10]),
        ]);
        $planetOrbitIndex->set(md5('1_10_8'), [
            'id' => 11,
            'ids' => serialize([11]),
        ]);
        installTransportRepository(
            Planets::class,
            transportTable([$source, $target]),
            transportSyncTable(11),
            [
                'owner_id' => nonUniqueTransportIndex([
                    '100' => [10, 11],
                ]),
                'galaxy_system_planet_type' => $planetCoordinateIndex,
                'galaxy_system_planet' => $planetOrbitIndex,
            ]
        );

        $sourceOrbit = [
            'id' => 1,
            'galaxy' => 1,
            'system' => 10,
            'orbit' => 7,
            'distance' => 1000,
            'type' => GalaxyOrbits::PLANET,
        ];
        $targetOrbit = $sourceOrbit;
        $targetOrbit['id'] = 2;
        $targetOrbit['orbit'] = 8;
        $targetOrbit['distance'] = 1600;
        installTransportRepository(
            GalaxyOrbits::class,
            transportTable([$sourceOrbit, $targetOrbit]),
            transportSyncTable(2),
            ['galaxy_system_orbit' => uniqueTransportIndex([
                '1_10_7' => 1,
                '1_10_8' => 2,
            ])]
        );

        installTransportRepository(
            Galaxy::class,
            transportTable(),
            transportSyncTable(),
            ['galaxy_system' => new Table()]
        );

        appendTransportSchemaColumns(Fleets::class, [
            'resource_metal' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            'resource_crystal' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            'resource_deuterium' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
        ]);
        appendTransportSchemaColumns(FleetsShips::class, [
            'small_cargo_count' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
            'small_cargo_damage' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            'small_cargo_experience' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            'small_cargo_morale' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 1.0],
            'small_cargo_status' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
        ]);

        $fleetRow = Fleets::castRowToSchema([
            'id' => 1,
            'owner_id' => 100,
            'home_planet_id' => 10,
            'current_coordinate_type' => CoordinateType::OBJECT->value,
            'current_galaxy' => 1,
            'current_system' => 10,
            'current_orbit' => 7,
            'current_object_type' => ObjectType::PLANET->value,
            'current_position_time' => 100.0,
            'resource_metal' => 0.0,
            'resource_crystal' => 0.0,
            'resource_deuterium' => 0.0,
        ], true);
        installTransportRepository(
            Fleets::class,
            transportTable([$fleetRow]),
            transportSyncTable(1),
            [
                'owner_id' => nonUniqueTransportIndex(['100' => [1]]),
                'target_coordinate' => nonUniqueTransportIndex(['0_0_0_0_0' => [1]]),
                'target_fleet' => nonUniqueTransportIndex(['0_0' => [1]]),
            ]
        );

        $shipsRow = FleetsShips::castRowToSchema([
            'id' => 1,
            'fleet_id' => 1,
            'owner_id' => 100,
            'small_cargo_count' => 10,
        ], true);
        installTransportRepository(
            FleetsShips::class,
            transportTable([$shipsRow]),
            transportSyncTable(1),
            []
        );

        installTransportRepository(
            FleetCommands::class,
            transportTable(),
            transportSyncTable(),
            []
        );

        installTransportRepository(
            Messages::class,
            transportTable(),
            transportSyncTable(),
            [
                'to' => new Table(),
                'from_to' => new Table(),
                'to_read' => new Table(),
                'to_type' => new Table(),
                'type_time' => new Table(),
                'from_type' => new Table(),
                'to_type_read' => new Table(),
            ]
        );
        installTransportRepository(
            PlayerQueue::class,
            transportTable(),
            transportSyncTable(),
            [
                'account_id' => new Table(),
                'user_id' => new Table(),
                'planet_id' => new Table(),
            ]
        );
        // FleetCommands наследует общее хранилище indexTables тестового double.
        // Восстанавливаем Fleet indexes после установки остальных репозиториев.
        installTransportRepository(
            Fleets::class,
            transportTable([$fleetRow]),
            transportSyncTable(1),
            [
                'owner_id' => nonUniqueTransportIndex(['100' => [1]]),
                'target_coordinate' => nonUniqueTransportIndex(['0_0_0_0_0' => [1]]),
                'target_fleet' => nonUniqueTransportIndex(['0_0' => [1]]),
            ]
        );

        $account = new TransportAccountData(100, 10, [
            10 => [
                901 => ['count' => 10000.0],
                902 => ['count' => 10000.0],
                903 => ['count' => 10000.0],
            ],
            11 => [
                901 => ['count' => 100.0],
                902 => ['count' => 200.0],
                903 => ['count' => 300.0],
            ],
        ], [10 => $source, 11 => $target]);

        return [
            'fleet' => new Fleet(1),
            'account' => $account,
            'source' => $source,
            'target' => $target,
        ];
    }

    function testTransportRejectsInsufficientFuelWithoutMutation(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $beforeMetal = $account->resourceCount(10, 901);
        $beforeDeuterium = $account->resourceCount(10, 903);

        $thrown = false;
        try {
            FleetCommandsService::startMission(
                $fleet,
                $account,
                FleetMissionType::TRANSPORT->value,
                Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray(),
                10.0,
                0,
                [901 => 1000, 902 => 0, 903 => 0],
                100.0
            );
        } catch (InvalidArgumentException $exception) {
            $thrown = str_contains($exception->getMessage(), 'топлива');
        }

        transportAssert($thrown, 'Миссия с недостаточным топливом должна быть отклонена.');
        transportAssert($account->resourceCount(10, 901) === $beforeMetal, 'Металл не должен списываться.');
        transportAssert($account->resourceCount(10, 903) === $beforeDeuterium, 'Дейтерий не должен списываться.');
        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'Fleet должен остаться IDLE.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Очередь FleetCommand должна остаться пустой.');
    }

    function testTransportRejectsExcessFuelWithoutMutation(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $coordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET);
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinate->toArray());
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::TRANSPORT);
        $maximumFuel = (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));
        $beforeDeuterium = $account->resourceCount(10, 903);

        $thrown = false;
        try {
            FleetCommandsService::startMission(
                $fleet,
                $account,
                FleetMissionType::TRANSPORT->value,
                $coordinate->toArray(),
                '10',
                (string)($maximumFuel + 1),
                [901 => 1000, 902 => 0, 903 => 0],
                100.0
            );
        } catch (InvalidArgumentException $exception) {
            $thrown = str_contains($exception->getMessage(), 'максимума топлива');
        }

        transportAssert($thrown, 'Миссия с избыточным топливом должна быть отклонена.');
        transportAssert(
            $account->resourceCount(10, 903) === $beforeDeuterium,
            'Избыточное топливо не должно списываться.'
        );
        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'Fleet должен остаться IDLE.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Очередь FleetCommand должна остаться пустой.');
    }

    function testTransportAcceptsCalculatedMinimumDuringPreparation(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $coordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET);
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinate->toArray());
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::TRANSPORT);
        $minimumFuel = (int)ceil((float)($travelInfo['fuel']['min'] ?? 0.0));

        $prepared = FleetCommandsService::prepareMissionRequest(
            $fleet,
            $account,
            FleetMissionType::TRANSPORT->value,
            $coordinate->toArray(),
            '10',
            (string)$minimumFuel,
            [901 => 1000, 902 => 0, 903 => 0]
        );

        transportAssert($minimumFuel > 0, 'Минимум топлива полного маршрута должен быть рассчитан.');
        transportAssert($prepared['fuel'] === (float)$minimumFuel, 'Расчётный минимум топлива должен приниматься.');
        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'Prepare не должен запускать Fleet mission.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Prepare не должен создавать FleetCommand.');
    }

    function testTransportCompletesRoundTripAndReconcilesResources(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates(
            Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray()
        );
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::TRANSPORT);
        $loadedFuel = (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));
        transportAssert($loadedFuel > 0, 'Сервер должен рассчитать максимальное потребление топлива миссии.');

        FleetCommandsService::startMission(
            $fleet,
            $account,
            FleetMissionType::TRANSPORT->value,
            Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray(),
            '10',
            (string)$loadedFuel,
            [901 => 1000, 902 => 500, 903 => 0],
            100.0
        );

        transportAssert($fleet->getStatus() === Fleet::STATUS_MISSION, 'Fleet должен перейти в MISSION.');
        transportAssert($account->resourceCount(10, 901) === 9000.0, 'Металл должен списаться один раз.');
        transportAssert($account->resourceCount(10, 902) === 9500.0, 'Кристалл должен списаться один раз.');
        transportAssert(
            $account->resourceCount(10, 903) === 10000.0 - $loadedFuel,
            'Должен загрузиться только рассчитанный максимум топлива.'
        );
        transportAssert($fleet->getResources()[901]->getCount() === 1000.0, 'Металл должен быть во Fleet cargo.');
        transportAssert(
            (Messages::findByIndex('to', [100]) ?? []) === [],
            'До выполнения смыслового действия сообщения отправляться не должны.'
        );

        $outbound = FleetCommands::getActiveCommand(1);
        transportAssert($outbound !== null, 'После LOAD должен быть активен outbound MOVE.');
        $outboundParams = json_decode($outbound['params'], true, 512, JSON_THROW_ON_ERROR);
        $outboundFuel = (float)($outboundParams['fuelCost'] ?? 0.0);
        transportAssert($outboundFuel > 0.0, 'Outbound MOVE должен списать топливо.');

        FleetCommandsService::completeCommand($outbound, $fleet, $account);
        transportAssert($account->resourceCount(11, 901) === 1100.0, 'Цель должна получить металл.');
        transportAssert($account->resourceCount(11, 902) === 700.0, 'Цель должна получить кристалл.');
        transportAssert($fleet->getResources()[901]->getCount() === 0.0, 'Cargo должен выгрузиться у цели.');

        $arrivalMessages = Messages::findByIndex('to', [100]) ?? [];
        transportAssert(count($arrivalMessages) === 1, 'Доставка на свою планету должна создать одно сообщение.');
        transportAssert(
            $arrivalMessages[0]['sample'] === 'FleetTransportArrivalOwner',
            'После первого UNLOAD должно прийти сообщение о доставке.'
        );
        $arrivalData = json_decode($arrivalMessages[0]['data'], true, 512, JSON_THROW_ON_ERROR);
        transportAssert(
            (float)$arrivalData['Resources'][901] === 1000.0
            && (float)$arrivalData['Resources'][902] === 500.0
            && $arrivalData['TargetCoordinate'] === '[1:10:8]',
            'Сообщение должно содержать фактически доставленный груз и координаты цели.'
        );

        $return = FleetCommands::getActiveCommand(1);
        transportAssert($return !== null, 'После UNLOAD должен быть активен return MOVE.');
        $returnParams = json_decode($return['params'], true, 512, JSON_THROW_ON_ERROR);
        $returnFuel = (float)($returnParams['fuelCost'] ?? 0.0);
        transportAssert($returnFuel > 0.0, 'Return MOVE должен списать топливо своего участка.');

        FleetCommandsService::completeCommand($return, $fleet, $account);

        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'После STAY Fleet должен вернуться в IDLE.');
        transportAssert($fleet->getMission() === 0, 'После STAY миссия должна быть сброшена.');
        transportAssert($fleet->getFuelCurrent() === 0.0, 'Остаток топлива должен вернуться на исходную планету.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Завершённая command queue должна быть очищена.');
        transportAssert(
            abs($account->resourceCount(10, 903) - (10000.0 - $outboundFuel - $returnFuel)) < 0.000001,
            'Источник должен потерять только фактически израсходованное топливо.'
        );

        $messages = Messages::findByIndex('to', [100]) ?? [];
        transportAssert(count($messages) === 2, 'После полного возврата должно появиться второе сообщение.');
        transportAssert(
            array_column($messages, 'sample') === ['FleetTransportArrivalOwner', 'FleetReturnedWithFuel'],
            'Сообщения должны соответствовать доставке и полному возвращению.'
        );
        $returned = json_decode($messages[1]['data'], true, 512, JSON_THROW_ON_ERROR);
        transportAssert(abs((float)$returned['ResourcesFuelReturned'][903] - ($loadedFuel - $outboundFuel - $returnFuel)) < 0.000001,
            'Сообщение должно содержать фактически выгруженный остаток, а не нулевой бак после STAY.');
        transportAssert(!isset($arrivalData['ResourcesFuelReturned']), 'Доставка груза не должна объявлять возврат топлива.');
        $messageEvents = PlayerQueue::findByIndex('user_id', [100]) ?? [];
        transportAssert(count($messageEvents) === 2, 'Каждое сообщение должно поставить push-событие игроку.');
        transportAssert(
            count(array_filter(
                $messageEvents,
                static fn(array $event): bool => $event['action'] === PlayerQueue::ActionMessagesNew
            )) === 2,
            'Push-события должны использовать существующий ActionMessagesNew contract.'
        );
    }

    function testRelocateAcceptsEmptyCargoDuringPreparation(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $coordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET);
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinate->toArray());
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::RELOCATE);
        $loadedFuel = (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));

        $prepared = FleetCommandsService::prepareMissionRequest(
            $fleet,
            $account,
            FleetMissionType::RELOCATE->value,
            $coordinate->toArray(),
            10.0,
            $loadedFuel,
            [901 => 0, 902 => 0, 903 => 0]
        );

        transportAssert(
            $prepared['mission'] === FleetMissionType::RELOCATE,
            'Передислокация должна приниматься без обязательного груза.'
        );
        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'Prepare не должен запускать передислокацию.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Prepare не должен создавать FleetCommand.');
    }

    function testRelocateCompletesOneWayAndChangesHomePlanet(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $coordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET);
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinate->toArray());
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::RELOCATE);
        $loadedFuel = (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));

        FleetCommandsService::startMission(
            $fleet,
            $account,
            FleetMissionType::RELOCATE->value,
            $coordinate->toArray(),
            10.0,
            $loadedFuel,
            [901 => 700, 902 => 0, 903 => 0],
            100.0
        );

        $chain = FleetCommands::getFleetCommandChain(1);
        transportAssert(
            array_map(
                static fn(array $command): int => (int)$command['command_type'],
                $chain
            ) === [
                FleetCommandType::LOAD->value,
                FleetCommandType::MOVE_IN_SYSTEM->value,
                FleetCommandType::UNLOAD->value,
                FleetCommandType::STAY->value,
            ],
            'Передислокация должна содержать только одностороннюю цепочку LOAD → MOVE → UNLOAD → STAY.'
        );
        transportAssert($fleet->getHomePlanetId() === 10, 'До прибытия домашняя планета не должна меняться.');

        $move = FleetCommands::getActiveCommand(1);
        transportAssert($move !== null, 'После LOAD должен быть активен MOVE передислокации.');
        $moveParams = json_decode($move['params'], true, 512, JSON_THROW_ON_ERROR);
        $fuelCost = (float)($moveParams['fuelCost'] ?? 0.0);
        transportAssert($fuelCost > 0.0, 'MOVE передислокации должен списывать топливо.');

        FleetCommandsService::completeCommand($move, $fleet, $account);

        transportAssert($fleet->getHomePlanetId() === 11, 'После STAY целью должна стать новая домашняя планета.');
        transportAssert(
            (int)(Fleets::getById(1)['home_planet_id'] ?? 0) === 11
            && (new Fleet(1))->getHomePlanetId() === 11,
            'Новый объект базирования должен быть записан в runtime repository и читаться новым Fleet.'
        );
        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'Завершённая передислокация должна вернуть Fleet в IDLE.');
        transportAssert($fleet->getMission() === 0, 'Завершённая передислокация должна сбросить mission.');
        transportAssert($fleet->getFuelCurrent() === 0.0, 'Остаток топлива должен выгрузиться на новом объекте базирования.');
        transportAssert($fleet->getResources()[901]->getCount() === 0.0, 'Груз должен выгрузиться на новом объекте базирования.');
        transportAssert($account->resourceCount(11, 901) === 800.0, 'Новая планета должна получить перевозимый металл.');
        transportAssert(
            abs($account->resourceCount(11, 903) - (300.0 + $loadedFuel - $fuelCost)) < 0.000001,
            'Новая планета должна получить неизрасходованный остаток топлива.'
        );
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Завершённая передислокация должна очистить command queue.');

        $messages = Messages::findByIndex('to', [100]) ?? [];
        transportAssert(count($messages) === 1, 'Передислокация должна создать одно итоговое сообщение.');
        transportAssert(
            $messages[0]['sample'] === 'FleetRelocatedWithFuel',
            'Итоговое сообщение должно подтверждать смену объекта базирования.'
        );
        $relocated = json_decode($messages[0]['data'], true, 512, JSON_THROW_ON_ERROR);
        transportAssert(abs((float)$relocated['ResourcesFuelReturned'][903] - ($loadedFuel - $fuelCost)) < 0.000001,
            'Передислокация сообщает остаток топлива, выгруженный на новую базу.');
    }

    function testRelocateRejectsForeignTargetWithoutMutation(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $targetPlanet = Planets::findById(11);
        $targetPlanet['owner_id'] = 200;
        Planets::update($targetPlanet);

        $thrown = false;
        try {
            FleetCommandsService::prepareMissionRequest(
                $fleet,
                $account,
                FleetMissionType::RELOCATE->value,
                Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray(),
                10.0,
                1,
                [901 => 0, 902 => 0, 903 => 0]
            );
        } catch (InvalidArgumentException $exception) {
            $thrown = true;
        }

        transportAssert($thrown, 'Передислокация на чужой объект должна быть отклонена.');
        transportAssert($fleet->getHomePlanetId() === 10, 'Отклонённая миссия не должна менять домашнюю планету.');
        transportAssert($fleet->getStatus() === Fleet::STATUS_IDLE, 'Отклонённая миссия не должна менять статус Fleet.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Отклонённая миссия не должна создавать команды.');
        transportAssert($account->resourceCount(10, 903) === 10000.0, 'Отклонённая миссия не должна списывать топливо.');
    }

    function testRelocateDoesNotUnloadIfTargetOwnershipChangesInFlight(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $coordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET);
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinate->toArray());
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::RELOCATE);
        $loadedFuel = (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));

        FleetCommandsService::startMission(
            $fleet,
            $account,
            FleetMissionType::RELOCATE->value,
            $coordinate->toArray(),
            10.0,
            $loadedFuel,
            [901 => 700, 902 => 0, 903 => 0],
            100.0
        );

        $targetPlanet = Planets::findById(11);
        $targetPlanet['owner_id'] = 200;
        Planets::update($targetPlanet);
        $move = FleetCommands::getActiveCommand(1);

        $thrown = false;
        try {
            FleetCommandsService::completeCommand($move, $fleet, $account);
        } catch (LogicException $exception) {
            $thrown = true;
        }

        transportAssert($thrown, 'UNLOAD должен повторно проверить владельца цели.');
        transportAssert($fleet->getHomePlanetId() === 10, 'Смена владельца цели не должна менять базирование Fleet.');
        transportAssert($fleet->getResources()[901]->getCount() === 700.0, 'Груз должен остаться во Fleet.');
        transportAssert($account->resourceCount(11, 901) === 100.0, 'Чужая цель не должна получить груз.');
        transportAssert(
            (Messages::findByIndex('to', [100]) ?? []) === [],
            'Незавершённая передислокация не должна создавать итоговое сообщение.'
        );
    }

    function testFlyingFleetProjectionContainsRemainingCommandsAndMissionEnd(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $account = $fixture['account'];
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates(
            Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray()
        );
        $travelInfo = $fleet->getTravelInfo($target, $account, 10.0, FleetMissionType::TRANSPORT);
        $loadedFuel = (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));

        FleetCommandsService::startMission(
            $fleet,
            $account,
            FleetMissionType::TRANSPORT->value,
            Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray(),
            10.0,
            $loadedFuel,
            [901 => 1000, 902 => 0, 903 => 0],
            100.0
        );

        $remainingBefore = FleetCommands::getFleetQueue(1);
        $commandsBefore = FleetCommands::getFleetCommandChain(1);
        $activeEnd = (float)($remainingBefore[0]['execute_time'] ?? 0.0);
        $page = (new ReflectionClass(FleetsPage::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(FleetsPage::class, 'buildFlightCommandProjection');
        $method->setAccessible(true);
        $projection = $method->invoke(
            $page,
            $fleet,
            $account,
            $commandsBefore,
            new FleetFlightService()
        );

        transportAssert(count($projection['commands']) === 6, 'Проекция должна содержать полную цепочку миссии.');
        transportAssert($projection['commands'][0]['status'] === 'completed', 'Выполненная LOAD должна оставаться в истории.');
        transportAssert($projection['commands'][1]['status'] === 'active', 'Текущая команда должна быть ACTIVE.');
        transportAssert(
            $projection['commands'][1]['estimated_time'] === $activeEnd,
            'ACTIVE-команда должна использовать authoritative execute_time.'
        );
        transportAssert(
            (float)$projection['commands'][1]['started_time'] === 100.0,
            'ACTIVE MOVE должна сохранять authoritative время начала участка.'
        );
        transportAssert(
            (float)($projection['commands'][1]['fuel_cost'] ?? 0.0) > 0.0
            && (float)($projection['route_leg']['segments'][0]['fuel_cost'] ?? 0.0)
                === (float)$projection['commands'][1]['fuel_cost'],
            'Проекция ACTIVE MOVE должна передавать расход топлива текущего участка.'
        );
        transportAssert(
            ($projection['route_leg']['direction'] ?? null) === 'outbound'
            && (float)($projection['route_leg']['started_at'] ?? 0.0) === 100.0
            && (float)($projection['route_leg']['arrives_at'] ?? 0.0) === $activeEnd
            && (int)($projection['route_leg']['active_segment'] ?? 0) === 1
            && (int)($projection['route_leg']['segments_total'] ?? 0) === 1,
            'Проекция должна объединять outbound MOVE в authoritative плечо маршрута.'
        );
        transportAssert(
            (float)$projection['mission_end_time'] > $activeEnd,
            'Полное завершение round trip должно быть позже текущего участка.'
        );
        transportAssert(
            $projection['mission_end_estimated'] === true,
            'Время будущих MOVE-команд должно быть помечено как прогноз.'
        );
        transportAssert(
            FleetCommands::getFleetCommandChain(1) === $commandsBefore
            && FleetCommands::getFleetQueue(1) === $remainingBefore,
            'Read-only проекция не должна изменять выполненные или pending команды.'
        );

        FleetCommandsService::completeCommand($remainingBefore[0], $fleet, $account);
        $returnCommands = FleetCommands::getFleetCommandChain(1);
        $returnProjection = $method->invoke(
            $page,
            $fleet,
            $account,
            $returnCommands,
            new FleetFlightService()
        );
        $returnActive = FleetCommands::getActiveCommand(1);
        transportAssert($returnActive !== null, 'После UNLOAD должна активироваться return MOVE.');
        transportAssert(
            ($returnProjection['route_leg']['direction'] ?? null) === 'return'
            && (float)($returnProjection['route_leg']['started_at'] ?? 0.0) === $activeEnd
            && (float)($returnProjection['route_leg']['arrives_at'] ?? 0.0)
                === (float)$returnActive['execute_time'],
            'После разворота route_leg должна описывать обратное плечо от цели домой.'
        );
    }

    function testTransportArrivalNotifiesForeignRecipient(): void
    {
        $fixture = installTransportFixture();
        $ownerAccount = $fixture['account'];
        $source = $fixture['source'];
        $target = $fixture['target'];
        $target['owner_id'] = 200;
        $targetAccount = new TransportAccountData(
            200,
            11,
            [
                11 => [
                    901 => ['count' => 1100.0],
                    902 => ['count' => 700.0],
                    903 => ['count' => 300.0],
                ],
            ],
            [11 => $target],
            2000
        );

        Notification::sendFleetTransportArrival(
            $ownerAccount,
            $targetAccount,
            1,
            $source,
            $target,
            [901 => 1000.0, 902 => 500.0, 903 => 0.0],
            150.0
        );

        $ownerMessages = Messages::findByIndex('to', [100]) ?? [];
        $targetMessages = Messages::findByIndex('to', [200]) ?? [];
        transportAssert(count($ownerMessages) === 1, 'Владелец Fleet должен получить сообщение о доставке.');
        transportAssert(count($targetMessages) === 1, 'Владелец чужой планеты должен получить сообщение о доставке.');
        transportAssert(
            $ownerMessages[0]['sample'] === 'FleetTransportArrivalOwner'
            && $targetMessages[0]['sample'] === 'FleetTransportArrivalTarget',
            'Отправитель и получатель должны получить свои варианты текста.'
        );
        transportAssert(
            count(PlayerQueue::findByIndex('user_id', [100]) ?? []) === 1
            && count(PlayerQueue::findByIndex('user_id', [200]) ?? []) === 1,
            'Push-событие должно быть поставлено обоим игрокам.'
        );
    }

    function testRouteLegProjectionCombinesMoveSegments(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $page = (new ReflectionClass(FleetsPage::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(FleetsPage::class, 'buildRouteLegProjection');
        $method->setAccessible(true);
        $move = static fn(
            int $id,
            string $status,
            float $start,
            float $end,
            bool $returnLeg,
            bool $estimated = false
        ): array => [
            'id' => $id,
            'position' => $id,
            'type' => FleetCommandType::MOVE_IN_SYSTEM->value,
            'key' => FleetCommandType::MOVE_IN_SYSTEM->name,
            'lang' => FleetCommandType::MOVE_IN_SYSTEM->getLangKey(),
            'status' => $status,
            'execute_time' => $status === 'pending' ? 0.0 : $end,
            'started_time' => $start,
            'start_time_estimated' => $estimated,
            'estimated_time' => $end,
            'time_estimated' => $estimated,
            'return_leg' => $returnLeg,
            'fuel_cost' => $status === 'active' ? 25.0 : null,
            'target' => null,
        ];
        $projection = [
            $move(1, 'completed', 100.0, 110.0, false),
            $move(2, 'active', 110.0, 130.0, false),
            $move(3, 'pending', 130.0, 160.0, false, true),
            $move(4, 'pending', 160.0, 190.0, true, true),
        ];

        $routeLeg = $method->invoke($page, $fleet, $projection, []);
        transportAssert(
            ($routeLeg['direction'] ?? null) === 'outbound'
            && (float)($routeLeg['started_at'] ?? 0.0) === 100.0
            && (float)($routeLeg['arrives_at'] ?? 0.0) === 160.0
            && (int)($routeLeg['active_segment'] ?? 0) === 2
            && (int)($routeLeg['segments_total'] ?? 0) === 3
            && ($routeLeg['estimated'] ?? false) === true,
            'Все MOVE текущего outbound-плеча должны образовать одну временную шкалу.'
        );

        $fleet->setReturn(true);
        $returnProjection = [
            $move(1, 'completed', 100.0, 110.0, false),
            $move(2, 'completed', 110.0, 130.0, false),
            $move(3, 'completed', 130.0, 160.0, false),
            $move(4, 'active', 160.0, 190.0, true),
            $move(5, 'pending', 190.0, 220.0, true, true),
        ];
        $routeLeg = $method->invoke($page, $fleet, $returnProjection, []);
        transportAssert(
            ($routeLeg['direction'] ?? null) === 'return'
            && (float)($routeLeg['started_at'] ?? 0.0) === 160.0
            && (float)($routeLeg['arrives_at'] ?? 0.0) === 220.0
            && (int)($routeLeg['active_segment'] ?? 0) === 1
            && (int)($routeLeg['segments_total'] ?? 0) === 2,
            'Return MOVE должны образовать отдельное плечо с обратным направлением.'
        );
    }

    function testIncomingFleetIsVisibleWithoutPrivateCargoData(): void
    {
        $fixture = installTransportFixture();
        $fleet = $fixture['fleet'];
        $ownerAccount = $fixture['account'];
        $target = $fixture['target'];
        $target['owner_id'] = 200;
        Planets::update($target);

        installTransportRepository(
            Accounts::class,
            transportTable([
                Accounts::castRowToSchema(['id' => 1000, 'login' => 'owner'], true),
                Accounts::castRowToSchema(['id' => 2000, 'login' => 'target'], true),
            ]),
            transportSyncTable(2000),
            []
        );
        installTransportRepository(
            Users::class,
            transportTable([
                Users::castRowToSchema([
                    'id' => 100,
                    'account_id' => 1000,
                    'name' => 'Owner',
                    'main_planet' => 10,
                    'current_planet' => 10,
                ], true),
                Users::castRowToSchema([
                    'id' => 200,
                    'account_id' => 2000,
                    'name' => 'Target',
                    'main_planet' => 11,
                    'current_planet' => 11,
                ], true),
            ]),
            transportSyncTable(200),
            ['account' => uniqueTransportIndex(['1000' => 100, '2000' => 200])]
        );
        installTransportRepository(
            Techs::class,
            transportTable([
                [
                    'id' => 100,
                    'combustion_tech' => 0,
                    'impulse_motor_tech' => 0,
                    'hyperspace_motor_tech' => 0,
                ],
                [
                    'id' => 200,
                    'combustion_tech' => 0,
                    'impulse_motor_tech' => 0,
                    'hyperspace_motor_tech' => 0,
                ],
            ]),
            transportSyncTable(200),
            []
        );

        $targetCoordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET);
        $travelInfo = $fleet->getTravelInfo(
            \SPGame\Game\Fleet\FleetTarget::fromCoordinates($targetCoordinate->toArray()),
            $ownerAccount,
            10.0,
            FleetMissionType::TRANSPORT
        );
        FleetCommandsService::startMission(
            $fleet,
            $ownerAccount,
            FleetMissionType::TRANSPORT->value,
            $targetCoordinate->toArray(),
            10.0,
            (int)ceil((float)($travelInfo['fuel']['max'] ?? 0.0)),
            [901 => 1000, 902 => 500, 903 => 0],
            100.0
        );

        transportAssert(
            count(Planets::findByIndex('owner_id', [200]) ?? []) === 1,
            'Тестовый владелец цели должен находиться по owner_id index.'
        );
        $targetFleetRows = Fleets::findByIndex('target_coordinate', [
                CoordinateType::OBJECT->value,
                1,
                10,
                8,
                ObjectType::PLANET->value,
            ]) ?? [];
        transportAssert(
            count($targetFleetRows) === 1,
            'Летящий Fleet должен находиться по target_coordinate index: '
                . json_encode(Fleets::getById(1), JSON_UNESCAPED_UNICODE)
        );

        $viewerAccount = new AccountData(2000);
        $page = new FleetsPage(new Message());
        $visible = $page->buildVisibleFlyingFleetList($viewerAccount);
        $incoming = $visible[1] ?? null;

        transportAssert(is_array($incoming), 'Входящий чужой Fleet должен быть виден владельцу цели.');
        transportAssert(
            ($incoming['is_incoming'] ?? false) === true
            && ($incoming['is_own'] ?? true) === false
            && ($incoming['owner_name'] ?? '') === 'Owner',
            'Входящее событие должно содержать признак и имя отправителя.'
        );
        transportAssert(
            !array_key_exists('ships', $incoming)
            && !array_key_exists('ships_count', $incoming)
            && !array_key_exists('resources', $incoming)
            && !array_key_exists('fuel', $incoming)
            && !array_key_exists('commands', $incoming),
            'Входящее событие не должно раскрывать состав, груз, топливо и цепочку команд.'
        );
        transportAssert(
            (float)($incoming['route_leg']['arrives_at'] ?? 0.0) > 100.0,
            'Входящее событие должно содержать время прибытия текущего outbound-плеча.'
        );

        $fleet->setReturn(true);
        $fleet->save();
        transportAssert(
            $page->buildVisibleFlyingFleetList($viewerAccount) === [],
            'Обратный рейс чужого Fleet не должен оставаться входящим событием.'
        );
    }

    function testReturnedFuelSurvivesSeparateUnloadAndStay(): void
    {
        foreach ([7.25, 0.0] as $fuel) {
            $fixture = installTransportFixture();
            $fleet = $fixture['fleet']; $account = $fixture['account'];
            $home = $fleet->getCurrentCoordinate();
            $fleet->setMission(FleetMissionType::TRANSPORT);
            $fleet->setStatus(Fleet::STATUS_MISSION);
            $fleet->setFuelTotal(10.0); $fleet->setFuelCurrent($fuel);
            $fleet->getResources()[903]->setCount(3.0); $fleet->save();
            FleetCommandsService::setFleetCommands(1, [
                ['type'=>FleetCommandType::UNLOAD,'params'=>['target'=>$home->toArray(),'unloadFuel'=>true]],
                ['type'=>FleetCommandType::STAY,'params'=>['at'=>$home->toArray()]],
            ]);
            $execute = new ReflectionMethod(FleetCommandsService::class,'executeImmediateCommand');
            $unload = FleetCommands::getNextPendingCommand(1);
            $before = $account->resourceCount(10,903);
            $execute->invoke(null,$unload,$fleet,$account);
            $saved = FleetCommands::getCommand((int)$unload['id']);
            $params = json_decode($saved['params'],true,512,JSON_THROW_ON_ERROR);
            transportAssert((int)$saved['executed']===1 && (float)$params['unloadedFuel']===$fuel,
                'Фактическая выгрузка сохранена в выполненной команде, в том числе нулевая.');
            transportAssert(abs($account->resourceCount(10,903)-$before-3.0-$fuel)<0.000001,
                'Дейтерий груза и остаток бака зачисляются отдельно и ровно один раз.');
            transportAssert((Messages::findByIndex('to',[100])??[])===[], 'Итоговое сообщение отправляется только после STAY.');
            // Новый Fleet/PHP-контекст не должен терять результат предыдущего UNLOAD.
            $restored = new Fleet(1);
            transportAssert($restored->getFuelCurrent()===0.0, 'Во время STAY бак уже пуст.');
            $execute->invoke(null,FleetCommands::getNextPendingCommand(1),$restored,$account);
            $messages = Messages::findByIndex('to',[100])??[];
            $data = json_decode($messages[0]['data'],true,512,JSON_THROW_ON_ERROR);
            transportAssert(count($messages)===1 && $messages[0]['sample']==='FleetReturnedWithCargoAndFuel'
                && (float)$data['ResourcesFuelReturned'][903]===$fuel && (float)$data['Resources'][903]===3.0,
                'Сообщение содержит фактический груз и отдельно остаток бака, в том числе нулевой.');
            transportAssert(abs($account->resourceCount(10,903)-$before-3.0-$fuel)<0.000001,
                'Формирование сообщения не зачисляет топливо повторно.');
        }
    }

    function testLegacyReturnMessagesKeepOriginalTemplates(): void
    {
        $fixture = installTransportFixture();
        $account = $fixture['account']; $planet = Planets::findExistingById(10);
        \SPGame\Game\Services\Notification::sendFleetReturned($account,1,$planet,100);
        \SPGame\Game\Services\Notification::sendFleetRelocated($account,1,$planet,101);
        \SPGame\Game\Services\Notification::sendFleetSpyReturned($account,1,$planet,102,2.5);
        $messages = Messages::findByIndex('to',[100])??[];
        transportAssert(array_column($messages,'sample')===['FleetReturned','FleetRelocated','FleetReturnedWithFuel'],
            'Без известного остатка остаются прежние шаблоны; шпионаж использует новый шаблон.');
        $lang = \SPGame\Game\Services\Lang::load('ru')['notifications']['sample'];
        foreach (['FleetReturned','FleetRelocated'] as $sample) {
            transportAssert(!str_contains($lang[$sample],'{ResourcesFuelReturned}'), 'Старые сообщения не требуют новых данных.');
        }
        foreach (['FleetReturnedWithFuel','FleetRelocatedWithFuel'] as $sample) {
            transportAssert(str_contains($lang[$sample],'{ResourcesFuelReturned}'), 'Новый шаблон отображает отдельный ресурс топлива.');
        }
        foreach (['FleetReturned','FleetReturnedWithFuel'] as $sample) {
            transportAssert(!str_contains($lang[$sample],'{Resources}'), 'Старые сообщения без данных груза продолжают отображаться.');
        }
    }

    function testReturnCargoMessagesKeepCargoAndFuelSeparate(): void
    {
        $fixture = installTransportFixture();
        $account = $fixture['account']; $planet = Planets::findExistingById(10);
        $before = $account->resourceCount(10,903);
        $cargo = [901=>125500.0,902=>100200.0,903=>75100.0];
        \SPGame\Game\Services\Notification::sendFleetReturned($account,1,$planet,100,9307,$cargo);
        \SPGame\Game\Services\Notification::sendFleetReturned($account,1,$planet,101,null,$cargo);
        \SPGame\Game\Services\Notification::sendFleetReturned($account,1,$planet,102,0,[901=>0,902=>0,903=>0]);
        \SPGame\Game\Services\Notification::sendFleetSpyReturned($account,1,$planet,103,2.5,$cargo);
        $messages = Messages::findByIndex('to',[100])??[];
        transportAssert(array_column($messages,'sample')===['FleetReturnedWithCargoAndFuel','FleetReturnedWithCargo',
            'FleetReturnedWithFuel','FleetReturnedWithCargoAndFuel'], 'Шаблон соответствует известным данным груза и топлива.');
        foreach ([0,1,3] as $index) {
            $data = json_decode($messages[$index]['data'],true,512,JSON_THROW_ON_ERROR);
            foreach ($cargo as $id=>$amount) transportAssert((float)$data['Resources'][$id]===$amount,'Точное количество доставленного груза.');
            if ($index===0) transportAssert((float)$data['ResourcesFuelReturned'][903]===9307.0,'Бак не смешивается с грузовым дейтерием.');
            if ($index===1) transportAssert(!isset($data['ResourcesFuelReturned']),'Неизвестное топливо не придумывается.');
        }
        transportAssert((int)$messages[3]['type']===21,'Возврат шпионажа остаётся в своей категории.');
        transportAssert($account->resourceCount(10,903)===$before,'Уведомления не начисляют ресурсы.');
    }

    $tests = [
        'testTransportRejectsInsufficientFuelWithoutMutation',
        'testTransportRejectsExcessFuelWithoutMutation',
        'testTransportAcceptsCalculatedMinimumDuringPreparation',
        'testTransportCompletesRoundTripAndReconcilesResources',
        'testRelocateAcceptsEmptyCargoDuringPreparation',
        'testRelocateCompletesOneWayAndChangesHomePlanet',
        'testRelocateRejectsForeignTargetWithoutMutation',
        'testRelocateDoesNotUnloadIfTargetOwnershipChangesInFlight',
        'testTransportArrivalNotifiesForeignRecipient',
        'testFlyingFleetProjectionContainsRemainingCommandsAndMissionEnd',
        'testRouteLegProjectionCombinesMoveSegments',
        'testIncomingFleetIsVisibleWithoutPrivateCargoData',
        'testReturnedFuelSurvivesSeparateUnloadAndStay',
        'testLegacyReturnMessagesKeepOriginalTemplates',
        'testReturnCargoMessagesKeepCargoAndFuelSeparate',
    ];

    foreach ($tests as $test) {
        $test();
        echo "OK {$test}\n";
    }
}
