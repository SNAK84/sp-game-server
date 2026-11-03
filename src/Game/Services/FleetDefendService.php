<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Builds, Config, FleetCommands, Friends, Planets, Users, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Удержание союзного флота. Его расход входит в ресурсы ПРИНИМАЮЩЕГО объекта. */
final class FleetDefendService
{
    public const HOURS = [1, 2, 4, 8, 12, 16, 32];

    public static function duration(mixed $value): int
    {
        $value ??= 3600;
        if (!is_int($value) || !in_array($value, array_map(static fn($h) => $h * 3600, self::HOURS), true)) {
            throw new \InvalidArgumentException('Время обороны: 1, 2, 4, 8, 12, 16 или 32 часа.');
        }
        return $value;
    }

    public static function fuelRate(Fleet $fleet, AccountData $owner): float
    {
        $factor = (float)Config::getValue('FleetDefendFuelPerHourFactor', 0.1);
        if (!is_finite($factor) || $factor < 0) throw new \LogicException('Некорректный коэффициент снабжения обороны.');
        return $fleet->getTotalFuelConsumption($owner) * $factor / 3600;
    }

    public static function planet(Coordinate $target): ?array
    {
        if ($target->getType() !== CoordinateType::OBJECT
            || !in_array($target->getObjectType(), [ObjectType::PLANET, ObjectType::MOON], true)) return null;
        return Planets::findByIndex('galaxy_system_planet_type', [
            $target->getGalaxy(), $target->getSystem(), $target->getOrbit(), $target->getObjectType()->value,
        ]);
    }

    public static function capacity(array $planet): int
    {
        $key = Vars::$resource[34] ?? 'ally_deposit';
        return max(0, (int)(Builds::findExistingById((int)$planet['id'])[$key] ?? 0));
    }

    /** Проверяется в preview, перед enqueue, в EventLoop и повторно при прибытии. */
    public static function reason(Fleet $fleet, ?array $planet, bool $checkCapacity = true): ?string
    {
        if ($planet === null || (int)($planet['owner_id'] ?? 0) <= 0) return 'Цель обороны недоступна.';
        if ((int)$planet['owner_id'] === $fleet->getOwnerId()) return 'Оборона предназначена для планеты или луны друга.';
        if (!Friends::areFriends($fleet->getOwnerId(), (int)$planet['owner_id'])) return 'Оборонять можно только подтверждённого друга.';
        if ($checkCapacity && count(self::holdingFleets((int)$planet['id'])) >= self::capacity($planet)) {
            return 'На целевом объекте нет свободного места в Складе альянса.';
        }
        return null;
    }

    /** Только ACTIVE DEFEND, не летящие флоты и не ожидающие команды. */
    public static function holds(?int $planetId = null): array
    {
        $result = [];
        foreach (FleetCommands::getActiveDefenseCommands() as $command) {
            $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
            if (!isset($params['hold']['planetId'])) continue;
            if ($planetId !== null && (int)$params['hold']['planetId'] !== $planetId) continue;
            $result[] = ['command' => $command, 'params' => $params];
        }
        return $result;
    }

    public static function holdingFleets(int $planetId): array
    {
        return array_column(array_column(self::holds($planetId), 'command'), 'fleet_id');
    }

    public static function supplyRate(int $planetId): float
    {
        return array_sum(array_map(static fn($h) => (float)$h['params']['hold']['rate'], self::holds($planetId)));
    }

    /** Интеграл расхода: не списывать за время до прибытия или после конца удержания. */
    public static function supplyCost(int $planetId, float $from, float $to): float
    {
        $cost = 0.0;
        foreach (self::holds($planetId) as $item) {
            $hold = $item['params']['hold'];
            $cost += (float)$hold['rate'] * max(0.0,
                min($to, (float)$hold['until']) - max($from, (float)$hold['startedAt']));
        }
        return $cost;
    }

    public static function isHoldingAt(Fleet $fleet, array $planet): bool
    {
        foreach (self::holds((int)$planet['id']) as $item) {
            if ((int)$item['command']['fleet_id'] !== $fleet->getId()) continue;
            return (int)$item['params']['hold']['ownerId'] === (int)$planet['owner_id']
                && self::reason($fleet, $planet, false) === null
                && $fleet->getCurrentCoordinate()->toArray() === FleetCommandsService::getCommandTarget($item['command'])?->toArray();
        }
        return false;
    }

