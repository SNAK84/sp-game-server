<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Fleets, Users};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Серверные identity/видимость целей и физическая встреча, без доверия client snapshot. */
final class FleetRendezvousService
{
    public static function target(int $viewerId, int $fleetId, ?int $sourceId = null): Fleet
    {
        if ($fleetId <= 0 || $fleetId === $sourceId) throw new \InvalidArgumentException('Целевой флот недоступен.');
        $row = Fleets::getById($fleetId);
        if ($row === null || ((int)$row['owner_id'] !== $viewerId
            && !isset(FleetsServices::incomingFleetRows($viewerId)[$fleetId]))) {
            throw new \InvalidArgumentException('Целевой флот недоступен.');
        }
        $fleet = FleetsData::getInstance()->get($fleetId);
        if ($fleet === null) throw new \InvalidArgumentException('Целевой флот недоступен.');
        $command = FleetCommands::getActiveCommand($fleetId) ?? FleetCommands::getNextPendingCommand($fleetId);
        // Скрыта именно фаза исследования, а не перелёты миссии EXPEDITION.
        // Проверка общая для списка целей, ручного ID и фактической передачи.
        if ((int)($command['command_type'] ?? 0) === Command::EXPEDITION->value) {
            throw new \InvalidArgumentException('Целевой флот недоступен.');
        }
        $current = $fleet->getCurrentCoordinate();
        if ($fleet->getStatus() === Fleet::STATUS_IDLE && $current->getType() === CoordinateType::OBJECT
            && in_array($current->getObjectType(), [ObjectType::PLANET, ObjectType::MOON], true)) {
            // Пауза/действие у объекта не равны базированию: такой флот
            // остаётся самостоятельной целью, даже без активного MOVE.
            throw new \InvalidArgumentException('Целевой флот недоступен.');
        }
        return $fleet;
    }

    /** POINT вычисляет только сервер; lastKnownPosition от клиента игнорируется. */
    public static function coordinate(int $viewerId, int $fleetId, float $time, ?int $sourceId = null): Coordinate
    {
        return Coordinate::fleet($fleetId, self::position(self::target($viewerId, $fleetId, $sourceId), $time));
    }

    public static function position(Fleet $fleet, float $time): Coordinate
    {
        $flight = new FleetFlightService();
        $coordinate = $fleet->getCurrentCoordinate();
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ($active !== null && in_array((int)$active['command_type'], [10, 11, 12], true)) {
            if ($time <= (float)$active['execute_time']) {
                return $flight->calculateCurrentCoordinate($fleet, $active, $time);
            }
            // После прибытия OBJECT продолжает двигаться по орбите. Не
            // замораживаем прогноз в motionEnd и не исполняем будущие команды.
            $target = FleetCommandsService::getCommandTarget($active);
            if ($target === null) throw new \LogicException('У MOVE-команды отсутствует цель встречи.');
            $coordinate = $flight->resolveCommandEndCoordinate(Command::from((int)$active['command_type']),
                $coordinate, $target, FleetControlService::params($active),
                $fleet->getCurrentPositionTime(), (float)$active['execute_time']);
        }
        $point = $flight->resolvePhysicalPoint($coordinate, $time);
        return Coordinate::point($point->galaxy, $point->system, $point->radius, $point->angle);
    }

    /** Свои и входящие небазирующиеся Fleet вне EXPEDITION, без чужого груза/маршрута. */
    public static function targets(int $viewerId, float $time): array
    {
        $rows = FleetsServices::incomingFleetRows($viewerId);
        foreach (Fleets::findByIndex('owner_id', (string)$viewerId) ?? [] as $row) $rows[(int)$row['id']] = $row;
        $result = [];
        foreach ($rows as $id => $row) {
            try {
                $fleet = self::target($viewerId, (int)$id);
                $result[] = ['id' => (int)$id, 'name' => $fleet->getOwnerId() === $viewerId ? $fleet->getName() : '',
                    'is_own' => $fleet->getOwnerId() === $viewerId, 'delivery' => true,
                    'owner_name' => (string)(Users::findExistingById($fleet->getOwnerId())['name'] ?? ''),
                    'position' => self::position($fleet, $time)->toArray()];
                if ($fleet->getOwnerId() !== $viewerId) {
                    try {
                        FleetInterceptionService::observation($viewerId, (int)$id, $time);
                        $result[array_key_last($result)]['interceptable'] = true;
                    } catch (\InvalidArgumentException|\LogicException) { /* Входящий переход ещё не цель перехвата. */ }
                }
            } catch (\InvalidArgumentException|\LogicException) {
                // Повреждённый/исчезнувший Fleet не превращается в произвольную цель.
            }
        }
        $known = array_column($result, null, 'id');
        foreach (FleetInterceptionService::targets($viewerId, $time) as $contact) {
            if (isset($known[$contact['id']])) {
                foreach ($result as &$target) if ($target['id'] === $contact['id']) $target['interceptable'] = $contact['interceptable'];
                unset($target);
            } else $result[] = $contact;
        }
        usort($result, static fn($a, $b) => ($b['is_own'] <=> $a['is_own']) ?: ($a['id'] <=> $b['id']));
        return $result;
    }

    /** Время встречи ищется по реальной траектории текущего участка, а не по старой точке. */
    public static function meeting(Fleet $source, Fleet $target, Coordinate $start, float $time,
        float $speed, float $speedFactor): array
    {
        $flight = new FleetFlightService();
        $travel = static function (float $after) use ($flight, $source, $target, $start, $time, $speed, $speedFactor): array {
            $point = self::position($target, $time + $after);
            if ($point->getGalaxy() !== $start->getGalaxy() || $point->getSystem() !== $start->getSystem()) {
                throw new \LogicException('Встреча внутри системы недоступна: целевой флот перешёл в другую систему.');
            }
            return [$flight->calculateCommandDuration(Command::MOVE_IN_SYSTEM, $start, $point, $time, $speed, $speedFactor), $point];
        };
        $low = 0.0;
        $high = max(1.0, $travel(0)[0]);
        for ($i = 0; $i < 32 && $travel($high)[0] > $high; $i++) $high *= 2;
        if ($high > 7 * 86400 || $travel($high)[0] > $high) throw new \LogicException('Не удалось рассчитать время встречи.');
        for ($i = 0; $i < 48; $i++) {
            $mid = ($low + $high) / 2;
            if ($travel($mid)[0] > $mid) $low = $mid; else $high = $mid;
        }
        return ['duration' => $high, 'target' => Coordinate::fleet($target->getId(), $travel($high)[1])];
    }

    public static function distance(Coordinate $left, Coordinate $right, float $time): float
    {
        if ($left->getGalaxy() !== $right->getGalaxy() || $left->getSystem() !== $right->getSystem()) return INF;
        $flight = new FleetFlightService();
        return $flight->calculateDistance($flight->resolvePhysicalPoint($left, $time), $flight->resolvePhysicalPoint($right, $time));
    }
}
