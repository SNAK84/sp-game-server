<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Fleets, GalaxyOrbits, PhalanxScans, Planets, SpaceBattles};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/**
 * Подготовка перехвата по наблюдаемому участку, НЕ исполнение ATTACK.
 * Снимки движения остаются на сервере. Обычный rendezvous доставки их не использует.
 */
final class FleetInterceptionService
{
    public static function policy(AccountData $owner, mixed $policy = null): string
    {
        $policy ??= 'last_position';
        if (!is_string($policy) || !in_array($policy, ['last_position', 'stop'], true)) {
            throw new \InvalidArgumentException('Некорректное поведение при потере контакта.');
        }
        if ($policy === 'stop' && !FleetControlService::capabilities($owner)['interceptStop']) {
            throw new \InvalidArgumentException('Остановка при потере контакта доступна с Компьютерной технологии 6.');
        }
        return $policy;
    }

    /** Только фазы, которые уже умеет останавливать пространственный боевой адаптер. */
    public static function targetObservation(Fleet $source, int $targetId, float $now, ?Coordinate $origin = null): array
    {
        if ($source->getId() === $targetId) throw new \InvalidArgumentException('Нельзя перехватить собственный флот.');
        $observation = self::observation($source->getOwnerId(), $targetId, $now);
        $origin ??= $source->getCurrentCoordinate();
        if ($origin->getType() === CoordinateType::FLEET) $origin = $origin->getLastKnownPosition();
        $point = Coordinate::fromArray($observation['position']);
        if ($origin->getGalaxy() !== $point->getGalaxy() || $origin->getSystem() !== $point->getSystem()) {
            throw new \InvalidArgumentException('Перехват доступен только внутри текущей системы флота.');
        }
        $command = self::command($targetId);
        $battle = SpaceBattles::forFleet($targetId);
        if ($battle === null) {
            $target = FleetsData::getInstance()->get($targetId);
            $context = $target === null ? null : ResourceBaseService::battleContext($target, $now);
            if ($context !== null) ResourceBaseService::assertBattleSupported($context);
        }
        if ($battle === null && ($command === null || ((float)$command['execute_time'] > 0
            && !in_array((int)$command['command_type'], [Command::MOVE_IN_SYSTEM->value, Command::OBSERVE->value, Command::RESEARCH->value], true)
            && !FleetMiningRuntimeService::canInterruptForBattle(FleetsData::getInstance()->get($targetId))))) {
            throw new \InvalidArgumentException('Эта фаза цели пока недоступна для перехвата.');
        }
        if ($battle !== null && ($battle['state']['phase'] !== 'running'
            || !in_array($source->getOwnerId(), [(int)$battle['state']['mission']['attackerId'],
                (int)$battle['state']['mission']['defenderId']], true))) {
            throw new \InvalidArgumentException('В этот бой нельзя вступить.');
        }
        return $observation;
    }

    /** Дополнение picker: только уже известные игроку IDs, без живого положения. */
    public static function targets(int $viewerId, float $now): array
    {
        $known = [];
        foreach (FleetSensorService::contacts($viewerId, $now) as $id => $contact) {
            $known[(int)$id] = ['id' => (int)$id, 'name' => 'Контакт #' . $id, 'is_own' => false,
                'owner_name' => $contact['owner_name'] ?? '', 'position' => $contact['position']];
        }
        foreach (PhalanxScans::get($viewerId, $now)['fleets'] ?? [] as $fleet) {
            $known[(int)$fleet['id']] = ['id' => (int)$fleet['id'], 'name' => $fleet['name'] ?? '',
                'is_own' => false, 'owner_name' => $fleet['ownerName'] ?? '', 'position' => null];
        }
        foreach (SpaceBattles::active() as $state) if (isset($state['mission']['attackerId'], $state['mission']['defenderId'])
            && in_array($viewerId, [(int)$state['mission']['attackerId'], (int)$state['mission']['defenderId']], true)) {
            foreach ($state['mission']['participants'] as $id => $participant) if ((int)$participant['ownerId'] !== $viewerId) {
                $known[(int)$id] = ['id' => (int)$id, 'name' => $participant['fleetName'] ?? '', 'is_own' => false,
                    'owner_name' => $participant['ownerName'] ?? '', 'position' => $state['mission']['spaceTarget']['coordinate']];
            }
        }
        foreach ($known as $id => &$target) {
            $target += ['selectable' => false, 'interceptable' => false, 'delivery' => false, 'reason' => 'Последняя отметка'];
            try {
                $observation = self::observation($viewerId, $id, $now);
                $target['position'] = $observation['position'];
                $target['observedAt'] = $observation['observedAt'];
                $target['selectable'] = $target['interceptable'] = true;
                $target['reason'] = '';
            } catch (\InvalidArgumentException|\LogicException) { /* Устаревший снимок не становится живой целью. */ }
        }
        unset($target);
        return array_values(array_filter($known, static fn($target) => $target['position'] !== null));
    }

