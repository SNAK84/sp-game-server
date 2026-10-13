<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Enums\FleetMissionType;

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Services\FleetCommandsService;
use SPGame\Game\Services\FleetFlightService;
use SPGame\Game\Services\FleetFunctions;
use SPGame\Game\Services\GalaxyGenerator;

use SPGame\Game\Services\AccountData;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;


class FleetsPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {

        $AvailableMissions = null;
        $TravelInfo = null;
        $NavigationSystem = null;
        $Target = $this->Msg->getData('target', []);
        if (!is_array($Target)) {
            $Target = [];
        }

        Logger::getInstance()->info("FleetsPage getAction", [$this->Msg->getAction()]);


        if ($this->Msg->getAction() === 'GetAvailableMissions') {

            $coordinates = $this->Msg->getData('coordinates', []);

            // Coordinate target: OBJECT / POINT / FLEET без client-side lastKnownPosition.
            Logger::getInstance()->info("coordinates", $coordinates);

            $fleet = $AccountData->getFleetsData()->get(
                (int)$this->Msg->getData('fleetId')
            );
            if ($fleet !== null) {
                $target = FleetTarget::fromCoordinates($coordinates);
                $speedFactor = max(1.0, min(
                    10.0,
                    (float)$this->Msg->getData('speedFactor', 10)
                ));
                $missionType = FleetMissionType::tryFrom(
                    (int)$this->Msg->getData('missionType', 0)
                );
                $AvailableMissions = $fleet->getAvailableMissions($target, $AccountData);
                $TravelInfo = $fleet->getTravelInfo(
                    $target,
                    $AccountData,
                    $speedFactor,
                    $missionType
                );
            }
        }

        if ($this->Msg->getAction() === 'GetSystemNavigationInfo') {
            $NavigationSystem = $this->buildNavigationSystemInfo($AccountData);
        }

        $Planet = &$AccountData['Planet'];

        // Список флотов игрока (активные, летящие, стоящие)
        $FleetList = $this->buildFleetList($AccountData);
        $FlyingFleets = $this->buildFlyingFleetList($AccountData);

        // Список кораблей на планете (в ангаре)
        $PlanetShips = $this->buildPlanetShips($AccountData);

