<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Fleets, SensorContacts, Techs, Users};
use SPGame\Game\Space\{Coordinate, CoordinateType};

/** Мобильные снимки. Ни чтение контакта, ни известный ID не дают live-доступа к цели. */
final class FleetSensorService
{
    public const SHIP_ID = 221;
    public const INTERVAL = 10;
    public const FRESH_SECONDS = 30;
    public const RETENTION = 300;
    private static float $nextScanAt = 0.0;

    private static function log2(int $value): int
    {
        if ($value < 1) throw new \InvalidArgumentException('Количество кораблей должно быть положительным.');
        $power = 0;
        while ($value >= 2) { $value = intdiv($value, 2); ++$power; }
        return $power;
    }

    public static function score(int $sensor, int $spy, int $scouts, int $ships, bool $moving): int
    {
        return 2 * (max(0, $sensor) - max(0, $spy)) + self::log2($scouts) + self::log2($ships) + ($moving ? 2 : 0);
    }

    private static function source(Fleet $fleet, float $now): ?array
    {
        $command = FleetCommands::getActiveCommand($fleet->getId());
        if ($command === null || (int)$command['command_type'] !== Command::OBSERVE->value
            || (float)$command['execute_time'] <= $now || $fleet->getStatus() !== Fleet::STATUS_MISSION) return null;
        $params = FleetControlService::params($command);
        $count = $fleet->getShips()[self::SHIP_ID] ?? null;
        $level = (int)(Techs::findExistingById($fleet->getOwnerId())['sensor_tech'] ?? 0);
        $fuel = $fleet->getFuelCurrent() - (float)($params['hold']['rate'] ?? 0)
            * max(0, $now - (float)($params['hold']['lastAt'] ?? $now));
        if ($count === null || $count->getCount() < 1 || $level < 3 || $fuel <= .000001
            || !isset($params['hold']) || isset($params['pause'])) return null;
        try { FleetBattleService::assertFleetAvailable($fleet->getId()); }
        catch (\LogicException) { return null; }
        return ['command' => $command, 'params' => $params, 'count' => $count->getCount(), 'level' => $level];
    }

    public static function state(Fleet $fleet, ?float $now = null): array
    {
        $source = self::source($fleet, $now ?? microtime(true));
        return ['available' => $source !== null, 'enabled' => $source !== null && !empty($source['params']['sensorsEnabled'])];
    }

    public static function validateMode(Fleet $fleet, AccountData $owner, mixed $enabled, ?float $now = null): void
    {
        if (!is_bool($enabled)) throw new \InvalidArgumentException('Некорректный режим сенсоров.');
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        if (self::source($fleet, $now ?? microtime(true)) === null) {
            throw new \LogicException('Для сенсоров нужны разведчик, Сенсорные технологии 3 и активное удержание с топливом вне боя.');
        }
    }

    public static function setMode(Fleet $fleet, AccountData $owner, bool $enabled, float $now): void
    {
        self::validateMode($fleet, $owner, $enabled, $now);
        $source = self::source($fleet, $now);
        if ((bool)($source['params']['sensorsEnabled'] ?? false) === $enabled) return;
        $params = $source['params'];
        $params['sensorsEnabled'] = $enabled;
        $params['sensorVersion'] = (int)($params['sensorVersion'] ?? 0) + 1;
        FleetControlService::commit([$fleet], static fn() => FleetControlService::patchCommand((int)$source['command']['id'],
            ['params' => json_encode($params, JSON_THROW_ON_ERROR)]));
    }

    private static function detectable(Fleet $fleet): bool
    {
        if ($fleet->getStatus() === Fleet::STATUS_IDLE) return false;
        $command = FleetCommands::getActiveCommand($fleet->getId()) ?? FleetCommands::getNextPendingCommand($fleet->getId());
        return !ResourceBaseService::sheltered($fleet->getCurrentCoordinate(), $command)
            && !in_array((int)($command['command_type'] ?? 0), [11, 12, Command::EXPEDITION->value], true);
    }