    public static function arrive(array $command, Fleet $fleet, AccountData $owner): ?array
    {
        if (FleetResourceDefendService::isCommand($command)) return FleetResourceDefendService::arrive($command,$fleet,$owner);
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $target = FleetCommandsService::getCommandTarget($command);
        $planet = $target === null ? null : self::planet($target);
        $time = $fleet->getCurrentPositionTime();
        $reason = self::reason($fleet, $planet);
        if ($planet !== null && (int)($params['targetOwnerId'] ?? 0) !== (int)$planet['owner_id']) $reason = 'Владелец цели изменился.';
        $host = null;
        if ($reason === null) {
            $host = self::host($planet);
            Helpers::processPlanet($time, $host);
            $host->save();
            if ((float)$host['Resources'][903]['count'] <= 0) $reason = 'На принимающем объекте закончился дейтерий.';
        }
        if ($reason !== null) {
            FleetCommands::markExecuted((int)$command['id']);
            Logger::getInstance()->info('Оборона отменена при прибытии', ['fleetId' => $fleet->getId(), 'reason' => $reason]);
            self::notify($owner, null, $fleet, $planet ?? [], $reason . ' Флот возвращается.', $time);
            return FleetCommandsService::activateNextCommand($fleet, $owner);
        }
        $duration = self::duration($params['duration'] ?? null);
        $params['startedAt'] = $time;
        $params['hold'] = ['planetId' => (int)$planet['id'], 'ownerId' => (int)$planet['owner_id'],
            'startedAt' => $time, 'until' => $time + $duration, 'rate' => self::fuelRate($fleet, $owner)];
        $active = FleetCommandsService::activateCommand((int)$command['id'], $time + $duration, $params);
        if ($active === null) throw new \RuntimeException('Не удалось активировать оборону.');
        self::notify($owner, $host, $fleet, $planet, 'Начато удержание для обороны. Снабжение оплачивает принимающий объект.', $time);
        // Прибытие подкрепления во время боя использует существующий admission раундов.
        return FleetAttackService::pauseArrival($active, $fleet, $owner) ?? $active;
    }

