<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetAttackGroups, FleetCommands, Fleets, FleetsShips, Vars, MiningCheckpoints as Journal};
use SPGame\Game\Space\CoordinateType;

/** Слияние при физической встрече; компенсация ограничена двумя Fleet и их командами. */
final class FleetMergeService
{
    public static function receiver(Fleet $donor, int $id): Fleet
    {
        $receiver = FleetRendezvousService::target($donor->getOwnerId(), $id, $donor->getId());
        if ($receiver->getOwnerId() !== $donor->getOwnerId()) throw new \InvalidArgumentException('Объединять можно только собственные флоты.');
        foreach ([$donor, $receiver] as $fleet) {
            FleetBattleService::assertFleetAvailable($fleet->getId(), $fleet === $receiver);
            $active = FleetCommands::getActiveCommand($fleet->getId());
            $type = (int)($active['command_type'] ?? 0);
            if (in_array($type, [11, 12, Command::DEFEND->value, Command::EXPEDITION->value,
                Command::ATTACK->value, Command::GROUP_ATTACK->value, Command::DESTROY->value], true)) {
                throw new \LogicException('Объединение недоступно во время перехода, боя, обороны или экспедиции.');
            }
            foreach (FleetAttackGroups::rows() as $group) {
                if (in_array($fleet->getId(), json_decode($group['fleets'], true, 512, JSON_THROW_ON_ERROR), true)
                    && isset(FleetGroupAttackService::members($group)[$fleet->getId()])) {
                    throw new \LogicException('Участник САБ не может объединяться до завершения участия.');
                }
            }
            if ($fleet->getTotalShips() <= 0) throw new \LogicException('Нельзя объединить пустой флот.');
        }
        return $receiver;
    }

    public static function reason(Fleet $donor, int $id): ?string
    {
        try { self::receiver($donor, $id); return null; }
        catch (\InvalidArgumentException|\LogicException $error) { return $error->getMessage(); }
    }

