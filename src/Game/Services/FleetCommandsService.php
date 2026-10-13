<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

class FleetCommandsService
{
    /**
     * Получить все команды для флота
     */
    public static function getFleetCommands(int $fleetId): array
    {
        $queue = FleetCommands::getFleetQueue($fleetId);
        return array_values(array_map(fn($cmd) => [
            'id' => $cmd['id'],
            'type' => $cmd['command_type'],
            'key' => FleetCommandType::from($cmd['command_type'])->getLangKey(),
            'params' => self::decodeParams($cmd['params'] ?? ''),
            'execute_time' => $cmd['execute_time'],
            'executed' => (bool)$cmd['executed'],
        ], $queue));
    }

    private static function decodeParams(string|array $params): array
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

    /**
     * Установить цепочку команд.
     *
     * Команды добавляются в локальном порядке массива. До активации у всех
     * execute_time равен нулю; расчёт времени выполняется позднее для
     * конкретной следующей команды от состояния Fleet.
     * Пример payload:
     * [
     *   { "type": 10, "params": {"target": Coordinate} },
     *   { "type": 20, "params": {"target": Coordinate} }
     * ]
     */
    public static function setFleetCommands(int $fleetId, array $commands): array
    {
        FleetCommands::clearFleetQueue($fleetId);

        foreach ($commands as $cmd) {
            $type = $cmd['type'] instanceof FleetCommandType
                ? $cmd['type']
                : FleetCommandType::from((int)$cmd['type']);
            $params = $cmd['params'] ?? [];

            FleetCommands::addCommand(
                $fleetId,
                $type,
                $params
            );
        }

        return self::getFleetCommands($fleetId);
    }

    /**
     * Получить следующую команду, которую можно активировать для Fleet.
     */
    public static function getNextPendingCommand(int $fleetId): ?array
    {
        return FleetCommands::getNextPendingCommand($fleetId);
    }

    /**
     * Получить target команды в типизированном виде.
     */
    public static function getCommandTarget(array $command): ?Coordinate
    {
        $params = self::decodeParams($command['params'] ?? '');
        if (!isset($params['target']) || !is_array($params['target'])) {
            return null;
        }

        return Coordinate::fromArray($params['target']);
    }