    /** Вызывать только после проверки видимости источником сканирования. */
    public static function captureObserved(Fleet $fleet, float $now, bool $knownSegment = false): ?array
    {
        self::validTime($now);
        $command = self::command($fleet->getId());
        if (!self::available($fleet, $command)) return null;
        $activeMove = (int)($command['command_type'] ?? 0) === Command::MOVE_IN_SYSTEM->value
            && (float)$command['execute_time'] > 0;
        // Просроченный MOVE ещё не подтверждает ни прибытие, ни следующую команду.
        if ($activeMove && (float)$command['execute_time'] <= $now) return null;
        $flight = new FleetFlightService();
        $point = FleetRendezvousService::position($fleet, $now);
        $motion = ['kind' => 'stationary'];
        $params = $command === null ? [] : FleetControlService::params($command);
        if ($activeMove && !isset($params['pause'])) {
            $until = (float)$command['execute_time'];
            $end = $flight->calculateCurrentCoordinate($fleet, $command, $until);
            if ($knownSegment) {
                $motion = ['kind' => 'linear', 'until' => $until, 'end' => $end->toArray()];
            } else {
                // Мобильному сенсору неизвестен конец участка. Наблюдаем
                // скорость, но НЕ прогнозируем тайный поворот/остановку цели.
                $edge = GalaxyGenerator::getSpecialOrbit($point->getGalaxy(), $point->getSystem(), GalaxyOrbits::EXPEDITION);
                $motion = ['kind' => 'course',
                    'vx' => ($end->getDistance() * cos(deg2rad($end->getAngle())) - $point->getDistance() * cos(deg2rad($point->getAngle()))) / ($until - $now),
                    'vy' => ($end->getDistance() * sin(deg2rad($end->getAngle())) - $point->getDistance() * sin(deg2rad($point->getAngle()))) / ($until - $now),
                    'radiusLimit' => max($point->getDistance(), (float)$edge['distance'])];
            }
        } elseif ($fleet->getCurrentCoordinate()->getType() === CoordinateType::OBJECT) {
            // Стоянка у объекта следует орбите. Фиксируем измеренное угловое
            // движение, а не сохраняем live-ссылку на планету/будущие команды.
            $coordinate = $fleet->getCurrentCoordinate();
            $anchor = $coordinate->getObjectType() === null ? null : Planets::findByIndex('galaxy_system_planet_type',
                [$coordinate->getGalaxy(), $coordinate->getSystem(), $coordinate->getOrbit(), ObjectType::PLANET->value]);
            $startsAt = max($now, (float)($anchor['update_time'] ?? 0));
            $first = $flight->resolvePhysicalPoint($coordinate, $startsAt);
            $next = $flight->resolvePhysicalPoint($coordinate, $startsAt + 1);
            $delta = fmod($next->angle - $first->angle + 540.0, 360.0) - 180.0;
            $motion = ['kind' => 'orbit', 'degreesPerSecond' => $delta, 'startsAt' => $startsAt];
        }
        return ['id' => $fleet->getId(), 'observedAt' => $now, 'position' => $point->toArray(),
            'signature' => self::signature($fleet, $command), 'motion' => $motion,
            'validUntil' => $knownSegment && (float)($command['execute_time'] ?? 0) > 0 ? (float)$command['execute_time'] : null];
    }

