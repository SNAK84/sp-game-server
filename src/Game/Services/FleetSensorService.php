<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\{Environment, Logger};
use SPGame\Core\RuntimeMetrics;
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
    private static ?\Generator $work = null;
    private static float $scanNow = 0.0;
    private static int $peakBytes = 0;
    private const MAX_SOURCE_SYSTEMS = 4096;

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

    /** Один наблюдатель: компактный лучший источник на систему и один результат.
     * null означает переполнение: ни один частичный контакт не публикуется.
     * Каждый yield — граница порции; никаких Fleet/маршрутов всего мира в кэше.
     */
    private static function observations(int $viewer): \Generator
    {
        $sources = [];
        foreach (Fleets::iterateIndexIds('owner_id', (string)$viewer) as $id) {
            yield null;
            $row = Fleets::findExistingById($id);
            if ($row === null || (int)$row['owner_id'] !== $viewer || (int)$row['status'] !== Fleet::STATUS_MISSION) continue;
            $state = self::source(new Fleet($id), self::$scanNow);
            if ($state === null || empty($state['params']['sensorsEnabled'])) continue;
            $key = (int)$row['current_galaxy'] . ':' . (int)$row['current_system'];
            if (!isset($sources[$key]) && count($sources) >= self::MAX_SOURCE_SYSTEMS) {
                RuntimeMetrics::increment('sensor.scanOversized');
                return null;
            }
            $power = 2 * $state['level'] + self::log2($state['count']);
            if (($sources[$key]['power'] ?? PHP_INT_MIN) < $power) $sources[$key] = [
                'id' => $id, 'power' => $power, 'commandId' => (int)$state['command']['id'],
                'version' => (int)($state['params']['sensorVersion'] ?? 0),
            ];
        }
        RuntimeMetrics::gauge('sensor.scanSystems', (float)count($sources));
        if (!$sources) return [];
        $result = [];
        $bytes = 2;
        $limit = max(2, min(SensorContacts::MAX_JSON_BYTES, Environment::getInt('SENSOR_SCAN_MAX_BYTES', SensorContacts::MAX_JSON_BYTES)));
        $candidates = 0;
        // Только системы источников и небазирующиеся статусы. Не превращаем
        // порционный скан в viewers × все флоты мира; держим один блок16 ID.
        foreach ($sources as $systemKey => $_) foreach (Fleet::nonIdleStatuses() as $status) {
            yield null; // Пустой ключ индекса тоже расходует квант работы.
            foreach (Fleets::iterateIndexIds('sensor_system', [...explode(':', $systemKey), $status]) as $id) {
                yield null;
                ++$candidates;
                RuntimeMetrics::gauge('sensor.targetCandidates', (float)$candidates);
                $row = Fleets::findExistingById($id);
                if ($row === null || (int)$row['status'] !== $status || (int)$row['owner_id'] === $viewer) continue;
                $key = (int)$row['current_galaxy'] . ':' . (int)$row['current_system'];
                if ($key !== $systemKey) continue;
                $source = $sources[$key];
                $sourceRow = Fleets::findExistingById($source['id']);
                if ($sourceRow === null || (int)$sourceRow['owner_id'] !== $viewer) continue;
                $currentSource = self::source(new Fleet($source['id']), self::$scanNow);
                if ($currentSource === null || empty($currentSource['params']['sensorsEnabled'])
                    || (int)$currentSource['command']['id'] !== $source['commandId']
                    || (int)($currentSource['params']['sensorVersion'] ?? 0) !== $source['version']) continue;
                $target = new Fleet($id);
                if (!self::detectable($target)) continue;
                $now = self::$scanNow;
                try { $point = FleetRendezvousService::position($target, $now)->toArray(); }
                catch (\LogicException|\InvalidArgumentException) { continue; }
                $ships = []; $total = 0;
                foreach ($target->getShips() as $shipId => $ship) if ($ship->getCount() > 0) {
                    $ships[] = ['id' => (int)$shipId, 'count' => $ship->getCount()];
                    $total = $ship->getCount() > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $ship->getCount();
                }
                if ($total < 1) continue;
                $command = FleetCommands::getActiveCommand($id) ?? FleetCommands::getNextPendingCommand($id);
                $spy = (int)(Techs::findExistingById($target->getOwnerId())['spy_tech'] ?? 0);
                $moving = (int)($command['command_type'] ?? 0) === Command::MOVE_IN_SYSTEM->value
                    && (float)$command['execute_time'] > $now && !isset(FleetControlService::params($command)['pause']);
                // Состав/уровень источника могли измениться между порциями.
                $power = 2 * $currentSource['level'] + self::log2($currentSource['count']);
                $score = $power + self::log2($total) - 2 * $spy + ($moving ? 2 : 0);
                if ($score < 0) continue;
                $tier = $score >= 8 ? 3 : ($score >= 4 ? 2 : 1);
                $contact = ['id' => $id, 'viewerId' => $viewer, 'sourceId' => $source['id'],
                    'sourceCommandId' => $source['commandId'], 'sourceVersion' => $source['version'],
                    'targetCommandId' => (int)($command['id'] ?? 0), 'score' => $score, 'tier' => $tier,
                    'fresh' => true, 'position' => $point, 'observedAt' => $now,
                    'freshUntil' => $now + self::FRESH_SECONDS, 'retainUntil' => $now + self::RETENTION];
                $observation = FleetInterceptionService::captureObserved($target, $now);
                if ($observation !== null) $contact['_observation'] = $observation;
                $course = self::course($target, $command, $point, $now);
                if ($course !== null) $contact['course'] = $course;
                if ($tier >= 2) {
                    $contact['owner_name'] = (string)(Users::findExistingById($target->getOwnerId())['name'] ?? '');
                    $lower = 1;
                    while ($lower <= intdiv($total, 10)) $lower *= 10;
                    $contact['countRange'] = [$lower, $lower > intdiv(PHP_INT_MAX, 10) ? PHP_INT_MAX : $lower * 10 - 1];
                }
                if ($tier === 3) {
                    $contact['ships'] = $ships;
                    $route = self::captureRoute($target, $now);
                    if ($route !== null) $contact['route'] = $route;
                }
                $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
                // Ключ, разделители и прежний контакт при повторе ID из изменившегося индекса.
                $entryBytes = strlen(json_encode($contact, $flags)) + strlen((string)$id) + 4;
                $beforeBytes = isset($result[$id]) ? strlen(json_encode($result[$id], $flags)) + strlen((string)$id) + 4 : 0;
                if ($bytes + $entryBytes - $beforeBytes > $limit) {
                    RuntimeMetrics::increment('sensor.scanOversized');
                    return null;
                }
                $bytes += $entryBytes - $beforeBytes;
                self::$peakBytes = max(self::$peakBytes, $bytes);
                RuntimeMetrics::gauge('sensor.scanPeakBytes', (float)self::$peakBytes);
                $result[$id] = $contact;
            }
        }
        return $result;
    }

    /** Один возобновляемый цикл; seen хранит только ID владельцев, не DTO. */
    private static function cycle(array $viewers, bool $scan): \Generator
    {
        $seen = [];
        foreach ($viewers as $viewer) {
            $seen[$viewer] = true;
            yield from self::observeViewer($viewer, $scan);
        }
        if (!$scan) return;
        $candidates = 0;
        foreach (FleetCommands::iterateIndexIds('command_type', (string)Command::OBSERVE->value) as $id) {
            yield null;
            RuntimeMetrics::gauge('sensor.sourceCandidates', (float)++$candidates);
            $command = FleetCommands::findExistingById($id);
            if ($command === null || (int)$command['command_type'] !== Command::OBSERVE->value
                || (int)$command['executed'] || (float)$command['execute_time'] <= self::$scanNow
                || empty(FleetControlService::params($command)['sensorsEnabled'])) continue;
            $row = Fleets::findExistingById((int)$command['fleet_id']);
            if ($row === null || isset($seen[(int)$row['owner_id']])) continue;
            $viewer = (int)$row['owner_id'];
            $seen[$viewer] = true;
            yield from self::observeViewer($viewer, true);
        }
    }

    private static function observeViewer(int $viewer, bool $scan): \Generator
    {
        yield null;
        try {
            $contacts = $scan ? yield from self::observations($viewer) : [];
            yield [$viewer, $contacts ?? []];
        } catch (\Throwable $error) {
            Logger::getInstance()->error('Ошибка подготовки сенсорных контактов', ['viewerId' => $viewer, 'message' => $error->getMessage()]);
        }
    }

    /** Вызывается после игровых действий. Возвращает только аккаунты для прежнего ActualData. */
    public static function tick(float $now): array
    {
        self::$scanNow = $now;
        $cache = SensorContacts::sweep($now);
        $affected = [];
        foreach ($cache['expired'] as $viewer) {
            $user = Users::findExistingById($viewer);
            if ($user !== null) $affected[(int)$user['account_id']] = true;
        }
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
                foreach ($observations as $id => $contact) {
                    if ($contact['retainUntil'] <= $now) continue;
                    // Источник мог выключиться/цель уйти в скрытую фазу между
                    // порциями. observedAt остаётся временем реального наблюдения.
                    $contact['fresh'] = self::fresh($contact, $now, $sources);
                    $contacts[$id] = $contact;
                }
                if ($contacts === $old) return;
                if (!SensorContacts::put((int)$viewer, $contacts)) return;
                $user = Users::findExistingById((int)$viewer);
                if ($user !== null) $affected[(int)$user['account_id']] = true;
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Ошибка публикации сенсорных контактов', ['viewerId' => $viewer, 'message' => $error->getMessage()]);
            }
        };
        if (self::$work === null) {
            $scan = $now >= self::$nextScanAt;
            if ($scan) self::$nextScanAt = $now + self::INTERVAL;
            if ($scan) {
                self::$peakBytes = 0;
                RuntimeMetrics::gauge('sensor.scanPeakBytes', 0);
            }
            self::$work = self::cycle($cache['viewers'], $scan);
        }
        $maxSteps = max(1, min(16384, Environment::getInt('SENSOR_SCAN_MAX_STEPS', 1024)));
        $budgetMs = max(1, min(25, Environment::getInt('SENSOR_SCAN_BUDGET_MS', 5)));
        $start = hrtime(true);
        $steps = 0;
        try {
            while ($steps < $maxSteps && (hrtime(true) - $start) / 1000000 < $budgetMs) {
                if (!self::$work->valid()) { self::$work = null; break; }
                $step = self::$work->current();
                if ($step !== null) $publish($step[0], $step[1]);
                ++$steps;
                self::$work->next();
            }
        } catch (\Throwable $error) {
            self::$work = null;
            self::$nextScanAt = max(self::$nextScanAt, $now + self::INTERVAL);
            Logger::getInstance()->error('Ошибка обхода сенсорных кандидатов', ['message' => $error->getMessage()]);
        }
        if (self::$work !== null) RuntimeMetrics::increment('sensor.scanBudgetHits');
        RuntimeMetrics::gauge('sensor.scanLastSteps', (float)$steps);
        $elapsedMs = (hrtime(true) - $start) / 1000000;
        RuntimeMetrics::gauge('sensor.scanLastMs', $elapsedMs);
        RuntimeMetrics::observe('sensor.scan', $elapsedMs);
        RuntimeMetrics::gauge('sensor.scanPending', self::$work === null ? 0 : 1);
        RuntimeMetrics::gauge('sensor.scanMaxSteps', (float)$maxSteps);
        RuntimeMetrics::gauge('sensor.scanBudgetMs', (float)$budgetMs);
        RuntimeMetrics::gauge('sensor.scanMaxBytes', (float)max(2, min(SensorContacts::MAX_JSON_BYTES,
            Environment::getInt('SENSOR_SCAN_MAX_BYTES', SensorContacts::MAX_JSON_BYTES))));
        SensorContacts::recordMetrics();
        return array_keys($affected);
    }
}