    /**
     * Проверить и нормализовать высокоуровневый запрос отправки миссии.
     * Клиент не передаёт готовые FleetCommand и server timestamp.
     *
     * Execution реализован для TRANSPORT и RELOCATE.
     *
     * @return array<string, mixed>
     */
    public static function prepareMissionRequest(
        Fleet $fleet,
        AccountData $accountData,
        int $missionType,
        array $coordinates,
        mixed $speedFactor,
        mixed $fuel,
        array $resources
    ): array {
        $mission = FleetMissionType::tryFrom($missionType);
        if (!in_array($mission, [FleetMissionType::TRANSPORT, FleetMissionType::RELOCATE], true)) {
            throw new \InvalidArgumentException('Выбранная миссия пока не поддерживает отправку.');
        }

        $ownerId = (int)($accountData['User']['id'] ?? 0);
        if ($ownerId <= 0 || $fleet->getOwnerId() !== $ownerId) {
            throw new \InvalidArgumentException('Флот недоступен.');
        }
        if ($fleet->getStatus() !== Fleet::STATUS_IDLE) {
            throw new \LogicException('Флот уже выполняет миссию.');
        }
        if (FleetCommands::getFleetQueue($fleet->getId()) !== []) {
            throw new \LogicException('У флота уже есть очередь команд.');
        }

        $speedFactor = self::normalizeSpeedFactor($speedFactor);
        $fuel = self::normalizeAmount($fuel, 'fuel');
        $resources = self::normalizeResources($resources);
        if ($mission === FleetMissionType::TRANSPORT && array_sum($resources) <= 0.0) {
            throw new \InvalidArgumentException('Для транспортной миссии нужно выбрать ресурсы.');
        }

        $target = FleetTarget::fromCoordinates($coordinates);
        $available = $fleet->getAvailableMissions($target, $accountData);
        if (!in_array($mission->value, $available['missions'] ?? [], true)) {
            throw new \InvalidArgumentException('Выбранная миссия недоступна для указанной цели.');
        }

        $targetCoordinate = Coordinate::fromArray($target->toCoordinates());
        if (
            $targetCoordinate->getType() !== CoordinateType::OBJECT
            || !in_array($targetCoordinate->getObjectType(), [ObjectType::PLANET, ObjectType::MOON], true)
        ) {
            throw new \InvalidArgumentException('Миссия может быть отправлена только на планету или луну.');
        }

        $sourcePlanet = Planets::findById($fleet->getHomePlanetId());
        if (!$sourcePlanet || (int)$sourcePlanet['owner_id'] !== $ownerId) {
            throw new \LogicException('Исходная планета флота недоступна.');
        }

        $sourceCoordinate = Coordinate::object(
            galaxy: (int)$sourcePlanet['galaxy'],
            system: (int)$sourcePlanet['system'],
            orbit: (int)$sourcePlanet['planet'],
            objectType: ObjectType::from((int)$sourcePlanet['planet_type'])
        );
        if (!self::sameObjectCoordinate($fleet->getCurrentCoordinate(), $sourceCoordinate)) {
            throw new \LogicException('Флот не находится на исходной планете.');
        }

        $targetPlanet = self::findPlanetByCoordinate($targetCoordinate);
        if (!$targetPlanet || (int)($targetPlanet['owner_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Целевая планета недоступна.');
        }
        if (
            $mission === FleetMissionType::RELOCATE
            && (int)$targetPlanet['owner_id'] !== $ownerId
        ) {
            throw new \InvalidArgumentException('Передислокация возможна только на собственную планету или луну.');
        }
        if ((int)$targetPlanet['id'] === (int)$sourcePlanet['id']) {
            throw new \InvalidArgumentException('Исходная и целевая планеты совпадают.');
        }

        $accountData['WorkPlanet'] = (int)$sourcePlanet['id'];
        $planetResources = &$accountData['Resources'];
        foreach ($resources as $resourceId => $amount) {
            if ($amount > (float)($planetResources[$resourceId]['count'] ?? 0.0)) {
                throw new \InvalidArgumentException('На исходной планете недостаточно ресурсов.');
            }
        }
        if (
            $fuel + $resources[903]
            > (float)($planetResources[903]['count'] ?? 0.0)
        ) {
            throw new \InvalidArgumentException('На исходной планете недостаточно дейтерия для груза и топлива.');
        }

        $cargoAfterLoad = $fleet->getUsedCapacity() + array_sum($resources);
        $fuelAfterLoad = $fleet->getFuelCurrent() + $fuel;
        if ($cargoAfterLoad + $fuelAfterLoad > $fleet->getCapacity()) {
            throw new \InvalidArgumentException('Груз и топливо превышают грузоподъёмность флота.');
        }

        $travelInfo = $fleet->getTravelInfo($target, $accountData, $speedFactor, $mission);
        if ($travelInfo === null) {
            throw new \LogicException('Не удалось рассчитать топливо для миссии.');
        }
        $minimumFuel = (float)ceil((float)($travelInfo['fuel']['min'] ?? 0.0));
        $maximumFuel = (float)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));
        if ($maximumFuel <= 0.0 || $minimumFuel > $maximumFuel) {
            throw new \LogicException('Не удалось рассчитать топливо для миссии.');
        }
        if ($fuelAfterLoad < $minimumFuel) {
            throw new \InvalidArgumentException('Загруженного топлива недостаточно для минимальной дальности полёта.');
        }
        $maximumFuelToLoad = max(0.0, $maximumFuel - $fleet->getFuelCurrent());
        if ($fuel > $maximumFuelToLoad) {
            throw new \InvalidArgumentException('Нельзя загрузить больше рассчитанного максимума топлива.');
        }

