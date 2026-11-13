<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Space\Coordinate;

/** Передача груза/топлива только при фактической физической встрече двух Fleet. */
final class FleetTransferService
{
    public static function receiver(Fleet $source, array $params): Fleet
    {
        $id = (int)($params['target']['fleetId'] ?? 0);
        $receiver = FleetRendezvousService::target($source->getOwnerId(), $id, $source->getId());
        FleetBattleService::assertFleetAvailable($receiver->getId(), $receiver->getOwnerId() === $source->getOwnerId());
        return $receiver;
    }

    /** Неиспользованная часть уже оплаченного участка физически ещё находится в баке. */
    public static function physicalFuel(Fleet $fleet, float $time): float
    {
        $fuel = $fleet->getFuelCurrent();
        if ($fleet->getBaseFuelState() !== null) return ResourceBaseService::standingProjection(
            $fuel, $fleet->getBaseFuelState(), max($time, (float)$fleet->getBaseFuelState()['time']))['fuel'];
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ($active !== null && in_array((int)$active['command_type'], [Command::OBSERVE->value, Command::RESEARCH->value], true)) {
            $hold = FleetControlService::params($active)['hold'] ?? [];
            return max(0.0, $fuel - (float)($hold['rate'] ?? 0) * max(0.0, $time - (float)($hold['lastAt'] ?? $time)));
        }
        if ($active === null || !in_array((int)$active['command_type'], [10,11,12], true)) return $fuel;
        $params = FleetControlService::params($active);
        $duration = (float)$active['execute_time'] - $fleet->getCurrentPositionTime();
        $left = $duration > 0 ? min(1.0, max(0.0, ((float)$active['execute_time'] - $time) / $duration)) : 0.0;
        return $fuel + (float)($params['fuelCost'] ?? 0) * $left;
    }

    public static function deliver(array $command, Fleet $source, array $params): array
    {
        $current = FleetCommands::getCommand((int)$command['id']);
        if ($current === null || (int)$current['fleet_id'] !== $source->getId()
            || (int)$current['command_type'] !== Command::UNLOAD->value) throw new \LogicException('Команда передачи недоступна.');
        if ((int)$current['executed'] !== 0) return ['fleetTransfer' => true, 'receiverId' => (int)($params['target']['fleetId'] ?? 0), 'resources' => []];
        $target = self::receiver($source, $params);
        $time = $source->getCurrentPositionTime();
        if (FleetRendezvousService::distance($source->getCurrentCoordinate(), FleetRendezvousService::position($target, $time), $time) > .05) {
            throw new \LogicException('Флоты ещё не встретились: передача в другой точке запрещена.');
        }
        if (FleetMiningRuntimeService::hasMining($target->getId())) {
            return FleetMiningRuntimeService::transfer($current, $source, $target);
        }
        return FleetControlService::commit([$source, $target], static function () use ($command, $source, $target, $params, $time): array {
            FleetControlService::settleHold($target, $time);
            $free = max(0.0, $target->getCapacity() - $target->getUsedCapacity() - self::physicalFuel($target, $time));
            $cargo = [];
            foreach ([901,902,903] as $id) $cargo[$id] = (float)$source->getResources()[$id]->getCount();
            $factor = array_sum($cargo) > 0
                ? min(FleetPlanService::percentage($params['percent'] ?? 100) / 100.0, $free / array_sum($cargo)) : 0.0;
            $delivered = [];
            foreach ($cargo as $id => $count) {
                $amount = $count * $factor;
                $source->getResources()[$id]->setCount(max(0.0, $count - $amount));
                if ($id === 903 && !empty($params['refuel'])) {
                    $target->setFuelTotal(max($target->getFuelTotal(), self::physicalFuel($target, $time) + $amount));
                    $target->setFuelCurrent($target->getFuelCurrent() + $amount);
                } else {
                    $target->getResources()[$id]->setCount($target->getResources()[$id]->getCount() + $amount);
                }
                $delivered[$id] = $amount;
            }
            $source->save(); $target->save();
            $hold = FleetCommands::getActiveCommand($target->getId());
            if ($hold !== null && in_array((int)$hold['command_type'], [Command::OBSERVE->value, Command::RESEARCH->value], true)) {
                $holdParams = FleetControlService::params($hold);
                $rate = (float)$holdParams['hold']['rate'];
                if ($rate > 0) FleetControlService::patchCommand((int)$hold['id'], ['execute_time' => min(
                    (float)($holdParams['survey']['endsAt'] ?? INF), $time + $target->getFuelCurrent() / $rate)]);
            }
            FleetControlService::patchCommand((int)$command['id'], ['executed' => 1]);
            // Заправка не снимает pause: продолжение только по явной команде владельца.
            return ['fleetTransfer' => true, 'receiverId' => $target->getId(), 'resources' => $delivered];
        });
    }

