<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Config, FleetCommands, Fleets, GalaxyOrbits, Messages, MiningCheckpoints as Journal, OrbitalResourceSites as Sites, ResourceSurveys as Knowledge, SurveyResults as Results, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Обследование: движущийся OBJECT, личный резерв и идемпотентное завершение. */
final class FleetSurveyService
{
    public const SHIP = 222;

    public static function isResource(Coordinate $coordinate): bool
    {
        return $coordinate->getType() === CoordinateType::OBJECT
            && in_array($coordinate->getObjectType(), [ObjectType::GAS_GIANT, ObjectType::ASTEROID_SECTOR], true);
    }

    public static function site(Coordinate $coordinate): array
    {
        $site = self::isResource($coordinate) ? Sites::findExistingById((int)$coordinate->getSiteId()) : null;
        if ($site === null || (int)$site['galaxy'] !== $coordinate->getGalaxy()
            || (int)$site['system'] !== $coordinate->getSystem() || (int)$site['orbit'] !== $coordinate->getOrbit()
            || ((int)$site['site_type'] === Sites::GAS_GIANT ? ObjectType::GAS_GIANT : ObjectType::ASTEROID_SECTOR) !== $coordinate->getObjectType()) {
            throw new \InvalidArgumentException('Ресурсный объект или сектор не найден.');
        }
        return $site;
    }

    public static function coordinate(array $site): Coordinate
    {
        return Coordinate::object((int)$site['galaxy'], (int)$site['system'], (int)$site['orbit'],
            (int)$site['site_type'] === Sites::GAS_GIANT ? ObjectType::GAS_GIANT : ObjectType::ASTEROID_SECTOR,
            siteId: (int)$site['id']);
    }

    public static function label(array $site): string
    {
        return sprintf('[%d:%d:%d] ', $site['galaxy'], $site['system'], $site['orbit'])
            . ((int)$site['site_type'] === Sites::GAS_GIANT ? 'Газовый гигант' : 'Пояс · сектор ' . $site['sector']);
    }