        return [
            'page'          => 'fleet',
            'planet_id'     => $Planet['id'],
            'FleetList'     => $FleetList,
            'FlyingFleets'  => $FlyingFleets,
            'PlanetShips'   => $PlanetShips,
            'FleetTypes'    => Vars::$reslist['nametype']['fleet'],
            'FleetMissions' => FleetMissionType::list(),
            'AvailableMissions' => $AvailableMissions,
            'TravelInfo'    => $TravelInfo,
            'NavigationSystem' => $NavigationSystem,
            'FleetCommands' => FleetCommandType::list(),
            'Target'        => $Target,
        ];
    }

    /**
     * Собирает список флотов игрока
     */
    private function buildFleetList(AccountData &$AccountData): array
    {
        $fleets = $AccountData->getFleetsData()->getForPlanet(
            (int) $AccountData['User']['id'],
            (int) $AccountData['Planet']['id']
        );

        $FleetList = [];

        foreach ($fleets as $fleet) {
            if ($fleet === null) {
                continue;
            }

            $shipsNormalized = [];

            $countTotal = 0;
            $types = 0;
            $capacityTotal = 0.0;
            $consumptionTotal = 0.0;
            $speedList = [];

            // Перебираем фактический состав доменного Fleet.
            foreach ($fleet->getShips() as $ShipId => $ship) {
                $ShipId = (int) $ShipId;
                //$name = Vars::$resource[$ShipId] ?? (string) $ShipId;

                $shipCount = $ship->getCount();
                if ($shipCount <= 0) {
                    // сохраним нулевой корабль (опционально) — но для клиента лучше не класть нулевые элементы
                    continue;
                }

                // Атрибуты корабля из Vars
                //$attr = Vars::$attributes[$ShipId] ?? [];
                //$shipCapacity = $attr['capacity'] ?? 0.0;

                // Функции расчёта, использующие AccountData (например модификаторы)
                //$shipSpeed = FleetFunctions::GetShipSpeed($ShipId, $AccountData);
                //$shipConsumption = FleetFunctions::GetShipConsumption($ShipId, $AccountData);

                // Нормализованный объект корабля
                $shipsNormalized[$ShipId] = [
                    'id'          => $ShipId,
                    'name'        => $ship->getShipTypeName(),
                    'count'       => $shipCount,
                    'speed'       => $ship->getSpeed($AccountData),

                    'capacity'    => $ship->getCapacity(),
                    'consumption' => $ship->getConsumption($AccountData),

                    'damage'      => $ship->getDamage(),
                    'experience'  => $ship->getExperience(),
                    'morale'      => $ship->getMorale(),
                    'status'      => $ship->getStatus(),
                ];

                // Агрегаты флота
                $countTotal += $shipCount;
                $types++;
                $capacityTotal += $ship->getCapacityAll();
                $consumptionTotal += $ship->getConsumptionAll($AccountData);
                $speedList[] = $ship->getSpeed($AccountData);
            }

            $fleetSpeed = $fleet->getTotalSpeed($AccountData);
            $startCoordinate = $fleet->getStartCoordinate();
            $targetCoordinate = $fleet->getTargetCoordinate();
            $currentCoordinate = $fleet->getCurrentCoordinate();

            // Сформируем запись для клиента
            $FleetList[$fleet->getId()] = [
                'id'            => $fleet->getId(),
                'mission'       => $fleet->getMission(),
                'status'        => $fleet->getStatus(),
                //'is_return'     => $fleet->isReturn() ? 1 : 0,
                'speed'         => $fleetSpeed,
                'count'         => $countTotal,
                'types'         => $types,
                'capacity'      => $capacityTotal,
                'consumption'   => $consumptionTotal,
                'fuel'          => $fleet->getFuelCurrent(),
                'experience'    => $fleet->getExperience(),
                'resources'     => $this->extractResources($fleet),
                'ships'         => $shipsNormalized,
                'start' => $startCoordinate?->toArray(),
                'target' => $targetCoordinate?->toArray(),
                'current' => $currentCoordinate->toArray(),
                'current_position_time' => $fleet->getCurrentPositionTime(),
            ];
        }

        return $FleetList;
    }

    /**
     * Собирает активные участки полёта всех собственных Fleet.
     *
     * Клиент получает уже материализованные сервером границы текущей команды.
     * Для ожидающих MOVE-команд дополнительно строится read-only прогноз теми
     * же формулами, которыми команда будет рассчитана при активации.
     */
    public function buildFlyingFleetList(AccountData &$AccountData): array
    {
        $ownerId = (int)($AccountData['User']['id'] ?? 0);
        $flyingFleets = [];
        $flightService = new FleetFlightService();

        foreach ($AccountData->getFleetsData()->getForAccount($ownerId) as $fleet) {
            $event = $this->buildFlyingFleetEvent($fleet, $AccountData, $flightService, true);
            if ($event !== null) {
                $event['owner_id'] = $ownerId;
                $event['owner_name'] = (string)($AccountData['User']['name'] ?? '');
                $event['is_own'] = true;
                $event['is_incoming'] = false;
                $event['is_hostile'] = false;
                $flyingFleets[$fleet->getId()] = $event;
            }
        }

        $this->sortFlyingFleetEvents($flyingFleets);

        return $flyingFleets;
    }

    /**
     * События, видимые владельцу аккаунта: собственные Fleet и чужие Fleet,
     * направленные к одному из принадлежащих ему объектов/Fleet.
     */
    public function buildVisibleFlyingFleetList(AccountData &$AccountData): array
    {
        $visible = $this->buildFlyingFleetList($AccountData);
        foreach ($this->buildIncomingFleetList($AccountData) as $fleetId => $event) {
            $visible[$fleetId] = $event;
        }

        $this->sortFlyingFleetEvents($visible);
        return $visible;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildIncomingFleetList(AccountData &$AccountData): array
    {
        $viewerId = (int)($AccountData['User']['id'] ?? 0);
        if ($viewerId <= 0) {
            return [];
        }

        $candidateRows = [];
        foreach (Planets::findByIndex('owner_id', [$viewerId]) ?? [] as $planet) {
            $targetKey = [
                CoordinateType::OBJECT->value,
                (int)$planet['galaxy'],
                (int)$planet['system'],
                (int)$planet['planet'],
                (int)$planet['planet_type'],
            ];
            foreach (Fleets::findByIndex('target_coordinate', $targetKey) ?? [] as $row) {
                $candidateRows[(int)$row['id']] = $row;
            }
        }

        foreach (Fleets::findByIndex('owner_id', [$viewerId]) ?? [] as $ownFleet) {
            foreach (Fleets::findByIndex('target_fleet', [
                CoordinateType::FLEET->value,
                (int)$ownFleet['id'],
            ]) ?? [] as $row) {
                $candidateRows[(int)$row['id']] = $row;
            }
        }

        $events = [];
        $ownerAccounts = [];
        $flightService = new FleetFlightService();
        foreach ($candidateRows as $fleetId => $row) {
            $ownerId = (int)($row['owner_id'] ?? 0);
            if (
                $ownerId <= 0
                || $ownerId === $viewerId
                || (bool)($row['is_return'] ?? false)
                || (int)($row['mission'] ?? 0) <= 0
            ) {
                continue;
            }

            $owner = Users::findById($ownerId);
            $accountId = (int)($owner['account_id'] ?? 0);
            if ($accountId <= 0) {
                Logger::getInstance()->warning('Не найден аккаунт владельца входящего Fleet', [
                    'fleetId' => $fleetId,
                    'ownerId' => $ownerId,
                ]);
                continue;
            }

            try {
                $ownerAccounts[$accountId] ??= new AccountData($accountId);
                $fleet = $ownerAccounts[$accountId]->getFleetsData()->get($fleetId);
                if ($fleet === null || $fleet->isReturn()) {
                    continue;
                }

                $event = $this->buildFlyingFleetEvent(
                    $fleet,
                    $ownerAccounts[$accountId],
                    $flightService,
                    false
                );
                if ($event === null) {
                    continue;
                }

                $event['owner_id'] = $ownerId;
                $event['owner_name'] = (string)($owner['name'] ?? '');
                $event['is_own'] = false;
                $event['is_incoming'] = true;
                $event['is_hostile'] = $this->isHostileMission((int)$event['mission']);
                $events[$fleetId] = $event;
            } catch (\Throwable $exception) {
                Logger::getInstance()->warning('Не удалось подготовить входящий Fleet для отображения', [
                    'fleetId' => $fleetId,
                    'ownerId' => $ownerId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $events;
    }

    /** @return array<string, mixed>|null */
    private function buildFlyingFleetEvent(
        Fleet $fleet,
        AccountData $ownerAccountData,
        FleetFlightService $flightService,
        bool $includePrivateData
    ): ?array {
        $activeCommand = FleetCommands::getActiveCommand($fleet->getId());
        if ($activeCommand === null) {
            return null;
        }

        try {
            $commandType = FleetCommandType::from((int)$activeCommand['command_type']);
            $target = FleetCommandsService::getCommandTarget($activeCommand);
            if ($target === null) {
                throw new \LogicException('У активной команды отсутствует target.');
            }

            $params = json_decode(
                (string)($activeCommand['params'] ?? ''),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($params)) {
                $params = [];
            }

            $startTime = $fleet->getCurrentPositionTime();
            $endTime = (float)$activeCommand['execute_time'];
            $segmentStart = $fleet->getCurrentCoordinate();
            $segmentEnd = $flightService->resolveCommandEndCoordinate(
                $commandType,
                $segmentStart,
                $target,
                $params,
                $startTime,
                $endTime
            );
            $commands = FleetCommands::getFleetCommandChain($fleet->getId());
            $commandProjection = $this->buildFlightCommandProjection(
                $fleet,
                $ownerAccountData,
                $commands,
                $flightService
            );

            $event = [
                'id' => $fleet->getId(),
                'mission' => $fleet->getMission(),
                'status' => $fleet->getStatus(),
                'is_return' => $fleet->isReturn(),
                'route_leg' => $includePrivateData
                    ? $commandProjection['route_leg']
                    : $this->redactIncomingRouteLeg($commandProjection['route_leg']),
                'command' => [
                    'id' => (int)$activeCommand['id'],
                    'type' => $commandType->value,
                    'key' => $commandType->name,
                    'lang' => $commandType->getLangKey(),
                ],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'segment_start' => $segmentStart->toArray(),
                'segment_end' => $segmentEnd->toArray(),
                'mission_start' => $fleet->getStartCoordinate()?->toArray(),
                'mission_target' => $fleet->getTargetCoordinate()?->toArray(),
            ];

            if (!$includePrivateData) {
                return $event;
            }

            $shipsCount = 0;
            $shipTypes = 0;
            $ships = [];
            foreach ($fleet->getShips() as $shipId => $ship) {
                $count = $ship->getCount();
                if ($count <= 0) {
                    continue;
                }
                $ships[(int)$shipId] = [
                    'id' => (int)$shipId,
                    'name' => $ship->getShipTypeName(),
                    'count' => $count,
                ];
                $shipsCount += $count;
                $shipTypes++;
            }

            $event += [
                'ships_count' => $shipsCount,
                'ship_types' => $shipTypes,
                'ships' => $ships,
                'resources' => $this->extractResources($fleet),
                'fuel' => $fleet->getFuelCurrent(),
                'commands_left' => count(FleetCommands::getFleetQueue($fleet->getId())),
                'commands_total' => count($commands),
                'commands' => $commandProjection['commands'],
                'mission_end_time' => $commandProjection['mission_end_time'],
                'mission_end_estimated' => $commandProjection['mission_end_estimated'],
            ];

            return $event;
        } catch (\Throwable $exception) {
            Logger::getInstance()->warning('Не удалось подготовить Fleet для таблицы полётов', [
                'fleetId' => $fleet->getId(),
                'commandId' => (int)($activeCommand['id'] ?? 0),
                'message' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /** @param array<string, mixed>|null $routeLeg */
    private function redactIncomingRouteLeg(?array $routeLeg): ?array
    {
        if ($routeLeg === null) {
            return null;
        }

        return array_intersect_key($routeLeg, array_flip([
            'direction',
            'start',
            'target',
            'started_at',
            'arrives_at',
            'estimated',
        ]));
    }

    private function isHostileMission(int $missionId): bool
    {
        return in_array($missionId, [
            FleetMissionType::ATTACK->value,
            FleetMissionType::GROUP_ATTACK->value,
            FleetMissionType::DESTROY->value,
            FleetMissionType::SPY->value,
        ], true);
    }

    /** @param array<int, array<string, mixed>> $events */
    private function sortFlyingFleetEvents(array &$events): void
    {
        uasort($events, static function (array $left, array $right): int {
            $leftTime = (float)($left['route_leg']['arrives_at'] ?? $left['end_time'] ?? 0.0);
            $rightTime = (float)($right['route_leg']['arrives_at'] ?? $right['end_time'] ?? 0.0);
            $timeOrder = $leftTime <=> $rightTime;
            return $timeOrder !== 0 ? $timeOrder : ($left['id'] <=> $right['id']);
        });
    }

    /**
     * Построить клиентское представление оставшейся цепочки FleetCommand.
     *
     * ACTIVE-команда имеет authoritative execute_time. Время ожидающих MOVE
     * является прогнозом: runtime очередь не изменяется и материализованные
     * координаты в FleetCommands не записываются.
     *
     * @param array<int, array<string, mixed>> $commands
     * @return array{
     *     commands: array<int, array<string, mixed>>,
     *     route_leg: ?array<string, mixed>,
     *     mission_end_time: ?float,
     *     mission_end_estimated: bool
     * }
     */
    private function buildFlightCommandProjection(
        Fleet $fleet,
        AccountData $AccountData,
        array $commands,
        FleetFlightService $flightService
    ): array {
        $cursor = $fleet->getCurrentCoordinate();
        $cursorTime = $fleet->getCurrentPositionTime();
        $fleetSpeed = $fleet->getTotalSpeed($AccountData);
        $scheduleAvailable = true;
        $missionEndEstimated = false;
        $projection = [];
        $commandCreateTimes = [];

        foreach ($commands as $position => $command) {
            $type = FleetCommandType::from((int)$command['command_type']);
            $params = $this->decodeFleetCommandParams($command['params'] ?? '');
            $target = $this->getProjectionCommandTarget($params);
            $completed = (bool)$command['executed'];
            $active = !$completed && (float)$command['execute_time'] > 0.0;
            $commandTime = null;
            $commandStartTime = $this->getProjectionStartedAt($params);
            $startTimeEstimated = false;
            $timeEstimated = false;
            $commandCreateTimes[(int)$command['id']] = (float)($command['create_time'] ?? 0.0);

            if ($completed) {
                $executedAt = (float)$command['execute_time'];
                $commandTime = $executedAt > 0.0 ? $executedAt : null;
            } elseif ($scheduleAvailable) {
                try {
                    if ($active) {
                        if (!$this->isMoveCommand($type) || $target === null) {
                            throw new \LogicException('ACTIVE-команда не содержит корректную MOVE-цель.');
                        }

                        $commandStartTime ??= $cursorTime;
                        $commandTime = (float)$command['execute_time'];
                        $cursor = $flightService->resolveCommandEndCoordinate(
                            $type,
                            $cursor,
                            $target,
                            $params,
                            $cursorTime,
                            $commandTime
                        );
                        $cursorTime = $commandTime;
                    } elseif ($this->isMoveCommand($type)) {
                        if ($target === null) {
                            throw new \LogicException('Ожидающая MOVE-команда не содержит target.');
                        }

                        $commandStartTime = $cursorTime;
                        $startTimeEstimated = true;
                        $params = $flightService->materializeCommandCoordinates(
                            $type,
                            $cursor,
                            $target,
                            $params,
                            $cursorTime
                        );
                        $durationTarget = isset($params['resolvedTarget'])
                            && is_array($params['resolvedTarget'])
                                ? Coordinate::fromArray($params['resolvedTarget'])
                                : $target;
                        $duration = $flightService->calculateCommandDuration(
                            $type,
                            $cursor,
                            $durationTarget,
                            $cursorTime,
                            $fleetSpeed,
                            $this->getProjectionSpeedFactor($params)
                        );
                        $commandTime = $cursorTime + $duration;
                        $cursor = $flightService->resolveCommandEndCoordinate(
                            $type,
                            $cursor,
                            $target,
                            $params,
                            $cursorTime,
                            $commandTime
                        );
                        $cursorTime = $commandTime;
                        $timeEstimated = true;
                        $missionEndEstimated = true;
                    } elseif ($this->isImmediateProjectionCommand($type)) {
                        $commandTime = $cursorTime;
                        $timeEstimated = true;
                        if ($type === FleetCommandType::STAY && $target !== null) {
                            $cursor = $target;
                        }
                    } else {
                        // Длительность боевых, экспедиционных и WAIT-команд
                        // пока не является частью execution contract.
                        $scheduleAvailable = false;
                    }
                } catch (\Throwable $exception) {
                    $scheduleAvailable = false;
                    Logger::getInstance()->warning('Не удалось рассчитать прогноз FleetCommand', [
                        'fleetId' => $fleet->getId(),
                        'commandId' => (int)($command['id'] ?? 0),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $projection[] = [
                'id' => (int)$command['id'],
                'position' => $position + 1,
                'type' => $type->value,
                'key' => $type->name,
                'lang' => $type->getLangKey(),
                'status' => $completed ? 'completed' : ($active ? 'active' : 'pending'),
                'execute_time' => (float)$command['execute_time'],
                'started_time' => $commandStartTime,
                'start_time_estimated' => $startTimeEstimated,
                'estimated_time' => $commandTime,
                'time_estimated' => $timeEstimated,
                'return_leg' => (bool)($params['returnLeg'] ?? false),
                'fuel_cost' => $this->getProjectionFuelCost($params),
                'target' => $this->serializeProjectionTarget($target),
            ];
        }

        $routeLeg = $this->buildRouteLegProjection(
            $fleet,
            $projection,
            $commandCreateTimes
        );

        return [
            'commands' => $projection,
            'route_leg' => $routeLeg,
            'mission_end_time' => $scheduleAvailable ? $cursorTime : null,
            'mission_end_estimated' => $missionEndEstimated,
        ];
    }

    /** @param array<string, mixed> $params */
    private function getProjectionStartedAt(array $params): ?float
    {
        $startedAt = $params['startedAt'] ?? null;
        if (!is_numeric($startedAt)) {
            return null;
        }

        $startedAt = (float)$startedAt;
        return is_finite($startedAt) && $startedAt > 0.0 ? $startedAt : null;
    }

    /** @param array<string, mixed> $params */
    private function getProjectionFuelCost(array $params): ?float
    {
        $fuelCost = $params['fuelCost'] ?? null;
        if (!is_numeric($fuelCost)) {
            return null;
        }

        $fuelCost = (float)$fuelCost;
        return is_finite($fuelCost) && $fuelCost >= 0.0 ? $fuelCost : null;
    }

    /**
     * Собрать единую временную шкалу текущего плеча маршрута.
     *
     * Отдельные MOVE-команды остаются execution source of truth. Эта проекция
     * только объединяет их для отображения пути start -> target или обратно.
     *
     * @param array<int, array<string, mixed>> $projection
     * @param array<int, float> $commandCreateTimes
     * @return array<string, mixed>|null
     */
    private function buildRouteLegProjection(
        Fleet $fleet,
        array $projection,
        array $commandCreateTimes
    ): ?array {
        $isReturn = $fleet->isReturn();
        $moveCommands = array_values(array_filter(
            $projection,
            fn(array $command): bool => $this->isMoveCommand(
                FleetCommandType::from((int)$command['type'])
            ) && (bool)$command['return_leg'] === $isReturn
        ));
        if ($moveCommands === []) {
            return null;
        }

        $firstMoveId = (int)$moveCommands[0]['id'];
        $previousMoveEnd = null;
        foreach ($projection as $command) {
            if ((int)$command['id'] === $firstMoveId) {
                break;
            }
            if (
                $this->isMoveCommand(FleetCommandType::from((int)$command['type']))
                && is_numeric($command['estimated_time'])
                && (float)$command['estimated_time'] > 0.0
            ) {
                $previousMoveEnd = (float)$command['estimated_time'];
            }
        }

        $segments = [];
        $previousEnd = $previousMoveEnd;
        $activeSegment = null;
        $estimated = false;
        foreach ($moveCommands as $index => $command) {
            $startedAt = is_numeric($command['started_time'])
                ? (float)$command['started_time']
                : null;
            $startEstimated = (bool)$command['start_time_estimated'];
            if ($startedAt === null || $startedAt <= 0.0) {
                $startedAt = $previousEnd;
                if ($startedAt === null || $startedAt <= 0.0) {
                    $createdAt = $commandCreateTimes[(int)$command['id']] ?? 0.0;
                    $startedAt = $createdAt > 0.0 ? $createdAt : null;
                }
                $startEstimated = true;
            }

            $endsAt = is_numeric($command['estimated_time'])
                ? (float)$command['estimated_time']
                : null;
            if ($endsAt !== null && $endsAt <= 0.0) {
                $endsAt = null;
            }

            $segmentEstimated = $startEstimated || (bool)$command['time_estimated'];
            $estimated = $estimated || $segmentEstimated;
            if ($command['status'] === 'active') {
                $activeSegment = $index + 1;
            }

            $segments[] = [
                'id' => (int)$command['id'],
                'position' => (int)$command['position'],
                'type' => (int)$command['type'],
                'key' => (string)$command['key'],
                'lang' => (string)$command['lang'],
                'status' => (string)$command['status'],
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'fuel_cost' => $command['fuel_cost'] ?? null,
                'estimated' => $segmentEstimated,
            ];
            if ($endsAt !== null) {
                $previousEnd = $endsAt;
            }
        }

        $missionStart = $fleet->getStartCoordinate();
        $missionTarget = $fleet->getTargetCoordinate();
        $routeStart = $isReturn ? $missionTarget : $missionStart;
        $routeTarget = $isReturn ? $missionStart : $missionTarget;

        return [
            'direction' => $isReturn ? 'return' : 'outbound',
            'start' => $routeStart?->toArray(),
            'target' => $routeTarget?->toArray(),
            'started_at' => $segments[0]['started_at'],
            'arrives_at' => $segments[array_key_last($segments)]['ends_at'],
            'estimated' => $estimated,
            'active_segment' => $activeSegment,
            'segments_total' => count($segments),
            'segments' => $segments,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeFleetCommandParams(string|array $params): array
    {
        if (is_array($params)) {
            return $params;
        }
        if ($params === '') {
            return [];
        }

        $decoded = json_decode($params, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $params */
    private function getProjectionCommandTarget(array $params): ?Coordinate
    {
        $coordinate = $params['target'] ?? $params['at'] ?? null;
        return is_array($coordinate) ? Coordinate::fromArray($coordinate) : null;
    }

    /** @return array<string, mixed>|null */
    private function serializeProjectionTarget(?Coordinate $target): ?array
    {
        if ($target === null) {
            return null;
        }
        if ($target->getType() === CoordinateType::FLEET) {
            return [
                'type' => CoordinateType::FLEET->value,
                'fleetId' => $target->getFleetId(),
            ];
        }

        return $target->toArray();
    }

    private function isMoveCommand(FleetCommandType $type): bool
    {
        return in_array($type, [
            FleetCommandType::MOVE_IN_SYSTEM,
            FleetCommandType::MOVE_BETWEEN_SYSTEMS,
            FleetCommandType::MOVE_BETWEEN_GALAXIES,
        ], true);
    }

    private function isImmediateProjectionCommand(FleetCommandType $type): bool
    {
        return in_array($type, [
            FleetCommandType::LOAD,
            FleetCommandType::UNLOAD,
            FleetCommandType::STAY,
        ], true);
    }

    /** @param array<string, mixed> $params */
    private function getProjectionSpeedFactor(array $params): float
    {
        $speedFactor = $params['speedFactor'] ?? Config::getValue('FleetSpeedFactor') ?? 1.0;
        if (!is_numeric($speedFactor)) {
            throw new \LogicException('Некорректный speedFactor в FleetCommand.');
        }

        $speedFactor = (float)$speedFactor;
        if (!is_finite($speedFactor) || $speedFactor < 1.0 || $speedFactor > 10.0) {
            throw new \LogicException('speedFactor FleetCommand должен быть в диапазоне 1..10.');
        }

        return $speedFactor;
    }

    /**
     * Собирает список кораблей, находящихся на планете (ангар)
     */
    private function buildPlanetShips(AccountData &$AccountData): array
    {
        $Ships = &$AccountData['Ships'];
        $PlanetFleet = [];

        foreach (Vars::$reslist['fleet'] as $ShipId) {
            $count = $Ships[Vars::$resource[$ShipId]] ?? 0;

            if (Vars::$attributes[$ShipId]['speed'] > 0)
                $PlanetFleet[$ShipId] = [
                    'id'            => $ShipId,
                    'name'          => Vars::$resource[$ShipId],
                    'count'         => $count,
                    'capacity'      => Vars::$attributes[$ShipId]['capacity'],
                    'speed'         => FleetFunctions::GetShipSpeed($ShipId, $AccountData),
                    'consumption'   => FleetFunctions::GetShipConsumption($ShipId, $AccountData),
                    'structure'     => Vars::$attributes[$ShipId]['cost'][901] + Vars::$attributes[$ShipId]['cost'][902],
                    'attack'        => Vars::$CombatCaps[$ShipId]['attack'],
                    'shield'        => Vars::$CombatCaps[$ShipId]['shield'],
                ];
        }

        return $PlanetFleet;
    }

    /**
     * Извлекает ресурсы из строки флота
     */
    private function extractResources(Fleet $fleet): array
    {
        $resources = [];

        foreach ($fleet->getResources() as $resource) {
            $resources[$resource->getID()] = [
                'id' => $resource->getID(),
                'name' => $resource->getName(),
                'count' => $resource->getCount(),
            ];
        }

        return $resources;
    }

    /**
     * Ограничения навигации для выбранной системы.
     * Граница системы намеренно не передаётся клиенту как доступная цель.
     */
    private function buildNavigationSystemInfo(AccountData $accountData): array
    {
        $galaxy = (int)$this->Msg->getData('galaxy', 0);
        $system = (int)$this->Msg->getData('system', 0);
        $maxGalaxy = (int)Config::getValue('MaxGalaxy');
        $maxSystem = (int)Config::getValue('MaxSystem');

        if (
            $galaxy < 1
            || $system < 1
            || ($maxGalaxy > 0 && $galaxy > $maxGalaxy)
            || ($maxSystem > 0 && $system > $maxSystem)
        ) {
            return [
                'valid' => false,
                'galaxy' => $galaxy,
                'system' => $system,
                'message' => 'Система недоступна.',
            ];
        }

        $systemData = Galaxy::getSystem($galaxy, $system);
        if (!$systemData) {
            return [
                'valid' => false,
                'galaxy' => $galaxy,
                'system' => $system,
                'message' => 'Система не найдена.',
            ];
        }

        $expeditionOrbit = null;
        $fleetId = (int)$this->Msg->getData('fleetId', 0);
        $fleet = $fleetId > 0 ? $accountData->getFleetsData()->get($fleetId) : null;
        $ownerId = (int)($accountData['User']['id'] ?? 0);

        if ($fleet !== null && $fleet->getOwnerId() === $ownerId) {
            $current = $fleet->getCurrentCoordinate();
            if ($current->getType() === CoordinateType::FLEET) {
                $current = $current->getLastKnownPosition();
            }

            if (
                $current !== null
                && $current->getGalaxy() === $galaxy
                && $current->getSystem() === $system
            ) {
                $expedition = GalaxyGenerator::getSpecialOrbit(
                    $galaxy,
                    $system,
                    GalaxyOrbits::EXPEDITION
                );
                $expeditionOrbit = (int)$expedition['orbit'];
            }
        }

        return [
            'valid' => true,
            'galaxy' => $galaxy,
            'system' => $system,
            'minOrbit' => 1,
            'maxOrbit' => (int)$systemData['max_orbits'],
            'expeditionOrbit' => $expeditionOrbit,
        ];
    }

}