    /** Отдельное due-событие: durable передача не входит в откат следующего перелёта. */
    public static function completeMining(array $command, Fleet $source, AccountData $owner): bool
    {
        if (FleetCommands::getCommand((int)$command['id']) !== $command) return true;
        $params = FleetControlService::params($command);
        if (!isset($params['miningTransfer'])) {
            $target = self::receiver($source, $params);
            if (!FleetMiningRuntimeService::hasMining($target->getId())) {
                // За время пути цель вышла из добычи: прежний обычный delivery path
                // повторно проверит встречу и при необходимости перестроит подлёт.
                FleetControlService::patchCommand((int)$command['id'], ['execute_time' => 0.0]);
                FleetCommandsService::activateNextCommand($source, $owner);
                $source->save();
                return true;
            }
            self::deliver($command, $source, $params);
            $command = FleetCommands::getCommand((int)$command['id']);
        }
        if (!isset(FleetControlService::params($command)['miningTransfer'])) throw new \LogicException('Нет receipt передачи добытчику.');
        FleetControlService::commit([$source], static function () use ($source, $owner, $command): void {
            FleetCommands::markExecuted((int)$command['id']);
            FleetCommandsService::activateNextCommand($source, $owner);
            $source->save();
        });
        return true;
    }

    /** Цель изменила курс: новый leg от фактической точки, без телепортации и повторного LOAD. */
    public static function redirect(array $command, Fleet $source, Fleet $target): void
    {
        $params = FleetControlService::params($command);
        $attempt = (int)($params['pursuitAttempts'] ?? 0) + 1;
        if ($attempt > 12) {
            FleetControlService::pause($command, 'target');
            return;
        }
        $time = $source->getCurrentPositionTime();
        $destination = Coordinate::fleet($target->getId(), FleetRendezvousService::position($target, $time));
        $commands = FleetMissionBuilder::buildForwardPath($source->getCurrentCoordinate(), $destination);
        // Перестраивается геометрия, но не назначение этапа и не параметры САБ.
        $intentKeys = array_flip(['planStep','planStepId','planMission','groupId','groupExecuteAt','groupSpeedCap']);
        foreach ($commands as &$next) $next['params'] += ['speedFactor' => (float)($params['speedFactor'] ?? 10), 'returnLeg' => false]
            + array_intersect_key($params, $intentKeys);
        unset($next);
        $commands[] = ['type' => Command::from((int)$command['command_type']), 'params' => array_replace($params,
            ['target' => $destination->toArray(), 'pursuitAttempts' => $attempt])];
        // Сохраняем последующие пользовательские действия, а не подменяем их возвратом.
        // Первый последующий перелёт строится заново от новой точки встречи.
        $suffix = array_values(array_filter(FleetCommands::getFleetQueue($source->getId()),
            static fn(array $row): bool => (int)$row['id'] > (int)$command['id']));
        $cursor = $destination;
        for ($i = 0; $i < count($suffix); $i++) {
            $row = $suffix[$i]; $type = Command::from((int)$row['command_type']);
            $p = FleetControlService::params($row);
            if (in_array($type, [Command::MOVE_IN_SYSTEM,Command::MOVE_BETWEEN_SYSTEMS,Command::MOVE_BETWEEN_GALAXIES], true)) {
                // Соседние MOVE одного назначения составляют единый путь между целями.
                while (isset($suffix[$i + 1]) && in_array((int)$suffix[$i + 1]['command_type'], [10,11,12], true)
                    && (FleetControlService::params($suffix[$i + 1])['target'] ?? null) === ($p['target'] ?? null)) $i++;
                $end = Coordinate::fromArray($p['target']);
                foreach (FleetMissionBuilder::buildForwardPath($cursor, $end) as $leg) {
                    $leg['params'] += ['speedFactor' => (float)($p['speedFactor'] ?? 10), 'returnLeg' => !empty($p['returnLeg'])]
                        + array_intersect_key($p, $intentKeys);
                    $commands[] = $leg;
                }
                $cursor = $end;
            } else $commands[] = ['type' => $type, 'params' => $p];
        }
        FleetControlService::commit([$source], static function () use ($commands, $source, $destination): void {
            foreach (FleetCommands::getFleetQueue($source->getId()) as $old) {
                $oldParams = FleetControlService::params($old); $oldParams['cancelled'] = true;
                FleetControlService::patchCommand((int)$old['id'], ['executed' => 1, 'params' => json_encode($oldParams, JSON_THROW_ON_ERROR)]);
            }
            foreach ($commands as $next) {
                FleetControlService::add($source->getId(), $next['type'], $next['params']);
            }
            $source->setTargetCoordinate($destination, $source->getCurrentPositionTime());
            $source->save();
        });
    }
}
