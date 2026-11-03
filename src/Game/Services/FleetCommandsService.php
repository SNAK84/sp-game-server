<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\FleetAttackGroups;
use SPGame\Game\Repositories\DebrisFields;
use SPGame\Game\Repositories\SpaceDebrisFields;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\ResourceBases;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

class FleetCommandsService
{
    // Точность сохранённого топлива — 6 знаков; доля после боя хранится в DOUBLE.
    private const FUEL_EPSILON = 0.000001;

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

    /** Проверяется до PlayerQueue и повторно при исполнении команды. */
    public static function assertRecall(Fleet $fleet, AccountData $owner): array
    {
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        FleetBattleService::assertFleetAvailable($fleet->getId(), true);
        $command = FleetCommands::getActiveCommand($fleet->getId());
        if ($command === null) {
            $pending = FleetCommands::getNextPendingCommand($fleet->getId());
            if ($pending !== null && (ResourceBaseService::isArrivalCommand($pending)
                    || ResourceBaseService::isPostBattleGuard($fleet)
                    || ((int)$pending['command_type'] === FleetCommandType::MINING->value
                        && isset(self::decodeParams($pending['params'])['miningRejected'])))) $command = $pending;
        }
        $resourceArrival = $command !== null && ResourceBaseService::isArrivalCommand($command);
        $params = $command === null ? [] : self::decodeParams($command['params']);
        $type = $command === null ? null : FleetCommandType::from((int)$command['command_type']);
        if ($fleet->getStatus() !== Fleet::STATUS_MISSION || $fleet->isReturn() || $command === null
            || FleetAttackService::mustWait($command) || !empty($params['recallAfterTransition']) || !empty($params['recalled'])
            || !(self::isMoveType($type) || ($type === FleetCommandType::DEFEND && isset($params['hold']))
                || $type === FleetCommandType::EXPEDITION || $type === FleetCommandType::RESEARCH
                || ($type === FleetCommandType::OBSERVE && isset($params['hold']))
                || $type === FleetCommandType::MINING || $resourceArrival || ResourceBaseService::isPostBattleGuard($fleet))) {
            throw new \LogicException('Отзыв недоступен: флот уже возвращается, отозван или участвует в бою.');
        }
        ResourceBaseService::homeCoordinate($fleet);
        return $command;
    }