    /** Проверяет смену фазы/траектории, но не обновляет точку старого снимка. */
    public static function matchesCurrent(array $observation, ?float $now = null): bool
    {
        $id = (int)($observation['id'] ?? 0);
        if ($id <= 0 || Fleets::getById($id) === null) return false;
        $fleet = new Fleet($id); // Свежая Table, не потенциально устаревший registry.
        $command = self::command($id);
        return self::available($fleet, $command)
            && ($now === null || (float)($command['execute_time'] ?? 0) <= 0 || (float)$command['execute_time'] > $now)
            && hash_equals((string)($observation['signature'] ?? ''), self::signature($fleet, $command));
    }

    /**
     * Отдельное разрешение только для перехвата. ID не открывает target() доставки.
     * Чтение проверяет доступность, но никогда не дополняет старое наблюдение
     * живой траекторией. Для обновления нужны новый скан либо входящая видимость.
     */
    public static function observation(int $viewerId, int $targetId, float $now): array
    {
        self::validTime($now);
        $row = $targetId > 0 ? Fleets::getById($targetId) : null;
        if ($viewerId <= 0 || $row === null || (int)$row['owner_id'] === $viewerId) {
            throw new \InvalidArgumentException('Нет актуального контакта для перехвата.');
        }
        $observations = [];
        $sensor = FleetSensorService::interceptionObservation($viewerId, $targetId, $now);
        if ($sensor !== null) $observations[] = $sensor + ['source' => 'sensor'];
        $phalanx = PhalanxScans::observation($viewerId, $targetId, $now);
        if ($phalanx !== null) $observations[] = $phalanx + ['source' => 'phalanx'];
        if (isset(FleetsServices::incomingFleetRows($viewerId)[$targetId])) {
            $incoming = self::captureObserved(new Fleet($targetId), $now, true);
            if ($incoming !== null) $observations[] = $incoming + ['source' => 'incoming'];
        }
        $battle = SpaceBattles::forFleet($targetId);
        if ($battle !== null && in_array($battle['state']['phase'], ['running', 'finished', 'settled'], true)
            && isset($battle['state']['mission']['attackerId'], $battle['state']['mission']['defenderId'])
            && in_array($viewerId, [(int)$battle['state']['mission']['attackerId'],
            (int)$battle['state']['mission']['defenderId']], true)) {
            // Собственный участник сообщает место текущего боя своему владельцу.
            // Это не наблюдение третьего игрока и не сопровождение после боя.
            $known = self::captureObserved(new Fleet($targetId), $now);
            if ($known !== null) $observations[] = $known + ['source' => 'battle'];
        }
        usort($observations, static fn($a, $b) => $b['observedAt'] <=> $a['observedAt']);
        foreach ($observations as $observation) {
            if (($observation['observedAt'] ?? INF) > $now
                || (!in_array($observation['source'], ['incoming', 'battle'], true) && ($observation['freshUntil'] ?? 0) <= $now)
                || (($observation['validUntil'] ?? null) !== null && $observation['validUntil'] <= $now)
                || !self::matchesCurrent($observation, $observation['source'] === 'battle' ? null : $now)) continue;
            return $observation;
        }
        throw new \InvalidArgumentException('Нет актуального контакта для перехвата.');
    }

