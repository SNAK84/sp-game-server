<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{FleetCommands, FleetAttackGroups as Groups, Fleets, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType};

/** Компиляция пользовательских этапов в существующие команды; своего scheduler нет. */
final class FleetPlanService
{
    /** Текущий неизменяемый этап. История и внутренние переходы не занимают места. */
    public static function activeStage(Fleet $fleet): ?array
    {
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ($active === null) return null;
        $moving = in_array((int)$active['command_type'], [10, 11, 12], true);
        $target = $moving ? FleetCommandsService::getCommandTarget($active) : null;
        return ['moving' => $moving, 'returning' => !empty(FleetControlService::params($active)['returnLeg']),
            'coordinates' => $target === null ? null : self::coordinateKey(self::clientCoordinates($target))];
    }

    private static function coordinateKey(array $coordinate): array
    {
        $keys = match ((int)($coordinate['type'] ?? 0)) {
            CoordinateType::OBJECT->value => ['type', 'galaxy', 'system', 'orbit', 'objectType', 'siteId'],
            CoordinateType::POINT->value => ['type', 'galaxy', 'system', 'distance', 'angle'],
            CoordinateType::FLEET->value => ['type', 'fleetId'],
            default => [],
        };
        return array_filter(array_intersect_key($coordinate, array_flip($keys)), static fn($value) => $value !== null);
    }

    public static function stageCount(Fleet $fleet, array $steps): int
    {
        $active = self::activeStage($fleet);
        if ($active === null) return count($steps);
        $first = $steps[0] ?? [];
        // Перелёт к цели + ещё не выполненное действие у неё — один этап.
        // Два действия у одной цели остаются двумя этапами.
        $continues = $active['moving'] && (
            (($first['kind'] ?? '') === 'return' && $active['returning'])
            || (in_array($first['kind'] ?? '', ['move', 'mission', 'transfer', 'load'], true)
                && is_array($first['coordinates'] ?? null) && $active['coordinates'] !== null
                && self::coordinateKey($first['coordinates']) == $active['coordinates']));
        return count($steps) + ($continues ? 0 : 1);
    }