    public static function recall(Fleet $fleet, AccountData $owner, float $time): void
    {
        if (!is_finite($time) || $time <= 0) throw new \InvalidArgumentException('Некорректное время отзыва.');
        $command = self::assertRecall($fleet, $owner);
        $type = FleetCommandType::from((int)$command['command_type']);
        if (ResourceBaseService::isPostBattleGuard($fleet)) {
            FleetControlService::commit([$fleet], static function () use ($fleet, $owner, $command, $time): void {
                $time = max($time, (float)$fleet->getBaseFuelState()['time']);
                ResourceBaseService::settleStanding($fleet, $time);
                $fleet->setBaseFuelState(null);
                self::commitRecallReturn($fleet, $owner, $command, $time, true, true);
            });
            return;
        }
        if ($type === FleetCommandType::MINING) {
            if (isset(self::decodeParams($command['params'])['mining'])) $command = FleetMiningRuntimeService::stop($fleet, $time, $owner);
            self::commitRecallReturn($fleet, $owner, $command, $time, true, true);
            return;
        }
        if ($type === FleetCommandType::DEFEND) {
            // Снабжение принимающего объекта завершается существующим due pipeline.
            FleetDefendService::recall($fleet, $owner, $time);
            return;
        }
        if ($type === FleetCommandType::OBSERVE) {
            // Удержание не является переходом: возвращаемся из текущей точки.
            // При отказе возврата восстанавливаем также расход/clock удержания.
            FleetControlService::commit([$fleet], static function () use ($fleet, $owner, $command, $time): void {
                $params = self::decodeParams($command['params']);
                $time = max($fleet->getCurrentPositionTime(), (float)$params['hold']['lastAt'], min($time, (float)$command['execute_time']));
                FleetControlService::settleHold($fleet, $time);
                $fleet->save();
                self::commitRecallReturn($fleet, $owner, FleetCommands::getCommand((int)$command['id']), $time, true);
            });
            return;
        }
        if ($type === FleetCommandType::RESEARCH) {
            FleetControlService::settleHold($fleet, min($time, (float)$command['execute_time']));
            $fleet->save();
            $command = FleetCommands::getCommand((int)$command['id']);
        }
        if (!in_array($type, [FleetCommandType::MOVE_IN_SYSTEM, FleetCommandType::EXPEDITION, FleetCommandType::RESEARCH], true) && $time < (float)$command['execute_time']) {
            // В модели нет координаты «между системами». Не телепортируем флот
            // к точке входа: после текущего перехода он сразу направится на базу.
            $params = self::decodeParams($command['params']);
            $params['recallAfterTransition'] = true;
            FleetCommands::update(['id' => (int)$command['id'],
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            return;
        }
        self::commitRecallReturn($fleet, $owner, $command, $time);
    }

    /** Только адаптер завершённого боя, до снятия его защиты; не WS action. */
    public static function returnInterruptedSurvey(Fleet $fleet, AccountData $owner, array $command, float $time): void
    {
        if (!FleetSurveyService::isBattleReturn($command)) throw new \LogicException('Нет прерванного боем обследования.');
        self::returnAfterResourceBattle($fleet,$owner,$command,$time);
    }

    public static function returnInterruptedResourceDefense(Fleet $fleet, AccountData $owner, array $command, float $time): void
    {
        if (!FleetResourceDefendService::isCommand($command)
            || (FleetControlService::params($command)['pause'] ?? null) !== 'battle') throw new \LogicException('Нет прерванной ресурсной обороны.');
        self::returnAfterResourceBattle($fleet,$owner,$command,$time);
    }

    private static function returnAfterResourceBattle(Fleet $fleet, AccountData $owner, array $command, float $time): void
    {
        $pending = FleetCommands::getNextPendingCommand($fleet->getId());
        if (!is_finite($time) || $time <= 0 || !empty($command['executed']) || (float)$command['execute_time'] !== 0.0
            || $fleet->getOwnerId() !== (int)$owner['User']['id'] || $fleet->getStatus() !== Fleet::STATUS_MISSION
            || (int)$command['fleet_id'] !== $fleet->getId() || ($pending['id'] ?? null) !== $command['id']
            || FleetCommands::getActiveCommand($fleet->getId()) !== null) {
            throw new \LogicException('Нет прерванной команды для автоматического возврата после боя.');
        }
        FleetControlService::commit([$fleet], static function () use ($fleet, $owner, $command, $time): void {
            $fleet->setBaseFuelState(null);
            self::commitRecallReturn($fleet, $owner, $command, $time, true, true);
        });
    }

    /** Локальная компенсация только позиции, топлива и цепочки данного флота. */
    private static function commitRecallReturn(Fleet $fleet, AccountData $owner, array $command, float $time,
        bool $stationary = false, bool $allowFuelPause = false): void
    {
        $oldCurrent = $fleet->getCurrentCoordinate();
        $oldTime = $fleet->getCurrentPositionTime();
        $oldStart = $fleet->getStartCoordinate();
        $oldTarget = $fleet->getTargetCoordinate();
        $oldSnapshotTime = $fleet->getTargetSnapshotTime();
        $oldReturn = $fleet->isReturn();
        $oldFuel = $fleet->getFuelCurrent();
        $oldCommands = FleetCommands::getFleetCommandChain($fleet->getId());
        $oldIds = array_column($oldCommands, 'id');
        $params = self::decodeParams($command['params']);
        $resourceArrival = ResourceBaseService::isArrivalCommand($command);
        $time = $resourceArrival || $stationary ? max($oldTime, $time) : max($oldTime, min($time, (float)$command['execute_time']));
        $current = $resourceArrival || $stationary || in_array((int)$command['command_type'], [FleetCommandType::EXPEDITION->value, FleetCommandType::RESEARCH->value], true)
            ? $oldCurrent : (new FleetFlightService())->calculateCurrentCoordinate($fleet, $command, $time);
        $homeCoordinate = ResourceBaseService::homeCoordinate($fleet);
        $returnCommands = FleetMissionBuilder::buildReturn($current, $homeCoordinate);
        $speedFactor = self::normalizeSpeedFactor($params['speedFactor'] ?? null);
        $duration = (float)$command['execute_time'] - $oldTime;
        $progress = $duration > 0 ? min(1.0, max(0.0, ($time - $oldTime) / $duration)) : 1.0;
        $unusedFuel = $stationary ? 0.0 : (float)($params['fuelCost'] ?? 0) * (1 - $progress);
        if (!$stationary && (int)$command['command_type'] === FleetCommandType::MOVE_IN_SYSTEM->value) {
            $flight = new FleetFlightService();
            $returnTarget = $returnCommands[0]['params']['target'];
            $returnParams = $flight->materializeCommandCoordinates(FleetCommandType::MOVE_IN_SYSTEM,
                $current, $returnTarget, $returnCommands[0]['params'], $time);
            $physicalTarget = isset($returnParams['resolvedTarget'])
                ? Coordinate::fromArray($returnParams['resolvedTarget']) : $returnTarget;
            $returnParams['recallFlight'] = $flight->prepareRecallFlight($fleet, $command, $physicalTarget, $time);
            $returnCommands[0]['params'] = $returnParams;
        }
        try {
            // Отменённые команды остаются диагностируемыми, но больше не исполняются.
            // Не удаляем прежние строки: rollback восстанавливает их обычным update.
            foreach ($oldCommands as $old) {
                if ((int)$old['executed']) continue;
                $oldParams = self::decodeParams($old['params']);
                $oldParams['cancelled'] = true;
                FleetCommands::update(['id' => (int)$old['id'], 'executed' => 1,
                    'params' => json_encode($oldParams, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }
            $fleet->setCurrentCoordinate($current, $time);
            $fleet->setStartCoordinate($homeCoordinate);
            $fleet->setTargetCoordinate($current);
            $fleet->setReturn(true);
            // Полная стоимость участка списана при активации; неиспользованная
            // часть остаётся во флоте. Планетные ресурсы отзыв не меняет.
            $fleet->setFuelCurrent(min($fleet->getFuelTotal(), $oldFuel + $unusedFuel));
            foreach ($returnCommands as $returnCommand) {
                $returnParams = $returnCommand['params'] + ['returnLeg' => true, 'speedFactor' => $speedFactor];
                if ($fleet->getHomeSiteId() > 0) $returnParams['homeSiteId'] = $fleet->getHomeSiteId();
                if ((int)$command['command_type'] === FleetCommandType::EXPEDITION->value) {
                    $returnParams['expeditionTimeFactor'] = FleetExpeditionService::returnFactor($fleet->getId());
                }
                $added = FleetCommands::addCommand($fleet->getId(), $returnCommand['type'], $returnParams);
                if (FleetCommands::findExistingById((int)$added['id']) === null) {
                    FleetCommands::discardUncommittedRow($added);
                    throw new \RuntimeException('Не удалось создать команду возврата.');
                }
            }
            self::activateNextCommand($fleet, $owner);
            $active = FleetCommands::getActiveCommand($fleet->getId());
            $fuelPaused = $active === null && FleetControlService::state($fleet)['reason'] === 'fuel';
            if ($fuelPaused && !$allowFuelPause) {
                throw new \LogicException('Недостаточно топлива для возврата.');
            }
            if ($active === null && !($allowFuelPause && $fuelPaused)) throw new \RuntimeException('Не удалось активировать возврат.');
            $fleet->save();
        } catch (\Throwable $exception) {
            try {
                foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $row) {
                    if (!in_array((int)$row['id'], $oldIds, true)) FleetCommands::delete(['id' => (int)$row['id']]);
                }
                foreach ($oldCommands as $old) FleetCommands::update($old);
                $fleet->setCurrentCoordinate($oldCurrent, $oldTime);
                $fleet->setStartCoordinate($oldStart);
                $fleet->setTargetCoordinate($oldTarget, $oldSnapshotTime);
                $fleet->setReturn($oldReturn);
                $fleet->setFuelCurrent($oldFuel);
                $fleet->save();
            } catch (\Throwable $rollback) {
                Logger::getInstance()->error('CRITICAL: rollback отзыва флота завершился ошибкой',
                    ['fleetId' => $fleet->getId(), 'message' => $rollback->getMessage(), 'originalMessage' => $exception->getMessage()]);
            }
            Logger::getInstance()->error('Не удалось отозвать флот', ['fleetId' => $fleet->getId(), 'message' => $exception->getMessage()]);
            throw $exception;
        }
        if ((int)$command['command_type'] === FleetCommandType::EXPEDITION->value) {
            try { FleetExpeditionService::recalled($fleet,$owner,$time); }
            catch (\Throwable $error) { Logger::getInstance()->error('Итог отозванной экспедиции ожидает доставки',[
                'fleetId'=>$fleet->getId(),'message'=>$error->getMessage(),
            ]); }
        }
    }

    /** Только внутреннее переназначение уже потерянного ресурсного дома. */
    public static function redirectLostBaseReturn(Fleet $fleet, AccountData $owner, float $time): void
    {
        if (!$fleet->isReturn() || $fleet->getHomeSiteId() > 0) return;
        $active = FleetCommands::getActiveCommand($fleet->getId());
        $command = $active ?? FleetCommands::getNextPendingCommand($fleet->getId());
        if ($command === null) return;
        $type = FleetCommandType::from((int)$command['command_type']);
        if ($active !== null && in_array($type, [FleetCommandType::MOVE_BETWEEN_SYSTEMS, FleetCommandType::MOVE_BETWEEN_GALAXIES], true)
            && $time < (float)$command['execute_time']) {
            $params = self::decodeParams($command['params']);
            $params['recallAfterTransition'] = true;
            $params['resourceBaseLost'] = true;
            FleetControlService::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            return;
        }
        // Бой/доставка завершаются своим handler; их будущий возврат помечен homeSiteId.
        if ($active !== null && !self::isMoveType($type) && !ResourceBaseService::isArrivalCommand($command)) return;
        self::commitRecallReturn($fleet, $owner, $command, $time, $active === null, true);
    }

    /** Общие проверки действия: используются обычной отправкой и этапами маршрута. */
    public static function prepareMissionAction(
        Fleet $fleet, AccountData $owner, FleetMissionType $mission, array $coordinates,
        mixed $duration = null, mixed $refuel = false, ?float $at = null, ?Coordinate $origin = null,
        mixed $contactLoss = null, mixed $mining = null
    ): array {
        if (!in_array($mission, [FleetMissionType::ATTACK, FleetMissionType::GROUP_ATTACK,
            FleetMissionType::DESTROY, FleetMissionType::DEFEND, FleetMissionType::TRANSPORT,
            FleetMissionType::RELOCATE, FleetMissionType::SPY, FleetMissionType::RECYCLE,
            FleetMissionType::COLONIZE, FleetMissionType::OBSERVE, FleetMissionType::EXPEDITION, FleetMissionType::MERGE, FleetMissionType::RESEARCH, FleetMissionType::CAPTURE, FleetMissionType::MINING], true)) {
            throw new \InvalidArgumentException('Действие пока не реализовано.');
        }
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        if (!is_bool($refuel)) throw new \InvalidArgumentException('Некорректный режим заправки.');
        $duration = match ($mission) {
            FleetMissionType::DEFEND => FleetDefendService::duration($duration),
            FleetMissionType::EXPEDITION => FleetExpeditionService::duration($duration, $owner),
            default => null,
        };
        if ($mission === FleetMissionType::SPY && ($fleet->getShips()[FleetSpyService::PROBE] ?? null)?->getCount() <= 0) {
            throw new \InvalidArgumentException('Для шпионажа необходимы шпионские зонды.');
        }
        if ($mission === FleetMissionType::RECYCLE && self::getRecyclerCapacity($fleet) <= 0) {
            throw new \InvalidArgumentException('Для переработки необходимы корабли-переработчики.');
        }
        $target = FleetTarget::fromCoordinates($coordinates);
        $fleetDelivery = $target->getCoordinateType() === CoordinateType::FLEET
            && in_array($mission, [FleetMissionType::TRANSPORT, FleetMissionType::MERGE], true);
        $fleetAttack = $target->getCoordinateType() === CoordinateType::FLEET && $mission === FleetMissionType::ATTACK;
        $interception = null;
        if ($fleetAttack) {
            $observation = FleetInterceptionService::targetObservation($fleet, (int)$target->getFleetId(), $at ?? microtime(true), $origin);
            $interception = ['policy' => FleetInterceptionService::policy($owner, $contactLoss)];
        } elseif ($contactLoss !== null) throw new \InvalidArgumentException('Поведение при потере контакта задаётся только для перехвата.');
        $targetCoordinate = $fleetAttack ? Coordinate::fleet((int)$target->getFleetId(), Coordinate::fromArray($observation['position'])) : ($fleetDelivery
            ? FleetRendezvousService::coordinate($fleet->getOwnerId(), (int)$target->getFleetId(), $at ?? microtime(true), $fleet->getId())
            : Coordinate::fromArray($target->toCoordinates()));
        $freeHold = $mission === FleetMissionType::OBSERVE && $targetCoordinate->getType() === CoordinateType::POINT;
        $survey = $mission === FleetMissionType::RESEARCH;
        $resourceBaseAction = $mission === FleetMissionType::CAPTURE
            || ($mission === FleetMissionType::RELOCATE && FleetSurveyService::isResource($targetCoordinate));
        $resourceSpy = $mission === FleetMissionType::SPY && FleetSurveyService::isResource($targetCoordinate);
        $resourceDefend = $mission === FleetMissionType::DEFEND && FleetSurveyService::isResource($targetCoordinate);
        $resourceMining = $mission === FleetMissionType::MINING;
        $miningOptions = null;
        if ($resourceMining) {
            if ($mining !== null && !is_array($mining)) throw new \InvalidArgumentException('Некорректные настройки добычи.');
            $miningOptions = FleetMiningRuntimeService::prepare($fleet, $owner, $targetCoordinate, $mining ?? []);
        }
        if ($resourceSpy && ($reason = FleetSpyService::resourceReason($fleet, $owner, $targetCoordinate)) !== null) {
            throw new \InvalidArgumentException($reason);
        }
        if ($resourceBaseAction) {
            ResourceBaseService::prepareDestination($fleet, $owner, $targetCoordinate, $mission === FleetMissionType::CAPTURE);
        }
        if ($survey && ($reason = FleetSurveyService::reason($fleet, $owner, $targetCoordinate)) !== null) {
            throw new \InvalidArgumentException($reason);
        }
        if ($mission === FleetMissionType::MERGE) {
            if (!$fleetDelivery) throw new \InvalidArgumentException('Для объединения выберите свой флот.');
            FleetMergeService::receiver($fleet, (int)$target->getFleetId());
        }
        $expedition = $mission === FleetMissionType::EXPEDITION;
        if ($expedition && ($reason = FleetExpeditionService::reason($fleet, $owner, $targetCoordinate, $origin)) !== null) {
            throw new \InvalidArgumentException($reason);
        }
        if ($mission === FleetMissionType::OBSERVE && (!$freeHold || !FleetControlService::capabilities($owner)['hold'])) {
            throw new \InvalidArgumentException('Для удержания в свободной точке нужна Флотовая логистика 1.');
        }
        if ($mission === FleetMissionType::COLONIZE
            && ($reason = FleetColonizationService::availabilityReason($fleet, $owner, $targetCoordinate)) !== null) {
            throw new \InvalidArgumentException($reason);
        }
        $available = (new FleetMissionService())->getAvailableMissions($fleet, $target, $owner, $origin, $at);
        if (!in_array($mission->value, $available['missions'] ?? [], true)) {
            throw new \InvalidArgumentException('Выбранная миссия недоступна для указанной цели.');
        }
        $allowed = $mission === FleetMissionType::RECYCLE ? [ObjectType::DEBRIS] : [ObjectType::PLANET, ObjectType::MOON];
        $spaceRecycle = $mission === FleetMissionType::RECYCLE && $targetCoordinate->getType() === CoordinateType::POINT;
        if (!$resourceDefend && !$resourceMining && !$resourceBaseAction && !$resourceSpy && !$survey && !$spaceRecycle && !$fleetAttack && !$fleetDelivery && !$freeHold && !$expedition && ($targetCoordinate->getType() !== CoordinateType::OBJECT
            || !in_array($targetCoordinate->getObjectType(), $allowed, true))) {
            throw new \InvalidArgumentException('Недопустимый объект для выбранной миссии.');
        }
        $targetPlanet = $resourceDefend || $resourceMining || $resourceBaseAction || $resourceSpy || $survey || $spaceRecycle || $fleetAttack || $fleetDelivery || $freeHold || $expedition ? null : self::findPlanetByCoordinate($targetCoordinate);
        if (!$resourceDefend && !$resourceMining && !$resourceBaseAction && !$resourceSpy && !$survey && !$fleetAttack && !$fleetDelivery && !$freeHold && !$expedition
            && !in_array($mission, [FleetMissionType::RECYCLE, FleetMissionType::COLONIZE], true)
            && (!$targetPlanet || (int)$targetPlanet['owner_id'] <= 0)) {
            throw new \InvalidArgumentException('Целевая планета недоступна.');
        }
        if ($mission === FleetMissionType::DEFEND && ($reason = $resourceDefend
            ? FleetResourceDefendService::reason($fleet,$targetCoordinate) : FleetDefendService::reason($fleet, $targetPlanet)) !== null) {
            throw new \InvalidArgumentException($reason);
        }
        if ($mission === FleetMissionType::RELOCATE && !$resourceBaseAction) {
            if ((int)$targetPlanet['owner_id'] !== $fleet->getOwnerId()) throw new \InvalidArgumentException('Передислокация возможна только на собственный объект.');
            FleetsServices::assertBaseSlot($owner, $targetPlanet, $fleet->getId());
        }
        if (!$fleetAttack && !$resourceSpy && in_array($mission, [FleetMissionType::SPY, FleetMissionType::ATTACK, FleetMissionType::GROUP_ATTACK, FleetMissionType::DESTROY], true)
            && (int)$targetPlanet['owner_id'] === $fleet->getOwnerId()) {
            throw new \InvalidArgumentException('Враждебная миссия на собственный объект недоступна.');
        }
        if ($mission === FleetMissionType::DESTROY && ($targetCoordinate->getObjectType() !== ObjectType::MOON
            || FleetDestroyService::deathStars($fleet) <= 0)) {
            throw new \InvalidArgumentException('Уничтожить можно только чужую луну флотом со Звездой смерти.');
        }
        return compact('target', 'targetCoordinate', 'targetPlanet', 'fleetDelivery', 'fleetAttack', 'interception', 'freeHold', 'duration', 'miningOptions');
    }

    /** Проверка отправки: клиент не назначает внутренние команды и server timestamp. */
    public static function prepareMissionRequest(
        Fleet $fleet,
        AccountData $accountData,
        int $missionType,
        array $coordinates,
        mixed $speedFactor,
        mixed $fuel,
        array $resources,
        mixed $duration = null,
        mixed $group = null,
        ?float $startTime = null,
        mixed $refuel = false,
        mixed $contactLoss = null, mixed $mining = null
    ): array {
        $mission = FleetMissionType::tryFrom($missionType);
        if (!in_array($mission, [FleetMissionType::ATTACK, FleetMissionType::GROUP_ATTACK, FleetMissionType::DESTROY, FleetMissionType::DEFEND, FleetMissionType::TRANSPORT, FleetMissionType::RELOCATE, FleetMissionType::SPY, FleetMissionType::RECYCLE, FleetMissionType::COLONIZE, FleetMissionType::OBSERVE, FleetMissionType::EXPEDITION, FleetMissionType::MERGE, FleetMissionType::RESEARCH, FleetMissionType::CAPTURE, FleetMissionType::MINING], true)) {
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
        FleetsServices::assertMissionSlot($accountData);

        $speedFactor = self::normalizeSpeedFactor($speedFactor);
        $fuel = self::normalizeAmount($fuel, 'fuel');
        $resources = self::normalizeResources($resources);
        $expedition = $mission === FleetMissionType::EXPEDITION;
        $duration = match ($mission) {
            FleetMissionType::DEFEND => FleetDefendService::duration($duration),
            FleetMissionType::EXPEDITION => FleetExpeditionService::duration($duration,$accountData),
            default => null,
        };
        $siteHome = $fleet->getHomeSiteId() > 0;
        if ($siteHome && !in_array($mission->value, ResourceBaseService::departureMissions(), true)) {
            throw new \InvalidArgumentException('Эта миссия ещё не подключена для вылета с ресурсной базы.');
        }
        $onboardCargo = $siteHome ? array_sum(array_map(static fn(int $id): float =>
            (float)(($fleet->getResources()[$id] ?? null)?->getCount() ?? 0), [901, 902, 903])) : 0;
        if ($mission === FleetMissionType::TRANSPORT && array_sum($resources) + $onboardCargo <= 0.0) {
            throw new \InvalidArgumentException('Для транспортной миссии нужно выбрать ресурсы.');
        }
        if ($mission === FleetMissionType::SPY) {
            if (($fleet->getShips()[FleetSpyService::PROBE] ?? null)?->getCount() <= 0) {
                throw new \InvalidArgumentException('Для шпионажа необходимы шпионские зонды.');
            }
            if (array_sum($resources) > 0.0) {
                throw new \InvalidArgumentException('В миссии шпионажа загружается только топливо.');
            }
        }
        if ($mission === FleetMissionType::RECYCLE && self::getRecyclerCapacity($fleet) <= 0.0) {
            throw new \InvalidArgumentException('Для переработки необходимы корабли-переработчики.');
        }

        $action = self::prepareMissionAction($fleet, $accountData, $mission, $coordinates, $duration, $refuel, $startTime, contactLoss: $contactLoss, mining: $mining);
        ['target' => $target, 'targetCoordinate' => $targetCoordinate, 'targetPlanet' => $targetPlanet,
            'fleetDelivery' => $fleetDelivery, 'freeHold' => $freeHold] = $action;

        FleetBattleService::assertFleetAvailable($fleet->getId());
        $sourcePlanet = null;
        if ($siteHome) {
            $sourceCoordinate = ResourceBaseService::departureCoordinate($fleet);
            if ($fuel > 0 || array_sum($resources) > 0) {
                throw new \InvalidArgumentException('У ресурсной базы нет общего склада: используйте груз и топливо на борту. Погрузка должна быть нулевой.');
            }
            // Контекст очереди — собственный реальный объект, но не источник ресурсов.
            $queuePlanetId = (int)ResourceBaseService::fallbackPlanet($fleet)['id'];
        } else {
            $sourcePlanet = Planets::findById($fleet->getHomePlanetId());
            if (!$sourcePlanet || (int)$sourcePlanet['owner_id'] !== $ownerId) {
                throw new \LogicException('Исходная планета флота недоступна.');
            }
            FleetBattleService::assertPlanetAvailable((int)$sourcePlanet['id']);
            $sourceCoordinate = Coordinate::object((int)$sourcePlanet['galaxy'], (int)$sourcePlanet['system'],
                (int)$sourcePlanet['planet'], ObjectType::from((int)$sourcePlanet['planet_type']));
            $queuePlanetId = (int)$sourcePlanet['id'];
        }
        if (!self::sameObjectCoordinate($fleet->getCurrentCoordinate(), $sourceCoordinate)) {
            throw new \LogicException('Флот не находится на исходной планете.');
        }
        if ($siteHome && $mission !== FleetMissionType::MINING && $sourceCoordinate->toArray() === $targetCoordinate->toArray()) {
            throw new \InvalidArgumentException('Исходная и целевая ресурсные базы совпадают.');
        }

        if ($sourcePlanet !== null && $targetPlanet !== null && (int)$targetPlanet['id'] === (int)$sourcePlanet['id']) {
            throw new \InvalidArgumentException('Исходная и целевая планеты совпадают.');
        }

        if ($sourcePlanet !== null) {
            $accountData['WorkPlanet'] = (int)$sourcePlanet['id'];
            $planetResources = &$accountData['Resources'];
            foreach ($resources as $resourceId => $amount) {
                if ($amount > (float)($planetResources[$resourceId]['count'] ?? 0.0)) {
                    throw new \InvalidArgumentException('На исходной планете недостаточно ресурсов.');
                }
            }
            if ($fuel + $resources[903] > (float)($planetResources[903]['count'] ?? 0.0)) {
                throw new \InvalidArgumentException('На исходной планете недостаточно дейтерия для груза и топлива.');
            }
        }

        $cargoAfterLoad = $fleet->getUsedCapacity() + array_sum($resources);
        $availableFuel = $fleet->getFuelCurrent();
        if ($siteHome) {
            $clock = $fleet->getBaseFuelState();
            if ($clock === null) throw new \LogicException('Стоянка ресурсной базы ещё не инициализирована.');
            $standing = ResourceBaseService::standingProjection($availableFuel, $clock, $startTime ?? microtime(true));
            if ($standing['due'] !== null && $standing['due'] <= ($startTime ?? microtime(true))) {
                throw new \LogicException('Достигнут резерв эвакуации: дождитесь обновления состояния флота.');
            }
            $availableFuel = $standing['fuel'];
        }
        $fuelAfterLoad = $availableFuel + $fuel;
        if ($cargoAfterLoad + $fuelAfterLoad > $fleet->getCapacity()) {
            throw new \InvalidArgumentException('Груз и топливо превышают грузоподъёмность флота.');
        }

        $travelInfo = $action['fleetAttack']
            ? (new FleetMissionService())->getTravelInfo($fleet, $target, $accountData, $speedFactor, $mission, at: $startTime)
            : $fleet->getTravelInfo($target, $accountData, $speedFactor, $mission);
        if ($travelInfo === null) {
            throw new \LogicException('Не удалось рассчитать топливо для миссии.');
        }
        if ($mission === FleetMissionType::MINING) {
            $estimate = FleetMiningRuntimeService::preview($fleet, $accountData, $targetCoordinate, $action['miningOptions']);
            foreach (['min', 'max'] as $bound) $travelInfo['fuel'][$bound] += $estimate['workFuel'];
        }
        if ($mission === FleetMissionType::DEFEND && FleetSurveyService::isResource($targetCoordinate)) {
            $travelInfo = FleetResourceDefendService::travelFuel($travelInfo,FleetDefendService::fuelRate($fleet,$accountData),$duration);
        }
        if ($mission === FleetMissionType::COLONIZE) {
            self::assertColonizationReturnCapacity($fleet, $travelInfo);
        }
        $minimumFuel = (float)ceil((float)($travelInfo['fuel']['min'] ?? 0.0));
        $maximumFuel = (float)ceil((float)($travelInfo['fuel']['max'] ?? 0.0));
        if ($maximumFuel <= 0.0 || $minimumFuel > $maximumFuel) {
            throw new \LogicException('Не удалось рассчитать топливо для миссии.');
        }
        if ($fuelAfterLoad < $minimumFuel) {
            throw new \InvalidArgumentException('Загруженного топлива недостаточно для минимальной дальности полёта.');
        }
        // Бессрочное удержание/стоянка базы не имеют конечного расхода:
        // дополнительный запас ограничен баком/грузом. Остальные миссии без изменений.
        $resourceBaseAction = in_array($mission, [FleetMissionType::CAPTURE, FleetMissionType::RELOCATE], true)
            && FleetSurveyService::isResource($targetCoordinate);
        $maximumFuelToLoad = max(0.0, ($freeHold || $resourceBaseAction ? $fleet->getCapacity() - $cargoAfterLoad : $maximumFuel) - $fleet->getFuelCurrent());
        if ($fuel > $maximumFuelToLoad) {
            throw new \InvalidArgumentException('Нельзя загрузить больше рассчитанного максимума топлива.');
        }

        $groupPlan = null;
        if ($mission === FleetMissionType::GROUP_ATTACK) {
            $time = $startTime ?? microtime(true);
            $groupPlan = FleetGroupAttackService::prepare($fleet, $targetPlanet, $group, $time, $travelInfo);
            $flight = new FleetFlightService();
            $params = $flight->materializeCommandCoordinates(FleetCommandType::MOVE_IN_SYSTEM,
                $sourceCoordinate, $targetCoordinate, [], $time, false);
            FleetGroupAttackService::schedule(FleetCommandType::MOVE_IN_SYSTEM, $sourceCoordinate,
                $targetCoordinate, $params, $time, $fleet->getTotalSpeed($accountData),
                $groupPlan['after']['execute_time'], $speedFactor);
        }

        return [
            'mission' => $mission,
            'sourcePlanet' => $sourcePlanet,
            'queuePlanetId' => $queuePlanetId,
            'sourceSiteId' => $siteHome ? $fleet->getHomeSiteId() : 0,
            'sourceCoordinate' => $sourceCoordinate,
            'targetPlanet' => $targetPlanet,
            'targetCoordinate' => $targetCoordinate,
            'speedFactor' => $speedFactor,
            'fuel' => $fuel,
            'minimumFuel' => $minimumFuel,
            'maximumFuel' => $maximumFuel,
            'resources' => $resources,
            'duration' => $duration,
            'mining' => $action['miningOptions'],
            'group' => $groupPlan,
            'refuel' => $fleetDelivery && $refuel,
            'interception' => $action['interception'],
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
        float $startTime,
        mixed $duration = null,
        mixed $group = null,
        mixed $refuel = false,
        mixed $contactLoss = null, mixed $mining = null
    ): array {
        if ($fleet->getHomeSiteId() > 0) {
            // В отличие от стартовой погрузки с планеты стоянка уже оплачивалась
            // до текущего tick: не отправляем ресурсный флот задним числом added_at.
            $startTime = max($startTime, microtime(true), (float)($fleet->getBaseFuelState()['time'] ?? 0));
        }
        $prepared = self::prepareMissionRequest(
            $fleet,
            $accountData,
            $missionType,
            $coordinates,
            $speedFactor,
            $fuel,
            $resources,
            $duration,
            $group,
            $startTime,
            $refuel,
            $contactLoss,
            $mining
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

        $sourcePlanetId = $prepared['sourcePlanet'] === null ? null : (int)$prepared['sourcePlanet']['id'];
        if ($sourcePlanetId !== null) $accountData['WorkPlanet'] = $sourcePlanetId;

        $resourceSnapshot = [];
        foreach ($sourcePlanetId === null ? [] : [901, 902, 903] as $resourceId) {
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
            'baseFuel' => $fleet->getBaseFuelState(),
        ];

        $commands = match ($prepared['mission']) {
            FleetMissionType::MERGE => FleetMissionBuilder::buildMerge($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::RESEARCH => FleetMissionBuilder::buildResearch($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::CAPTURE => FleetMissionBuilder::buildCapture($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::MINING => FleetMissionBuilder::buildMining($prepared['sourceCoordinate'], $prepared['targetCoordinate'], $prepared['mining']),
            FleetMissionType::OBSERVE => FleetMissionBuilder::buildObserve($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::DEFEND => FleetMissionBuilder::buildDefend($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::ATTACK => FleetMissionBuilder::buildAttack($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::GROUP_ATTACK => FleetMissionBuilder::buildGroupAttack($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::DESTROY => FleetMissionBuilder::buildDestroy($prepared['sourceCoordinate'], $prepared['targetCoordinate']),
            FleetMissionType::TRANSPORT => FleetMissionBuilder::buildTransport(
                $prepared['sourceCoordinate'],
                $prepared['targetCoordinate'],
                ['return' => true]
            ),
            FleetMissionType::RELOCATE => FleetMissionBuilder::buildRelocate(
                $prepared['sourceCoordinate'],
                $prepared['targetCoordinate']
            ),
            FleetMissionType::SPY => FleetMissionBuilder::buildSpy(
                $prepared['sourceCoordinate'],
                $prepared['targetCoordinate']
            ),
            FleetMissionType::RECYCLE => FleetMissionBuilder::buildRecycle(
                $prepared['sourceCoordinate'],
                $prepared['targetCoordinate']
            ),
            FleetMissionType::COLONIZE => FleetMissionBuilder::buildColonize(
                $prepared['sourceCoordinate'], $prepared['targetCoordinate']
            ),
            FleetMissionType::EXPEDITION => FleetMissionBuilder::buildExpedition(
                $prepared['sourceCoordinate'], $prepared['targetCoordinate'], ['duration'=>$prepared['duration']]
            ),
            default => throw new \LogicException('Для выбранной миссии отсутствует command pipeline.'),
        };
        $isRelocation = $prepared['mission'] === FleetMissionType::RELOCATE;
        $isSpy = $prepared['mission'] === FleetMissionType::SPY;
        $isRecycle = $prepared['mission'] === FleetMissionType::RECYCLE;
        $isColonization = $prepared['mission'] === FleetMissionType::COLONIZE;
        $returnLeg = false;
        $unloadIndex = 0;
        $groupPlan = $prepared['group'];
        if ($groupPlan !== null && (int)$groupPlan['after']['id'] === 0) {
            $groupPlan['after']['id'] = \SPGame\Game\Repositories\FleetAttackGroups::nextId();
        }
        foreach ($commands as &$command) {
            $type = $command['type'];
            if ($prepared['sourceSiteId'] > 0 && ($returnLeg || ($type === FleetCommandType::STAY
                && ($command['params']['at'] ?? null) == $prepared['sourceCoordinate']))) {
                $command['params']['homeSiteId'] = $prepared['sourceSiteId'];
                $command['params']['speedFactor'] = $prepared['speedFactor'];
            }
            if ($prepared['interception'] !== null && !$returnLeg
                && in_array($type, [FleetCommandType::MOVE_IN_SYSTEM, FleetCommandType::ATTACK], true)) {
                $command['params']['interception'] = $prepared['interception'];
                $command['params']['speedFactor'] = $prepared['speedFactor'];
            }
            if ($type === FleetCommandType::LOAD) {
                $command['params']['sourcePlanetId'] = $sourcePlanetId;
                $command['params']['resources'] = $prepared['resources'];
                $command['params']['fuel'] = $prepared['fuel'];
            } elseif (self::isMoveType($type)) {
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $command['params']['returnLeg'] = $returnLeg;
                if ($groupPlan !== null && !$returnLeg) {
                    $command['params']['groupId'] = $groupPlan['after']['id'];
                    $command['params']['groupExecuteAt'] = $groupPlan['after']['execute_time'];
                    $command['params']['groupSpeedCap'] = $prepared['speedFactor'];
                }
                if ($isColonization && !$returnLeg) $command['params']['colonizationTarget'] = true;
            } elseif ($type === FleetCommandType::CAPTURE
                || ($type === FleetCommandType::STAY && FleetSurveyService::isResource($prepared['targetCoordinate']))) {
                // При отказе в занятии/приписке отзыв сохраняет выбранную скорость.
                $command['params']['speedFactor'] = $prepared['speedFactor'];
            } elseif ($type === FleetCommandType::UNLOAD) {
                $command['params']['refuel'] = $prepared['refuel'];
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $unloadIndex++;
                $command['params']['unloadFuel'] = $isRelocation || $isSpy || $isRecycle || $isColonization
                    || in_array($prepared['mission'], [FleetMissionType::ATTACK, FleetMissionType::GROUP_ATTACK, FleetMissionType::DESTROY, FleetMissionType::DEFEND, FleetMissionType::OBSERVE, FleetMissionType::EXPEDITION, FleetMissionType::RESEARCH, FleetMissionType::MINING], true) || $unloadIndex > 1;
                if (!$isRelocation && $unloadIndex === 1) {
                    $returnLeg = true;
                }
            } elseif ($type === FleetCommandType::OBSERVE) {
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $returnLeg = true;
            } elseif ($type === FleetCommandType::MINING) {
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $command['params']['miningRequest']['speedFactor'] = $prepared['speedFactor'];
                $returnLeg = true;
            } elseif ($type === FleetCommandType::EXPEDITION || $type === FleetCommandType::RESEARCH) {
                // Отзыв во время обследования должен сохранить выбранную скорость.
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $returnLeg = true;
            } elseif ($type === FleetCommandType::DEFEND) {
                $command['params']['duration'] = $prepared['duration'];
                $command['params']['targetOwnerId'] = FleetSurveyService::isResource($prepared['targetCoordinate'])
                    ? (int)ResourceBases::findExistingById((int)$prepared['targetCoordinate']->getSiteId())['owner_id']
                    : (int)$prepared['targetPlanet']['owner_id'];
                $command['params']['speedFactor'] = $prepared['speedFactor'];
                $returnLeg = true;
            } elseif ($type === FleetCommandType::SPY || $type === FleetCommandType::RECYCLE || $type === FleetCommandType::COLONIZE || in_array($type, [FleetCommandType::ATTACK, FleetCommandType::GROUP_ATTACK, FleetCommandType::DESTROY], true)) {
                if ($type === FleetCommandType::GROUP_ATTACK) {
                    $command['params']['groupId'] = $groupPlan['after']['id'];
                    $command['params']['groupExecuteAt'] = $groupPlan['after']['execute_time'];
                }
                // У CAPTURE бой предшествует занятию новой базы, это ещё
                // прямое плечо. Потеря старой базы не отменяет явную цель.
                $returnLeg = empty($command['params']['resourceCapture']);
            }
        }
        unset($command);

        try {
            if ($prepared['sourceSiteId'] > 0) {
                ResourceBaseService::settleStanding($fleet, $startTime);
                if ($fleet->getFuelCurrent() < $prepared['minimumFuel']) throw new \LogicException('После оплаты стоянки недостаточно топлива для миссии.');
                $fleet->setBaseFuelState(null);
            }
            if ($groupPlan !== null) \SPGame\Game\Repositories\FleetAttackGroups::store($groupPlan['after']);
            $fleet->setMission($prepared['mission']);
            $fleet->setStatus(Fleet::STATUS_MISSION);
            $fleet->setReturn(false);

            $result = self::startFleetCommands(
                $fleet,
                $commands,
                max($startTime, $fleet->getCurrentPositionTime()),
                $accountData
            );
            if ($groupPlan !== null && $groupPlan['before'] === null) {
                // Gameplay уже committed: сбой уведомления не должен предлагать повторный вылет.
                try { Notification::sendGroupAttackInvitation($groupPlan['after']); }
                catch (\Throwable $error) {
                    Logger::getInstance()->error('Не удалось отправить приглашение в групповую атаку',
                        ['groupId' => $groupPlan['after']['id'], 'message' => $error->getMessage()]);
                }
            }
            return $result;
        } catch (\Throwable $exception) {
            try {
                if ($groupPlan !== null) {
                    if ($groupPlan['before'] === null) \SPGame\Game\Repositories\FleetAttackGroups::discardUncommittedRow($groupPlan['after']);
                    else \SPGame\Game\Repositories\FleetAttackGroups::store($groupPlan['before']);
                }
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
                $fleet->setBaseFuelState($fleetSnapshot['baseFuel']);
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
        ?AccountData $accountData = null,
        bool $useCargoFuel = false
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

        // Проверяем до enter()/LOAD и любых изменений runtime, даже при прямом вызове.
        if ($useCargoFuel) FleetControlService::assertCargoRefuel($accountData);

        while (($pending = FleetCommands::getNextPendingCommand($fleet->getId())) !== null) {
            if (isset(self::decodeParams($pending['params'])['pause'])) return null;
            $homeSiteId = (int)(self::decodeParams($pending['params'])['homeSiteId'] ?? 0);
            if ($homeSiteId > 0 && $homeSiteId !== $fleet->getHomeSiteId()) {
                // Только неявный путь домой. Явная цель/выгрузка остаётся прежней.
                self::commitRecallReturn($fleet, $accountData, $pending, $fleet->getCurrentPositionTime(), true, true);
                return FleetCommands::getActiveCommand($fleet->getId());
            }
            try {
                $pending = FleetPlanService::enter($pending, $fleet, $accountData);
            } catch (\InvalidArgumentException|\LogicException $error) {
                // Уже начавшийся перелёт не меняем; недоступное следующее действие
                // не пропускаем и не заменяем другой миссией без решения игрока.
                $params = self::decodeParams($pending['params']);
                if (!isset($params['planStep'])) throw $error;
                $params['planError'] = $error->getMessage();
                FleetControlService::patchCommand((int)$pending['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
                FleetControlService::pause($pending, 'plan');
                return null;
            }
            $type = FleetCommandType::from((int)$pending['command_type']);
            if (!$type instanceof FleetCommandType) {
                throw new \LogicException("Неизвестный тип FleetCommand #{$pending['id']}.");
            }

            if (!$type || !self::isMoveType($type)) {
                if ($type === FleetCommandType::MERGE || $type === FleetCommandType::RESEARCH || $type === FleetCommandType::MINING
                    || ($type === FleetCommandType::DEFEND && FleetResourceDefendService::isCommand($pending))
                    || ($type === FleetCommandType::ATTACK && !empty(self::decodeParams($pending['params'])['resourceCapture']))
                    || ResourceBaseService::isArrivalCommand($pending)) {
                    // Побочные эффекты не входят во внешний commit запуска/правки плана или завершения MOVE.
                    FleetControlService::patchCommand((int)$pending['id'], ['execute_time' => $fleet->getCurrentPositionTime()]);
                    return FleetCommands::getCommand((int)$pending['id']);
                }
                if ($type === FleetCommandType::LOAD && isset(self::decodeParams($pending['params'])['loading'])) {
                    // Отдельное событие существующего scheduler: погрузка не попадает
                    // во внешний commit сохранения плана/Resume с другим snapshot ресурсов.
                    FleetControlService::patchCommand((int)$pending['id'], ['execute_time' => $fleet->getCurrentPositionTime()]);
                    return FleetCommands::getCommand((int)$pending['id']);
                }
                if ($type === FleetCommandType::OBSERVE) {
                    return FleetControlService::beginHold($pending, $fleet, $accountData);
                }
                if ($type === FleetCommandType::EXPEDITION) {
                    return FleetExpeditionService::begin($pending,$fleet,$accountData);
                }
                if ($type === FleetCommandType::UNLOAD && (int)(self::decodeParams($pending['params'])['target']['type'] ?? 0) === CoordinateType::FLEET->value) {
                    $deliveryParams = self::decodeParams($pending['params']);
                    try { $receiver = FleetTransferService::receiver($fleet, $deliveryParams); }
                    catch (\InvalidArgumentException|\LogicException) {
                        FleetControlService::pause($pending, 'target');
                        return null;
                    }
                    if (FleetRendezvousService::distance($fleet->getCurrentCoordinate(),
                        FleetRendezvousService::position($receiver, $fleet->getCurrentPositionTime()), $fleet->getCurrentPositionTime()) > .05) {
                        FleetTransferService::redirect($pending, $fleet, $receiver);
                        continue;
                    }
                    if (FleetMiningRuntimeService::hasMining($receiver->getId())) {
                        $deliveryParams['deferredMiningTransfer'] = true;
                        FleetControlService::patchCommand((int)$pending['id'], ['execute_time' => $fleet->getCurrentPositionTime(),
                            'params' => json_encode($deliveryParams, JSON_THROW_ON_ERROR)]);
                        return FleetCommands::getCommand((int)$pending['id']);
                    }
                }
                if ($type === FleetCommandType::DEFEND) {
                    return FleetDefendService::arrive($pending, $fleet, $accountData);
                }
                if (in_array($type, [FleetCommandType::ATTACK, FleetCommandType::GROUP_ATTACK, FleetCommandType::DESTROY], true)) {
                    return FleetAttackService::arrive($pending, $fleet, $accountData);
                }
                if (in_array($type, [FleetCommandType::UNLOAD, FleetCommandType::STAY], true)) {
                    $waiting = FleetAttackService::pauseArrival($pending, $fleet, $accountData);
                    if ($waiting !== null) return $waiting;
                }
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

            $meetingDuration = null;
            if ($target->getType() === CoordinateType::FLEET && isset($params['interception'])) {
                try {
                    $meeting = FleetInterceptionFlightService::prepareMove($pending, $fleet, $accountData, $start, $startTime);
                    $target = $meeting['target']; $meetingDuration = $meeting['duration'];
                    $params = $meeting['params'];
                } catch (\InvalidArgumentException|\LogicException $error) {
                    FleetInterceptionFlightService::pauseBefore($pending, $fleet, 'contact_lost', $error->getMessage());
                    return null;
                }
            } elseif ($target->getType() === CoordinateType::FLEET) {
                try {
                    $receiver = FleetRendezvousService::target($fleet->getOwnerId(), (int)$target->getFleetId(), $fleet->getId());
                    $target = Coordinate::fleet($receiver->getId(), FleetRendezvousService::position($receiver, $startTime));
                    $point = $target->getLastKnownPosition();
                    $sameSystem = $start->getGalaxy() === $point->getGalaxy() && $start->getSystem() === $point->getSystem();
                    if ($sameSystem && $type !== FleetCommandType::MOVE_IN_SYSTEM) {
                        FleetControlService::patchCommand((int)$pending['id'], ['executed' => 1]);
                        continue;
                    }
                    if ($sameSystem) {
                        $meeting = FleetRendezvousService::meeting($fleet, $receiver, $start, $startTime, $speed, $speedFactor);
                        $target = $meeting['target']; $meetingDuration = $meeting['duration'];
                    } elseif ($type !== FleetCommandType::MOVE_IN_SYSTEM) {
                        $params['destination'] = ['galaxy' => $point->getGalaxy(), 'system' => $point->getSystem()];
                        $type = $start->getGalaxy() !== $point->getGalaxy()
                            ? FleetCommandType::MOVE_BETWEEN_GALAXIES : FleetCommandType::MOVE_BETWEEN_SYSTEMS;
                        FleetControlService::patchCommand((int)$pending['id'], ['command_type' => $type->value]);
                    }
                    $params['target'] = $target->toArray();
                } catch (\InvalidArgumentException|\LogicException) {
                    FleetControlService::pause($pending, 'target');
                    return null;
                }
            }

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
            $duration = $meetingDuration ?? $flight->calculateCommandDuration(
                $type,
                $start,
                $durationTarget,
                $startTime,
                $speed,
                $speedFactor,
                $params['recallFlight'] ?? null
            );

            $executeTime = $startTime + $duration;
            if (isset($params['expeditionTimeFactor'])) {
                $duration *= (float)$params['expeditionTimeFactor'];
                $executeTime = $startTime + $duration;
            }
            if (isset($params['groupExecuteAt']) && empty($params['returnLeg'])) {
                $schedule = FleetGroupAttackService::schedule($type, $start, $target, $params,
                    $startTime, $speed, (float)$params['groupExecuteAt'], (float)$params['groupSpeedCap']);
                $duration = $schedule['duration'];
                $executeTime = $startTime + $duration;
                $params['speedFactor'] = $speedFactor = $schedule['speedFactor'];
            }
            if ($type === FleetCommandType::MOVE_IN_SYSTEM) {
                $params = $flight->captureCommandMotion($start, $target, $params, $startTime, $executeTime);
            }
            if (isset($params['recallFlight'])) {
                if (empty($params['returnLeg'])) throw new \LogicException('Кинематика отзыва указана не для возврата.');
                $fuelCost = (float)$params['recallFlight']['fuelCost'];
            } else {
                $distance = $flight->calculateCommandGameDistance($type, $start, $target, $params, $startTime, $executeTime);
                $fuelCost = $flight->calculateFuelConsumption($fleet, $accountData, [$distance], $speedFactor);
            }
            if ($fuelCost - $fleet->getFuelCurrent() > self::FUEL_EPSILON) {
                // Только явное продолжение под FleetControlService::commit().
                // Груз не расходуется автоматически на следующих участках.
                $missingFuel = $fuelCost - $fleet->getFuelCurrent();
                $deuterium = $fleet->getResources()[903] ?? null;
                if ($useCargoFuel && $deuterium !== null && $deuterium->getCount() >= $missingFuel) {
                    // Перемещение внутри общей вместимости: сначала освобождаем груз.
                    $deuterium->setCount($deuterium->getCount() - $missingFuel);
                    $fleet->setFuelTotal(max($fleet->getFuelTotal(), $fuelCost));
                    $fleet->setFuelCurrent($fuelCost);
                }
            }
            if ($fuelCost - $fleet->getFuelCurrent() > self::FUEL_EPSILON) {
                if (($params['fuelPolicy'] ?? null) === 'available' && $type === FleetCommandType::MOVE_IN_SYSTEM
                    && $flight->getLocalObjectTransferDistance($start, $target) === null
                    && $fleet->getFuelCurrent() > self::FUEL_EPSILON) {
                    // Та же прямая и скорость: оплачиваемая доля времени/пути,
                    // а не новый MOVE с Nova-разгоном и минимальными 5 секундами.
                    $stopAt = $startTime + ($executeTime - $startTime) * $fleet->getFuelCurrent() / $fuelCost;
                    if ($stopAt - $startTime > self::FUEL_EPSILON) {
                        $point = $flight->calculateCurrentCoordinate($fleet, array_replace($pending, [
                            'execute_time' => $executeTime, 'params' => $params,
                        ]), $stopAt);
                        $params['fuelStop'] = true;
                        $weight = (float)($params['progressWeight'] ?? 1);
                        $done = (float)($params['progressDone'] ?? 0);
                        $params['progressSpan'] = max(0.0, $weight - $done) * $fleet->getFuelCurrent() / $fuelCost;
                        $params['motionEnd'] = $point->toArray();
                        $executeTime = $stopAt;
                        $fuelCost = $fleet->getFuelCurrent();
                    }
                }
            }
            if ($fuelCost - $fleet->getFuelCurrent() > self::FUEL_EPSILON) {
                FleetControlService::pause($pending, 'fuel');
                return null;
            }

            $previousFuel = $fleet->getFuelCurrent();
            $previousReturn = $fleet->isReturn();
            try {
                $fleet->setFuelCurrent(max(0.0, $previousFuel - $fuelCost));
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

        // Snapshot мог устареть после остановки, редактирования или завершения
        // маршрута. Не применяем его к новой цепочке и не меняем позицию флота.
        if (FleetCommands::getCommand((int)$command['id']) !== $command) {
            return true;
        }

        $type = FleetCommandType::from((int)$command['command_type']);
        if (!self::isMoveType($type)) {
            if ($type === FleetCommandType::ATTACK && !empty(self::decodeParams($command['params'])['resourceCapture'])) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                FleetSpaceBattleService::arriveResource($command, $fleet, $accountData);
                return true;
            }
            if ($type === FleetCommandType::UNLOAD && !empty(self::decodeParams($command['params'])['deferredMiningTransfer'])) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                return FleetTransferService::completeMining($command, $fleet, $accountData);
            }
            if ($type === FleetCommandType::MINING) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                return FleetMiningRuntimeService::complete($command, $fleet, $accountData);
            }
            if (ResourceBaseService::isArrivalCommand($command)) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                return ResourceBaseService::completeArrival($command, $fleet, $accountData);
            }
            if ($type === FleetCommandType::RESEARCH) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                return FleetSurveyService::complete($command, $fleet, $accountData);
            }
            if ($type === FleetCommandType::MERGE) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                return FleetMergeService::execute($command, $fleet, $accountData);
            }
            if ($type === FleetCommandType::LOAD && isset(self::decodeParams($command['params'])['loading'])) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                if (!FleetLoadingService::execute($command, $fleet, $accountData)) return true;
                try {
                    $next = FleetCommands::getNextPendingCommand($fleet->getId());
                    if ($next !== null && in_array((int)$next['command_type'], [10, 11, 12], true)) {
                        $p = self::decodeParams($next['params']);
                        $groupId = (int)($p['groupId'] ?? $p['planStep']['group']['id'] ?? 0);
                        $groups = $groupId > 0 ? [$groupId => FleetAttackGroups::findExistingById($groupId)] : [];
                        FleetControlService::commit([$fleet], static function () use ($fleet, $accountData): void {
                            $active = self::activateNextCommand($fleet, $accountData);
                            if ($active !== null && FleetCommands::getCommand((int)$active['id']) !== $active) {
                                throw new \RuntimeException('Не сохранился перелёт после погрузки.');
                            }
                        }, null, $groups);
                    } else {
                        self::activateNextCommand($fleet, $accountData);
                    }
                    $fleet->save();
                } catch (\Throwable $error) {
                    // Погрузка уже committed. Повтор due события продолжит активацию,
                    // но не спишет ресурсы второй раз благодаря сохранённому receipt.
                    if (FleetCommands::getActiveCommand($fleet->getId()) === null) {
                        FleetControlService::patchCommand((int)$command['id'], ['executed' => 0]);
                    }
                    throw $error;
                }
                return true;
            }
            if ($type === FleetCommandType::EXPEDITION) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                return FleetExpeditionService::complete($command,$fleet,$accountData);
            }
            $params = self::decodeParams($command['params']);
            if ($type === FleetCommandType::OBSERVE && isset($params['hold'])) {
                FleetControlService::exhaustHold($command, $fleet);
                return true;
            }
            if ($type === FleetCommandType::GROUP_ATTACK && isset($params['groupRendezvous'])) {
                if (!FleetGroupAttackService::ready((int)$params['groupId'])) return false;
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                FleetAttackService::arrive($command, $fleet, $accountData);
                return true;
            }
            if ($type === FleetCommandType::DEFEND) {
                if (FleetAttackService::mustWait($command)) return false;
                if (FleetAttackService::isBattleWait($command)) {
                    FleetAttackService::resetWait((int)$command['id'], (float)$command['execute_time']);
                    $command = FleetCommands::getCommand((int)$command['id']);
                }
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                if (FleetResourceDefendService::isCommand($command) && !isset(FleetControlService::params($command)['hold'])) {
                    FleetResourceDefendService::arrive($command,$fleet,$accountData);
                    return true;
                }
                return FleetDefendService::complete($command, $fleet, $accountData);
            }
            if (FleetAttackService::isBattleWait($command) && !FleetAttackService::mustWait($command)) {
                FleetAttackService::resetWait((int)$command['id'], (float)$command['execute_time']);
                self::activateNextCommand($fleet, $accountData);
                $fleet->save();
                return true;
            }
            return false;
        }

        $target = self::getCommandTarget($command);
        if ($target === null) {
            throw new \LogicException("У MOVE-команды #{$command['id']} отсутствует params.target.");
        }

        $params = self::decodeParams($command['params'] ?? '');
        $startTime = $fleet->getCurrentPositionTime();
        $movementStart = $fleet->getCurrentCoordinate();
        $endTime = (float)$command['execute_time'];
        $endCoordinate = (new FleetFlightService())->resolveCommandEndCoordinate(
            $type,
            $fleet->getCurrentCoordinate(),
            $target,
            $params,
            $startTime,
            $endTime
        );

        if (!empty($params['fuelStop'])) {
            FleetControlService::finishFuelLimitedMove($command, $fleet, $endCoordinate, $endTime);
            return true;
        }

        if (!empty($params['recallAfterTransition'])) {
            $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
            self::commitRecallReturn($fleet, $accountData, $command, $endTime, false, !empty($params['resourceBaseLost']));
            return true;
        }

        $fleet->setCurrentCoordinate($endCoordinate, $endTime);
        $fleet->save();

        FleetCommands::markExecuted((int)$command['id']);

        if (!empty($params['stopForLostBase'])) {
            ResourceBaseService::pauseWithoutFallback($fleet, (int)$params['stopForLostBase'], $endTime);
            return true;
        }

        if (!empty($params['stopAfterTransition'])) {
            $next = FleetCommands::getNextPendingCommand($fleet->getId());
            if ($next !== null) {
                $accountData ??= self::createAccountDataForUser($fleet->getOwnerId());
                try { FleetControlService::holdBefore($next, $fleet, $accountData); }
                catch (\Throwable $error) {
                    FleetControlService::patchCommand((int)$command['id'], ['executed' => 0]);
                    throw $error;
                }
            }
            return true;
        }

        $nextBeforeActivation = FleetCommands::getNextPendingCommand($fleet->getId());
        try {
            self::activateNextCommand($fleet, $accountData);
        } catch (\Throwable $exception) {
            if ((int)($nextBeforeActivation['command_type'] ?? 0) === FleetCommandType::EXPEDITION->value) {
                $fleet->setCurrentCoordinate($movementStart,$startTime);
                $fleet->save();
            }
            // Локальный rollback COLONIZE повторяется существующим due scheduler.
            // Уже committed COLONIZE имеет marker и не применяется второй раз.
            if (in_array($fleet->getMission(), [FleetMissionType::COLONIZE->value, FleetMissionType::ATTACK->value, FleetMissionType::GROUP_ATTACK->value, FleetMissionType::DESTROY->value, FleetMissionType::DEFEND->value, FleetMissionType::EXPEDITION->value], true)
                || (($target->getType() === CoordinateType::FLEET || $fleet->getMission() === FleetMissionType::OBSERVE->value
                    || isset(self::decodeParams($nextBeforeActivation['params'] ?? '{}')['planStep']))
                    && FleetCommands::getActiveCommand($fleet->getId()) === null)) {
                FleetCommands::update(['id' => (int)$command['id'], 'executed' => 0]);
            }
            throw $exception;
        }
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
                $notification = self::executeUnload($fleet, $accountData, $params, $mission, $command);
                break;
            case FleetCommandType::STAY:
                $notification = self::executeStay($fleet, $params, $mission);
                break;
            case FleetCommandType::SPY:
                $notification = self::executeSpy($fleet, $accountData, $params, (int)$command['id']);
                break;
            case FleetCommandType::RECYCLE:
                $notification = self::executeRecycle($command, $fleet, $params);
                break;
            case FleetCommandType::COLONIZE:
                $notification = FleetColonizationService::execute($command, $fleet, $accountData, $params);
                break;
            default:
                throw new \LogicException("Activation команды {$type->name} пока не реализована.");
        }

        // Для RECYCLE marker входит в локальный commit вместе с обломками и грузом.
        if (!empty($notification['alreadyExecuted'])) return;
        if (!in_array($type, [FleetCommandType::RECYCLE, FleetCommandType::COLONIZE], true)
            && empty($notification['fleetTransfer']) && empty($notification['planUnload'])) {
            if ($type === FleetCommandType::UNLOAD && $notification !== null && $notification['unloadFuel']) {
                // STAY отправит сообщение после обнуления бака. Результат выгрузки
                // хранится вместе с выполненной командой, а не только в PHP-контексте.
                $params['unloadedFuel'] = $notification['unloadedFuel'];
                $params['unloadedResources'] = $notification['resources'];
                $command['params'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $command['executed'] = 1;
                FleetCommands::update($command);
            } else {
                FleetCommands::markExecuted((int)$command['id']);
            }
        }
        if (is_array($notification)) $notification['fleetName'] = $fleet->getName();
        if (($type === FleetCommandType::SPY && ($notification['report']['destroyed'] ?? false))
            || ($type === FleetCommandType::COLONIZE && ($notification['consumed'] ?? false))) {
            // После гибели нет возврата или возмещения топлива. Удаляем runtime
            // строки и отмечаем DELETE для обычного RepositorySaver.
            FleetCommands::clearFleetQueue($fleet->getId());
            FleetsShips::delete(['id' => $fleet->getId()]);
            Fleets::delete(['id' => $fleet->getId()]);
            FleetsData::getInstance()->remove($fleet->getId(), false);
        } elseif ($type === FleetCommandType::STAY) {
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

    /** Только вместимость кораблей с capability RECYCLE, не транспортов сопровождения. */
    public static function assertColonizationReturnCapacity(Fleet $fleet, array $travel): void
    {
        $ships = FleetColonizationService::returnShips($fleet);
        $capacity = array_sum(array_map(static fn($ship): float => $ship->getCapacityAll(), $ships));
        if ($ships !== [] && $capacity < (float)($travel['colonizationReturnFuel']['max'] ?? INF)) {
            throw new \InvalidArgumentException('После расходования колонизатора оставшимся кораблям не хватит вместимости для топлива возврата. Добавьте транспорт или уменьшите скорость.');
        }
    }

    private static function getRecyclerCapacity(Fleet $fleet): float
    {
        $capacity = 0.0;
        foreach ($fleet->getShips() as $ship) {
            $missions = Vars::$attributes[$ship->getShipTypeId()]['missions'] ?? [];
            if ($ship->getCount() > 0 && is_array($missions)
                && in_array(FleetMissionType::RECYCLE->value, $missions, true)) {
                $capacity += $ship->getCapacityAll();
            }
        }
        return $capacity;
    }

    /** Сбор выполняется с актуального запаса при прилёте, а не со snapshot отправки. */
    private static function executeRecycle(array $command, Fleet $fleet, array $params): array
    {
        $coordinate = isset($params['target']) && is_array($params['target'])
            ? Coordinate::fromArray($params['target']) : null;
        $space = $coordinate?->getType() === CoordinateType::POINT;
        if ($coordinate === null || ($space
            ? FleetRendezvousService::distance($fleet->getCurrentCoordinate(), $coordinate, $fleet->getCurrentPositionTime()) > .05
            : ($coordinate->getObjectType() !== ObjectType::DEBRIS
                || !self::sameObjectCoordinate($fleet->getCurrentCoordinate(), $coordinate)))) {
            throw new \LogicException('RECYCLE выполняется только после прибытия к полю обломков.');
        }
        $commandSnapshot = FleetCommands::getCommand((int)$command['id']);
        if ($commandSnapshot === null || (int)$commandSnapshot['fleet_id'] !== $fleet->getId()
            || (int)$commandSnapshot['executed'] !== 0) {
            throw new \LogicException('Некорректное состояние команды переработки.');
        }
        $recyclerCapacity = self::getRecyclerCapacity($fleet);
        if (!is_finite($recyclerCapacity) || $recyclerCapacity <= 0.0) {
            throw new \LogicException('Во флоте отсутствуют корабли-переработчики.');
        }
        $fieldSnapshot = $space ? null : DebrisFields::at($coordinate->getGalaxy(), $coordinate->getSystem(), $coordinate->getOrbit());
        $spaceSnapshots = $space ? SpaceDebrisFields::atPoint($coordinate) : [];
        ksort($spaceSnapshots); // Одновременные прибытия забирают актуальный остаток по очереди.
        $metal = $space ? array_sum(array_column($spaceSnapshots, 'metal')) : (float)($fieldSnapshot['metal'] ?? 0.0);
        $crystal = $space ? array_sum(array_column($spaceSnapshots, 'crystal')) : (float)($fieldSnapshot['crystal'] ?? 0.0);
        $freeCapacity = $fleet->getFreeCapacity();
        if (!is_finite($metal) || !is_finite($crystal) || $metal < 0.0 || $crystal < 0.0
            || !is_finite($metal + $crystal) || !is_finite($freeCapacity)) {
            throw new \LogicException('Некорректный запас обломков или вместимость флота.');
        }
        $capacity = max(0.0, min($recyclerCapacity, $freeCapacity, $metal + $crystal));
        // Пропорция как в Nova, но без ceil для каждого ресурса: он может переполнить трюм.
        $collectedMetal = $metal + $crystal > 0.0 ? min($metal, $capacity * ($metal / ($metal + $crystal))) : 0.0;
        $collected = $capacity >= $metal + $crystal
            ? [901 => $metal, 902 => $crystal]
            : [901 => $collectedMetal, 902 => min($crystal, max(0.0, $capacity - $collectedMetal))];
        $resourceSnapshot = [];
        foreach ($collected as $resourceId => $amount) {
            $resource = $fleet->getResources()[$resourceId] ?? null;
            if ($resource === null) throw new \LogicException("FleetResource {$resourceId} отсутствует.");
            $resourceSnapshot[$resourceId] = $resource->getCount();
        }
        $time = $fleet->getCurrentPositionTime();
        try {
            if ($capacity > 0.0) {
                if ($space) {
                    $left = $collected;
                    foreach ($spaceSnapshots as $field) {
                        $takeMetal = min($field['metal'], $left[901]);
                        $takeCrystal = min($field['crystal'], $left[902]);
                        SpaceDebrisFields::subtract((int)$field['id'], $takeMetal, $takeCrystal, $time);
                        $left[901] = max(0.0, $left[901] - $takeMetal);
                        $left[902] = max(0.0, $left[902] - $takeCrystal);
                    }
                    if ($left[901] + $left[902] > .000001) throw new \LogicException('Не списаны все собираемые обломки.');
                } else {
                    DebrisFields::subtractResources($coordinate->getGalaxy(), $coordinate->getSystem(),
                        $coordinate->getOrbit(), $collected[901], $collected[902], $time);
                }
                foreach ($collected as $resourceId => $amount) {
                    if ($fleet->getResources()[$resourceId]->add($amount) > 0.000001) {
                        throw new \LogicException('Не удалось полностью загрузить собранные обломки.');
                    }
                }
                $fleet->save();
                // BaseRepository::update не сигнализирует set(false); проверяем фактический runtime.
                $savedFleet = Fleets::getById($fleet->getId());
                foreach ($collected as $resourceId => $amount) {
                    $key = 'resource_' . Vars::$resource[$resourceId];
                    if ($savedFleet === null || abs((float)$savedFleet[$key] - $fleet->getResources()[$resourceId]->getCount()) > 0.000001) {
                        throw new \RuntimeException('Не удалось сохранить груз переработчиков в runtime.');
                    }
                }
            }
            FleetCommands::markExecuted((int)$command['id']);
            if ((int)(FleetCommands::getCommand((int)$command['id'])['executed'] ?? 0) !== 1) {
                throw new \RuntimeException('Не удалось завершить команду переработки в runtime.');
            }
            if ($space && (!in_array((int)$command['id'], FleetCommands::getPendingSync()['update'], true)
                || ($capacity > 0 && !in_array($fleet->getId(), Fleets::getPendingSync()['update'], true)))) {
                throw new \RuntimeException('Не сохранены dirty markers сбора обломков.');
            }
        } catch (\Throwable $exception) {
            try {
                foreach ($resourceSnapshot as $resourceId => $count) {
                    $fleet->getResources()[$resourceId]->setCount($count);
                }
                $fleet->save();
                $savedFleet = Fleets::getById($fleet->getId());
                foreach ($resourceSnapshot as $resourceId => $count) {
                    if ($savedFleet === null || abs((float)$savedFleet['resource_' . Vars::$resource[$resourceId]] - $count) > 0.000001) {
                        throw new \RuntimeException('Не удалось восстановить груз переработчиков.');
                    }
                }
                if ($fieldSnapshot !== null) {
                    DebrisFields::update($fieldSnapshot);
                    if (DebrisFields::findById((int)$fieldSnapshot['id']) !== $fieldSnapshot) {
                        throw new \RuntimeException('Не удалось восстановить запас поля обломков.');
                    }
                }
                foreach ($spaceSnapshots as $field) {
                    SpaceDebrisFields::update($field);
                    if (SpaceDebrisFields::findById((int)$field['id']) !== $field) {
                        throw new \RuntimeException('Не восстановлены обломки в пространстве.');
                    }
                }
                if ($space) {
                    // markExecuted мог изменить индекс даже при set(false), см. B006.
                    FleetCommands::discardUncommittedRow(array_replace($commandSnapshot, ['executed' => 1]));
                    FleetCommands::restoreDeletedRow($commandSnapshot);
                } else FleetCommands::update($commandSnapshot);
                if ((int)(FleetCommands::getCommand((int)$command['id'])['executed'] ?? 1) !== 0) {
                    throw new \RuntimeException('Не удалось восстановить команду переработки.');
                }
            } catch (\Throwable $rollbackException) {
                Logger::getInstance()->error('CRITICAL: rollback RECYCLE завершился ошибкой', [
                    'fleetId' => $fleet->getId(), 'commandId' => (int)$command['id'],
                    'message' => $rollbackException->getMessage(), 'originalMessage' => $exception->getMessage(),
                ]);
                if ($space) {
                    // Не повторяем сбор автоматически при неизвестном результате rollback.
                    try {
                        FleetControlService::patchCommand((int)$command['id'], ['executed' => 0]);
                        FleetControlService::pause(FleetCommands::getCommand((int)$command['id']) ?? $commandSnapshot, 'recycle_rollback');
                    }
                    catch (\Throwable $pauseError) {
                        Logger::getInstance()->error('CRITICAL: не удалось остановить RECYCLE после rollback failure',
                            ['fleetId' => $fleet->getId(), 'message' => $pauseError->getMessage()]);
                    }
                }
            }
            Logger::getInstance()->error('Переработка обломков не применена', [
                'fleetId' => $fleet->getId(), 'commandId' => (int)$command['id'], 'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
        return ['coordinate' => $coordinate->toArray(), 'resources' => $collected, 'spaceDebrisChanged' => $space && $capacity > 0];
    }

    /** Отчёт снимается с состояния цели, актуализированного EventLoop на момент прилёта. */
    private static function executeSpy(Fleet $fleet, AccountData $owner, array $params, int $commandId = 0): array
    {
        $coordinate = isset($params['target']) && is_array($params['target'])
            ? Coordinate::fromArray($params['target']) : null;
        if ($coordinate === null || !self::sameObjectCoordinate($fleet->getCurrentCoordinate(), $coordinate)) {
            throw new \LogicException('SPY выполняется только после прибытия к цели.');
        }
        if (FleetSurveyService::isResource($coordinate)) {
            if (FleetSpyService::resourceReason($fleet, $owner, $coordinate) !== null) {
                return ['coordinate' => $coordinate->toArray(), 'unavailable' => true];
            }
            $site = FleetSurveyService::site($coordinate);
            $base = \SPGame\Game\Repositories\ResourceBases::findExistingById((int)$site['id']);
            $target = self::createAccountDataForUser((int)$base['owner_id']);
            $report = FleetSpyService::inspectResource($fleet, $owner, $target, $site, $fleet->getCurrentPositionTime());
            if ($report['destroyed']) {
                $debris = FleetSpyService::debrisFor($fleet);
                $report['debris'] = $debris;
                $source = md5('resource-spy:' . $fleet->getId() . ':' . $commandId);
                if (array_sum($debris) > 0 && SpaceDebrisFields::findByIndex('battle_id', [$source]) === null) {
                    $point = FleetSurveyService::physicalPoint($coordinate, $fleet->getCurrentPositionTime());
                    $row = SpaceDebrisFields::castRowToSchema(['battle_id' => $source, 'galaxy' => $point->galaxy, 'system' => $point->system,
                        'distance' => $point->radius, 'angle' => $point->angle, 'metal' => $debris[901], 'crystal' => $debris[902],
                        'update_time' => $fleet->getCurrentPositionTime()], true);
                    try {
                        SpaceDebrisFields::add($row);
                        $sync = SpaceDebrisFields::getPendingSync();
                        if (SpaceDebrisFields::findById((int)$row['id']) !== $row
                            || SpaceDebrisFields::findByIndex('battle_id', [$source]) !== $row
                            || !in_array((int)$row['id'], $sync['update'], true)
                            || in_array((int)$row['id'], $sync['delete'], true)) {
                            throw new \RuntimeException('Не подтверждено сохранение обломков шпионского флота.');
                        }
                    } catch (\Throwable $error) {
                        SpaceDebrisFields::discardUncommittedRow($row);
                        throw $error;
                    }
                }
            }
            return ['targetSite' => $site, 'targetAccountData' => $target, 'report' => $report];
        }
        $planet = self::findPlanetByCoordinate($coordinate);
        $ownerId = (int)($planet['owner_id'] ?? 0);
        if ($planet === null || $ownerId <= 0 || $ownerId === $fleet->getOwnerId()) {
            // Цель исчезла/сменила владельца во время полёта: возвращаем флот,
            // не раскрывая данные уже собственного или недоступного объекта.
            return ['coordinate' => $coordinate->toArray(), 'unavailable' => true];
        }
        $target = self::createAccountDataForUser($ownerId);
        $target['WorkPlanet'] = (int)$planet['id'];
        $report = FleetSpyService::inspect($fleet, $owner, $target);
        if ($report['destroyed']) {
            $debris = FleetSpyService::debrisFor($fleet);
            $report['debris'] = $debris;
            DebrisFields::addResources(
                $coordinate->getGalaxy(), $coordinate->getSystem(), $coordinate->getOrbit(),
                $debris[901], $debris[902], $fleet->getCurrentPositionTime()
            );
        }
        return ['targetPlanet' => $planet, 'targetAccountData' => $target, 'report' => $report];
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
        FleetBattleService::assertFleetAvailable($fleet->getId());
        FleetBattleService::assertPlanetAvailable($sourcePlanetId);

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
        int $mission,
        ?array $command = null
    ): ?array {
        $target = isset($params['target']) && is_array($params['target'])
            ? Coordinate::fromArray($params['target'])
            : null;
        if ($target === null) {
            throw new \LogicException('У UNLOAD отсутствует target.');
        }

        if ($target->getType() === CoordinateType::FLEET) {
            $command = FleetCommands::getNextPendingCommand($fleet->getId());
            if ($command === null || (int)$command['command_type'] !== FleetCommandType::UNLOAD->value) {
                throw new \LogicException('Не найдена команда передачи груза.');
            }
            return FleetTransferService::deliver($command, $fleet, $params);
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

        if (array_key_exists('percent', $params)) {
            if ($unloadFuel || $command === null || !self::sameObjectCoordinate($fleet->getCurrentCoordinate(), $target)) {
                throw new \LogicException('Частичная выгрузка разрешена только в фактической точке прибытия.');
            }
            $percent = FleetPlanService::percentage($params['percent']) / 100.0;
            $current = FleetCommands::getCommand((int)$command['id']);
            if ($current === null || (int)$current['fleet_id'] !== $fleet->getId()) throw new \LogicException('Команда выгрузки недоступна.');
            if ((int)$current['executed'] !== 0) return ['alreadyExecuted' => true];
            return FleetControlService::commit([$fleet], static function () use ($fleet, $targetAccountData, $planet, $command, $params, $percent): array {
                $delivered = [];
                foreach ([901, 902, 903] as $id) {
                    $count = $fleet->getResources()[$id]->getCount();
                    $delivered[$id] = $count * $percent;
                    $targetAccountData['Resources'][$id]['count'] += $delivered[$id];
                    $fleet->getResources()[$id]->setCount(max(0.0, $count - $delivered[$id]));
                }
                $targetAccountData->save(); $fleet->save();
                FleetControlService::patchCommand((int)$command['id'], ['executed' => 1,
                    'params' => json_encode($params + ['unloadedResources' => $delivered], JSON_THROW_ON_ERROR)]);
                return ['planUnload' => true, 'targetAccountData' => $targetAccountData, 'targetPlanet' => $planet,
                    'resources' => $delivered, 'unloadFuel' => false, 'unloadedFuel' => 0.0];
            }, $targetAccountData);
        }

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
                'unloadedFuel' => $unloadFuel ? $oldFuelCurrent : 0.0,
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
        $unloadedFuel = null;
        $unloadedResources = [];
        foreach (array_reverse(FleetCommands::getFleetCommandChain($fleet->getId())) as $previous) {
            if (!(int)$previous['executed'] || (int)$previous['command_type'] !== FleetCommandType::UNLOAD->value) continue;
            $unloadParams = self::decodeParams($previous['params']);
            if (!empty($unloadParams['unloadFuel']) && isset($unloadParams['unloadedFuel'], $unloadParams['target'])
                && self::sameObjectCoordinate(Coordinate::fromArray($unloadParams['target']), $at)) {
                $unloadedFuel = (float)$unloadParams['unloadedFuel'];
                $unloadedResources = $unloadParams['unloadedResources'] ?? [];
            }
            break;
        }
        if ($mission === FleetMissionType::RELOCATE->value) {
            if (!$planet || (int)($planet['owner_id'] ?? 0) !== $fleet->getOwnerId()) {
                throw new \LogicException('Передислокация отклонена: целевой объект недоступен владельцу Fleet.');
            }
            $fleet->setHomePlanetId((int)$planet['id']);
        }

        $fleet->setCurrentCoordinate($at, $fleet->getCurrentPositionTime());
        $expeditionResult = FleetExpeditionService::finishReturn($fleet);
        $fleet->setStartCoordinate(null);
        $fleet->setTargetCoordinate(null);
        $fleet->setMission(0);
        $fleet->setStatus(Fleet::STATUS_IDLE);
        $fleet->setReturn(false);
        $fleet->save();

        return [
            'planet' => $planet,
            'unloadedFuel' => $unloadedFuel,
            'unloadedResources' => $unloadedResources,
            'expeditionResult' => $expeditionResult,
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
            if (!empty($notification['fleetTransfer'])) return;
            // Экспедиция могла быть промежуточным этапом, а не последней миссией.
            if ($type === FleetCommandType::STAY && is_array($notification['planet'] ?? null)
                && ($mission === FleetMissionType::EXPEDITION->value || ($notification['expeditionResult'] ?? null) !== null)) {
                FleetExpeditionService::sendPendingSummary($fleet,$ownerAccountData,$fleet->getCurrentPositionTime());
                Notification::sendFleetExpeditionReturned($ownerAccountData, $fleet, $notification, $fleet->getCurrentPositionTime());
                FleetExpeditionService::clear($fleet->getId());
                return;
            }
            if (in_array($mission, [FleetMissionType::DEFEND->value, FleetMissionType::RESEARCH->value, FleetMissionType::MINING->value, FleetMissionType::CAPTURE->value, FleetMissionType::OBSERVE->value], true) && $type === FleetCommandType::STAY
                && is_array($notification['planet'] ?? null)) {
                Notification::sendFleetReturned($ownerAccountData, $fleet->getId(), $notification['planet'], $fleet->getCurrentPositionTime(), $notification['unloadedFuel'], $notification['unloadedResources']);
                return;
            }
            if ($mission === FleetMissionType::ATTACK->value && $type === FleetCommandType::STAY
                && is_array($notification['planet'] ?? null)) {
                Notification::sendFleetReturned($ownerAccountData, $fleet->getId(), $notification['planet'], $fleet->getCurrentPositionTime(), $notification['unloadedFuel'], $notification['unloadedResources']);
                return;
            }
            if ($mission === FleetMissionType::COLONIZE->value && $type === FleetCommandType::COLONIZE && $notification !== null) {
                Notification::sendFleetColonizationResult($ownerAccountData, $fleet->getId(), $notification, $fleet->getCurrentPositionTime());
                return;
            }
            if ($mission === FleetMissionType::COLONIZE->value && $type === FleetCommandType::STAY
                && is_array($notification['planet'] ?? null)) {
                Notification::sendFleetReturned($ownerAccountData, $fleet->getId(), $notification['planet'], $fleet->getCurrentPositionTime(), $notification['unloadedFuel'], $notification['unloadedResources']);
                return;
            }
            if ($mission === FleetMissionType::RECYCLE->value && $type === FleetCommandType::RECYCLE && $notification !== null) {
                if (!empty($notification['spaceDebrisChanged'])) Notification::refreshGalaxySystem(
                    (int)$notification['coordinate']['galaxy'], (int)$notification['coordinate']['system']);
                Notification::sendFleetRecycleResult($ownerAccountData, $fleet->getId(), $notification, $fleet->getCurrentPositionTime());
                return;
            }
            if ($mission === FleetMissionType::RECYCLE->value && $type === FleetCommandType::STAY
                && is_array($notification['planet'] ?? null)) {
                Notification::sendFleetReturned($ownerAccountData, $fleet->getId(), $notification['planet'], $fleet->getCurrentPositionTime(), $notification['unloadedFuel'], $notification['unloadedResources']);
                return;
            }
            if ($mission === FleetMissionType::SPY->value && $type === FleetCommandType::SPY && $notification !== null) {
                if (!empty($notification['targetSite']) && !empty($notification['report']['destroyed'])) {
                    Notification::refreshGalaxySystem((int)$notification['targetSite']['galaxy'], (int)$notification['targetSite']['system']);
                }
                Notification::sendFleetSpyResult($ownerAccountData, $fleet->getId(), $notification, $fleet->getCurrentPositionTime());
                return;
            }
            if ($mission === FleetMissionType::SPY->value && $type === FleetCommandType::STAY
                && is_array($notification['planet'] ?? null)) {
                Notification::sendFleetSpyReturned($ownerAccountData, $fleet->getId(), $notification['planet'], $fleet->getCurrentPositionTime(), $notification['unloadedFuel'], $notification['unloadedResources']);
                return;
            }
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
                    $fleet->getCurrentPositionTime(),
                    $notification['unloadedFuel']
                );
                return;
            }

            if (
                $mission === FleetMissionType::TRANSPORT->value
                && $type === FleetCommandType::UNLOAD
                && $notification !== null
                && $notification['unloadFuel'] === false
            ) {
                $sourcePlanet = self::transportSource($fleet, $command);

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
                    $fleet->getCurrentPositionTime(),
                    $notification['unloadedFuel'],
                    $notification['unloadedResources']
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

    /** Источник доставки, а не изменяемая домашняя база. Старые очереди читаем без записи. */
    private static function transportSource(Fleet $fleet, array $command): array|Coordinate
    {
        $params = self::decodeParams($command['params'] ?? '');
        if (isset($params['transportSource'])) {
            $source = Coordinate::fromArray($params['transportSource']);
        } else {
            // Совместимость с уже летящими миссиями: последовательные MOVE одной
            // доставки не превращают орбиту перехода в её логический источник.
            $cursor = $source = $fleet->getStartCoordinate();
            $moving = false;
            $stepId = null;
            foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $previous) {
                if ((int)$previous['id'] >= (int)$command['id']) break;
                $previousParams = self::decodeParams($previous['params'] ?? '');
                if (!(int)$previous['executed'] || !empty($previousParams['cancelled'])) continue;
                $target = self::getCommandTarget($previous);
                if (self::isMoveType(FleetCommandType::from((int)$previous['command_type']))) {
                    $nextStepId = $previousParams['planStepId'] ?? null;
                    if (!$moving || $nextStepId !== $stepId) $source = $cursor;
                    $stepId = $nextStepId;
                    $moving = true;
                } else {
                    $source = $target ?? $cursor;
                    $moving = false;
                }
                $cursor = $target ?? $cursor;
            }
        }
        if ($source === null) throw new \LogicException('Не найден источник транспортного участка для уведомления.');
        // Сохраняем прежнюю локализацию названия планеты/луны для каждого получателя.
        return in_array($source->getObjectType(), [ObjectType::PLANET, ObjectType::MOON], true)
            ? self::findPlanetByCoordinate($source) ?? $source : $source;
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
            && $left->getObjectType() === $right->getObjectType()
            && $left->getSiteId() === $right->getSiteId();
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