    /** Только азимут прямолинейного MOVE; ни конечная точка, ни ETA не выходят в DTO. */
    private static function course(Fleet $fleet, ?array $command, array $point, float $now): ?float
    {
        if ((int)($command['command_type'] ?? 0) !== Command::MOVE_IN_SYSTEM->value
            || (float)$command['execute_time'] <= $now
            || isset(FleetControlService::params($command)['pause'])) return null;
        $end = (new FleetFlightService())->calculateCurrentCoordinate($fleet, $command, (float)$command['execute_time']);
        if ($end->getGalaxy() !== (int)$point['galaxy'] || $end->getSystem() !== (int)$point['system']) return null;
        $dx = $end->getDistance() * cos(deg2rad($end->getAngle())) - $point['distance'] * cos(deg2rad($point['angle']));
        $dy = $end->getDistance() * sin(deg2rad($end->getAngle())) - $point['distance'] * sin(deg2rad($point['angle']));
        return hypot($dx, $dy) > .000001 ? fmod(rad2deg(atan2($dy, $dx)) + 360.0, 360.0) : null;
    }

    /** Чистый разрешённый DTO: только текущий участок, без чужих Fleet ID и будущих команд. */
    public static function routeSnapshot(int $score, Coordinate $start, Coordinate $end,
        Coordinate $motionStart, Coordinate $motionEnd, float $from, float $until, float $observedAt): ?array
    {
        if ($score < 8) return null;
        if (!is_finite($from) || !is_finite($until) || !is_finite($observedAt)
            || $from <= 0 || $until <= $from || $observedAt < $from || $observedAt >= $until
            || $motionStart->getType() !== CoordinateType::POINT || $motionEnd->getType() !== CoordinateType::POINT
            || $motionStart->getGalaxy() !== $motionEnd->getGalaxy() || $motionStart->getSystem() !== $motionEnd->getSystem()) {
            throw new \InvalidArgumentException('Некорректный снимок сенсорного участка.');
        }
        // FLEET endpoint не раскрывает ID другого, возможно скрытого флота.
        $start = $start->getType() === CoordinateType::FLEET ? $motionStart : $start;
        $end = $end->getType() === CoordinateType::FLEET ? $motionEnd : $end;
        foreach ([$start, $end] as $coordinate) {
            if ($coordinate->getGalaxy() !== $motionStart->getGalaxy() || $coordinate->getSystem() !== $motionStart->getSystem()) {
                throw new \InvalidArgumentException('Сенсор раскрывает только внутрисистемный участок.');
            }
        }
        return ['start' => $start->toArray(), 'end' => $end->toArray(),
            'motionStart' => $motionStart->toArray(), 'motionEnd' => $motionEnd->toArray(),
            'startsAt' => $from, 'endsAt' => $until];
    }

    private static function captureRoute(Fleet $fleet, float $now): ?array
    {
        $command = FleetCommands::getActiveCommand($fleet->getId());
        if ($command === null || (int)$command['command_type'] !== Command::MOVE_IN_SYSTEM->value
            || (float)$command['execute_time'] <= $now) return null;
        $params = FleetControlService::params($command);
        if (isset($params['pause'])) return null;
        $flight = new FleetFlightService();
        $from = $fleet->getCurrentPositionTime();
        $until = (float)$command['execute_time'];
        if ($from <= 0 || $from > $now || $until <= $from) return null;
        $start = $fleet->getCurrentCoordinate();
        // Выход к границе системы и fuelStop — не конечная цель всей миссии.
        $end = $flight->resolveCommandEndCoordinate(Command::MOVE_IN_SYSTEM, $start,
            Coordinate::fromArray($params['target']), $params, $from, $until);
        return self::routeSnapshot(8, $start, $end,
            $flight->calculateCurrentCoordinate($fleet, $command, $from),
            $flight->calculateCurrentCoordinate($fleet, $command, $until), $from, $until, $now);
    }

