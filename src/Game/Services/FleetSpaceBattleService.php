<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{BattleReports, Config, FleetCommands, Fleets, FleetsShips, Messages,
    ResourceBases, SpaceBattles, SpaceDebrisFields, Users, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Адаптер боя Fleet ↔ Fleet. Никаких планетарных ресурсов, очередей или лун. */
final class FleetSpaceBattleService
{
    private const REPOSITORIES = ['fleets' => Fleets::class, 'ships' => FleetsShips::class,
        'commands' => FleetCommands::class, 'debris' => SpaceDebrisFields::class, 'messages' => Messages::class,
        'bases' => ResourceBases::class];
    private static float $nextCleanupAt = 0.0;

    /** Только заранее отправленный захват того же объекта и того же атакующего. */
    public static function canReinforceResource(array $state, int $ownerId, array $target): bool
    {
        $site = $state['mission']['resourceSite'] ?? null;
        if ($ownerId <= 0 || ($state['phase'] ?? null) !== 'running' || !is_array($site)
            || (int)($state['mission']['attackerId'] ?? 0) !== $ownerId
            || ($site['coordinate'] ?? null) !== $target || (int)($target['siteId'] ?? 0) <= 0
            || (int)($site['id'] ?? 0) !== (int)$target['siteId']
            || ($target['type'] ?? null) !== CoordinateType::OBJECT->value
            || !in_array($target['objectType'] ?? null, [ObjectType::GAS_GIANT->value, ObjectType::ASTEROID_SECTOR->value], true)) return false;
        $round = count($state['combat']['rounds'] ?? []) + (isset($state['job']) ? 2 : 1);
        return $round <= (int)($state['combat']['roundLimit'] ?? 0);
    }

    /** Дружба даёт сторону защиты только явной DEFEND, не вражескому CAPTURE. */
    public static function canReinforceResourceDefense(array $state, int $ownerId, int $hostId, array $target, bool $friends): bool
    {
        $site = $state['mission']['resourceSite'] ?? null;
        if (!$friends || $ownerId <= 0 || $hostId <= 0 || $ownerId === $hostId || ($state['phase'] ?? null) !== 'running'
            || (int)($state['mission']['attackerId'] ?? 0) === $ownerId || !is_array($site)
            || (int)($site['ownerId'] ?? 0) !== $hostId || (int)($state['mission']['defenderId'] ?? 0) !== $hostId
            || ($site['coordinate'] ?? null) !== $target || (int)($target['siteId'] ?? 0) <= 0
            || (int)($site['id'] ?? 0) !== (int)$target['siteId'] || ($target['type'] ?? null) !== CoordinateType::OBJECT->value
            || !in_array($target['objectType'] ?? null,[ObjectType::GAS_GIANT->value,ObjectType::ASTEROID_SECTOR->value],true)) return false;
        foreach ($state['mission']['participants'] ?? [] as $participant) {
            if (($participant['side'] ?? null) === 'attacker' && (int)($participant['ownerId'] ?? 0) === $ownerId) return false;
        }
        $round = count($state['combat']['rounds'] ?? []) + (isset($state['job']) ? 2 : 1);
        return $round <= (int)($state['combat']['roundLimit'] ?? 0);
    }

    /** Собственная ранее отправленная RELOCATE не меняет владельца объекта. */
    public static function canReinforceResourceRelocation(array $state, int $ownerId, array $target): bool
    {
        $site = $state['mission']['resourceSite'] ?? null;
        if ($ownerId <= 0 || ($state['phase'] ?? null) !== 'running' || !is_array($site)
            || (int)($site['ownerId'] ?? 0) !== $ownerId || (int)($state['mission']['defenderId'] ?? 0) !== $ownerId
            || (int)($state['mission']['attackerId'] ?? 0) === $ownerId
            || ($site['coordinate'] ?? null) !== $target || (int)($target['siteId'] ?? 0) <= 0
            || (int)($site['id'] ?? 0) !== (int)$target['siteId'] || ($target['type'] ?? null) !== CoordinateType::OBJECT->value
            || !in_array($target['objectType'] ?? null, [ObjectType::GAS_GIANT->value, ObjectType::ASTEROID_SECTOR->value], true)) return false;
        foreach ($state['mission']['participants'] ?? [] as $participant) {
            if (($participant['side'] ?? null) === 'attacker' && (int)($participant['ownerId'] ?? 0) === $ownerId) return false;
        }
        return count($state['combat']['rounds'] ?? []) + (isset($state['job']) ? 2 : 1) <= (int)($state['combat']['roundLimit'] ?? 0);
    }

    /** Приписка и admission в одном rollback; завершённую цепочку удалит durable result. */
    public static function reinforceResourceRelocation(array $command, Fleet $fleet, AccountData $owner, array $battle, array $prepared): void
    {
        $key = (int)$battle['key']; $before = SpaceBattles::state($key);
        $time = (float)$command['execute_time']; $at = $prepared['target'];
        if ($before !== $battle['state'] || FleetCommands::getActiveCommand($fleet->getId()) !== $command
            || (int)$command['command_type'] !== Command::STAY->value || !empty($command['executed'])
            || (int)$command['fleet_id'] !== $fleet->getId() || $fleet->getOwnerId() !== (int)$owner['User']['id']
            || $fleet->getMission() !== \SPGame\Game\Enums\FleetMissionType::RELOCATE->value
            || $fleet->getCurrentCoordinate()->toArray() !== $at->toArray() || !is_finite($time)
            || $time < (float)$before['startedAt']
            || !self::canReinforceResourceRelocation($before, $fleet->getOwnerId(), $at->toArray())
            || ResourceBaseService::prepareDestination($fleet,$owner,$at,false,true) !== array_intersect_key($prepared,
                array_flip(['siteId','fallbackPlanetId','base']))) {
            throw new \LogicException('Нельзя включить передислокацию в этот бой.');
        }
        $rowBefore = Fleets::getById($fleet->getId());
        try {
            FleetControlService::commit([$fleet], static function() use($command,$fleet,$owner,$key,$before,$time,$at,$prepared):void {
                $params = FleetControlService::params($command);
                $groups = FleetAttackService::fleetGroups($fleet,$owner);
                $participant = FleetAttackService::participant($fleet,'defender') + ['siteIdle'=>true,
                    'siteSpeed'=>(float)($params['speedFactor'] ?? 10), 'resourceRelocationCommandId'=>(int)$command['id']];
                $point = FleetRendezvousService::position($fleet,$time);
                $fleet->setHomeResourceSite($prepared['siteId'],$prepared['fallbackPlanetId']);
                $fleet->setStartCoordinate(null); $fleet->setTargetCoordinate(null);
                $fleet->setMission(0); $fleet->setStatus(Fleet::STATUS_IDLE); $fleet->setReturn(false);
                $fleet->setBaseFuelState(null); $fleet->setCurrentCoordinate($point,$time); $fleet->save();
                FleetControlService::patchCommand((int)$command['id'],['executed'=>1]);
                FleetBattleService::reinforceSpace($key,$before['id'],'relocate:'.$command['id'],'defender',$groups,$time,[$fleet->getId()],$participant);
            });
        } catch (\Throwable $error) {
            try {
                SpaceBattles::store($key,$before);
                if (Fleets::getById($fleet->getId()) !== $rowBefore || FleetCommands::getCommand((int)$command['id']) !== $command) {
                    throw new \RuntimeException('Не восстановлена передислокация подкрепления.');
                }
            } catch (\Throwable $rollback) {
                $failed = SpaceBattles::state($key) ?? $before; $failed['phase']='failed';
                $failed['fleets']=array_values(array_unique([...$failed['fleets'],$fleet->getId()]));
                try { SpaceBattles::store($key,$failed); }
                catch (\Throwable $lockError) { Logger::getInstance()->critical('Не заблокирован rollback передислокации',
                    ['battleKey'=>$key,'message'=>$lockError->getMessage()]); }
                Logger::getInstance()->critical('Rollback передислокации подкрепления не выполнен',
                    ['battleKey'=>$key,'message'=>$rollback->getMessage()]);
            }
            throw $error;
        }
    }

    /** Server-only admission прибывшей DEFEND, тем же координатором раундов. */
    public static function reinforceResourceDefense(array $command, Fleet $fleet, AccountData $owner, array $battle, array $params): array
    {
        $key = (int)$battle['key']; $before = SpaceBattles::state($key);
        $time = $fleet->getCurrentPositionTime(); $at = Coordinate::fromArray($params['target']);
        $hostId = (int)($params['hold']['ownerId'] ?? 0);
        if ($before !== $battle['state'] || FleetCommands::getCommand((int)$command['id']) !== $command
            || !FleetResourceDefendService::isCommand($command) || !empty($command['executed'])
            || (int)$command['fleet_id'] !== $fleet->getId() || $fleet->getOwnerId() !== (int)$owner['User']['id']
            || $fleet->getCurrentCoordinate()->toArray() !== $at->toArray() || !is_finite($time) || $time < (float)$before['startedAt']
            || !self::canReinforceResourceDefense($before,$fleet->getOwnerId(),$hostId,$at->toArray(),
                \SPGame\Game\Repositories\Friends::areFriends($fleet->getOwnerId(),$hostId))) {
            throw new \LogicException('Нельзя включить ресурсную охрану в этот бой.');
        }
        FleetBattleService::assertFleetAvailable($fleet->getId());
        $rowBefore = Fleets::getById($fleet->getId());
        try {
            return FleetControlService::commit([$fleet],static function() use($command,$fleet,$owner,$key,$before,$time,$params):array {
                $groups = FleetAttackService::fleetGroups($fleet,$owner);
                $participant = FleetAttackService::participant($fleet,'defender')
                    + ['resourceDefenseCommandId'=>(int)$command['id'],'siteIdle'=>false,'siteSpeed'=>(float)($params['speedFactor'] ?? 10)];
                $point = FleetRendezvousService::position($fleet,$time);
                $params['pause'] = 'battle';
                FleetControlService::patchCommand((int)$command['id'],['execute_time'=>0.0,'params'=>self::commandParams($params)]);
                $fleet->setBaseFuelState(null); $fleet->setCurrentCoordinate($point,$time); $fleet->save();
                FleetBattleService::reinforceSpace($key,$before['id'],'defend:'.$command['id'],'defender',$groups,$time,[$fleet->getId()],$participant);
                return FleetCommands::getCommand((int)$command['id']);
            });
        } catch (\Throwable $error) {
            try {
                SpaceBattles::store($key,$before);
                if (Fleets::getById($fleet->getId()) !== $rowBefore || FleetCommands::getCommand((int)$command['id']) !== $command) {
                    throw new \RuntimeException('Не восстановлена прибывшая охрана.');
                }
            } catch (\Throwable $rollback) {
                $failed = SpaceBattles::state($key) ?? $before; $failed['phase']='failed';
                $failed['fleets']=array_values(array_unique([...$failed['fleets'],$fleet->getId()]));
                try { SpaceBattles::store($key,$failed); }
                catch (\Throwable $lockError) { Logger::getInstance()->critical('Не заблокирован rollback подкрепления охраны',['battleKey'=>$key,'message'=>$lockError->getMessage()]); }
                Logger::getInstance()->critical('Rollback подкрепления охраны не выполнен',['battleKey'=>$key,'message'=>$rollback->getMessage()]);
            }
            throw $error;
        }
    }

    /** Server-only: вызывается исполнителем ATTACK ПОСЛЕ расчёта полёта, не WS handler. */
    public static function engage(array $command, Fleet $attacker, Fleet $target, AccountData $owner,
        AccountData $targetOwner, float $time): ?array
    {
        return \SPGame\Game\Repositories\MiningCheckpoints::worldLocked(
            static fn(): ?array => self::engageLocked($command, $attacker, $target, $owner, $targetOwner, $time), true);
    }

    /** Явный захват OBJECT: общий бой без живого контакта с отдельным Fleet. */
    public static function arriveResource(array $command, Fleet $attacker, AccountData $owner): ?array
    {
        return \SPGame\Game\Repositories\MiningCheckpoints::worldLocked(static function () use ($command, $attacker, $owner): ?array {
            if (FleetCommands::getCommand((int)$command['id']) !== $command) return null;
            $params = FleetControlService::params($command);
            if (isset($params['battleWait'])) return $command;
            if (empty($params['resourceCapture']) || (int)$command['command_type'] !== Command::ATTACK->value
                || (int)$command['fleet_id'] !== $attacker->getId()) throw new \LogicException('Некорректная проверка обороны объекта.');
            $at = Coordinate::fromArray($params['target']);
            $time = $attacker->getCurrentPositionTime();
            if ($at->toArray() !== $attacker->getCurrentCoordinate()->toArray()) throw new \LogicException('Флот ещё не прибыл к объекту захвата.');
            try {
                $battle = ResourceBaseService::battleAt((int)$at->getSiteId());
                if ($battle !== null) {
                    if (!self::canReinforceResource($battle['state'], $attacker->getOwnerId(), $at->toArray())) {
                        throw new \LogicException('Подкрепление не может вступить в этот бой за объект.');
                    }
                    $target = null;
                    foreach ($battle['state']['mission']['participants'] as $id => $participant) {
                        if ($participant['side'] === 'defender' && (int)$participant['ownerId'] === (int)$battle['state']['mission']['defenderId']) {
                            $target = FleetsData::getInstance()->get((int)$id);
                            if ($target !== null) break;
                        }
                    }
                    if ($target === null) throw new \LogicException('Не найден защитник уже идущего боя.');
                } else {
                    $context = ResourceBaseService::objectBattleContext($at, $time);
                    if ($context === null || (int)$context['base']['owner_id'] === $attacker->getOwnerId() || $context['fleets'] === []) {
                        FleetCommands::markExecuted((int)$command['id']);
                        return FleetCommandsService::activateNextCommand($attacker, $owner);
                    }
                    // Условия могли измениться за время полёта. Отказ до закрытия
                    // экономики — обычный неудавшийся захват, не вечный due retry.
                    ResourceBaseService::prepareDestination($attacker, $owner, $at, true, true);
                    $target = reset($context['fleets']);
                }
            } catch (\InvalidArgumentException|\LogicException $error) {
                $params['resourceFailure'] = $error->getMessage();
                FleetControlService::patchCommand((int)$command['id'], ['params' => self::commandParams($params)]);
                FleetCommands::markExecuted((int)$command['id']);
                return FleetCommandsService::activateNextCommand($attacker, $owner);
            }
            $user = Users::findExistingById($target->getOwnerId()) ?? throw new \LogicException('Нет владельца защиты объекта.');
            return self::engageLocked($command, $attacker, $target, $owner, new AccountData((int)$user['account_id']), $time, true);
        }, true);
    }

    private static function engageLocked(array $command, Fleet $attacker, Fleet $target, AccountData $owner,
        AccountData $targetOwner, float $time, bool $objectCapture = false): ?array
    {
        $current = FleetCommands::getCommand((int)$command['id']);
        if ($current === null || (int)$current['executed'] !== 0) return null;
        $params = FleetControlService::params($current);
        if ((int)$current['command_type'] !== Command::ATTACK->value || (int)$current['fleet_id'] !== $attacker->getId()
            || (!$objectCapture && ((int)($params['target']['fleetId'] ?? 0) !== $target->getId()
                || (int)($params['target']['type'] ?? 0) !== CoordinateType::FLEET->value))
            || ($objectCapture && (empty($params['resourceCapture'])
                || (int)($params['target']['type'] ?? 0) !== CoordinateType::OBJECT->value))
            || $attacker->getOwnerId() !== (int)$owner['User']['id']
            || $target->getOwnerId() !== (int)$targetOwner['User']['id'] || $attacker->getOwnerId() === $target->getOwnerId()
            || !is_finite($time) || $time <= 0) throw new \InvalidArgumentException('Некорректное прибытие на перехват.');
        $ownBattle = SpaceBattles::forFleet($attacker->getId());
        if ($ownBattle !== null) {
            $participant = $ownBattle['state']['mission']['participants'][$attacker->getId()] ?? null;
            if (($participant['attackCommandId'] ?? null) === (int)$current['id']) return $current;
            throw new \LogicException('Атакующий уже участвует в бою.');
        }
        FleetBattleService::assertFleetAvailable($attacker->getId());
        $existing = SpaceBattles::forFleet($target->getId());
        $resourceReinforcement = isset($existing['state']['mission']['resourceSite']) && $objectCapture
            && self::canReinforceResource($existing['state'], $attacker->getOwnerId(), $params['target']);
        if (isset($existing['state']['mission']['resourceSite']) && !$resourceReinforcement) {
            throw new \LogicException('В этот бой за объект можно войти только захватом OBJECT того же атакующего.');
        }
        $context = $existing === null ? ($objectCapture
            ? ResourceBaseService::objectBattleContext(Coordinate::fromArray($params['target']), $time)
            : ResourceBaseService::battleContext($target, $time)) : null;
        if ($context !== null) {
            if ((int)$context['base']['owner_id'] === $attacker->getOwnerId()) throw new \LogicException('Нельзя атаковать свою ресурсную базу.');
            ResourceBaseService::assertBattleSupported($context);
        }
        $defenders = $context['fleets'] ?? [$target->getId() => $target];
        $admitted = $existing === null ? [$attacker, ...array_values($defenders)] : [$attacker];
        $mining = $existing === null && FleetMiningRuntimeService::canInterruptForBattle($target);
        if ($existing === null) FleetBattleService::assertFleetAvailable($target->getId(), $mining);
        if (!$objectCapture) FleetInterceptionService::observation($attacker->getOwnerId(), $target->getId(), $time);
        // Бой связан с движущимся siteId, а не со старой POINT первого freeze.
        $point = FleetRendezvousService::position($resourceReinforcement ? $attacker : $target, $time);
        if ($time < $attacker->getCurrentPositionTime() || $time < $target->getCurrentPositionTime()
            || FleetRendezvousService::distance($attacker->getCurrentCoordinate(), $point, $time) > .05) {
            throw new \LogicException('Флоты не встретились в одной физической точке.');
        }
        if ($existing === null && $context === null) self::assertInterruptible($target);
        $side = 'attacker';
        if ($existing !== null) {
            $state = $existing['state'];
            $side = $attacker->getOwnerId() === (int)$state['mission']['attackerId'] ? 'attacker'
                : ($attacker->getOwnerId() === (int)$state['mission']['defenderId'] ? 'defender' : null);
            if ($side === null || $state['phase'] !== 'running') throw new \LogicException('Флот не может вступить в этот бой.');
        }
        $key = $existing['key'] ?? SpaceBattles::nextId();
        self::commandParams(array_replace($params, ['battleWait' => ['scope' => 'space', 'key' => $key, 'id' => str_repeat('0', 32)], 'startedAt' => $time]));
        // Все проверки встречи/видимости сделаны. Экономику уже нельзя откатывать
        // вместе с неудачной подготовкой боя: rollback ниже видит только paused OBSERVE.
        if ($existing === null) FleetMiningRuntimeService::interruptManyForBattle(array_values(array_filter($defenders,
            static fn(Fleet $fleet): bool => FleetMiningRuntimeService::hasMining($fleet->getId()))), $time);
        $old = SpaceBattles::state($key);
        $battleWritten = false;
        $mutated = false;
        $startChanges = [];
        foreach ($admitted as $fleet) {
            $before = Fleets::getById($fleet->getId());
            $startChanges[] = ['repository' => 'fleets', 'id' => $fleet->getId(), 'before' => $before,
                'after' => array_replace($before, ['current_coordinate_type' => CoordinateType::POINT->value,
                    'current_galaxy' => $point->getGalaxy(), 'current_system' => $point->getSystem(), 'current_orbit' => 0, 'current_object_type' => 0])];
        }
        $startChanges[] = ['repository' => 'commands', 'id' => (int)$current['id'], 'before' => $current,
            'after' => array_replace($current, ['execute_time' => $time + FleetCombatService::DEFAULT_ROUNDS])];
        if ($existing === null) {
            foreach ($defenders as $defender) {
                $next = FleetCommands::getActiveCommand($defender->getId()) ?? FleetCommands::getNextPendingCommand($defender->getId());
                if ($next !== null) $startChanges[] = ['repository' => 'commands', 'id' => (int)$next['id'], 'before' => $next,
                    'after' => array_replace($next, ['execute_time' => 0.0])];
            }
        }
        try {
            return FleetControlService::commit($admitted,
                static function () use ($current, $attacker, $target, $owner, $targetOwner, $time, $point, $existing, $key, $side,
                    $context, $defenders, &$battleWritten, &$mutated): array {
                    // Оба состава/Factors/XP готовятся тем же адаптером, что и в ATTACK планеты.
                    $a = FleetAttackService::fleetGroups($attacker, $owner);
                    if ($a === []) throw new \LogicException('Для боя нужны корабли обеих сторон.');
                    $mutated = true;
                    $attacker->setCurrentCoordinate($point, $time);
                    $attacker->save();
                    $participant = FleetAttackService::participant($attacker, $side, (int)$current['id']);
                    if ($existing === null) {
                        $d = []; $participants = [$attacker->getId() => $participant];
                        foreach ($defenders as $defender) {
                            $user = Users::findExistingById($defender->getOwnerId());
                            if ($user === null) throw new \LogicException('Не найден владелец защитника объекта.');
                            $defenderOwner = $defender->getOwnerId() === $target->getOwnerId()
                                ? $targetOwner : new AccountData((int)$user['account_id']);
                            $idle = $defender->getStatus() === Fleet::STATUS_IDLE;
                            $speed = (float)($defender->getBaseFuelState()['speed'] ?? 10.0);
                            $working = FleetCommands::getActiveCommand($defender->getId());
                            $surveyCommandId = (int)($working['command_type'] ?? 0) === Command::RESEARCH->value
                                ? (int)$working['id'] : null;
                            $resourceDefenseCommandId = FleetResourceDefendService::isCommand($working) ? (int)$working['id'] : null;
                            $pausedMove = $context === null ? null : ResourceBaseService::pausedMoveAtResource($defender);
                            if ($context !== null) ResourceBaseService::settleStanding($defender, $time);
                            self::freeze($defender, $point, $time, $context !== null && $idle);
                            $d += FleetAttackService::fleetGroups($defender, $defenderOwner);
                            $participants[$defender->getId()] = FleetAttackService::participant($defender, 'defender')
                                + ['surveyCommandId' => $surveyCommandId]
                                + ['resourceDefenseCommandId' => $resourceDefenseCommandId]
                                + ($pausedMove === null ? [] : ['resourcePausedMove' => ['commandId' => (int)$pausedMove['id'],
                                    'pause' => FleetControlService::params($pausedMove)['pause']]])
                                + ($context === null ? [] : ['siteIdle' => $idle, 'siteSpeed' => $speed]);
                        }
                        if ($d === []) throw new \LogicException('Для боя нужны корабли обеих сторон.');
                        $percent = (float)(Config::getValue('FleetDebrisPercent') ?? 30);
                        if (!is_finite($percent) || $percent < 0 || $percent > 100) throw new \LogicException('Некорректный процент обломков флота.');
                        FleetBattleService::beginSpace($key, $a, $d, $time, Vars::$rapidfire, array_keys($participants), [
                            'spaceTarget' => ['name' => $context === null ? 'Перехват флота' : FleetSurveyService::label($context['site']), 'coordinate' => $point->toArray()],
                            'attackerId' => $attacker->getOwnerId(), 'defenderId' => (int)($context['base']['owner_id'] ?? $target->getOwnerId()),
                            'participants' => $participants, 'fleetDebrisPercent' => $percent,
                        ] + ($context === null ? [] : ['resourceSite' => ['id' => (int)$context['site']['id'],
                            'ownerId' => (int)$context['base']['owner_id'], 'coordinate' => $context['coordinate']]]));
                    } else FleetBattleService::reinforceSpace($key, $existing['state']['id'], 'command:' . $current['id'], $side,
                        $a, $time, [$attacker->getId()], $participant);
                    $battleWritten = true;
                    $state = SpaceBattles::state($key);
                    $params = FleetControlService::params($current);
                    $params['battleWait'] = ['scope' => 'space', 'key' => $key, 'id' => $state['id']];
                    $params['startedAt'] = $time;
                    FleetControlService::patchCommand((int)$current['id'], ['execute_time' => $time + FleetCombatService::DEFAULT_ROUNDS,
                        'params' => self::commandParams($params)]);
                    return FleetCommands::getCommand((int)$current['id']);
                });
        } catch (\Throwable $error) {
            $rollbackFailed = false;
            if ($mutated) foreach (array_reverse($startChanges) as $change) {
                try { self::write($change, true); }
                catch (\Throwable $rollback) {
                    $rollbackFailed = true;
                    Logger::getInstance()->critical('Rollback индексов начала перехвата не выполнен',
                        ['repository' => $change['repository'], 'id' => $change['id'], 'originalMessage' => $error->getMessage(), 'message' => $rollback->getMessage()]);
                }
            }
            if ($battleWritten) {
                try {
                    if (!$rollbackFailed) {
                        if ($old !== null) SpaceBattles::store($key, $old);
                        else SpaceBattles::discardUncommittedRow(SpaceBattles::findById($key) ?? ['id' => $key]);
                        if (SpaceBattles::state($key) !== $old) throw new \RuntimeException('Не восстановлен checkpoint боя.');
                    }
                } catch (\Throwable $rollback) {
                    $rollbackFailed = true;
                    Logger::getInstance()->critical('Rollback начала перехвата не выполнен',
                        ['originalMessage' => $error->getMessage(), 'message' => $rollback->getMessage(), 'battleKey' => $key]);
                }
            }
            if ($rollbackFailed) {
                try {
                    $failed = SpaceBattles::state($key) ?? ['id' => bin2hex(random_bytes(16)), 'startedAt' => $time, 'job' => null, 'fleets' => []];
                    $failed['phase'] = 'failed';
                    $failed['fleets'] = array_values(array_unique([...$failed['fleets'], ...array_map(static fn(Fleet $fleet): int => $fleet->getId(), $admitted)]));
                    SpaceBattles::store($key, $failed);
                } catch (\Throwable $lockError) { Logger::getInstance()->critical('Не удалось заблокировать неполный rollback перехвата',
                    ['battleKey' => $key, 'originalMessage' => $error->getMessage(), 'message' => $lockError->getMessage()]); }
            }
            foreach ($admitted as $fleet) {
                try { $fleet->reloadFromRuntime(); }
                catch (\Throwable $reloadError) { Logger::getInstance()->critical('Не удалось перечитать участника после rollback',
                    ['fleetId' => $fleet->getId(), 'originalMessage' => $error->getMessage(), 'message' => $reloadError->getMessage()]); }
            }
            throw $error;
        }
    }

    private static function assertInterruptible(Fleet $fleet): void
    {
        FleetSurveyService::assertAvailable($fleet->getId());
        if ($fleet->getStatus() === Fleet::STATUS_IDLE) throw new \LogicException('Базирующийся флот не является целью перехвата.');
        $command = FleetCommands::getActiveCommand($fleet->getId());
        if ($command === null && FleetCommands::getNextPendingCommand($fleet->getId()) === null) {
            throw new \LogicException('У цели отсутствует рабочая цепочка команд.');
        }
        if ($command !== null && !in_array((int)$command['command_type'], [Command::MOVE_IN_SYSTEM->value, Command::OBSERVE->value, Command::RESEARCH->value], true)
            && !FleetMiningRuntimeService::canInterruptForBattle($fleet)) {
            throw new \LogicException('Перехват во время другого действия пока недоступен.');
        }
    }

    private static function freeze(Fleet $fleet, Coordinate $point, float $time, bool $siteIdle = false): void
    {
        $active = FleetCommands::getActiveCommand($fleet->getId());
        $next = $active ?? FleetCommands::getNextPendingCommand($fleet->getId());
        $fuel = FleetTransferService::physicalFuel($fleet, $time);
        if ($fuel > $fleet->getFuelTotal() + .000001) throw new \LogicException('Некорректный физический запас топлива цели.');
        if ($next !== null) {
            $params = FleetControlService::params($next);
            if ($active !== null && (int)$active['command_type'] === Command::MOVE_IN_SYSTEM->value) {
                $fraction = min(1.0, max(0.0, ($time - $fleet->getCurrentPositionTime())
                    / max(.000001, (float)$active['execute_time'] - $fleet->getCurrentPositionTime())));
                $weight = (float)($params['progressWeight'] ?? 1);
                $done = (float)($params['progressDone'] ?? 0);
                $params['progressDone'] = min($weight, $done + (float)($params['progressSpan'] ?? $weight - $done) * $fraction);
                foreach (['motionStart', 'motionEnd', 'resolvedTarget', 'startedAt', 'fuelCost', 'recallFlight', 'fuelStop', 'progressSpan'] as $name) unset($params[$name]);
            }
            $params['pause'] = 'battle';
            $patch = ['execute_time' => 0.0];
            if ((int)$next['command_type'] === Command::RESEARCH->value) {
                // Проход прерван без награды. После result commit выживший
                // автоматически возвращается домой, а не ждёт ручного Resume.
                unset($params['survey'], $params['hold'], $params['planStep']);
                $params['surveyBattleReturn'] = true;
                $params['target'] = $point->toArray();
                $patch['command_type'] = Command::OBSERVE->value;
            }
            FleetControlService::patchCommand((int)$next['id'], $patch + ['params' => self::commandParams($params)]);
        } elseif (!$siteIdle) throw new \LogicException('Во время подготовки исчезла команда цели.');
        $fleet->setBaseFuelState(null);
        $fleet->setFuelCurrent(max(0.0, min($fleet->getFuelTotal(), $fuel)));
        $fleet->setCurrentCoordinate($point, $time);
        $fleet->save();
    }

    public static function process(float $now): array
    {
        $affected = [];
        foreach (SpaceBattles::active() as $key => $state) {
            if (!isset($state['mission']) || !in_array($state['phase'], ['finished', 'settled'], true)) continue;
            try {
                if ($state['phase'] === 'finished') self::settle($key, $state, $now);
                $state = SpaceBattles::state($key);
                if ($state['phase'] === 'settled') {
                    BattleReports::publish($state['id']);
                    self::resume($key, $state);
                    if (isset($state['mission']['resourceSite']) || array_sum($state['resolution']['debris'] ?? []) > 0) {
                        $point = $state['mission']['spaceTarget']['coordinate'];
                        Notification::refreshGalaxySystem((int)$point['galaxy'], (int)$point['system']);
                    }
                }
                foreach (self::owners($state) as $ownerId) {
                    $user = Users::findExistingById((int)$ownerId);
                    if ($user !== null) $affected[(int)$user['account_id']] = true;
                }
            } catch (\Throwable $error) {
                $current = SpaceBattles::state($key);
                if ($current !== null && $current['phase'] === 'finished'
                    && ($error instanceof \LogicException || $error instanceof \OverflowException)) {
                    $current['phase'] = 'failed';
                    try { SpaceBattles::store($key, $current); }
                    catch (\Throwable $saveError) { Logger::getInstance()->critical('Не сохранён отказ пространственного боя',
                        ['battleKey' => $key, 'originalMessage' => $error->getMessage(), 'message' => $saveError->getMessage()]); }
                }
                Logger::getInstance()->error('Завершение боя в пространстве отложено', ['battleKey' => $key, 'message' => $error->getMessage()]);
            }
        }
        if ($now >= self::$nextCleanupAt) { self::$nextCleanupAt = $now + 1.0; self::cleanupApplied(); }
        return array_keys($affected);
    }

    /** Не удалять checkpoint, пока хотя бы одна связанная запись ещё ждёт flush. */
    public static function cleanupApplied(): void
    {
        $applied = array_filter(SpaceBattles::getAll() ?? [], static fn($row) => $row['phase'] === 'applied');
        if ($applied === []) return;
        $pending = [];
        foreach (self::REPOSITORIES + ['battles' => SpaceBattles::class] as $name => $repository) {
            $sync = $repository::getPendingSync();
            $pending[$name] = array_fill_keys([...$sync['update'], ...$sync['delete']], true);
        }
        foreach ($applied as $row) {
            try {
                $state = SpaceBattles::state((int)$row['id']);
                if (!empty($state['cleanupRequested'])) { SpaceBattles::finishCleanup((int)$row['id'], $state['id']); continue; }
                if (!isset($state['resolution']['syncRows']) || isset($pending['battles'][$row['id']])) continue;
                $references = $state['resolution']['syncRows'];
                foreach ($state['mission']['participants'] as $id => $_) foreach (FleetCommands::getFleetCommandChain((int)$id) as $command) {
                    $references['commands'][] = (int)$command['id'];
                }
                foreach ($references as $name => $ids) foreach ($ids as $id) if (isset($pending[$name][$id])) continue 3;
                SpaceBattles::requestCleanup((int)$row['id'], $state['id']);
            } catch (\Throwable $error) { Logger::getInstance()->error('Очистка checkpoint боя отложена',
                ['battleKey' => $row['id'], 'message' => $error->getMessage()]); }
        }
    }

    private static function settle(int $key, array $state, float $now): void
    {
        if (!isset($state['application'])) {
            $state['application'] = self::prepareResult($state, $now);
            SpaceBattles::store($key, $state);
        }
        $plan = $state['application'];
        $before = $after = 0;
        foreach ($plan['changes'] as $change) {
            $row = self::REPOSITORIES[$change['repository']]::findExistingById($change['id']);
            if ($row === $change['before']) ++$before;
            elseif ($row === $change['after']) ++$after;
            else throw new \LogicException('Защищённое состояние пространственного боя изменено извне.');
        }
        if ($before > 0 && $after > 0) throw new \LogicException('Частичное сохранение боя в пространстве: требуется диагностика.');
        $written = [];
        try {
            if ($after === 0) foreach ($plan['changes'] as $change) {
                $written[] = $change; // Включая отказ после фактической записи.
                self::write($change, false);
            }
            $state['phase'] = 'settled';
            $state['resolution'] = $plan['resolution'];
            unset($state['application']);
            SpaceBattles::store($key, $state);
        } catch (\Throwable $error) {
            $failed = false;
            foreach (array_reverse($written) as $change) try { self::write($change, true); }
            catch (\Throwable $rollback) {
                $failed = true;
                Logger::getInstance()->critical('Rollback результата перехвата не выполнен', ['repository' => $change['repository'],
                    'id' => $change['id'], 'originalMessage' => $error->getMessage(), 'message' => $rollback->getMessage()]);
            }
            $original = SpaceBattles::state($key);
            if ($original !== null) {
                if ($failed || $after > 0) $original['phase'] = 'failed';
                else unset($original['application']); // Новые сообщения/debris получают новые IDs после DELETE marker.
                try { SpaceBattles::store($key, $original); }
                catch (\Throwable $rollback) { Logger::getInstance()->critical('Не восстановлен checkpoint результата перехвата',
                    ['battleKey' => $key, 'originalMessage' => $error->getMessage(), 'message' => $rollback->getMessage()]); }
            }
            throw $error;
        }
        foreach ($state['mission']['participants'] as $id => $_) FleetsData::getInstance()->refresh((int)$id);
    }

    private static function prepareResult(array $state, float $now): array
    {
        $result = FleetCombatService::getResult($state['combat']);
        $rewards = [];
        foreach (FleetCombatService::experienceInputs($result) as $side => $input) $rewards[$side] = FleetExperienceService::calculateRewards(...$input);
        $changes = $capacity = $xp = $counts = [];
        $site = $state['mission']['resourceSite'] ?? null;
        if ($site !== null) {
            $base = self::row($changes, 'bases', (int)$site['id']);
            if ((int)$base['owner_id'] !== (int)$site['ownerId']) throw new \LogicException('Владелец объекта изменён во время общего боя.');
            // После гибели всех охранников обычный контроль не должен уходить в прошлое.
            self::patch($changes, 'bases', (int)$site['id'], ['updated_at' => max($now, (float)$base['updated_at'])]);
        }
        $losses = ['attacker' => 0.0, 'defender' => 0.0];
        $debris = [901 => 0.0, 902 => 0.0];
        foreach (['attacker', 'defender'] as $side) foreach ($result[$side]['groups'] as $id => $group) {
            $fleetId = (int)$group['fleetId'];
            $name = Vars::$resource[$group['type']];
            $ships = self::row($changes, 'ships', $fleetId);
            if ((int)$ships[$name . '_count'] !== $group['before'] || (float)$ships[$name . '_experience'] !== $group['experience']) {
                throw new \LogicException('Состав или опыт участника изменён вне боя.');
            }
            self::patch($changes, 'ships', $fleetId, [$name . '_count' => $group['after'],
                $name . '_experience' => $rewards[$side]['groups'][$id]['experience']]);
            $capacity[$fleetId] = ($capacity[$fleetId] ?? 0) + $group['after'] * $group['capacity'];
            $counts[$fleetId] = ($counts[$fleetId] ?? 0) + $group['after'];
            $xp[$fleetId][] = ['count' => $group['after'], 'unitCost' => $group['unitCost'], 'experience' => $rewards[$side]['groups'][$id]['experience']];
            $lost = $group['before'] - $group['after'];
            $losses[$side] += $lost * $group['unitCost'];
            foreach ($debris as $resourceId => &$amount) $amount += $lost * $group['price'][$resourceId] * $state['mission']['fleetDebrisPercent'] / 100;
            unset($amount);
        }
        $free = $dead = $engaged = $resourceWaiting = $spaceWaiting = [];
        $availableLoot = [901 => 0.0, 902 => 0.0, 903 => 0.0];
        foreach ($state['mission']['participants'] as $id => $participant) {
            $id = (int)$id;
            $row = self::row($changes, 'fleets', $id);
            if ((int)$row['owner_id'] !== (int)$participant['ownerId']) throw new \LogicException('Владелец участника изменён во время боя.');
            $action = $participant['attackCommandId'];
            if (!isset($counts[$id])) {
                if ($site !== null && !empty($participant['resourceRelocationCommandId'])) {
                    if ((float)$row['fuel_current'] !== $participant['fuel']) throw new \LogicException('Топливо ожидающей передислокации изменено вне боя.');
                    foreach ($participant['cargo'] as $resourceId=>$amount) if ((float)$row['resource_'.Vars::$resource[$resourceId]] !== $amount) {
                        throw new \LogicException('Груз ожидающей передислокации изменён вне боя.');
                    }
                    $at = Coordinate::fromArray($site['coordinate']);
                    $preview = (new Fleet($id))->projectShips(self::row($changes,'ships',$id));
                    $preview->setCurrentCoordinate($at,$now);
                    $user = Users::findExistingById((int)$participant['ownerId']) ?? throw new \LogicException('Нет владельца передислокации.');
                    ResourceBaseService::beginStanding($preview,new AccountData((int)$user['account_id']),$now,(float)$participant['siteSpeed'],$at);
                    $patch = ['current_coordinate_type'=>CoordinateType::OBJECT->value,'current_galaxy'=>$at->getGalaxy(),
                        'current_system'=>$at->getSystem(),'current_orbit'=>$at->getOrbit(),'current_object_type'=>$at->getObjectType()->value,
                        'current_site_id'=>$at->getSiteId(),'current_position_time'=>max($now,(float)$row['current_position_time'])];
                    foreach ($preview->getBaseFuelState() as $name=>$value) $patch['base_fuel_'.$name]=$value;
                    self::patch($changes,'fleets',$id,$patch);
                }
                if ($site !== null && !empty($participant['resourceDefenseCommandId'])) {
                    // Worker мог завершить бой раньше раунда pending-охраны.
                    // Состав/XP/груз не трогаем, но DEFEND должна выйти из freeze.
                    if ((float)$row['fuel_current'] !== $participant['fuel']) throw new \LogicException('Топливо ожидающей охраны изменено вне боя.');
                    foreach ($participant['cargo'] as $resourceId=>$amount) if ((float)$row['resource_'.Vars::$resource[$resourceId]] !== $amount) {
                        throw new \LogicException('Груз ожидающей охраны изменён вне боя.');
                    }
                    self::patch($changes,'fleets',$id,self::resourceDefenseResult($changes,$participant,$site,(float)$participant['fuel'],$now));
                    $resourceWaiting[$id] = true;
                }
                if ($action !== null) {
                    $command = self::row($changes, 'commands', $action);
                    $params = FleetControlService::params($command); unset($params['battleWait']);
                    if ($site !== null && $participant['side'] === 'attacker' && !empty($params['resourceCapture'])) {
                        // Раунд в работе мог закончить бой до входа pending-группы.
                        // Потерь/XP нет, но захват/возврат не должен остаться в pause.
                        if ((float)$row['fuel_current'] !== $participant['fuel']) throw new \LogicException('Топливо ожидающего подкрепления изменено вне боя.');
                        foreach ($participant['cargo'] as $resourceId => $amount) if ((float)$row['resource_' . Vars::$resource[$resourceId]] !== $amount) {
                            throw new \LogicException('Груз ожидающего подкрепления изменён вне боя.');
                        }
                        $at = Coordinate::fromArray($site['coordinate']);
                        $params['resourceOutcome'] = $result['outcome'];
                        self::patch($changes, 'commands', $action, ['executed' => 1, 'execute_time' => $now,
                            'params' => self::commandParams($params)]);
                        self::patch($changes, 'fleets', $id, ['current_coordinate_type' => CoordinateType::OBJECT->value,
                            'current_galaxy' => $at->getGalaxy(), 'current_system' => $at->getSystem(),
                            'current_orbit' => $at->getOrbit(), 'current_object_type' => $at->getObjectType()->value,
                            'current_site_id' => $at->getSiteId(), 'current_position_time' => max($now, (float)$row['current_position_time'])]);
                        $resourceWaiting[$id] = true;
                    } elseif ($site === null) {
                        // Pending обычного перехвата не успел вступить в раунд.
                        // Закрываем только ожидание ATTACK, без потерь/XP/добычи.
                        if ((float)$row['fuel_current'] !== $participant['fuel']) throw new \LogicException('Топливо ожидающего подкрепления изменено вне боя.');
                        foreach ($participant['cargo'] as $resourceId => $amount) if ((float)$row['resource_' . Vars::$resource[$resourceId]] !== $amount) {
                            throw new \LogicException('Груз ожидающего подкрепления изменён вне боя.');
                        }
                        unset($params['pause']);
                        self::patch($changes, 'commands', $action, ['executed' => 1, 'execute_time' => $now,
                            'params' => self::commandParams($params)]);
                        self::patch($changes, 'fleets', $id, ['current_position_time' => max($now, (float)$row['current_position_time'])]);
                        $spaceWaiting[$id] = true;
                        if ($participant['side'] === 'defender') {
                            self::pauseDefenderRoute($changes, $id, $action, $state['mission']['spaceTarget']['coordinate']);
                        }
                    } else {
                        $params['pause'] = 'battle';
                        self::patch($changes, 'commands', $action, ['execute_time' => 0.0, 'params' => self::commandParams($params)]);
                    }
                }
                continue; // Позднее подкрепление не получает потери/XP этого боя.
            }
            $engaged[$id] = true;
            if ((float)$row['fuel_current'] !== $participant['fuel']) throw new \LogicException('Топливо участника изменено вне боя.');
            foreach ($participant['cargo'] as $resourceId => $amount) if ((float)$row['resource_' . Vars::$resource[$resourceId]] !== $amount) {
                throw new \LogicException('Груз участника изменён вне боя.');
            }
            if ($counts[$id] === 0) {
                $dead[] = $id;
                if ($participant['side'] !== $result['outcome']) foreach ($availableLoot as $resourceId => &$amount) $amount += $participant['cargo'][$resourceId] ?? 0.0;
                unset($amount);
                $changes['fleets:' . $id]['after'] = null;
                $changes['ships:' . $id]['after'] = null;
                foreach (FleetCommands::getFleetCommandChain($id) as $command) {
                    self::row($changes, 'commands', (int)$command['id']);
                    $changes['commands:' . $command['id']]['after'] = null;
                }
                continue;
            }
            $fuel = $participant['fuel'] * ($participant['capacity'] > 0 ? min(1.0, $capacity[$id] / $participant['capacity']) : 0.0);
            $cargoTotal = array_sum(array_intersect_key($participant['cargo'], array_flip([901, 902, 903])));
            $factor = $cargoTotal > 0 ? min(1.0, max(0.0, $capacity[$id] - $fuel) / $cargoTotal) : 1.0;
            $patch = ['fuel_current' => $fuel, 'current_position_time' => max($now, (float)$row['current_position_time']),
                'experience' => FleetExperienceService::fleetAverage($xp[$id])];
            foreach ($participant['cargo'] as $resourceId => $amount) $patch['resource_' . Vars::$resource[$resourceId]] =
                in_array((int)$resourceId, [901, 902, 903], true) ? $amount * $factor : $amount;
            if ($site !== null && $participant['side'] === 'defender') {
                $at = Coordinate::fromArray($site['coordinate']);
                $patch += ['current_coordinate_type' => CoordinateType::OBJECT->value,
                    'current_galaxy' => $at->getGalaxy(), 'current_system' => $at->getSystem(),
                    'current_orbit' => $at->getOrbit(), 'current_object_type' => $at->getObjectType()->value,
                    'current_site_id' => $at->getSiteId()];
                if (!empty($participant['resourcePausedMove'])) {
                    // Результат восстанавливает прежнюю остановку и тот же MOVE.
                    // Не превращаем возврат в OBSERVE/siteGuard, не дозаправляем
                    // и не начинаем маршрут автоматически после боя.
                    $stopped = $participant['resourcePausedMove'];
                    $move = self::row($changes, 'commands', (int)$stopped['commandId']);
                    $params = FleetControlService::params($move);
                    if ((int)$move['fleet_id'] !== $id || (int)$move['command_type'] !== Command::MOVE_IN_SYSTEM->value
                        || !empty($move['executed']) || (float)$move['execute_time'] !== 0.0
                        || ($params['pause'] ?? null) !== 'battle'
                        || !is_string($stopped['pause']) || in_array($stopped['pause'], ['', 'battle'], true)) {
                        throw new \LogicException('Изменена остановленная команда защитника ресурсного объекта.');
                    }
                    $params['pause'] = $stopped['pause'];
                    self::patch($changes, 'commands', (int)$move['id'], ['params' => self::commandParams($params)]);
                }
                if ((int)$participant['ownerId'] === (int)$site['ownerId'] && empty($participant['surveyCommandId'])
                    && empty($participant['resourcePausedMove'])) {
                    // Чистый preview выжившего состава: новый расход/резерв записываются
                    // в тот же result plan, а не повторно из resume после рестарта.
                    $preview = (new Fleet($id))->projectShips($changes['ships:' . $id]['after']);
                    $preview->setCurrentCoordinate($at, $now);
                    $user = Users::findExistingById((int)$participant['ownerId']);
                    if ($user === null) throw new \LogicException('Потерян владелец охраны объекта.');
                    ResourceBaseService::beginStanding($preview, new AccountData((int)$user['account_id']),
                        $now, (float)($participant['siteSpeed'] ?? 10.0), $at);
                    foreach ($preview->getBaseFuelState() as $name => $value) $patch['base_fuel_' . $name] = $value;
                    if (empty($participant['siteIdle']) || (int)$row['home_site_id'] !== (int)$site['id']) {
                        $pending = FleetCommands::getNextPendingCommand($id);
                        if ($pending === null || (int)$pending['command_type'] !== Command::OBSERVE->value) {
                            throw new \LogicException('Нет паузы выжившего защитника ресурсного объекта.');
                        }
                        $params = FleetControlService::params($pending);
                        $params['target'] = $at->toArray(); $params['pause'] = 'battle'; $params['siteGuard'] = (int)$site['id'];
                        self::patch($changes, 'commands', (int)$pending['id'], ['execute_time' => 0.0,
                            'params' => self::commandParams($params)]);
                    }
                }
                if (!empty($participant['resourceDefenseCommandId'])) {
                    $patch = array_replace($patch,self::resourceDefenseResult($changes,$participant,$site,$fuel,$now));
                }
            }
            self::patch($changes, 'fleets', $id, $patch);
            if ($participant['side'] === $result['outcome']) $free[$id] = max(0.0, $capacity[$id] - $fuel - $cargoTotal * $factor);
            if ($action !== null) {
                $command = self::row($changes, 'commands', $action);
                $params = FleetControlService::params($command); unset($params['battleWait']);
                if ($site !== null && !empty($params['resourceCapture'])) {
                    $at = Coordinate::fromArray($site['coordinate']);
                    self::patch($changes, 'fleets', $id, ['current_coordinate_type'=>CoordinateType::OBJECT->value,
                        'current_galaxy'=>$at->getGalaxy(), 'current_system'=>$at->getSystem(),
                        'current_orbit'=>$at->getOrbit(), 'current_object_type'=>$at->getObjectType()->value,
                        'current_site_id'=>$at->getSiteId()]);
                    $params['resourceOutcome'] = $result['outcome'];
                }
                self::patch($changes, 'commands', $action, ['executed' => 1, 'execute_time' => $now,
                    'params' => self::commandParams($params)]);
                if ($participant['side'] === 'defender') {
                    self::pauseDefenderRoute($changes, $id, $action, $state['mission']['spaceTarget']['coordinate']);
                }
            }
        }
        // STAY завершена при admission, но удаление цепочки должно переживать restart
        // вместе с результатом боя, включая подкрепление, не успевшее в свой раунд.
        foreach ($state['mission']['participants'] as $id=>$participant) {
            if (empty($participant['resourceRelocationCommandId']) || in_array((int)$id,$dead,true)) continue;
            foreach (FleetCommands::getFleetCommandChain((int)$id) as $command) {
                if (empty($command['executed'])) throw new \LogicException('У передислоцированного подкрепления остались команды.');
                self::row($changes,'commands',(int)$command['id']);
                $changes['commands:'.$command['id']]['after']=null;
            }
        }
        $loot = FleetAttackService::calculateLoot($availableLoot, in_array($result['outcome'], ['attacker', 'defender'], true) ? array_sum($free) : 0.0);
        foreach ($loot as $resourceId => $amount) {
            $left = $amount; $remaining = array_sum($free);
            foreach ($free as $id => $available) {
                $take = $remaining > 0 ? min($left, $left * $available / $remaining) : 0.0;
                $name = 'resource_' . Vars::$resource[$resourceId];
                $row = self::row($changes, 'fleets', $id);
                self::patch($changes, 'fleets', $id, [$name => $row[$name] + $take]);
                $left = max(0.0, $left - $take); $remaining = max(0.0, $remaining - $available);
            }
        }
        if (array_sum($debris) > 0) {
            $point = Coordinate::fromArray($state['mission']['spaceTarget']['coordinate']);
            $row = SpaceDebrisFields::castRowToSchema(['id' => SpaceDebrisFields::nextId(), 'battle_id' => $state['id'],
                'galaxy' => $point->getGalaxy(), 'system' => $point->getSystem(), 'distance' => $point->getDistance(), 'angle' => $point->getAngle(),
                'metal' => $debris[901], 'crystal' => $debris[902], 'update_time' => $now], true);
            $changes['debris:' . $row['id']] = ['repository' => 'debris', 'id' => (int)$row['id'], 'before' => null, 'after' => $row];
        }
        $point = Coordinate::fromArray($state['mission']['spaceTarget']['coordinate']);
        $summary = ['BattleId' => $state['id'], 'ReportVersion' => 1,
            'TargetCoordinate' => sprintf('[%d:%d] %.2f / %.2f°', $point->getGalaxy(), $point->getSystem(), $point->getDistance(), $point->getAngle()),
            'Rounds' => count($result['rounds']), 'LossesAttacker' => $losses['attacker'], 'LossesDefender' => $losses['defender'],
            'ResourcesLoot' => $loot, 'ResourcesDebris' => $debris, 'MoonChance' => 0,
            'lang' => ['Outcome' => 'battle.' . $result['outcome']]];
        BattleReports::prepare(BattleReportService::snapshot($state, $result, [], $rewards, $loot, $debris, $losses, $now), $summary);
        foreach (self::owners($state) as $ownerId) {
            $message = Notification::prepareFleetBattleReport((int)$ownerId, $summary, $now);
            $changes['messages:' . $message['id']] = ['repository' => 'messages', 'id' => (int)$message['id'], 'before' => null, 'after' => $message];
        }
        $changes = array_values(array_filter($changes, static fn($c) => $c['before'] !== $c['after']));
        $syncRows = [];
        foreach ($changes as $change) $syncRows[$change['repository']][] = $change['id'];
        return ['changes' => $changes,
            'resolution' => ['finishedAt' => $now, 'dead' => $dead, 'engaged' => $engaged, 'resourceWaiting' => $resourceWaiting,
                'spaceWaiting' => $spaceWaiting, 'loot' => $loot,
                'debris' => $debris, 'outcome' => $result['outcome'], 'syncRows' => $syncRows]];
    }

    /** Защитник ждёт решения на следующей команде, а не на завершённой ATTACK. */
    private static function pauseDefenderRoute(array &$changes, int $id, int $action, array $coordinate): void
    {
        $future = array_values(array_filter(FleetCommands::getFleetQueue($id), static fn($c) => (int)$c['id'] > $action));
        if ($future !== []) {
            $params = FleetControlService::params($future[0]); $params['pause'] = 'battle';
            self::patch($changes, 'commands', (int)$future[0]['id'], ['execute_time' => 0.0, 'params' => self::commandParams($params)]);
        } else {
            $pending = FleetCommands::castRowToSchema(['fleet_id' => $id, 'command_type' => Command::OBSERVE->value,
                'params' => self::commandParams(['target' => $coordinate, 'pause' => 'battle'])], true);
            $changes['commands:' . $pending['id']] = ['repository' => 'commands', 'id' => (int)$pending['id'], 'before' => null, 'after' => $pending];
        }
    }

    /** Одинаковый durable выход из freeze для участвовавшей и ожидавшей охраны. */
    private static function resourceDefenseResult(array &$changes, array $participant, array $site, float $fuel, float $time): array
    {
        $id=(int)$participant['fleetId']; $at=Coordinate::fromArray($site['coordinate']);
        $patch=['current_coordinate_type'=>CoordinateType::OBJECT->value,'current_galaxy'=>$at->getGalaxy(),
            'current_system'=>$at->getSystem(),'current_orbit'=>$at->getOrbit(),'current_object_type'=>$at->getObjectType()->value,
            'current_site_id'=>$at->getSiteId(),'current_position_time'=>$time,
            'base_fuel_time'=>0.0,'base_fuel_rate'=>0.0,'base_fuel_reserve'=>0.0,'base_fuel_speed'=>0.0];
        $guard=self::row($changes,'commands',(int)$participant['resourceDefenseCommandId']);
        $preview=(new Fleet($id))->projectShips(self::row($changes,'ships',$id));
        $preview->setCurrentCoordinate($at,$time); $preview->setFuelCurrent($fuel);
        $user=Users::findExistingById((int)$participant['ownerId']) ?? throw new \LogicException('Нет владельца дружественной охраны.');
        $restore=FleetResourceDefendService::restore($guard,$preview,new AccountData((int)$user['account_id']),$at,$time);
        if ($restore !== null) {
            self::patch($changes,'commands',(int)$guard['id'],$restore);
            foreach ($preview->getBaseFuelState() as $name=>$value) $patch['base_fuel_'.$name]=$value;
        }
        return $patch;
    }

    private static function row(array &$changes, string $repository, int $id): array
    {
        $key = $repository . ':' . $id;
        if (!isset($changes[$key])) {
            $row = self::REPOSITORIES[$repository]::findExistingById($id);
            if ($row === null) throw new \LogicException('Нет защищённой строки ' . $key);
            $changes[$key] = ['repository' => $repository, 'id' => $id, 'before' => $row, 'after' => $row];
        }
        return $changes[$key]['after'];
    }

    private static function owners(array $state): array
    {
        return array_values(array_unique([(int)$state['mission']['attackerId'], (int)$state['mission']['defenderId'],
            ...array_map(static fn(array $participant): int => (int)$participant['ownerId'], $state['mission']['participants'])]));
    }

    private static function commandParams(array $params): string
    {
        $encoded = json_encode($params, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 2048) throw new \OverflowException('Параметры команды перехвата превышают runtime лимит.');
        return $encoded;
    }

    private static function patch(array &$changes, string $repository, int $id, array $patch): void
    {
        self::row($changes, $repository, $id);
        $key = $repository . ':' . $id;
        $changes[$key]['after'] = array_replace($changes[$key]['after'], $patch);
    }

    private static function write(array $change, bool $rollback): void
    {
        $repository = self::REPOSITORIES[$change['repository']];
        $row = $change[$rollback ? 'before' : 'after'];
        $current = $repository::findExistingById($change['id']);
        if ($rollback) {
            // set(false) может оставить старую main row, но уже новые индексы;
            // простой update(before) тогда no-op. Убираем attempted-index entries
            // и восстанавливаем row/индексы вместе, обязательно снимая DELETE.
            $repository::discardUncommittedRow($change['after'] ?? $change['before']);
            if ($row !== null) $repository::restoreDeletedRow($row);
        } elseif ($row === null) $repository::discardUncommittedRow($current ?? $change['before']);
        elseif ($current === null) $repository::add($row);
        else $repository::update($row);
        if ($repository::findExistingById($change['id']) !== $row) throw new \RuntimeException('Не подтверждена запись перехвата: ' . $change['repository']);
        $pending = $repository::getPendingSync();
        if (!in_array($change['id'], $pending[$row === null ? 'delete' : 'update'], true)) throw new \RuntimeException('Нет sync marker перехвата.');
    }

    private static function resume(int $key, array $state): void
    {
        foreach ($state['resolution']['dead'] as $id) FleetsData::getInstance()->remove($id, false);
        foreach ($state['mission']['participants'] as $id => $participant) {
            if (!empty($participant['resourceDefenseCommandId'])
                && (isset($state['resolution']['engaged'][$id]) || isset($state['resolution']['resourceWaiting'][$id]))
                && !in_array((int)$id,$state['resolution']['dead'],true)) {
                $command = FleetCommands::getCommand((int)$participant['resourceDefenseCommandId']);
                if ($command === null || !empty($command['executed']) || (float)$command['execute_time'] > 0) continue;
                $fleet = FleetsData::getInstance()->get((int)$id);
                if ($fleet === null) throw new \LogicException('Потеряна выжившая дружественная охрана.');
                $user = Users::findExistingById($fleet->getOwnerId()) ?? throw new \LogicException('Потерян владелец охраны.');
                FleetResourceDefendService::returnAfterBattle($fleet,new AccountData((int)$user['account_id']),$command,
                    (float)$state['resolution']['finishedAt']);
                continue;
            }
            if (!empty($participant['surveyCommandId']) && isset($state['resolution']['engaged'][$id])
                && !in_array((int)$id, $state['resolution']['dead'], true)) {
                $fleet = FleetsData::getInstance()->get((int)$id);
                $command = FleetCommands::getCommand((int)$participant['surveyCommandId']);
                // Уже запущенный возврат (в том числе pause=fuel) не создаём заново.
                if ($fleet === null || $command === null || !empty($command['executed'])) continue;
                $user = Users::findExistingById($fleet->getOwnerId());
                if ($user === null) throw new \LogicException('Потерян владелец выжившего обследователя.');
                FleetCommandsService::returnInterruptedSurvey($fleet, new AccountData((int)$user['account_id']),
                    $command, (float)$state['resolution']['finishedAt']);
                continue;
            }
            if ($participant['side'] !== 'attacker' || $participant['attackCommandId'] === null
                || (!isset($state['resolution']['engaged'][$id]) && !isset($state['resolution']['resourceWaiting'][$id])
                    && !isset($state['resolution']['spaceWaiting'][$id]))
                || in_array((int)$id, $state['resolution']['dead'], true)) continue;
            $fleet = FleetsData::getInstance()->get((int)$id);
            if ($fleet === null || FleetCommands::getActiveCommand((int)$id) !== null) continue;
            $user = Users::findExistingById($fleet->getOwnerId());
            if ($user === null) throw new \LogicException('Потерян владелец выжившего перехватчика.');
            $owner = new AccountData((int)$user['account_id']);
            try {
                FleetControlService::commit([$fleet], static function () use ($fleet, $owner): void {
                    FleetCommandsService::activateNextCommand($fleet, $owner);
                    $fleet->save();
                });
            } catch (\LogicException $error) {
                $next = FleetCommands::getNextPendingCommand((int)$id);
                if ($next === null) throw $error;
                $params = FleetControlService::params($next);
                $params['planError'] = $error->getMessage();
                FleetControlService::patchCommand((int)$next['id'], ['params' => self::commandParams($params)]);
                FleetControlService::pause(FleetCommands::getCommand((int)$next['id']), 'plan');
                Logger::getInstance()->warning('Маршрут после перехвата приостановлен', ['fleetId' => $id, 'message' => $error->getMessage()]);
            }
        }
        FleetBattleService::releaseSpaceAfterCommit($key, $state['id']);
    }
}