    public static function percentage(mixed $value = 100): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
            || $value < 0 || $value > 100 || floor((float)$value) !== (float)$value) {
            throw new \InvalidArgumentException('Процент выгрузки должен быть целым числом от 0 до 100.');
        }
        return (float)$value;
    }

    public static function clientCoordinates(Coordinate $coordinate): array
    {
        return $coordinate->getType() === CoordinateType::FLEET
            ? ['type' => CoordinateType::FLEET->value, 'fleetId' => $coordinate->getFleetId()]
            : $coordinate->toArray();
    }

    public static function runtimeTarget(Fleet $fleet, Coordinate $target, ?float $at = null): Coordinate
    {
        if ($target->getType() === CoordinateType::OBJECT && $target->getObjectType() === null && $target->getAngle() === null) {
            $point = (new FleetFlightService())->resolvePhysicalPoint($fleet->getCurrentCoordinate(), $at ?? $fleet->getCurrentPositionTime());
            return Coordinate::object((int)$target->getGalaxy(), (int)$target->getSystem(), (int)$target->getOrbit(), angle:$point->angle);
        }
        return $target;
    }

    public static function validate(Fleet $fleet, AccountData $owner, array $step, Coordinate $from,
        bool $prepareGroup = true, ?float $at = null): array
    {
        $id = $step['mission'] ?? null;
        $mission = is_int($id) ? Mission::tryFrom($id) : null;
        if ($mission === null) throw new \InvalidArgumentException('Выберите действие этапа.');
        if (!is_array($step['coordinates'] ?? null)) throw new \InvalidArgumentException('Не указаны координаты этапа.');
        if ($from->getType() === CoordinateType::FLEET) $from = $from->getLastKnownPosition();
        $action = FleetCommandsService::prepareMissionAction($fleet, $owner, $mission,
            $step['coordinates'], $step['duration'] ?? null, $step['refuel'] ?? false, $at, $from, $step['contactLoss'] ?? null, $step['mining'] ?? null);
        if ($mission === Mission::CAPTURE || ($mission === Mission::RELOCATE
            && FleetSurveyService::isResource($action['targetCoordinate']))) {
            throw new \InvalidArgumentException('Прибытие на ресурсную базу пока доступно только отдельной миссией, не этапом маршрута.');
        }
        if ($mission === Mission::EXPEDITION) {
            $limits = FleetExpeditionService::limits($owner);
            $row = Fleets::getById($fleet->getId());
            $self = (int)($row['mission'] ?? 0) === Mission::EXPEDITION->value ? 1 : 0;
            if ($limits['used'] - $self >= $limits['max']) throw new \LogicException('Нет свободного места для экспедиции.');
        }
        if ($mission === Mission::COLONIZE) {
            $travel = (new FleetMissionService())->getTravelInfo($fleet, $action['target'], $owner,
                (float)($step['speedFactor'] ?? 10), $mission, $from);
            if ($travel === null) throw new \LogicException('Не удалось рассчитать возврат после колонизации.');
            FleetCommandsService::assertColonizationReturnCapacity($fleet, $travel);
        }
        if ($mission === Mission::GROUP_ATTACK && $prepareGroup) {
            $travel = (new FleetMissionService())->getTravelInfo($fleet, $action['target'], $owner, (float)($step['speedFactor'] ?? 10), $mission, $from);
            if ($travel === null) throw new \LogicException('Не удалось рассчитать прибытие в САБ.');
            $action['groupPlan'] = FleetGroupAttackService::prepare($fleet, $action['targetPlanet'],
                $step['group'] ?? [], $at ?? microtime(true), $travel);
            if ($from->toArray() !== $action['targetCoordinate']->toArray()) {
                $now = $at ?? microtime(true);
                $params = (new FleetFlightService())->materializeCommandCoordinates(Command::MOVE_IN_SYSTEM,
                    $from, $action['targetCoordinate'], [], $now, false);
                FleetGroupAttackService::schedule(Command::MOVE_IN_SYSTEM, $from, $action['targetCoordinate'],
                    $params, $now, $fleet->getTotalSpeed($owner), (float)$action['groupPlan']['after']['execute_time'], (float)($step['speedFactor'] ?? 10));
            }
        }
        return $action + ['mission' => $mission];
    }

    public static function compile(Fleet $fleet, AccountData $owner, array $steps, Coordinate $cursor, Coordinate $base): array
    {
        $commands = [];
        $lastMission = Mission::tryFrom($fleet->getMission()) ?? Mission::OBSERVE;
        $planId = bin2hex(random_bytes(8));
        foreach ($steps as $index => $input) {
            if (!is_array($input)) throw new \InvalidArgumentException('Некорректный этап.');
            $kind = $input['kind'] ?? null;
            $speed = $input['speedFactor'] ?? 10;
            if ((!is_int($speed) && !is_float($speed)) || !is_finite((float)$speed) || $speed < 1 || $speed > 10) {
                throw new \InvalidArgumentException('Скорость должна быть от 10% до 100%.');
            }
            $step = ['kind' => $kind, 'speedFactor' => (float)$speed];
            $rows = [];
            $mission = $kind === 'return' ? $lastMission : Mission::OBSERVE;
            if ($kind === 'return') {
                if ($index !== count($steps) - 1) throw new \InvalidArgumentException('Возврат должен завершать план.');
                $rows = $cursor->toArray() === $base->toArray()
                    ? [['type' => Command::UNLOAD, 'params' => ['target' => $base, 'unloadFuel' => true]],
                        ['type' => Command::STAY, 'params' => ['at' => $base]]]
                    : FleetMissionBuilder::buildReturn($cursor, $base);
                $cursor = $base;
            } elseif ($kind === 'hold') {
                if (!FleetControlService::capabilities($owner)['hold']) throw new \LogicException('Для удержания нужна Флотовая логистика 1.');
                $rows[] = ['type' => Command::OBSERVE, 'params' => ['target' => $cursor->toArray()]];
            } elseif (in_array($kind, ['move', 'transfer', 'mission', 'load'], true)) {
                if (!is_array($input['coordinates'] ?? null)) throw new \InvalidArgumentException('Не указана цель этапа.');
                if ($kind === 'transfer') $input['mission'] = Mission::TRANSPORT->value;
                if ($kind === 'load') {
                    $step += FleetLoadingService::settings($input);
                    $mission = Mission::TRANSPORT;
                    $target = Coordinate::fromArray($input['coordinates']);
                    FleetLoadingService::source($fleet, $target);
                } elseif ($kind === 'move') {
                    $target = (int)($input['coordinates']['type'] ?? 0) === CoordinateType::FLEET->value
                        ? FleetRendezvousService::coordinate($fleet->getOwnerId(), (int)($input['coordinates']['fleetId'] ?? 0), microtime(true), $fleet->getId())
                        : Coordinate::fromArray($input['coordinates']);
                    if ($target->getType() === CoordinateType::OBJECT && $target->getObjectType() === null) {
                        throw new \InvalidArgumentException('Служебная орбита требует соответствующей миссии.');
                    }
                    $available = (new FleetMissionService())->getAvailableMissions($fleet,
                        FleetTarget::fromCoordinates(self::clientCoordinates($target)), $owner,
                        $cursor->getType() === CoordinateType::FLEET ? $cursor->getLastKnownPosition() : $cursor);
                    if (!empty($available['disabled'])) throw new \InvalidArgumentException('Цель этапа недоступна.');
                } else {
                    $action = self::validate($fleet, $owner, $input, $cursor);
                    $target = $action['targetCoordinate'];
                    $mission = $action['mission'];
                    $step['mission'] = $mission->value;
                    $step['kind'] = 'mission';
                    if ($action['fleetAttack']) $step['contactLoss'] = $action['interception']['policy'];
                    if ($kind === 'transfer' && $target->getType() !== CoordinateType::FLEET) throw new \InvalidArgumentException('Выберите целевой флот.');
                    if ($mission === Mission::TRANSPORT) {
                        $step['percent'] = self::percentage(array_key_exists('percent', $input) ? $input['percent'] : 100);
                        $step['refuel'] = ($input['refuel'] ?? false) === true;
                    }
                    if ($action['duration'] !== null) $step['duration'] = $action['duration'];
                    if ($mission === Mission::MINING) {
                        $step['mining'] = FleetMiningRuntimeService::options($action['miningOptions'], $target->getObjectType() === \SPGame\Game\Space\ObjectType::GAS_GIANT);
                        if ($step['mining']['finish'] === 'stay' && $index !== count($steps) - 1) throw new \InvalidArgumentException('Стоянка после добычи завершает маршрут на ресурсной базе.');
                    }
                    if ($mission === Mission::GROUP_ATTACK) $step['group'] = array_intersect_key($input['group'] ?? [],
                        array_flip(['id', 'executeAt', 'users', 'usernames']));
                    if ($mission === Mission::RELOCATE && $index !== count($steps) - 1) {
                        throw new \InvalidArgumentException('Передислокация завершает план на новой базе.');
                    }
                    if ($mission === Mission::MERGE && $index !== count($steps) - 1) {
                        throw new \InvalidArgumentException('Объединение завершает маршрут донора.');
                    }
                }
                $step['coordinates'] = self::clientCoordinates($target);
                // Повторное действие у той же планеты не создаёт фиктивный перелёт.
                if ($cursor->toArray() !== $target->toArray()) $rows = FleetMissionBuilder::buildForwardPath($cursor, $target);
                if ($kind === 'load') {
                    $rows[] = ['type' => Command::LOAD, 'params' => ['target' => $target->toArray(),
                        'loading' => array_intersect_key($step, array_flip(['fuel', 'resources', 'fillResource', 'shortage']))]];
                } elseif ($kind !== 'move') {
                    $type = match ($mission) {
                        Mission::TRANSPORT, Mission::RELOCATE => Command::UNLOAD,
                        Mission::ATTACK => Command::ATTACK, Mission::GROUP_ATTACK => Command::GROUP_ATTACK,
                        Mission::DESTROY => Command::DESTROY, Mission::DEFEND => Command::DEFEND,
                        Mission::SPY => Command::SPY, Mission::RECYCLE => Command::RECYCLE,
                        Mission::COLONIZE => Command::COLONIZE, Mission::OBSERVE => Command::OBSERVE,
                        Mission::MERGE => Command::MERGE,
                        Mission::RESEARCH => Command::RESEARCH,
                        Mission::MINING => Command::MINING,
                        Mission::EXPEDITION => Command::EXPEDITION,
                        default => throw new \InvalidArgumentException('Нет обработчика действия.'),
                    };
                    $params = ['target' => $target->toArray()];
                    if ($mission === Mission::TRANSPORT) $params += ['percent' => $step['percent'], 'refuel' => $step['refuel'],
                        'transportSource' => $cursor->toArray()];
                    if ($mission === Mission::RELOCATE) $params['unloadFuel'] = true;
                    if (isset($step['duration'])) $params['duration'] = $step['duration'];
                    if ($mission === Mission::MINING) $params['miningRequest'] = array_replace($step['mining'], ['speedFactor' => (float)$speed]);
                    if ($mission === Mission::DEFEND) $params['targetOwnerId'] = FleetSurveyService::isResource($target)
                        ? (int)\SPGame\Game\Repositories\ResourceBases::findExistingById((int)$target->getSiteId())['owner_id']
                        : (int)$action['targetPlanet']['owner_id'];
                    $rows[] = ['type' => $type, 'params' => $params];
                    if ($mission === Mission::RELOCATE) $rows[] = ['type' => Command::STAY, 'params' => ['at' => $target->toArray()]];
                    if ($mission === Mission::MINING && $step['mining']['finish'] === 'stay') $rows[] = ['type' => Command::STAY, 'params' => ['at' => $target->toArray()]];
                }
                $cursor = $target;
            } else throw new \InvalidArgumentException('Неизвестный вид этапа.');
            foreach ($rows as $row) {
                if (isset($step['contactLoss']) && in_array($row['type'], [Command::MOVE_IN_SYSTEM, Command::ATTACK], true)) {
                    $row['params']['interception'] = ['policy' => $step['contactLoss']];
                }
                $row['params'] += ['speedFactor' => (float)$speed, 'returnLeg' => $kind === 'return',
                    'planStep' => $step, 'planStepId' => "$planId:$index", 'planMission' => $mission->value];
                $commands[] = $row;
            }
            $lastMission = $mission;
        }
        $last = end($steps);
        if (!in_array($last['kind'] ?? '', ['hold', 'return'], true)
            && !(($last['kind'] ?? '') === 'mission' && (in_array($last['mission'] ?? null, [Mission::RELOCATE->value, Mission::OBSERVE->value, Mission::MERGE->value], true)
                || ($last['mission'] ?? null) === Mission::MINING->value && ($last['mining']['finish'] ?? null) === 'stay'))) {
            throw new \InvalidArgumentException('Завершите план возвратом, удержанием, передислокацией или объединением.');
        }
        return $commands;
    }

    /** Будущие этапы для редактора, включая старые одноцелевые миссии. */
    public static function steps(Fleet $fleet): array
    {
        $steps = []; $seen = []; $move = null; $moveSpeed = null; $returned = false;
        foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $row) {
            $params = FleetControlService::params($row);
            $moving = in_array((int)$row['command_type'], [10, 11, 12], true);
            if (!empty($row['executed'])) {
                // Старый ATTACK мог остаться без скорости после завершения MOVE.
                // Отменённые строки не задают скорость/границы нового маршрута.
                if (empty($params['cancelled'])) $moveSpeed = $moving ? (float)($params['speedFactor'] ?? 10) : null;
                continue;
            }
            $type = Command::from((int)$row['command_type']);
            if ($moving) $moveSpeed = (float)($params['speedFactor'] ?? 10);
            if ((float)$row['execute_time'] > 0) {
                if (!$moving) $moveSpeed = null;
                continue;
            }
            $homeSiteId = (int)($params['homeSiteId'] ?? 0);
            if ($homeSiteId > 0 && $homeSiteId !== $fleet->getHomeSiteId()) {
                // Runtime перестроит весь неявный хвост; старый MOVE не является
                // отдельным пользовательским этапом к уже потерянному объекту.
                if (!$returned) $steps[] = ['kind' => 'return', 'speedFactor' => (float)($params['speedFactor'] ?? $moveSpeed ?? 10)];
                $move = null;
                break;
            }
            if (isset($params['planStep'], $params['planStepId'])) {
                if (!isset($seen[$params['planStepId']])) { $steps[] = $params['planStep']; $seen[$params['planStepId']] = true; }
                $move = null; $moveSpeed = null; continue;
            }
            $target = FleetCommandsService::getCommandTarget($row);
            if ($moving) { $move = $target; continue; }
            if ($type === Command::LOAD) continue;
            if ($type === Command::STAY) {
                if (!$returned && $fleet->getMission() === Mission::RELOCATE->value && $target !== null) {
                    $steps[] = ['kind' => 'mission', 'mission' => Mission::RELOCATE->value, 'coordinates' => self::clientCoordinates($target),
                        'speedFactor' => (float)($params['speedFactor'] ?? $moveSpeed ?? 10)];
                }
                continue;
            }
            if ($type === Command::UNLOAD && !empty($params['unloadFuel'])) {
                if ($fleet->getMission() !== Mission::RELOCATE->value && !$returned) {
                    $steps[] = ['kind' => 'return', 'speedFactor' => (float)($params['speedFactor'] ?? $moveSpeed ?? 10)];
                    $returned = true; $moveSpeed = null;
                }
                $move = null; continue;
            }
            if ($type === Command::OBSERVE) {
                if ($move !== null) $steps[] = ['kind' => 'move', 'coordinates' => self::clientCoordinates($move), 'speedFactor' => $moveSpeed ?? 10.0];
                $steps[] = ['kind' => 'hold']; $move = null; $moveSpeed = null; continue;
            }
            $mission = match ($type) {
                Command::UNLOAD => Mission::TRANSPORT, Command::ATTACK => Mission::ATTACK,
                Command::GROUP_ATTACK => Mission::GROUP_ATTACK, Command::DESTROY => Mission::DESTROY,
                Command::DEFEND => Mission::DEFEND, Command::SPY => Mission::SPY,
                Command::RECYCLE => Mission::RECYCLE, Command::COLONIZE => Mission::COLONIZE,
                Command::EXPEDITION => Mission::EXPEDITION, Command::MERGE => Mission::MERGE,
                Command::RESEARCH => Mission::RESEARCH, Command::MINING => Mission::MINING, default => null,
            };
            if ($mission === null || $target === null) continue;
            $step = ['kind' => 'mission', 'mission' => $mission->value, 'coordinates' => self::clientCoordinates($target),
                'speedFactor' => (float)($params['speedFactor'] ?? $moveSpeed ?? 10)];
            if ($mission === Mission::TRANSPORT) $step += ['percent' => $params['percent'] ?? 100, 'refuel' => !empty($params['refuel'])];
            if ($mission === Mission::ATTACK && isset($params['interception'])) $step['contactLoss'] = $params['interception']['policy'];
            if (isset($params['duration'])) $step['duration'] = $params['duration'];
            if ($mission === Mission::MINING) $step['mining'] = $params['miningRequest'] ?? [];
            if (isset($params['groupId'])) $step['group'] = ['id' => (int)$params['groupId']];
            $steps[] = $step; $move = null; $moveSpeed = null;
        }
        if ($move !== null) $steps[] = ['kind' => 'move', 'coordinates' => self::clientCoordinates($move), 'speedFactor' => $moveSpeed ?? 10.0];
        return $steps;
    }

    /** Проверка при фактическом старте/прибытии этапа; исчезнувшая цель не пропускается молча. */
    public static function enter(array $command, Fleet $fleet, AccountData $owner): array
    {
        $params = FleetControlService::params($command);
        if (!isset($params['planStep'])) return $command;
        $step = $params['planStep'];
        if (($step['kind'] ?? '') === 'load') {
            $fleet->setTargetCoordinate(Coordinate::fromArray($step['coordinates']));
        }
        // Уже вылетевший переработчик не зависает, если другой флот успел собрать поле.
        $spaceRecycle = (int)($step['mission'] ?? 0) === Mission::RECYCLE->value
            && (int)($params['target']['type'] ?? 0) === CoordinateType::POINT->value;
        if ($spaceRecycle) {
            $capable = false;
            foreach ($fleet->getShips() as $ship) {
                if ($ship->getCount() > 0 && in_array(Mission::RECYCLE->value,
                    Vars::$attributes[$ship->getShipTypeId()]['missions'] ?? [], true)) $capable = true;
            }
            if (!$capable) throw new \LogicException('Для переработки необходимы корабли-переработчики.');
        }
        if (($step['kind'] ?? '') === 'mission' && !$spaceRecycle && !((int)$command['command_type'] === Command::ATTACK->value && isset($params['interception']))) {
            $action = self::validate($fleet, $owner, $step, $fleet->getCurrentCoordinate(), !isset($params['groupId']), $fleet->getCurrentPositionTime());
            if ($action['mission'] === Mission::GROUP_ATTACK && !isset($params['groupId'])) {
                self::joinGroup($command, $fleet, $action['groupPlan']);
                $command = FleetCommands::getCommand((int)$command['id']);
                $params = FleetControlService::params($command);
            }
            $target = self::runtimeTarget($fleet, $action['targetCoordinate']);
            $fleet->setTargetCoordinate($target, $target->getType() === CoordinateType::FLEET ? $fleet->getCurrentPositionTime() : null);
        }
        $fleet->setMission((int)$params['planMission']);
        $fleet->setReturn(!empty($params['returnLeg']));
        $fleet->save();
        return $command;
    }

    private static function joinGroup(array $command, Fleet $fleet, array $plan): void
    {
        $before = $plan['before']; $after = $plan['after'];
        try {
            FleetControlService::commit([$fleet], static function () use ($command, $fleet, &$after): void {
                if ((int)$after['id'] === 0) $after['id'] = Groups::nextId();
                Groups::store($after);
                $stepId = FleetControlService::params($command)['planStepId'];
                foreach (FleetCommands::getFleetQueue($fleet->getId()) as $row) {
                    $p = FleetControlService::params($row);
                    if (($p['planStepId'] ?? null) !== $stepId) continue;
                    $p['groupId'] = (int)$after['id'];
                    $p['groupExecuteAt'] = (float)$after['execute_time'];
                    $p['groupSpeedCap'] = (float)($p['speedFactor'] ?? 10);
                    $p['planStep']['group'] = ['id' => (int)$after['id']];
                    FleetControlService::patchCommand((int)$row['id'], ['params' => json_encode($p, JSON_THROW_ON_ERROR)]);
                }
            });
        } catch (\Throwable $error) {
            try { if ($before === null && (int)$after['id'] > 0) Groups::discardUncommittedRow($after); elseif ($before !== null) Groups::store($before); }
            catch (\Throwable $rollback) { \SPGame\Core\Logger::getInstance()->error('CRITICAL: rollback группы маршрута', ['message' => $rollback->getMessage(), 'originalMessage' => $error->getMessage()]); }
            throw $error;
        }
    }
}