    /** Чистый прогноз: не читает Fleet, Commands, планету цели или будущий маршрут. */
    public static function positionAt(array $observation, float $time): Coordinate
    {
        self::validTime($time);
        $observedAt = (float)($observation['observedAt'] ?? 0);
        self::validTime($observedAt);
        if ($time < $observedAt) throw new \InvalidArgumentException('Прогноз не может предшествовать наблюдению.');
        if (($observation['validUntil'] ?? null) !== null) {
            $until = (float)$observation['validUntil'];
            if (!is_finite($until) || $until < $observedAt) throw new \LogicException('Некорректная граница наблюдаемого участка.');
            $time = min($time, $until);
        }
        $point = Coordinate::fromArray($observation['position'] ?? []);
        if ($point->getType() !== CoordinateType::POINT) throw new \LogicException('В наблюдении отсутствует физическая точка.');
        $motion = $observation['motion'] ?? [];
        if (($motion['kind'] ?? null) === 'stationary') return $point;
        if (($motion['kind'] ?? null) === 'course') {
            $vx = (float)($motion['vx'] ?? NAN);
            $vy = (float)($motion['vy'] ?? NAN);
            $radius = (float)($motion['radiusLimit'] ?? NAN);
            if (!is_finite($vx) || !is_finite($vy) || !is_finite($radius) || $radius < $point->getDistance()) {
                throw new \LogicException('Некорректный наблюдаемый курс.');
            }
            $x = $point->getDistance() * cos(deg2rad($point->getAngle()));
            $y = $point->getDistance() * sin(deg2rad($point->getAngle()));
            $speedSquared = $vx * $vx + $vy * $vy;
            if ($speedSquared === 0.0) return $point;
            // Ограничиваем только известной геометрией системы, а не тайным
            // временем прибытия/перехода цели. За этой точкой прогноз не идёт.
            $dot = $x * $vx + $y * $vy;
            $exit = (-$dot + sqrt(max(0.0, $dot * $dot + $speedSquared * ($radius * $radius - $x * $x - $y * $y)))) / $speedSquared;
            if (!is_finite($exit)) throw new \LogicException('Не удалось ограничить прогноз курса системой.');
            $elapsed = min($time - $observedAt, max(0.0, $exit));
            $x += $vx * $elapsed;
            $y += $vy * $elapsed;
            return Coordinate::point($point->getGalaxy(), $point->getSystem(), hypot($x, $y), fmod(rad2deg(atan2($y, $x)) + 360, 360.0));
        }
        if (($motion['kind'] ?? null) === 'orbit') {
            $rate = (float)($motion['degreesPerSecond'] ?? NAN);
            if (!is_finite($rate)) throw new \LogicException('Некорректное угловое движение наблюдения.');
            $startsAt = (float)($motion['startsAt'] ?? $observedAt);
            if (!is_finite($startsAt) || $startsAt < $observedAt) throw new \LogicException('Некорректное начало орбитального движения.');
            $angle = fmod($point->getAngle() + max(0.0, $time - $startsAt) * $rate, 360.0);
            return Coordinate::point($point->getGalaxy(), $point->getSystem(), $point->getDistance(), $angle < 0 ? $angle + 360 : $angle);
        }
        if (($motion['kind'] ?? null) !== 'linear') throw new \LogicException('Неизвестное движение наблюдения.');
        $until = (float)($motion['until'] ?? 0);
        $end = Coordinate::fromArray($motion['end'] ?? []);
        if (!is_finite($until) || $until <= $observedAt || $end->getType() !== CoordinateType::POINT
            || $end->getGalaxy() !== $point->getGalaxy() || $end->getSystem() !== $point->getSystem()) {
            throw new \LogicException('Некорректный наблюдаемый участок.');
        }
        // За границей известного участка — только его последняя точка. Следующий
        // MOVE, поворот, переход или вращение цели после прибытия нам неизвестны.
        $progress = min(1.0, ($time - $observedAt) / ($until - $observedAt));
        $x = $point->getDistance() * cos(deg2rad($point->getAngle()));
        $y = $point->getDistance() * sin(deg2rad($point->getAngle()));
        $x += ($end->getDistance() * cos(deg2rad($end->getAngle())) - $x) * $progress;
        $y += ($end->getDistance() * sin(deg2rad($end->getAngle())) - $y) * $progress;
        $angle = fmod(rad2deg(atan2($y, $x)) + 360, 360.0);
        return Coordinate::point($point->getGalaxy(), $point->getSystem(), hypot($x, $y), $angle);
    }