    public static function physicalPoint(Coordinate $coordinate, float $time): SystemCoordinate
    {
        $site = self::site($coordinate);
        $orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [$site['galaxy'], $site['system'], $site['orbit']]);
        if ($orbit === null) throw new \LogicException('Орбита ресурсного объекта не найдена.');
        $rate = (float)$site['speed'] * (float)Config::getValue('SpeedPlanets', 24) / 24 / 3600
            * ((int)$site['rotation'] === 0 ? -1 : 1);
        $angle = fmod((float)$site['anchor_angle'] + ($time - (float)$site['position_time']) * $rate, 360);
        return new SystemCoordinate(galaxy: (int)$site['galaxy'], system: (int)$site['system'],
            radius: (float)$orbit['distance'], angle: $angle < 0 ? $angle + 360 : $angle,
            type: strtolower($coordinate->getObjectType()->name), orbit: (int)$site['orbit'], rotationSpeed: $rate);
    }

    public static function reason(Fleet $fleet, AccountData $owner, Coordinate $target): ?string
    {
        self::site($target);
        if ((int)($owner['Techs'][Vars::$resource[135]] ?? 0) < 3
            || (int)($owner['Techs'][Vars::$resource[124]] ?? 0) < 3) return 'Для обследования нужны Сенсорные технологии 3 и астрофизика 3.';
        if (($fleet->getShips()[self::SHIP] ?? null)?->getCount() <= 0) return 'Нужен геологоразведочный корабль.';
        return null;
    }

    /** Без мутаций; параметры повторно фиксируются при фактическом прибытии. */
    public static function estimate(Fleet $fleet, AccountData $owner, Coordinate $target): array
    {
        $site = self::site($target);
        if (($reason = self::reason($fleet, $owner, $target)) !== null) throw new \InvalidArgumentException($reason);
        $level = (int)$owner['Techs'][Vars::$resource[135]];
        $ships = (int)$fleet->getShips()[self::SHIP]->getCount();
        $difficulty = max(1, (int)$site['survey_difficulty']);
        $duration = (int)ceil(max(300, 1800 * ($difficulty / 60) / (1 + .05 * $level + .25 * log($ships, 2))));
        $known = Knowledge::findByIndex('owner_site', [$fleet->getOwnerId(), (int)$site['id']]);
        $coverage = (float)($known['coverage'] ?? 0);
        $gain = min(1 - $coverage, (10 + 5 * $level + 10 * log($ships, 2)) / $difficulty);
        return ['duration' => $duration, 'gain' => $gain, 'coverage' => $coverage, 'ships' => $ships,
            'level' => $level, 'fuel' => (float)ceil($duration * FleetDefendService::fuelRate($fleet, $owner))];
    }

    public static function assertAvailable(int $fleetId): void
    {
        $active = FleetCommands::getActiveCommand($fleetId);
        if ($active === null || (int)$active['command_type'] !== Command::RESEARCH->value) return;
        $token = FleetControlService::params($active)['survey']['token'] ?? '';
        $receipt = $token === '' ? null : Results::findByIndex('token', [$token]);
        if ($receipt !== null) throw new \LogicException('Применяется результат обследования: дождитесь завершения.');
    }

    /** Чистый допуск рабочего обследования; receipt отдельно проверяет assertAvailable. */
    public static function canInterruptForBattle(?array $command, array $target): bool
    {
        if ($command === null || (int)($command['command_type'] ?? 0) !== Command::RESEARCH->value
            || !empty($command['executed']) || !is_numeric($command['execute_time'] ?? null)) return false;
        $params = FleetControlService::params($command);
        $survey = $params['survey'] ?? null; $hold = $params['hold'] ?? null;
        if (isset($params['pause']) || ($params['target'] ?? null) !== $target
            || !is_array($survey) || !is_array($hold)
            || !is_string($survey['token'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $survey['token'])
            || ($target['type'] ?? null) !== CoordinateType::OBJECT->value
            || !in_array($target['objectType'] ?? null, [ObjectType::GAS_GIANT->value, ObjectType::ASTEROID_SECTOR->value], true)
            || (int)($target['siteId'] ?? 0) <= 0) return false;
        foreach ([$survey['startedAt'] ?? null, $survey['endsAt'] ?? null,
            $hold['startedAt'] ?? null, $hold['lastAt'] ?? null, $hold['rate'] ?? null, $command['execute_time']] as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) return false;
        }
        $start = (float)$survey['startedAt']; $end = (float)$survey['endsAt'];
        $last = (float)$hold['lastAt']; $due = (float)$command['execute_time'];
        return $start > 0 && $end > $start && abs((float)$hold['startedAt'] - $start) <= .000002
            && $last >= $start && $last <= $end + .000002 && (float)$hold['rate'] >= 0
            && $due > 0 && $due + .000002 >= $last && $due <= $end + .000002;
    }

    /** Server-only отметка прерванного прохода; обычную паузу не отзываем. */
    public static function isBattleReturn(?array $command): bool
    {
        if ($command === null || !empty($command['executed'])
            || (int)($command['command_type'] ?? 0) !== Command::OBSERVE->value
            || !is_numeric($command['execute_time'] ?? null) || (float)$command['execute_time'] !== 0.0) return false;
        $params = FleetControlService::params($command);
        return ($params['surveyBattleReturn'] ?? false) === true && ($params['pause'] ?? null) === 'battle'
            && !isset($params['survey']) && !isset($params['hold']);
    }

    private static function begin(array $command, Fleet $fleet, AccountData $owner): bool
    {
        $params = FleetControlService::params($command);
        $target = Coordinate::fromArray($params['target']);
        if ($fleet->getCurrentCoordinate()->toArray() !== $target->toArray()) throw new \LogicException('Флот ещё не прибыл к объекту обследования.');
        $estimate = self::estimate($fleet, $owner, $target);
        foreach (Fleets::findByIndex('owner_id', [$fleet->getOwnerId()]) ?? [] as $row) {
            if ((int)$row['id'] === $fleet->getId()) continue;
            $active = FleetCommands::getActiveCommand((int)$row['id']);
            if ($active !== null && (int)$active['command_type'] === Command::RESEARCH->value
                && isset(FleetControlService::params($active)['survey'])
                && (int)(FleetControlService::params($active)['target']['siteId'] ?? 0) === $target->getSiteId()) {
                throw new \LogicException('На этом объекте уже работает другой ваш флот. Дождитесь завершения и продолжите маршрут.');
            }
        }
        $time = $fleet->getCurrentPositionTime();
        $rate = FleetDefendService::fuelRate($fleet, $owner);
        $params['survey'] = $estimate + ['token' => bin2hex(random_bytes(16)), 'startedAt' => $time, 'endsAt' => $time + $estimate['duration']];
        $params['hold'] = ['startedAt' => $time, 'lastAt' => $time, 'rate' => $rate];
        FleetControlService::patchCommand((int)$command['id'], ['execute_time' => max($time, min($time + $estimate['duration'],
            $rate > 0 ? $time + $fleet->getFuelCurrent() / $rate : INF)),
            'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
        return true;
    }

    /** Чистая проекция G; обследование не зачисляет ресурсы и не вычитает запас. */
    public static function stock(array $site, float $time): array
    {
        $stock = [];
        foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $id => $key) {
            $stock[$id] = min((float)$site[$key . '_capacity'], (float)$site[$key . '_stock']
                + (float)$site[$key . '_rate'] * max(0, $time - (float)$site['resource_update_time']) / 3600);
        }
        return $stock;
    }

    /** Клиенту только личный датированный снимок, не текущий общий запас. */
    public static function observation(int $ownerId, int $siteId, ?float $time = null): ?array
    {
        $row = Knowledge::findByIndex('owner_site', [$ownerId, $siteId]);
        if ($row === null) return null;
        $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        unset($state['lastToken']);
        return ['coverage' => (float)$row['coverage'], 'observedAt' => (float)$row['observed_at'],
            'stale' => ($time ?? microtime(true)) - (float)$row['observed_at'] >= 86400]
            + $state;
    }

    public static function complete(array $command, Fleet $fleet, AccountData $owner): bool
    {
        return Journal::worldLocked(static function () use ($command, $fleet, $owner): bool {
            FleetMiningCheckpointService::recoverPending();
            if (FleetCommands::getCommand((int)$command['id']) !== $command) return true;
            return self::completeLocked($command, $fleet, $owner);
        }, true);
    }

    /** Не даём другим операциям обогнать ещё не опубликованное дообследование. */
    public static function assertMiningReady(int $siteId, ?string $allowedToken = null): void
    {
        foreach (Results::getAll() ?? [] as $receipt) {
            if ($receipt['token'] === $allowedToken) continue;
            $result = json_decode($receipt['state'], true, 512, JSON_THROW_ON_ERROR);
            if (self::pendingMining($result, $siteId)) throw new \LogicException('Объект ожидает применения результата дообследования.');
        }
    }

    public static function pendingMining(array $result, int $siteId): bool
    {
        return in_array($result['phase'] ?? null, ['preparing', 'prepared'], true) && empty($result['miningApplied'])
            && ($result['barrier']['siteId'] ?? $result['mining']['siteId'] ?? null) === $siteId;
    }

    /** Та же формула q/K; G берётся из согласованного снимка, не из груза. */
    public static function reveal(array $site, ?array $before, array $stock, float $time, int $level, int $ships, string $token): array
    {
        if (!is_finite($time) || $time < (float)($before['reserve_time'] ?? 0) || $level < 0 || $ships < 1
            || !preg_match('/^[a-f0-9]{32}$/D', $token)) throw new \InvalidArgumentException('Некорректный контекст обследования.');
        $old = json_decode($before['state'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $q = (float)($before['coverage'] ?? 0);
        if (!is_finite($q) || $q < 0 || $q > 1) throw new \InvalidArgumentException('Некорректное покрытие обследования.');
        $gain = min(1 - $q, (10 + 5 * $level + 10 * log($ships, 2)) / max(1, (int)$site['survey_difficulty']));
        $reserve = $capacity = $rate = [];
        foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $id => $key) {
            $capacity[$id] = (float)$site[$key . '_capacity']; $rate[$id] = (float)$site[$key . '_rate'];
            if (!isset($stock[$id]) || !is_finite((float)$stock[$id]) || $stock[$id] < 0 || $stock[$id] > $capacity[$id]) {
                throw new \InvalidArgumentException('Некорректный общий запас обследования.');
            }
            $regenerated = min($q * $capacity[$id], (float)($old['reserve'][$id] ?? 0)
                + $q * $rate[$id] * max(0, $time - (float)($before['reserve_time'] ?? $time)) / 3600);
            $reserve[$id] = min(($q + $gain) * $capacity[$id], $regenerated + $gain * $stock[$id]);
        }
        return ['coverage' => $q + $gain, 'reserve_time' => $time, 'observed_at' => $time,
            'state' => json_encode(['reserve' => $reserve, 'capacity' => $capacity, 'rate' => $rate, 'lastToken' => $token], JSON_THROW_ON_ERROR)];
    }

    private static function completeLocked(array $command, Fleet $fleet, AccountData $owner): bool
    {
        $params = FleetControlService::params($command);
        if (!isset($params['survey'])) {
            try { return self::begin($command, $fleet, $owner); }
            catch (\InvalidArgumentException|\LogicException $error) {
                $params['planError'] = $error->getMessage();
                FleetControlService::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
                FleetControlService::pause(FleetCommands::getCommand((int)$command['id']), 'plan');
                return true;
            }
        }
        $time = (float)$command['execute_time'];
        $token = $params['survey']['token'];
        $receipt = Results::findByIndex('token', [$token]);
        if ($receipt === null && ($time + .000001 < (float)$params['survey']['endsAt']
            || ($fleet->getShips()[self::SHIP] ?? null)?->getCount() <= 0)) {
            FleetControlService::commit([$fleet], static function () use ($fleet, $command, $time): void {
                FleetControlService::settleHold($fleet, $time);
                $params = FleetControlService::params(FleetCommands::getCommand((int)$command['id']));
                unset($params['survey'], $params['hold']);
                FleetControlService::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
                FleetControlService::pause(FleetCommands::getCommand((int)$command['id']), 'fuel');
                $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), $time);
                $fleet->save();
            });
            return true;
        }
        if ($receipt === null) {
            $site = self::site(Coordinate::fromArray($params['target']));
            self::assertMiningReady((int)$site['id']);
            // Намерение раньше первого settle: при отказе частичной фазы остальные
            // добытчики не смогут обогнать ещё не рассчитанное обследование.
            $receipt = ['id' => Results::nextId(), 'token' => $token, 'state' => json_encode([
                'phase' => 'preparing', 'barrier' => ['siteId' => (int)$site['id'], 'ownerId' => $fleet->getOwnerId(), 'at' => $time]], JSON_THROW_ON_ERROR)];
            self::write(Results::class, $receipt);
            self::flush(Results::class, (int)$receipt['id']);
        }
        if (json_decode($receipt['state'], true, 512, JSON_THROW_ON_ERROR)['phase'] === 'preparing') {
            self::flush(Results::class, (int)$receipt['id']);
            $site = self::site(Coordinate::fromArray($params['target']));
            $context = FleetMiningRuntimeService::surveyContext((int)$site['id'], $fleet->getOwnerId(), $time, $token);
            $site = $context['site'];
            $before = Knowledge::findByIndex('owner_site', [$fleet->getOwnerId(), (int)$site['id']]);
            $q = (float)($before['coverage'] ?? 0);
            $after = Knowledge::castRowToSchema(['id' => $before['id'] ?? Knowledge::nextId(),
                'owner_id' => $fleet->getOwnerId(), 'site_id' => (int)$site['id']]
                + self::reveal($site, $before, $context['stock'], $time, (int)$params['survey']['level'], (int)$params['survey']['ships'], $token), false);
            $gain = $after['coverage'] - $q;
            $reserve = json_decode($after['state'], true)['reserve'];
            $message = Messages::castRowToSchema(['to_id' => $fleet->getOwnerId(), 'type' => Messages::Type::Spy->value,
                'from' => 'from.System', 'subject' => 'subject.Survey', 'sample' => 'FleetSurveyReport', 'time' => $time,
                'data' => json_encode(['FleetName' => Notification::fleetName($fleet->getId(), $fleet->getName()),
                    'SurveyToken' => $token,
                    'TargetCoordinate' => self::label($site), 'Coverage' => round(($q + $gain) * 100, 2),
                    'Gain' => round($gain * 100, 2), 'Resources' => $reserve], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)], true);
            $state = ['phase' => 'prepared', 'knowledge' => $after, 'message' => $message, 'fuel' => max(0, $fleet->getFuelCurrent()
                - (float)$params['hold']['rate'] * max(0, $time - (float)$params['hold']['lastAt'])),
                'barrier' => ['siteId' => (int)$site['id'], 'ownerId' => $fleet->getOwnerId(), 'at' => $time]];
            if ($context['mining'] !== null) $state['mining'] = $context['mining'];
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($encoded) > 4096) throw new \OverflowException('Результат обследования превышает размер receipt.');
            $receipt['state'] = $encoded;
            if (isset($state['mining'])) FleetMiningRuntimeService::survey($receipt, true);
            self::write(Results::class, $receipt);
        }
        // Checkpoint раньше эффектов. При сбое SQL команду не завершаем;
        // retry/restart использует ТОТ ЖЕ after и ID сообщения, не новый delta_q.
        self::flush(Results::class, (int)$receipt['id']);
        $result = json_decode($receipt['state'], true, 512, JSON_THROW_ON_ERROR);
        if (isset($result['mining']) && empty($result['miningApplied'])) {
            FleetMiningRuntimeService::survey($receipt);
            $receipt = Results::findExistingById((int)$receipt['id']);
            $result = json_decode($receipt['state'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ($result['phase'] !== 'applied') {
            // nextId живёт в runtime. После crash незаписанный ID мог достаться
            // другой сущности. Перепривязываем receipt, НИКОГДА не перезаписываем её.
            $changed = false;
            $personal = Knowledge::findByIndex('owner_site', [$fleet->getOwnerId(), $result['knowledge']['site_id']]);
            $occupied = Knowledge::findExistingById((int)$result['knowledge']['id']);
            if ($personal !== null && (int)$personal['id'] !== (int)$result['knowledge']['id']) {
                $result['knowledge']['id'] = (int)$personal['id']; $changed = true;
            } elseif ($personal === null && $occupied !== null) {
                $result['knowledge']['id'] = Knowledge::nextId(); $changed = true;
            }
            $occupiedMessage = Messages::findExistingById((int)$result['message']['id']);
            if ($occupiedMessage !== null && (json_decode($occupiedMessage['data'], true)['SurveyToken'] ?? null) !== $token) {
                $result['message']['id'] = Messages::nextId(); $changed = true;
            }
            if ($changed) {
                $receipt['state'] = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                self::write(Results::class, $receipt);
                self::flush(Results::class, (int)$receipt['id']);
            }
            $oldKnowledge = Knowledge::findExistingById((int)$result['knowledge']['id']);
            $oldMessage = Messages::findExistingById((int)$result['message']['id']);
            $lastToken = json_decode($oldKnowledge['state'] ?? '{}', true, 512, JSON_THROW_ON_ERROR)['lastToken'] ?? '';
            try {
                if (empty($result['miningApplied']) && $lastToken !== $token) self::write(Knowledge::class, $result['knowledge']);
                if ($oldMessage === null) self::write(Messages::class, $result['message']);
            } catch (\Throwable $error) {
                foreach ([[Knowledge::class, $oldKnowledge, $result['knowledge']], [Messages::class, $oldMessage, $result['message']]] as [$class, $before, $after]) {
                    if ($class === Knowledge::class && !empty($result['miningApplied'])) continue;
                    try { if ($before === null) $class::discardUncommittedRow($after); else self::write($class, $before); }
                    catch (\Throwable $rollback) { Logger::getInstance()->critical('Rollback обследования не выполнен', ['class' => $class, 'error' => $rollback->getMessage()]); }
                }
                throw $error;
            }
            if (empty($result['miningApplied'])) self::flush(Knowledge::class, (int)$result['knowledge']['id']);
            self::flush(Messages::class, (int)$result['message']['id']);
            $result['phase'] = 'applied';
            self::write(Results::class, ['id' => (int)$receipt['id'], 'token' => $token, 'state' => json_encode($result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]);
            self::flush(Results::class, (int)$receipt['id']);
        }
        // Только теперь фиксируем расход и завершаем команду. При неудаче
        // следующей активации возвращаем due: результат и доклад не повторяются.
        FleetControlService::commit([$fleet], static function () use ($fleet, $owner, $command, $result, $time): void {
            $fleet->setFuelCurrent((float)$result['fuel']);
            $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), $time);
            FleetCommands::markExecuted((int)$command['id']);
            FleetCommandsService::activateNextCommand($fleet, $owner);
            $fleet->save();
        });
        try { Notification::pushMessages($owner); }
        catch (\Throwable $error) { Logger::getInstance()->error('Push доклада обследования отложен', ['fleetId' => $fleet->getId(), 'error' => $error->getMessage()]); }
        return true;
    }

    private static function write(string $class, array $row): void
    {
        if (isset($row['state']) && strlen($row['state']) > ($class === Knowledge::class ? 2048 : 4096)) {
            throw new \OverflowException('Состояние обследования не помещается в runtime запись.');
        }
        if ($class::findExistingById((int)$row['id']) === null) $class::add($row); else $class::update($row);
        if ($class::findExistingById((int)$row['id']) !== $row) throw new \RuntimeException('Не подтверждена запись обследования: ' . $class);
    }

    private static function flush(string $class, int $id): void
    {
        $class::syncToDatabase();
        if (in_array($id, $class::getPendingSync()['update'], true)) throw new \RuntimeException('Сохранение обследования отложено: ' . $class);
    }
}