    public static function execute(array $command, Fleet $donor, AccountData $owner): bool
    {
        FleetMiningCheckpointService::recoverPending();
        if (FleetCommands::getCommand((int)$command['id']) !== $command || (int)$command['executed']) return true;
        $params = FleetControlService::params($command);
        if (isset($params['miningMerge'])) return self::removeMiningDonor($command, $donor);
        try {
            $receiver = self::receiver($donor, (int)($params['target']['fleetId'] ?? 0));
            $time = $donor->getCurrentPositionTime();
            if ($time < $receiver->getCurrentPositionTime()) throw new \LogicException('Состояние цели уже изменилось; требуется новая встреча.');
            if (FleetRendezvousService::distance($donor->getCurrentCoordinate(), FleetRendezvousService::position($receiver, $time), $time) > .05) {
                FleetControlService::commit([$donor], static function () use ($command, $donor, $receiver, $owner): void {
                    FleetTransferService::redirect($command, $donor, $receiver);
                    FleetCommandsService::activateNextCommand($donor, $owner);
                    $donor->save();
                });
                return true;
            }
        } catch (\InvalidArgumentException|\LogicException $error) {
            $params['planError'] = $error->getMessage();
            FleetControlService::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            FleetControlService::pause(FleetCommands::getCommand((int)$command['id']), 'target');
            return true;
        }

        if (FleetMiningRuntimeService::hasMining($receiver->getId())) {
            // Экономика и корабли сначала durable; удаление пустого донора повторяемо.
            FleetMiningRuntimeService::merge($command, $donor, $receiver, $owner);
            return self::removeMiningDonor(FleetCommands::getCommand((int)$command['id']), $donor);
        }

        // До первой мутации готовим состав, груз и физический остаток топлива.
        $ships = self::combinedShips($donor, $receiver);
        $fuel = FleetTransferService::physicalFuel($donor, $time) + FleetTransferService::physicalFuel($receiver, $time);
        $cargo = [];
        foreach ($receiver->getResources() as $id => $resource) {
            $cargo[$id] = $resource->getCount() + $donor->getResources()[$id]->getCount();
            if (!is_finite($cargo[$id])) throw new \OverflowException('Переполнение груза.');
        }
        if (!is_finite($fuel) || $fuel + array_sum(array_intersect_key($cargo, array_flip([901,902,903])))
            > $receiver->getCapacity() + $donor->getCapacity() + .000001) {
            throw new \LogicException('Груз и топливо превышают объединённую вместимость.');
        }
        $snapshots = [];
        foreach ([$donor, $receiver] as $fleet) $snapshots[$fleet->getId()] = [
            Fleets::getById($fleet->getId()), FleetsShips::findExistingById($fleet->getId()),
            FleetCommands::getFleetCommandChain($fleet->getId())];
        try {
            $active = FleetCommands::getActiveCommand($receiver->getId());
            $siteGuard = ResourceBaseService::isPostBattleGuard($receiver);
            $guardSpeed = (float)($receiver->getBaseFuelState()['speed'] ?? 10.0);
            $position = FleetRendezvousService::position($receiver, $time);
            foreach ($donor->getShips() as $id => $ship) {
                if ($ship->getCount() <= 0) continue;
                $name = Vars::$resource[$id];
                $group = $receiver->setShipCount($id, $ships[$name . '_count']);
                $group->setExperience($ships[$name . '_experience']);
                $group->setDamage($ships[$name . '_damage']);
                $group->setMorale($ships[$name . '_morale']);
                $group->setStatus($ships[$name . '_status']);
            }
            $receiver->setFuelTotal(max($fuel, $receiver->getFuelTotal() + $donor->getFuelTotal()));
            $receiver->setFuelCurrent($fuel);
            foreach ($cargo as $id => $count) $receiver->getResources()[$id]->setCount($count);
            if ($active !== null && (int)$active['command_type'] === Command::MOVE_IN_SYSTEM->value) {
                $p = FleetControlService::params($active);
                $fraction = min(1.0, max(0.0, ($time - $receiver->getCurrentPositionTime())
                    / max(.000001, (float)$active['execute_time'] - $receiver->getCurrentPositionTime())));
                $weight = (float)($p['progressWeight'] ?? 1);
                $done = (float)($p['progressDone'] ?? 0);
                $p['progressDone'] = min($weight, $done + (float)($p['progressSpan'] ?? $weight - $done) * $fraction);
                $p['progressSpan'] = max(0.0, $weight - $p['progressDone']);
                foreach (['motionStart','motionEnd','resolvedTarget','startedAt','fuelCost','recallFlight','fuelStop'] as $key) unset($p[$key]);
                FleetControlService::patchCommand((int)$active['id'], ['execute_time' => 0.0, 'params' => json_encode($p, JSON_THROW_ON_ERROR)]);
                $receiver->setCurrentCoordinate($position, $time);
            }
            if ($siteGuard) ResourceBaseService::beginStanding($receiver, $owner, $time, $guardSpeed,
                $receiver->getCurrentCoordinate());
            $receiver->save();
            if (FleetsShips::findExistingById($receiver->getId()) !== $ships) throw new \RuntimeException('Не сохранился объединённый состав.');
            if ($active !== null && (int)$active['command_type'] === Command::MOVE_IN_SYSTEM->value) {
                FleetCommandsService::activateNextCommand($receiver, $owner);
            } elseif ($active !== null && (int)$active['command_type'] === Command::OBSERVE->value) {
                // Текущий OBSERVE продолжается с новым расходом; пауза без active не снимается.
                FleetControlService::beginHold($active, $receiver, $owner, $time);
            }
            $saved = Fleets::getById($receiver->getId());
            if ($saved === null || abs($saved['fuel_current'] - $receiver->getFuelCurrent()) > .000001
                || abs($saved['experience'] - $receiver->getExperience()) > .000001) throw new \RuntimeException('Не сохранился объединённый Fleet.');
            foreach ($cargo as $id => $count) if (abs($saved['resource_' . Vars::$resource[$id]] - $count) > .000001) {
                throw new \RuntimeException('Не сохранился объединённый груз.');
            }
            $coordinate = $receiver->getCurrentCoordinate();
            $fields = ['current_position_time' => $receiver->getCurrentPositionTime(),
                'fuel_total' => $receiver->getFuelTotal(), 'status' => $receiver->getStatus(),
                'mission' => $receiver->getMission(), 'is_return' => (int)$receiver->isReturn(),
                'current_coordinate_type' => $coordinate->getType()->value,
                'current_galaxy' => $coordinate->getGalaxy(), 'current_system' => $coordinate->getSystem()];
            $fields += $coordinate->getType() === CoordinateType::POINT
                ? ['current_distance' => $coordinate->getDistance(), 'current_angle' => $coordinate->getAngle()]
                : ['current_orbit' => $coordinate->getOrbit(), 'current_object_type' => $coordinate->getObjectType()?->value ?? 0];
            foreach ($fields as $key => $value) if (abs((float)$saved[$key] - (float)$value) > .000001) {
                throw new \RuntimeException('Не сохранилось состояние получателя: ' . $key);
            }
            // Удаление донора — последняя часть того же компенсируемого commit.
            FleetCommands::clearFleetQueue($donor->getId());
            FleetsShips::delete(['id' => $donor->getId()]);
            Fleets::delete(['id' => $donor->getId()]);
            foreach ([Fleets::class, FleetsShips::class] as $repo) {
                if ($repo::findExistingById($donor->getId()) !== null
                    || !in_array($donor->getId(), $repo::getPendingSync()['delete'], true)) throw new \RuntimeException('Не удалён объединённый донор.');
            }
            if (FleetCommands::getFleetCommandChain($donor->getId()) !== []) throw new \RuntimeException('Не удалены команды донора.');
            $deletedCommands = FleetCommands::getPendingSync()['delete'];
            foreach ($snapshots[$donor->getId()][2] as $old) {
                if (!in_array((int)$old['id'], $deletedCommands, true)) throw new \RuntimeException('Не сохранён DELETE команды донора.');
            }
        } catch (\Throwable $error) {
            foreach ([$donor, $receiver] as $fleet) {
                try {
                    [$row, $oldShips, $commands] = $snapshots[$fleet->getId()];
                    foreach ([Fleets::class => $row, FleetsShips::class => $oldShips] as $repo => $old) {
                        if ($repo::findExistingById($fleet->getId()) !== null) $repo::update($old);
                        $repo::restoreDeletedRow($old);
                    }
                    $ids = array_column($commands, 'id');
                    foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $added) {
                        if (!in_array($added['id'], $ids, true)) FleetCommands::discardUncommittedRow($added);
                    }
                    foreach ($commands as $old) {
                        if (FleetCommands::getCommand((int)$old['id']) !== null) FleetControlService::patchCommand((int)$old['id'], $old);
                        FleetCommands::restoreDeletedRow($old);
                    }
                    $fleet->reloadFromRuntime();
                } catch (\Throwable $rollback) {
                    Logger::getInstance()->error('CRITICAL: rollback объединения флотов не выполнен',
                        ['fleetId' => $fleet->getId(), 'originalMessage' => $error->getMessage(), 'message' => $rollback->getMessage()]);
                }
            }
            throw $error;
        }
        FleetsData::getInstance()->remove($donor->getId(), false);
        Logger::getInstance()->info('Флоты объединены', ['donorId' => $donor->getId(), 'receiverId' => $receiver->getId(), 'time' => $time]);
        return true;
    }

    /** Тот же расчёт групп для обычного слияния и mining journal. */
    public static function combinedShips(Fleet $donor, Fleet $receiver): array
    {
        $ships = FleetsShips::findExistingById($receiver->getId());
        foreach (Vars::$reslist['fleet'] as $id) {
            $name = Vars::$resource[$id];
            $a = $receiver->getShips()[$id] ?? null; $b = $donor->getShips()[$id] ?? null;
            $n = $a?->getCount() ?? 0; $m = $b?->getCount() ?? 0;
            if ($m === 0) continue;
            if ($n > PHP_INT_MAX - $m) throw new \OverflowException('Переполнение количества кораблей.');
            $count = $n + $m;
            $ships[$name . '_count'] = $count;
            // experience — суммарный XP группы; damage/morale — на один корабль.
            $ships[$name . '_experience'] = ($a?->getExperience() ?? 0) + $b->getExperience();
            $ships[$name . '_damage'] = (($a?->getDamage() ?? 0) * $n + $b->getDamage() * $m) / $count;
            $ships[$name . '_morale'] = (($a?->getMorale() ?? 1) * $n + $b->getMorale() * $m) / $count;
            $ships[$name . '_status'] = max($n > 0 ? $a->getStatus() : 0, $b->getStatus());
        }
        foreach ($ships as $value) if (is_float($value) && !is_finite($value)) throw new \OverflowException('Некорректные характеристики объединённого флота.');
        return $ships;
    }

    /** Receipt означает уже durable-перенос. Очистка никогда не начисляет дельту повторно. */
    private static function removeMiningDonor(array $command, Fleet $donor): bool
    {
        return Journal::worldLocked(static function () use ($command, $donor): bool {
            FleetMiningCheckpointService::recoverPending();
            if (FleetCommands::getCommand((int)$command['id']) !== $command
                || !isset(FleetControlService::params($command)['miningMerge'])) return true;
            $id = $donor->getId();
            foreach (['fleets', 'fleets_ships'] as $table) Journal::assertMutable($table, $id);
            if ($donor->getTotalShips() !== 0 || $donor->getFuelCurrent() !== 0.0
                || array_sum(array_map(static fn($r) => $r->getCount(), $donor->getResources())) !== 0.0) {
                throw new \LogicException('Донор с receipt слияния не пуст.');
            }
            // Команда остаётся due до удаления обоих пустых носителей.
            foreach ([FleetsShips::class, Fleets::class] as $repository) {
                if ($repository::findExistingById($id) !== null) $repository::delete(['id' => $id]);
                if ($repository::findExistingById($id) !== null) throw new \RuntimeException('Не удалён пустой донор добытчика.');
                $repository::flushCheckpointDeletion($id);
            }
            FleetCommands::clearFleetQueue($id);
            FleetsData::getInstance()->remove($id, false);
            Logger::getInstance()->info('Слияние с добытчиком: пустой донор удалён',
                ['donorId' => $id] + FleetControlService::params($command)['miningMerge']);
            return true;
        }, true);
    }
}