    /** Остаётся только старый снимок; никакой коррекции точки из живого Fleet. */
    private static function fresh(array $contact, float $now, array &$sources): bool
    {
        if (empty($contact['fresh']) || $contact['observedAt'] + self::FRESH_SECONDS <= $now) return false;
        if (Fleets::getById((int)$contact['sourceId']) === null || Fleets::getById((int)$contact['id']) === null) return false;
        $target = Fleets::getById((int)$contact['id']);
        $targetCommand = FleetCommands::getActiveCommand((int)$contact['id']) ?? FleetCommands::getNextPendingCommand((int)$contact['id']);
        if (!self::detectable(new Fleet((int)$target['id']))) return false;
        if ((int)($targetCommand['id'] ?? 0) !== (int)$contact['targetCommandId']) return false;
        $sourceId = (int)$contact['sourceId'];
        if (!array_key_exists($sourceId, $sources)) {
            $fleet = new Fleet($sourceId);
            $sources[$sourceId] = ['ownerId' => $fleet->getOwnerId(), 'state' => self::source($fleet, $now)];
        }
        $state = $sources[$sourceId]['state'];
        return $state !== null && !empty($state['params']['sensorsEnabled'])
            && $sources[$sourceId]['ownerId'] === (int)$contact['viewerId']
            && (int)$state['command']['id'] === (int)$contact['sourceCommandId']
            && (int)($state['params']['sensorVersion'] ?? 0) === (int)$contact['sourceVersion'];
    }

    public static function contacts(int $viewerId, ?float $now = null): array
    {
        $now ??= microtime(true);
        $result = [];
        $sources = [];
        foreach (SensorContacts::get($viewerId) as $id => $contact) {
            if ($contact['retainUntil'] <= $now) continue;
            $contact['fresh'] = self::fresh($contact, $now, $sources);
            // Внутренние поля источника не являются частью client DTO.
            unset($contact['sourceId'], $contact['sourceCommandId'], $contact['sourceVersion'], $contact['targetCommandId'], $contact['viewerId'], $contact['score'], $contact['_observation']);
            $result[(int)$id] = $contact;
        }
        return $result;
    }

    /** Только server-side подготовка перехвата; не DTO и не разрешение доставки. */
    public static function interceptionObservation(int $viewerId, int $targetId, float $now): ?array
    {
        $contact = SensorContacts::get($viewerId)[$targetId] ?? null;
        $sources = [];
        if ($contact === null || $contact['retainUntil'] <= $now || !self::fresh($contact, $now, $sources)) return null;
        $observation = $contact['_observation'] ?? null;
        return $observation === null ? null : $observation + ['freshUntil' => $contact['freshUntil']];
    }