    /**
     * Подготовка только внутрисистемной встречи. После выдачи snapshot расчёт
     * не обращается к цели даже при её уничтожении/смене курса/потере сенсора.
     * Не является разрешением на бой: исполнитель обязан заново проверить
     * контакт и фактическое совпадение позиций перед началом раунда.
     * Возвращаемый server-only FLEET содержит нашу прогнозную, НЕ live-точку.
     */
    public static function meeting(array $observation, Coordinate $start, float $departure,
        float $speed, float $speedFactor): array
    {
        self::validTime($departure);
        if (!is_finite($speed) || $speed <= 0 || !is_finite($speedFactor) || $speedFactor <= 0 || $speedFactor > 10) {
            throw new \InvalidArgumentException('Некорректная скорость перехватчика.');
        }
        $flight = new FleetFlightService();
        $travel = static function (float $after) use ($observation, $start, $departure, $speed, $speedFactor, $flight): array {
            $point = self::positionAt($observation, $departure + $after);
            if ($point->getGalaxy() !== $start->getGalaxy() || $point->getSystem() !== $start->getSystem()) {
                throw new \LogicException('Перехват внутри системы недоступен.');
            }
            return [$flight->calculateCommandDuration(Command::MOVE_IN_SYSTEM, $start, $point, $departure, $speed, $speedFactor), $point];
        };
        $low = 0.0;
        $initial = $travel(0)[0];
        $high = max(1.0, $initial);
        if ($initial === 0.0) $high = 0.0;
        for ($i = 0; $i < 32 && $high <= 7 * 86400 && $travel($high)[0] > $high; ++$i) $high *= 2;
        if ($high > 7 * 86400 || $travel($high)[0] > $high) throw new \LogicException('Не удалось рассчитать встречу по наблюдению.');
        for ($i = 0; $i < 48; ++$i) {
            $mid = ($low + $high) / 2;
            if ($travel($mid)[0] > $mid) $low = $mid; else $high = $mid;
        }
        // Действующая формула округляет время до секунд. Не назначаем более
        // ранний прилёт, чем допускает та же формула для прогнозной точки.
        $duration = ceil($high);
        [$required, $point] = $travel($duration);
        if ($required > $duration) throw new \LogicException('Не удалось согласовать время встречи с движением цели.');
        return ['duration' => $duration, 'arrivesAt' => $departure + $duration,
            'observedAt' => $observation['observedAt'], 'target' => Coordinate::fleet((int)$observation['id'], $point)];
    }

    private static function command(int $id): ?array
    {
        return FleetCommands::getActiveCommand($id) ?? FleetCommands::getNextPendingCommand($id);
    }

    private static function available(Fleet $fleet, ?array $command): bool
    {
        return $fleet->getStatus() !== Fleet::STATUS_IDLE
            && !ResourceBaseService::sheltered($fleet->getCurrentCoordinate(), $command)
            && !in_array((int)($command['command_type'] ?? 0),
            [Command::MOVE_BETWEEN_SYSTEMS->value, Command::MOVE_BETWEEN_GALAXIES->value, Command::EXPEDITION->value], true);
    }

    private static function signature(Fleet $fleet, ?array $command): string
    {
        $params = $command === null ? [] : FleetControlService::params($command);
        return hash('sha256', json_encode([$fleet->getOwnerId(), $fleet->getCurrentCoordinate()->toArray(),
            $fleet->getCurrentPositionTime(), $command['id'] ?? null, $command['command_type'] ?? null,
            $command['execute_time'] ?? null, array_intersect_key($params,
                array_flip(['target', 'motionStart', 'motionEnd', 'resolvedTarget', 'pause']))], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function validTime(float $time): void
    {
        if (!is_finite($time) || $time <= 0) throw new \InvalidArgumentException('Некорректное время наблюдения.');
    }
}