        return [
            'mission' => $mission,
            'sourcePlanet' => $sourcePlanet,
            'sourceCoordinate' => $sourceCoordinate,
            'targetPlanet' => $targetPlanet,
            'targetCoordinate' => $targetCoordinate,
            'speedFactor' => $speedFactor,
            'fuel' => $fuel,
            'minimumFuel' => $minimumFuel,
            'maximumFuel' => $maximumFuel,
            'resources' => $resources,
        ];
    }

    /**
     * Запустить validated high-level mission через текущую очередь FleetCommand.
     */
    public static function startMission(
        Fleet $fleet,
        AccountData $accountData,
        int $missionType,
        array $coordinates,
        mixed $speedFactor,
        mixed $fuel,
        array $resources,
        float $startTime
    ): array {
        $prepared = self::prepareMissionRequest(
            $fleet,
            $accountData,
            $missionType,
            $coordinates,
            $speedFactor,
            $fuel,
            $resources
        );

        return self::startPreparedMission($fleet, $accountData, $prepared, $startTime);
    }

    /** @param array<string, mixed> $prepared */
    private static function startPreparedMission(
        Fleet $fleet,
        AccountData $accountData,
        array $prepared,
        float $startTime
    ): array {
        if (!is_finite($startTime) || $startTime <= 0.0) {
            throw new \InvalidArgumentException('Некорректное время старта миссии.');
        }

        $sourcePlanetId = (int)$prepared['sourcePlanet']['id'];
        $accountData['WorkPlanet'] = $sourcePlanetId;

        $resourceSnapshot = [];
        foreach ([901, 902, 903] as $resourceId) {
            $resourceSnapshot[$resourceId] = (float)$accountData['Resources'][$resourceId]['count'];
        }
        $fleetResourceSnapshot = [];
        foreach ($fleet->getResources() as $resourceId => $resource) {
            $fleetResourceSnapshot[(int)$resourceId] = $resource->getCount();
        }
        $fleetSnapshot = [
            'start' => $fleet->getStartCoordinate(),
            'current' => $fleet->getCurrentCoordinate(),
            'currentTime' => $fleet->getCurrentPositionTime(),
            'target' => $fleet->getTargetCoordinate(),
            'targetSnapshotTime' => $fleet->getTargetSnapshotTime(),
            'mission' => $fleet->getMission(),
            'status' => $fleet->getStatus(),
            'isReturn' => $fleet->isReturn(),
            'fuelTotal' => $fleet->getFuelTotal(),
            'fuelCurrent' => $fleet->getFuelCurrent(),
        ];

        $commands = match ($prepared['mission']) {
            FleetMissionType::TRANSPORT => FleetMissionBuilder::buildTransport(
                $prepared['sourceCoordinate'],
                $prepared['targetCoordinate'],
                ['return' => true]
            ),
            FleetMissionType::RELOCATE => FleetMissionBuilder::buildRelocate(
                $prepared['sourceCoordinate'],
                $prepared['targetCoordinate']
            ),
            default => throw new \LogicException('Для выбранной миссии отсутствует command pipeline.'),
        };
        $isRelocation = $prepared['mission'] === FleetMissionType::RELOCATE;
        $returnLeg = false;
        $unloadIndex = 0;
        foreach ($commands as &$command) {
            $type = $command['type'];
            if ($type === FleetCommandType::LOAD) {
                $command['params']['sourcePlanetId'] = $sourcePlanetId;
                $command['params']['resources'] = $prepared['resources'];
                $command['params']['fuel'] = $prepared['fuel'];
            } elseif (self::isMoveType($type)) {
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $command['params']['returnLeg'] = $returnLeg;
            } elseif ($type === FleetCommandType::UNLOAD) {
                $unloadIndex++;
                $command['params']['unloadFuel'] = $isRelocation || $unloadIndex > 1;
                if (!$isRelocation && $unloadIndex === 1) {
                    $returnLeg = true;
                }
            }
        }
        unset($command);

        try {
            $fleet->setMission($prepared['mission']);
            $fleet->setStatus(Fleet::STATUS_MISSION);
            $fleet->setReturn(false);

            return self::startFleetCommands(
                $fleet,
                $commands,
                max($startTime, $fleet->getCurrentPositionTime()),
                $accountData
            );
        } catch (\Throwable $exception) {
            try {
                FleetCommands::clearFleetQueue($fleet->getId());
                foreach ($resourceSnapshot as $resourceId => $count) {
                    $accountData['Resources'][$resourceId]['count'] = $count;
                }
                foreach ($fleetResourceSnapshot as $resourceId => $count) {
                    $fleet->getResources()[$resourceId]->setCount($count);
                }
                $fleet->setFuelCurrent(0.0);
                $fleet->setFuelTotal($fleetSnapshot['fuelTotal']);
                $fleet->setFuelCurrent($fleetSnapshot['fuelCurrent']);
                $fleet->setStartCoordinate($fleetSnapshot['start']);
                $fleet->setCurrentCoordinate($fleetSnapshot['current'], $fleetSnapshot['currentTime']);
                $fleet->setTargetCoordinate(
                    $fleetSnapshot['target'],
                    $fleetSnapshot['targetSnapshotTime']
                );
                $fleet->setMission($fleetSnapshot['mission']);
                $fleet->setStatus($fleetSnapshot['status']);
                $fleet->setReturn($fleetSnapshot['isReturn']);
                $accountData->save();
                $fleet->save();
            } catch (\Throwable $rollbackException) {
                Logger::getInstance()->error('CRITICAL: rollback отправки Fleet mission завершился ошибкой', [
                    'fleetId' => $fleet->getId(),
                    'message' => $rollbackException->getMessage(),
                    'originalMessage' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Запустить новую цепочку команд от текущего физического состояния Fleet.
     *
     * Это backend lifecycle API: frontend не рассчитывает startTime и не
     * активирует команды напрямую.
     */
    public static function startFleetCommands(
        Fleet $fleet,
        array $commands,
        float $startTime,
        ?AccountData $accountData = null
    ): array {
        if (!is_finite($startTime) || $startTime <= 0) {
            throw new \InvalidArgumentException('Время старта миссии должно быть положительным timestamp.');
        }

        if ($startTime < $fleet->getCurrentPositionTime()) {
            throw new \LogicException('Миссия не может начаться раньше current_position_time Fleet.');
        }

        $startCoordinate = $fleet->getCurrentCoordinate();
        $fleet->setStartCoordinate($startCoordinate);
        $fleet->setCurrentCoordinate($startCoordinate, $startTime);

        self::setFleetCommands($fleet->getId(), $commands);

        $firstTarget = null;
        foreach (self::getFleetCommands($fleet->getId()) as $command) {
            if (!self::isMoveType(FleetCommandType::from((int)$command['type']))) {
                continue;
            }

            $firstTarget = self::getCommandTarget([
                'params' => $command['params'],
            ]);
            if ($firstTarget !== null) {
                break;
            }
        }

        $flight = new FleetFlightService();
        if (
            $firstTarget !== null
            && $flight->isStationaryOrbitCoordinate($firstTarget)
            && $firstTarget->getAngle() === null
        ) {
            $startPoint = $flight->resolvePhysicalPoint($startCoordinate, $startTime);
            $firstTarget = Coordinate::object(
                galaxy: $firstTarget->getGalaxy(),
                system: $firstTarget->getSystem(),
                orbit: $firstTarget->getOrbit(),
                objectType: null,
                angle: $startPoint->angle
            );
        }

        $fleet->setTargetCoordinate(
            $firstTarget,
            $firstTarget?->getType() === CoordinateType::FLEET ? $startTime : null
        );
        $fleet->save();

        self::activateNextCommand($fleet, $accountData);
        $fleet->save();

        return self::getFleetCommands($fleet->getId());
    }

    /**
     * Активировать следующую pending-команду Fleet.
     */
    public static function activateNextCommand(
        Fleet $fleet,
        ?AccountData $accountData = null
    ): ?array {
        $activeCommands = FleetCommands::getActiveCommands($fleet->getId());
        if ($activeCommands !== []) {
            $active = $activeCommands[0];
            throw new \LogicException(
                "У Fleet #{$fleet->getId()} уже есть ACTIVE команда #{$active['id']} "
                . 'или повреждённая очередь с несколькими ACTIVE командами.'
            );
        }

        $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());

        while (($pending = FleetCommands::getNextPendingCommand($fleet->getId())) !== null) {
            $type = FleetCommandType::from((int)$pending['command_type']);
            if (!$type instanceof FleetCommandType) {
                throw new \LogicException("Неизвестный тип FleetCommand #{$pending['id']}.");
            }

            if (!$type || !self::isMoveType($type)) {
                self::executeImmediateCommand($pending, $fleet, $accountData);
                continue;
            }

            $target = self::getCommandTarget($pending);
            if ($target === null) {
                throw new \LogicException("У MOVE-команды #{$pending['id']} отсутствует params.target.");
            }

            $speed = $fleet->getTotalSpeed($accountData);
            $start = $fleet->getCurrentCoordinate();
            $startTime = $fleet->getCurrentPositionTime();
            $params = self::decodeParams($pending['params'] ?? '');
            $speedFactor = self::normalizeSpeedFactor($params['speedFactor'] ?? null);

            $flight = new FleetFlightService();
            $params = $flight->materializeCommandCoordinates(
                $type,
                $start,
                $target,
                $params,
                $startTime
            );
            $durationTarget = isset($params['resolvedTarget']) && is_array($params['resolvedTarget'])
                ? Coordinate::fromArray($params['resolvedTarget'])
                : $target;
            $duration = $flight->calculateCommandDuration(
                $type,
                $start,
                $durationTarget,
                $startTime,
                $speed,
                $speedFactor
            );

            $executeTime = $startTime + $duration;
            $distance = $flight->calculateCommandGameDistance(
                $type,
                $start,
                $target,
                $params,
                $startTime,
                $executeTime
            );
            $fuelCost = $flight->calculateFuelConsumption(
                $fleet,
                $accountData,
                [$distance],
                $speedFactor
            );
            if ($fuelCost > $fleet->getFuelCurrent()) {
                throw new \LogicException('Во флоте недостаточно топлива для следующего участка маршрута.');
            }

            $previousFuel = $fleet->getFuelCurrent();
            $previousReturn = $fleet->isReturn();
            try {
                $fleet->setFuelCurrent($previousFuel - $fuelCost);
                $fleet->setReturn((bool)($params['returnLeg'] ?? false));
                $params['fuelCost'] = $fuelCost;
                $params['startedAt'] = $startTime;

                $active = self::activateCommand(
                    (int)$pending['id'],
                    $executeTime,
                    $params
                );
                if ($active === null) {
                    throw new \LogicException("Не удалось активировать FleetCommand #{$pending['id']}.");
                }
                $fleet->save();
                return $active;
            } catch (\Throwable $exception) {
                $fleet->setFuelCurrent($previousFuel);
                $fleet->setReturn($previousReturn);
                $fleet->save();
                throw $exception;
            }
        }

        return null;
    }

    /**
     * Сохранить результат активации команды.
     * Расчёт времени и изменение params выполняются вызывающей игровой логикой.
     */
    public static function activateCommand(
        int $commandId,
        float $executeTime,
        ?array $params = null
    ): ?array {
        $command = FleetCommands::getCommand($commandId);
        if ($command === null) {
            return null;
        }

        $activeCommands = FleetCommands::getActiveCommands((int)$command['fleet_id']);
        if ($activeCommands !== [] && !in_array($commandId, array_column($activeCommands, 'id'), true)) {
            $active = $activeCommands[0];
            throw new \LogicException(
                "У Fleet #{$command['fleet_id']} уже есть ACTIVE команда #{$active['id']}."
            );
        }

        return FleetCommands::activateCommand($commandId, $executeTime, $params);
    }

    /**
     * Завершить due MOVE-команду и запустить следующую команду Fleet.
     */
    public static function completeCommand(
        array $command,
        Fleet $fleet,
        ?AccountData $accountData = null
    ): bool {
        if (
            (int)$command['fleet_id'] !== $fleet->getId()
            || (int)$command['executed'] !== 0
            || (float)$command['execute_time'] <= 0.0
        ) {
            throw new \LogicException('Некорректное состояние FleetCommand для завершения.');
        }

        $type = FleetCommandType::from((int)$command['command_type']);
        if (!self::isMoveType($type)) {
            return false;
        }

        $target = self::getCommandTarget($command);
        if ($target === null) {
            throw new \LogicException("У MOVE-команды #{$command['id']} отсутствует params.target.");
        }

        $params = self::decodeParams($command['params'] ?? '');
        $startTime = $fleet->getCurrentPositionTime();
        $endTime = (float)$command['execute_time'];
        $endCoordinate = (new FleetFlightService())->resolveCommandEndCoordinate(
            $type,
            $fleet->getCurrentCoordinate(),
            $target,
            $params,
            $startTime,
            $endTime
        );

        $fleet->setCurrentCoordinate($endCoordinate, $endTime);
        $fleet->save();

        FleetCommands::markExecuted((int)$command['id']);

        self::activateNextCommand($fleet, $accountData);
        $fleet->save();
        return true;
    }

    private static function executeImmediateCommand(
        array $command,
        Fleet $fleet,
        AccountData $accountData
    ): void {
        $type = FleetCommandType::from((int)$command['command_type']);
        $params = self::decodeParams($command['params'] ?? '');
        $mission = $fleet->getMission();
        $notification = null;

        switch ($type) {
            case FleetCommandType::LOAD:
                self::executeLoad($fleet, $accountData, $params);
                break;
            case FleetCommandType::UNLOAD:
                $notification = self::executeUnload($fleet, $accountData, $params, $mission);
                break;
            case FleetCommandType::STAY:
                $notification = self::executeStay($fleet, $params, $mission);
                break;
            default:
                throw new \LogicException("Activation команды {$type->name} пока не реализована.");
        }

        FleetCommands::markExecuted((int)$command['id']);
        if ($type === FleetCommandType::STAY) {
            FleetCommands::clearFleetQueue($fleet->getId());
        }

        self::sendCommandNotification(
            $command,
            $type,
            $mission,
            $fleet,
            $accountData,
            $notification
        );
    }

    /** @param array<string, mixed> $params */
    private static function executeLoad(
        Fleet $fleet,
        AccountData $accountData,
        array $params
    ): void {
        $sourcePlanetId = (int)($params['sourcePlanetId'] ?? 0);
        $sourcePlanet = Planets::findById($sourcePlanetId);
        if (
            !$sourcePlanet
            || (int)$sourcePlanet['owner_id'] !== $fleet->getOwnerId()
            || $sourcePlanetId !== $fleet->getHomePlanetId()
        ) {
            throw new \LogicException('Погрузка отклонена: исходная планета флота недоступна.');
        }

        $resources = self::normalizeResources(
            is_array($params['resources'] ?? null) ? $params['resources'] : []
        );
        $fuel = self::normalizeAmount($params['fuel'] ?? null, 'fuel');
        $accountData['WorkPlanet'] = $sourcePlanetId;

        $planetSnapshot = [];
        $fleetSnapshot = [];
        foreach ($resources as $resourceId => $amount) {
            $planetSnapshot[$resourceId] = (float)($accountData['Resources'][$resourceId]['count'] ?? 0.0);
            $fleetResource = $fleet->getResources()[$resourceId] ?? null;
            if ($fleetResource === null) {
                throw new \LogicException("FleetResource {$resourceId} отсутствует.");
            }
            $fleetSnapshot[$resourceId] = $fleetResource->getCount();
            if ($amount > $planetSnapshot[$resourceId]) {
                throw new \LogicException('На исходной планете недостаточно ресурсов для погрузки.');
            }
        }
        if ($fuel + $resources[903] > $planetSnapshot[903]) {
            throw new \LogicException('На исходной планете недостаточно дейтерия для груза и топлива.');
        }

        $oldFuelTotal = $fleet->getFuelTotal();
        $oldFuelCurrent = $fleet->getFuelCurrent();
        $newFuel = $oldFuelCurrent + $fuel;
        if (
            $fleet->getUsedCapacity()
            + array_sum($resources)
            + $newFuel
            > $fleet->getCapacity()
        ) {
            throw new \LogicException('Груз и топливо превышают грузоподъёмность флота.');
        }

        try {
            foreach ($resources as $resourceId => $amount) {
                $accountData['Resources'][$resourceId]['count'] = $planetSnapshot[$resourceId] - $amount;
            }
            $accountData['Resources'][903]['count'] -= $fuel;

            $fleet->setFuelTotal($newFuel);
            $fleet->setFuelCurrent($newFuel);
            foreach ($resources as $resourceId => $amount) {
                $notLoaded = $fleet->getResources()[$resourceId]->add($amount);
                if ($notLoaded > 0.000001) {
                    throw new \LogicException("Не удалось загрузить ресурс {$resourceId} полностью.");
                }
            }

            $accountData->save();
            $fleet->save();
        } catch (\Throwable $exception) {
            foreach ($planetSnapshot as $resourceId => $count) {
                $accountData['Resources'][$resourceId]['count'] = $count;
            }
            foreach ($fleetSnapshot as $resourceId => $count) {
                $fleet->getResources()[$resourceId]->setCount($count);
            }
            $fleet->setFuelCurrent(0.0);
            $fleet->setFuelTotal($oldFuelTotal);
            $fleet->setFuelCurrent($oldFuelCurrent);
            $accountData->save();
            $fleet->save();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $params */
    private static function executeUnload(
        Fleet $fleet,
        AccountData $ownerAccountData,
        array $params,
        int $mission
    ): ?array {
        $target = isset($params['target']) && is_array($params['target'])
            ? Coordinate::fromArray($params['target'])
            : null;
        if ($target === null) {
            throw new \LogicException('У UNLOAD отсутствует target.');
        }

        $planet = self::findPlanetByCoordinate($target);
        $unloadFuel = (bool)($params['unloadFuel'] ?? false);
        if (!$planet || (int)($planet['owner_id'] ?? 0) <= 0) {
            if (!$unloadFuel) {
                Logger::getInstance()->warning('Цель TRANSPORT исчезла до выгрузки; груз остаётся во Fleet', [
                    'fleetId' => $fleet->getId(),
                    'target' => $target->toArray(),
                ]);
                return null;
            }
            throw new \LogicException('Исходная планета недоступна для возврата груза и топлива.');
        }

        $targetOwnerId = (int)$planet['owner_id'];
        if (
            $mission === FleetMissionType::RELOCATE->value
            && $targetOwnerId !== $fleet->getOwnerId()
        ) {
            throw new \LogicException('Передислокация отклонена: целевой объект больше не принадлежит владельцу Fleet.');
        }
        if ((int)$ownerAccountData['User']['id'] === $targetOwnerId) {
            $targetAccountData = $ownerAccountData;
        } else {
            $targetUser = Users::findById($targetOwnerId);
            $targetAccountId = (int)($targetUser['account_id'] ?? 0);
            if ($targetAccountId <= 0) {
                throw new \LogicException('Аккаунт владельца целевой планеты не найден.');
            }
            $targetAccountData = new AccountData($targetAccountId);
        }
        $targetAccountData['WorkPlanet'] = (int)$planet['id'];

        $planetSnapshot = [];
        $fleetSnapshot = [];
        foreach ([901, 902, 903] as $resourceId) {
            $planetSnapshot[$resourceId] = (float)($targetAccountData['Resources'][$resourceId]['count'] ?? 0.0);
            $fleetResource = $fleet->getResources()[$resourceId] ?? null;
            if ($fleetResource === null) {
                throw new \LogicException("FleetResource {$resourceId} отсутствует.");
            }
            $fleetSnapshot[$resourceId] = $fleetResource->getCount();
        }
        $oldFuelTotal = $fleet->getFuelTotal();
        $oldFuelCurrent = $fleet->getFuelCurrent();

        try {
            foreach ($fleetSnapshot as $resourceId => $amount) {
                if ($amount <= 0.0) {
                    continue;
                }
                $targetAccountData['Resources'][$resourceId]['count'] += $amount;
                $fleet->getResources()[$resourceId]->setCount(0.0);
            }
            if ($unloadFuel && $oldFuelCurrent > 0.0) {
                $targetAccountData['Resources'][903]['count'] += $oldFuelCurrent;
                $fleet->setFuelCurrent(0.0);
                $fleet->setFuelTotal(0.0);
            }

            $targetAccountData->save();
            $fleet->save();

            return [
                'targetAccountData' => $targetAccountData,
                'targetPlanet' => $planet,
                'resources' => $fleetSnapshot,
                'unloadFuel' => $unloadFuel,
            ];
        } catch (\Throwable $exception) {
            foreach ($planetSnapshot as $resourceId => $count) {
                $targetAccountData['Resources'][$resourceId]['count'] = $count;
            }
            foreach ($fleetSnapshot as $resourceId => $count) {
                $fleet->getResources()[$resourceId]->setCount($count);
            }
            $fleet->setFuelTotal(max($oldFuelTotal, $oldFuelCurrent));
            $fleet->setFuelCurrent($oldFuelCurrent);
            $fleet->setFuelTotal($oldFuelTotal);
            $targetAccountData->save();
            $fleet->save();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $params */
    private static function executeStay(Fleet $fleet, array $params, int $mission): array
    {
        $at = isset($params['at']) && is_array($params['at'])
            ? Coordinate::fromArray($params['at'])
            : null;
        if ($at === null || $at->getType() !== CoordinateType::OBJECT) {
            throw new \LogicException('У STAY отсутствует корректная OBJECT-координата.');
        }
        if (!self::sameObjectCoordinate($fleet->getCurrentCoordinate(), $at)) {
            throw new \LogicException('Fleet не находится в точке завершения миссии.');
        }

        $planet = self::findPlanetByCoordinate($at);
        if ($mission === FleetMissionType::RELOCATE->value) {
            if (!$planet || (int)($planet['owner_id'] ?? 0) !== $fleet->getOwnerId()) {
                throw new \LogicException('Передислокация отклонена: целевой объект недоступен владельцу Fleet.');
            }
            $fleet->setHomePlanetId((int)$planet['id']);
        }

        $fleet->setCurrentCoordinate($at, $fleet->getCurrentPositionTime());
        $fleet->setStartCoordinate(null);
        $fleet->setTargetCoordinate(null);
        $fleet->setMission(0);
        $fleet->setStatus(Fleet::STATUS_IDLE);
        $fleet->setReturn(false);
        $fleet->save();

        return [
            'planet' => $planet,
        ];
    }

    /**
     * Отправить уведомление только после успешной игровой мутации и фиксации
     * FleetCommand как выполненной. Ошибка уведомления не должна повторно
     * применять UNLOAD/STAY и блокировать дальнейшую цепочку команд.
     */
    private static function sendCommandNotification(
        array $command,
        FleetCommandType $type,
        int $mission,
        Fleet $fleet,
        AccountData $ownerAccountData,
        ?array $notification
    ): void {
        try {
            if (
                $mission === FleetMissionType::RELOCATE->value
                && $type === FleetCommandType::STAY
                && $notification !== null
                && is_array($notification['planet'] ?? null)
                && (int)$notification['planet']['id'] === $fleet->getHomePlanetId()
            ) {
                Notification::sendFleetRelocated(
                    $ownerAccountData,
                    $fleet->getId(),
                    $notification['planet'],
                    $fleet->getCurrentPositionTime()
                );
                return;
            }

            if (
                $mission === FleetMissionType::TRANSPORT->value
                && $type === FleetCommandType::UNLOAD
                && $notification !== null
                && $notification['unloadFuel'] === false
            ) {
                $sourcePlanet = Planets::findById($fleet->getHomePlanetId());
                if ($sourcePlanet === null) {
                    throw new \LogicException('Исходная планета транспортного Fleet не найдена для уведомления.');
                }

                Notification::sendFleetTransportArrival(
                    $ownerAccountData,
                    $notification['targetAccountData'],
                    $fleet->getId(),
                    $sourcePlanet,
                    $notification['targetPlanet'],
                    $notification['resources'],
                    $fleet->getCurrentPositionTime()
                );
                return;
            }

            if (
                $mission === FleetMissionType::TRANSPORT->value
                && $type === FleetCommandType::STAY
                && $notification !== null
                && is_array($notification['planet'] ?? null)
                && (int)$notification['planet']['id'] === $fleet->getHomePlanetId()
            ) {
                Notification::sendFleetReturned(
                    $ownerAccountData,
                    $fleet->getId(),
                    $notification['planet'],
                    $fleet->getCurrentPositionTime()
                );
            }
        } catch (\Throwable $exception) {
            Logger::getInstance()->error('Не удалось отправить уведомление FleetCommand', [
                'fleetId' => $fleet->getId(),
                'commandId' => (int)($command['id'] ?? 0),
                'commandType' => $type->name,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Удалить все команды флота
     */
    public static function clearFleet(int $fleetId): void
    {
        FleetCommands::clearFleetQueue($fleetId);
    }

    private static function createAccountDataForUser(int $userId): AccountData
    {
        $user = Users::findById($userId);
        $accountId = (int)($user['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \LogicException("Аккаунт владельца Fleet (user #{$userId}) не найден.");
        }

        return new AccountData($accountId);
    }

    private static function findPlanetByCoordinate(Coordinate $coordinate): ?array
    {
        if (
            $coordinate->getType() !== CoordinateType::OBJECT
            || $coordinate->getObjectType() === null
        ) {
            return null;
        }

        return Planets::findByIndex(
            'galaxy_system_planet_type',
            [
                $coordinate->getGalaxy(),
                $coordinate->getSystem(),
                $coordinate->getOrbit(),
                $coordinate->getObjectType()->value,
            ]
        );
    }

    private static function sameObjectCoordinate(Coordinate $left, Coordinate $right): bool
    {
        return $left->getType() === CoordinateType::OBJECT
            && $right->getType() === CoordinateType::OBJECT
            && $left->getGalaxy() === $right->getGalaxy()
            && $left->getSystem() === $right->getSystem()
            && $left->getOrbit() === $right->getOrbit()
            && $left->getObjectType() === $right->getObjectType();
    }

    private static function normalizeSpeedFactor(mixed $value): float
    {
        if ($value === null) {
            $value = (float)(Config::getValue('FleetSpeedFactor') ?? 1.0);
        }
        if (
            !is_int($value)
            && !is_float($value)
            && !(is_string($value) && is_numeric($value))
        ) {
            throw new \InvalidArgumentException('Коэффициент скорости должен быть числом.');
        }

        $value = (float)$value;
        if (!is_finite($value) || $value < 1.0 || $value > 10.0) {
            throw new \InvalidArgumentException('Коэффициент скорости должен быть в диапазоне 1..10.');
        }

        return $value;
    }

    private static function normalizeAmount(mixed $value, string $field): float
    {
        if (
            !is_int($value)
            && !is_float($value)
            && !(is_string($value) && is_numeric($value))
        ) {
            throw new \InvalidArgumentException("{$field} должен быть числом.");
        }

        $value = (float)$value;
        if (!is_finite($value) || $value < 0.0 || floor($value) !== $value) {
            throw new \InvalidArgumentException("{$field} должен быть целым неотрицательным числом.");
        }

        return $value;
    }

    /** @return array<int, float> */
    private static function normalizeResources(array $resources): array
    {
        foreach ($resources as $resourceId => $amount) {
            if (!in_array((int)$resourceId, [901, 902, 903], true)) {
                throw new \InvalidArgumentException('Передан неподдерживаемый ресурс Fleet cargo.');
            }
        }

        $normalized = [];
        foreach ([901, 902, 903] as $resourceId) {
            $normalized[$resourceId] = self::normalizeAmount(
                $resources[$resourceId] ?? 0,
                "resources.{$resourceId}"
            );
        }

        return $normalized;
    }

    private static function isMoveType(FleetCommandType $type): bool
    {
        return in_array(
            $type,
            [
                FleetCommandType::MOVE_IN_SYSTEM,
                FleetCommandType::MOVE_BETWEEN_SYSTEMS,
                FleetCommandType::MOVE_BETWEEN_GALAXIES,
            ],
            true
        );
    }
}