    public static function complete(array $command, Fleet $fleet, AccountData $owner): bool
    {
        if (FleetResourceDefendService::isCommand($command)) return FleetResourceDefendService::complete($command,$fleet,$owner);
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $time = max((float)$command['execute_time'], $fleet->getCurrentPositionTime());
        $planet = Planets::findExistingById((int)$params['hold']['planetId']);
        $host = null;
        if ($planet !== null && (int)$planet['owner_id'] > 0) {
            $host = self::host($planet);
            Helpers::processPlanet($time, $host);
            $host->save();
        }
        $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), $time);
        $fleet->save();
        FleetCommands::markExecuted((int)$command['id']);
        FleetCommandsService::activateNextCommand($fleet, $owner);
        $fleet->save();
        $early = $time < (float)$params['hold']['startedAt'] + (int)$params['duration'];
        self::notify($owner, $host, $fleet, $planet ?? [],
            !empty($params['recalled']) ? 'Флот отозван владельцем и возвращается.' : ($early ? 'Оборона завершена досрочно: снабжение или условия удержания недоступны. Флот возвращается.'
                : 'Время обороны истекло. Флот возвращается.'), $time);
        return true;
    }

    public static function assertRecall(Fleet $fleet, AccountData $owner): array
    {
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        FleetBattleService::assertFleetAvailable($fleet->getId());
        $command = FleetCommands::getActiveCommand($fleet->getId());
        if ($command === null || (int)$command['command_type'] !== FleetCommandType::DEFEND->value
            || FleetAttackService::mustWait($command)) throw new \LogicException('Отозвать можно флот, находящийся в удержании вне боя.');
        return $command;
    }

    public static function recall(Fleet $fleet, AccountData $owner, float $time): void
    {
        $command = self::assertRecall($fleet, $owner);
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $until = max($fleet->getCurrentPositionTime(), $time);
        $params['hold']['until'] = min((float)$params['hold']['until'], $until);
        $params['recalled'] = true;
        // Удаление удержания и оплата оставшегося интервала — обычным due EventLoop.
        FleetCommands::update(['id' => (int)$command['id'],
            'execute_time' => min((float)$command['execute_time'], $until),
            'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    /** Потери кораблей меняют расход будущего удержания, но не уже оплаченного интервала. */
    public static function refreshRate(Fleet $fleet, AccountData $owner): void
    {
        foreach (self::holds() as $item) {
            if ((int)$item['command']['fleet_id'] !== $fleet->getId()) continue;
            $params = $item['params'];
            $params['hold']['rate'] = self::fuelRate($fleet, $owner);
            FleetCommands::update(['id' => (int)$item['command']['id'],
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** Переносим общий срок ухода на точный момент исчерпания снабжения группы. */
    public static function rescheduleSupplies(float $now): void
    {
        FleetResourceDefendService::reschedule($now);
        $groups = [];
        foreach (self::holds() as $item) $groups[(int)$item['params']['hold']['planetId']][] = $item;
        foreach ($groups as $planetId => $items) {
            try {
                $planet = Planets::findExistingById($planetId);
                $host = $planet === null || (int)$planet['owner_id'] <= 0 ? null : self::host($planet);
                $stock = $host === null ? 0.0 : (float)$host['Resources'][903]['count'];
                $baseRate = $host === null ? 0.0 : ((float)$host['Resources'][903]['perhour']
                    + (float)$host['Resources'][903]['holding_perhour']) / 3600;
                $baseTime = $host === null ? $now : (float)$host['Planet']['update_time'];
                $exhaustAt = self::exhaustionTime($items, $baseTime, $stock, $baseRate);
                $occupied = 0;
                foreach ($items as $item) {
                    $fleet = FleetsData::getInstance()->get((int)$item['command']['fleet_id']);
                    $params = $item['params'];
                    $invalid = $fleet === null || self::reason($fleet, $planet, false) !== null
                        || (int)($planet['owner_id'] ?? 0) !== (int)$params['hold']['ownerId'];
                    if (!$invalid) $invalid = $occupied++ >= self::capacity($planet ?? ['id' => 0]);
                    $until = (float)$params['hold']['until'];
                    $due = min($until, $exhaustAt, $invalid ? $now : INF);
                    if ($due < (float)$item['command']['execute_time']) {
                        // Одновременно заканчивается снабжение всех флотов на объекте.
                        // Не режем until при обычном прогнозе: пополнение до deadline
                        // должно позволять продолжить удержание до исходного срока.
                        if ($invalid) $params['hold']['until'] = min($until, $now);
                        FleetCommands::update(['id' => (int)$item['command']['id'], 'execute_time' => max(1.0, $due),
                            'params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                    } elseif ($due > (float)$item['command']['execute_time'] && !isset($params['battleWait'])) {
                        FleetCommands::update(['id' => (int)$item['command']['id'], 'execute_time' => $due]);
                    }
                }
            } catch (\Throwable $e) {
                Logger::getInstance()->error('Не удалось проверить снабжение обороны', ['planetId' => $planetId, 'message' => $e->getMessage()]);
            }
        }
    }

    /** После ухода одного флота общий расход уменьшается, а не остаётся прежним. */
    private static function exhaustionTime(array $items, float $time, float $stock, float $production): float
    {
        if ($stock <= 0) return $time;
        $ends = [];
        $rate = 0.0;
        foreach ($items as $item) {
            $hold = $item['params']['hold'];
            if ((float)$hold['until'] <= $time) continue;
            $rate += (float)$hold['rate'];
            $ends[] = ['time' => (float)$hold['until'], 'rate' => (float)$hold['rate']];
        }
        usort($ends, static fn($a, $b) => $a['time'] <=> $b['time']);
        foreach ($ends as $end) {
            $net = $production - $rate;
            if ($net < 0 && $stock / -$net <= $end['time'] - $time) return $time + $stock / -$net;
            $stock += $net * ($end['time'] - $time);
            $time = $end['time'];
            $rate -= $end['rate'];
        }
        return INF;
    }

    private static function notify(AccountData $owner, ?AccountData $host, Fleet $fleet, array $planet,
        string $state, float $time): void
    {
        try { Notification::sendFleetDefense($owner, $host, $fleet->getId(), $planet, $state, $time); }
        catch (\Throwable $e) {
            Logger::getInstance()->error('Не удалось отправить сообщение об обороне',
                ['fleetId' => $fleet->getId(), 'message' => $e->getMessage()]);
        }
    }

    private static function host(array $planet): AccountData
    {
        $user = Users::findById((int)$planet['owner_id']);
        if ($user === null) throw new \LogicException('Владелец принимающего объекта недоступен.');
        $host = new AccountData((int)$user['account_id']);
        $host['WorkPlanet'] = (int)$planet['id'];
        return $host;
    }
}