    /** Один проход мира на скан; группировка по системам, а не поиск мира на каждый Page. */
    private static function observations(float $now): \Generator
    {
        $systems = $sources = [];
        $rows = Fleets::getAll();
        // Сначала только явно включённые источники. Без них не рассчитываем мир.
        foreach ($rows as $row) {
            if ((int)$row['status'] === Fleet::STATUS_IDLE) continue;
            $command = FleetCommands::getActiveCommand((int)$row['id']);
            if ($command === null || (int)$command['command_type'] !== Command::OBSERVE->value
                || empty(FleetControlService::params($command)['sensorsEnabled'])) continue;
            $fleet = new Fleet((int)$row['id']);
            $state = self::source($fleet, $now);
            if ($state === null) continue;
            $key = (int)$row['current_galaxy'] . ':' . (int)$row['current_system'];
            $power = 2 * $state['level'] + self::log2($state['count']);
            if (($sources[$fleet->getOwnerId()][$key][1]['power'] ?? PHP_INT_MIN) < $power) {
                $state['power'] = $power;
                $sources[$fleet->getOwnerId()][$key] = [$fleet, $state];
            }
            $systems[$key] ??= [];
        }
        if (!$sources) return;
        $spies = $owners = [];
        foreach ($rows as $row) {
            $key = (int)$row['current_galaxy'] . ':' . (int)$row['current_system'];
            if ((int)$row['status'] === Fleet::STATUS_IDLE || !isset($systems[$key])) continue;
            $fleet = new Fleet((int)$row['id']);
            if (!self::detectable($fleet)) continue;
            try { $point = FleetRendezvousService::position($fleet, $now)->toArray(); }
            catch (\LogicException|\InvalidArgumentException) { continue; }
            $ships = []; $total = 0;
            foreach ($fleet->getShips() as $id => $ship) if ($ship->getCount() > 0) {
                    $ships[] = ['id' => (int)$id, 'count' => $ship->getCount()];
                    $total = $ship->getCount() > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $ship->getCount();
            }
            if ($total < 1) continue;
            $command = FleetCommands::getActiveCommand($fleet->getId()) ?? FleetCommands::getNextPendingCommand($fleet->getId());
            $spies[$fleet->getOwnerId()] ??= (int)(Techs::findExistingById($fleet->getOwnerId())['spy_tech'] ?? 0);
            // Наличие MOVE в очереди не означает движение: пауза и истёкший участок не дают бонус.
            $moving = (int)($command['command_type'] ?? 0) === Command::MOVE_IN_SYSTEM->value
                && (float)$command['execute_time'] > $now
                && !isset(FleetControlService::params($command)['pause']);
            $signature = self::log2($total) - 2 * $spies[$fleet->getOwnerId()]
                + ($moving ? 2 : 0);
            $systems[$key][] = [$fleet, $point, $ships, $total, $signature, (int)($command['id'] ?? 0),
                self::course($fleet, $command, $point, $now), FleetInterceptionService::captureObserved($fleet, $now)];
        }
        // Публикуем по одному наблюдателю: не держим декартово произведение в памяти.
        $routes = [];
        foreach ($sources as $viewer => $scanners) {
            $result = [];
            foreach ($scanners as $key => [$source, $state]) foreach ($systems[$key] as [$target, $point, $ships, $total, $signature, $commandId, $course, $observation]) {
                if ($target->getOwnerId() === $viewer) continue;
                $score = $state['power'] + $signature;
                if ($score < 0) continue;
                $tier = $score >= 8 ? 3 : ($score >= 4 ? 2 : 1);
                $contact = ['id' => $target->getId(), 'viewerId' => $viewer, 'sourceId' => $source->getId(),
                    'sourceCommandId' => (int)$state['command']['id'], 'sourceVersion' => (int)($state['params']['sensorVersion'] ?? 0),
                    'targetCommandId' => $commandId,
                    'score' => $score, 'tier' => $tier, 'fresh' => true, 'position' => $point,
                    'observedAt' => $now, 'freshUntil' => $now + self::FRESH_SECONDS, 'retainUntil' => $now + self::RETENTION];
                if ($observation !== null) $contact['_observation'] = $observation;
                if ($course !== null) $contact['course'] = $course;
                if ($tier >= 2) {
                    $owners[$target->getOwnerId()] ??= (string)(Users::findExistingById($target->getOwnerId())['name'] ?? '');
                    $contact['owner_name'] = $owners[$target->getOwnerId()];
                    $lower = 1;
                    while ($lower <= intdiv($total, 10)) $lower *= 10;
                    $contact['countRange'] = [$lower, $lower > intdiv(PHP_INT_MAX, 10) ? PHP_INT_MAX : $lower * 10 - 1];
                }
                if ($tier === 3) {
                    $contact['ships'] = $ships;
                    if (!array_key_exists($target->getId(), $routes)) $routes[$target->getId()] = self::captureRoute($target, $now);
                    if ($routes[$target->getId()] !== null) $contact['route'] = $routes[$target->getId()];
                }
                $result[$target->getId()] = $contact;
            }
            yield $viewer => $result;
        }
    }

    /** Вызывается после игровых действий. Возвращает только аккаунты для прежнего ActualData. */
    public static function tick(float $now): array
    {
        $scan = $now >= self::$nextScanAt;
        if ($scan) self::$nextScanAt = $now + self::INTERVAL;
        $viewers = array_fill_keys(SensorContacts::viewers(), true);
        $affected = [];
        $publish = static function (int $viewer, array $observations) use ($now, &$affected): void {
            try {
                $old = SensorContacts::get((int)$viewer);
                $contacts = $old;
                $sources = [];
                foreach ($contacts as $id => &$contact) {
                    if ($contact['retainUntil'] <= $now) { unset($contacts[$id]); continue; }
                    $contact['fresh'] = self::fresh($contact, $now, $sources);
                }
                unset($contact);
                foreach ($observations as $id => $contact) $contacts[$id] = $contact;
                if ($contacts === $old) return;
                SensorContacts::put((int)$viewer, $contacts);
                $user = Users::findExistingById((int)$viewer);
                if ($user !== null) $affected[(int)$user['account_id']] = true;
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Ошибка публикации сенсорных контактов', ['viewerId' => $viewer, 'message' => $error->getMessage()]);
            }
        };
        if ($scan) foreach (self::observations($now) as $viewer => $contacts) {
            $publish((int)$viewer, $contacts);
            unset($viewers[$viewer]);
        }
        foreach ($viewers as $viewer => $_) $publish((int)$viewer, []);
        return array_keys($affected);
    }
}
