<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Core\Logger;
use SPGame\Game\Repositories\{Config, FleetCommands, Fleets, Friends, Planets, ResourceBases, SpaceBattles, Users, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Владение и приписка ресурсных баз. Без планетарного склада и выдачи ресурса. */
final class ResourceBaseService
{
    public static function isEnabled(): bool
    {
        return ResourceBases::isReady() && (int)(Config::getValue('ResourceBasesEnabled') ?? 0) === 1;
    }

    /** Первый слой вылета: остальные миссии подключаются после своих return/receipt paths. */
    public static function departureMissions(): array
    {
        return [FleetMissionType::TRANSPORT->value, FleetMissionType::RELOCATE->value, FleetMissionType::MERGE->value, FleetMissionType::MINING->value, FleetMissionType::CAPTURE->value];
    }

    /** Публично только владение; состав, бак и запас обследования сюда не входят. */
    public static function publicControl(int $siteId, int $viewerId): ?array
    {
        if (!self::isEnabled()) return null;
        $base = ResourceBases::findExistingById($siteId);
        if ($base === null) return null;
        return ['ownerId' => (int)$base['owner_id'],
            'ownerName' => (string)(Users::findExistingById((int)$base['owner_id'])['name'] ?? ''),
            'isOwn' => (int)$base['owner_id'] === $viewerId];
    }

    /** Один защищаемый siteId, не вся орбита и не ID выбранного флота. */
    public static function battleAt(int $siteId): ?array
    {
        foreach (SpaceBattles::active() as $key => $state) {
            if ((int)($state['mission']['resourceSite']['id'] ?? 0) === $siteId) return ['key' => $key, 'state' => $state];
        }
        return null;
    }

    /** Чистый фильтр присутствия: приписанный, но улетевший флот не защищает базу. */
    public static function defendsSite(int $fleetOwner, array $position, ?array $command,
        array $at, int $baseOwner, bool $friend, float $time): bool
    {
        if ($position !== $at) return false;
        $params = $command === null ? [] : FleetControlService::params($command);
        if ($fleetOwner !== $baseOwner) {
            return $friend && (int)($command['command_type'] ?? 0) === FleetCommandType::DEFEND->value
                && isset($params['hold']) && ($params['target'] ?? null) === $at
                && !isset($params['pause']) && (float)$command['execute_time'] > $time;
        }
        return isset($params['pause']) || !in_array((int)($command['command_type'] ?? 0),
            [FleetCommandType::MOVE_IN_SYSTEM->value, FleetCommandType::MOVE_BETWEEN_SYSTEMS->value,
                FleetCommandType::MOVE_BETWEEN_GALAXIES->value, FleetCommandType::EXPEDITION->value], true)
            || (float)($command['execute_time'] ?? 0) <= 0;
    }

    /** Размещение у объекта скрыто; сохранённая OBJECT начала текущего MOVE — не база. */
    public static function sheltered(Coordinate $position, ?array $command): bool
    {
        if (!FleetSurveyService::isResource($position)) return false;
        return (int)($command['command_type'] ?? 0) !== FleetCommandType::MOVE_IN_SYSTEM->value
            || (float)($command['execute_time'] ?? 0) <= 0
            || isset(FleetControlService::params($command)['pause']);
    }

    /** Внутренний состав, не публичный ответ/обход сенсорной видимости. */
    public static function battleContext(Fleet $target, float $time): ?array
    {
        $coordinate = $target->getCurrentCoordinate();
        if (!self::isEnabled() || !FleetSurveyService::isResource($coordinate)) return null;
        if (!self::defendsSite($target->getOwnerId(), $coordinate->toArray(),
            FleetCommands::getActiveCommand($target->getId()), $coordinate->toArray(), $target->getOwnerId(), false, $time)) return null;
        $site = FleetSurveyService::site($coordinate);
        $base = ResourceBases::findExistingById((int)$site['id']);
        if ($base === null) throw new \LogicException('Общий бой за свободный ресурсный объект пока не подключён.');
        $at = FleetSurveyService::coordinate($site)->toArray();
        $fleets = self::defendingFleets($base, $coordinate, $time);
        if (!isset($fleets[$target->getId()])) throw new \LogicException('Цель у ресурсного объекта не входит в поддержанную общую оборону.');
        return ['site' => $site, 'base' => $base, 'coordinate' => $at, 'fleets' => $fleets];
    }

    /** OBJECT задаёт общий объект обороны, не раскрывая игроку ID защитников. */
    public static function objectBattleContext(Coordinate $coordinate, float $time): ?array
    {
        $site = FleetSurveyService::site($coordinate);
        if ($coordinate->toArray() !== FleetSurveyService::coordinate($site)->toArray()) {
            throw new \InvalidArgumentException('Для захвата выберите объект без ручного угла.');
        }
        $base = ResourceBases::findExistingById((int)$site['id']);
        return $base === null ? null : ['site'=>$site, 'base'=>$base, 'coordinate'=>$coordinate->toArray(),
            'fleets'=>self::defendingFleets($base, $coordinate, $time)];
    }

    /** Общий фактический состав для боя и шпионского снимка, без допуска боевых фаз. */
    public static function defendingFleets(array $base, Coordinate $coordinate, float $time): array
    {
        $at = $coordinate->toArray();
        if ((int)$coordinate->getSiteId() !== (int)$base['id']) throw new \LogicException('Другой объект общего состава защиты.');
        $rows = Fleets::findByIndex('owner_id', [(int)$base['owner_id']]) ?? [];
        foreach (FleetCommands::getActiveDefenseCommands() as $guard) {
            $row = Fleets::findExistingById((int)$guard['fleet_id']);
            if ($row !== null && (int)$row['owner_id'] !== (int)$base['owner_id']) $rows[] = $row;
        }
        $fleets = [];
        foreach ($rows as $row) {
            $fleet = FleetsData::getInstance()->get((int)$row['id']);
            if ($fleet === null || $fleet->getTotalShips() <= 0) continue;
            $command = FleetCommands::getActiveCommand($fleet->getId());
            if (self::defendsSite($fleet->getOwnerId(), $fleet->getCurrentCoordinate()->toArray(), $command,
                $at, (int)$base['owner_id'], Friends::areFriends($fleet->getOwnerId(), (int)$base['owner_id']), $time)) {
                $fleets[$fleet->getId()] = $fleet;
            }
        }
        ksort($fleets, SORT_NUMERIC);
        return $fleets;
    }

    /** Не исключать неподдержанного защитника из общего боя ради одиночной атаки. */
    public static function assertBattleSupported(array $context): void
    {
        if (self::battleAt((int)$context['site']['id']) !== null) throw new \LogicException('У ресурсного объекта уже идёт общий бой.');
        foreach ($context['fleets'] as $fleet) {
            $command = FleetCommands::getActiveCommand($fleet->getId()) ?? FleetCommands::getNextPendingCommand($fleet->getId());
            $idle = $fleet->getStatus() === Fleet::STATUS_IDLE && FleetCommands::getFleetQueue($fleet->getId()) === [];
            $params = $command === null ? [] : FleetControlService::params($command);
            // Durable close предшествует боевому before. Не запирать повтор входа,
            // если подготовка боя оборвалась уже после закрытия ресурсного MINING.
            $closed = ($params['miningClosed']['reason'] ?? null) === 'battle'
                && ($params['mining']['phase'] ?? null) === 'finished'
                && (int)($params['mining']['siteId'] ?? 0) === (int)$context['site']['id'];
            $observer = $command !== null && (int)$command['command_type'] === FleetCommandType::OBSERVE->value
                && ($fleet->getHomeSiteId() === 0 || $closed) && !isset($params['hold']);
            $observer = $observer || self::isPostBattleGuard($fleet);
            $survey = FleetSurveyService::canInterruptForBattle($command, $context['coordinate']);
            $defense = FleetResourceDefendService::canInterrupt($command,$context['coordinate'],(int)$context['base']['owner_id'])
                && $fleet->getBaseFuelState() !== null;
            $pausedMove = self::pausedMoveAtResource($fleet) !== null;
            if (!$idle && !$observer && !$survey && !$defense && !$pausedMove && !FleetMiningRuntimeService::canInterruptForBattle($fleet)) {
                throw new \LogicException('Общий бой пока не поддерживает фазу одного из защитников. Одиночная атака запрещена.');
            }
            FleetBattleService::assertFleetAvailable($fleet->getId(), FleetMiningRuntimeService::hasMining($fleet->getId()));
        }
    }

    /** Остановившийся у OBJECT флот не выполняет перелёт, но защищает объект. */
    public static function pausedMoveAtResource(Fleet $fleet): ?array
    {
        if (!FleetSurveyService::isResource($fleet->getCurrentCoordinate())
            || FleetCommands::getActiveCommand($fleet->getId()) !== null) return null;
        $command = FleetCommands::getNextPendingCommand($fleet->getId());
        if ($command === null || (int)$command['command_type'] !== FleetCommandType::MOVE_IN_SYSTEM->value) return null;
        $pause = FleetControlService::params($command)['pause'] ?? null;
        // Без явной паузы это ещё не начавшаяся команда; battle принадлежит
        // прежнему freeze и не должен повторно выдаваться за остановку игрока.
        return is_string($pause) && $pause !== '' && $pause !== 'battle' ? $command : null;
    }

    /** Личный read-only snapshot. Не начисляет расход и не меняет приписку. */
    public static function listFor(AccountData $owner): array
    {
        $level = (int)($owner['Techs'][Vars::$resource[136]] ?? 0);
        $bases = [];
        if (self::isEnabled()) foreach (ResourceBases::ownedBy((int)$owner['User']['id']) as $base) {
            $site = self::site((int)$base['id']);
            $bases[] = ['siteId' => (int)$base['id'], 'label' => FleetSurveyService::label($site),
                'coordinates' => FleetSurveyService::coordinate($site)->toArray(),
                'slots' => self::baseSlots($owner, (int)$base['id'])];
        }
        return ['enabled' => self::isEnabled(), 'used' => count($bases), 'max' => self::controlLimit($level), 'bases' => $bases];
    }

    public static function standingState(Fleet $fleet, float $time): ?array
    {
        $clock = $fleet->getBaseFuelState();
        if ($clock === null || ($fleet->getHomeSiteId() <= 0 && !self::isPostBattleGuard($fleet)
            && !FleetResourceDefendService::isCommand(FleetCommands::getActiveCommand($fleet->getId())))) return null;
        $projection = self::standingProjection($fleet->getFuelCurrent(), $clock, max($time, $clock['time']));
        return ['fuel' => $projection['fuel'], 'time' => $projection['time'],
            'perHour' => $clock['rate'] * 3600, 'reserve' => $clock['reserve'],
            'evacuationAt' => $projection['due'], 'speedFactor' => $clock['speed']];
    }

    public static function departureCoordinate(Fleet $fleet): Coordinate
    {
        if (!self::isEnabled()) throw new \LogicException('Ресурсные базы ещё не включены на сервере.');
        if ($fleet->getHomeSiteId() <= 0) throw new \LogicException('У флота нет ресурсной приписки.');
        FleetBattleService::assertFleetAvailable($fleet->getId());
        if ($fleet->getTotalShips() <= 0) throw new \LogicException('Пустой флот не может вылететь с базы.');
        $home = self::homeCoordinate($fleet);
        if ($fleet->getCurrentCoordinate()->toArray() !== $home->toArray()) {
            throw new \LogicException('Флот не находится у своей ресурсной базы.');
        }
        return $home;
    }

    /** Чистая проекция: резерв не увеличивает бак и не тратится на стоянку. */
    public static function standingProjection(float $fuel, array $clock, float $time): array
    {
        foreach ([$fuel, $time, $clock['time'] ?? -1, $clock['rate'] ?? -1, $clock['reserve'] ?? -1] as $value) {
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0) {
                throw new \InvalidArgumentException('Некорректные данные расхода стоянки.');
            }
        }
        if ($time < $clock['time']) throw new \LogicException('Стоянку нельзя пересчитать в прошлое.');
        $available = max(0.0, $fuel - (float)$clock['reserve']);
        $rate = (float)$clock['rate'];
        $due = $rate > 0 ? (float)$clock['time'] + $available / $rate : null;
        $cost = min($available, $rate * ($time - (float)$clock['time']));
        return ['fuel' => max(0.0, $fuel - $cost), 'time' => $due === null ? $time : min($time, $due),
            'rate' => $rate, 'reserve' => (float)$clock['reserve'], 'due' => $due];
    }

    /** Вызывается внутри commit прибытия/изменения состава, не при чтении страницы. */
    public static function beginStanding(Fleet $fleet, AccountData $owner, float $time, float $speed = 10.0,
        ?Coordinate $origin = null): void
    {
        $origin ??= self::homeCoordinate($fleet);
        if ($fleet->getHomeSiteId() > 0) $reserve = self::evacuationReserve($fleet, $owner, $origin, $time, $speed);
        else {
            $home = self::homeCoordinate($fleet);
            $travel = (new FleetMissionService())->getTravelInfo($fleet, FleetTarget::fromCoordinates($home->toArray()),
                $owner, $speed, null, $origin, $time);
            if ($travel === null) throw new \LogicException('Не рассчитан резерв возвращения охраны объекта.');
            $reserve = ceil((float)$travel['fuel']['max']);
        }
        $factor = (float)(Config::getValue('FleetHoldFuelPerHourFactor') ?? .1);
        if (!is_finite($factor) || $factor < 0) throw new \LogicException('Некорректный коэффициент расхода стоянки.');
        $fleet->setBaseFuelState(['time' => $time, 'rate' => $fleet->getTotalFuelConsumption($owner) * $factor / 3600,
            'reserve' => $reserve, 'speed' => $speed]);
    }

    /** Один путь до запасной планеты/луны. Добыча и стоянка защищают один резерв. */
    public static function evacuationReserve(Fleet $fleet, AccountData $owner, Coordinate $origin, float $time, float $speed): float
    {
        $fallback = self::fallbackCoordinate($fleet);
        $travel = (new FleetMissionService())->getTravelInfo($fleet, FleetTarget::fromCoordinates($fallback->toArray()),
            $owner, $speed, null, $origin, $time);
        if ($travel === null) throw new \LogicException('Не удалось рассчитать резерв эвакуации с базы.');
        return ceil((float)$travel['fuel']['max']);
    }

    /** Только бортовой бак и checkpoint Fleet. Внешний commit решает сохранение/компенсацию. */
    public static function settleStanding(Fleet $fleet, float $time): void
    {
        $clock = $fleet->getBaseFuelState();
        if ($clock === null) return;
        $next = self::standingProjection($fleet->getFuelCurrent(), $clock, $time);
        $fleet->setFuelCurrent($next['fuel']);
        $fleet->setBaseFuelState(['time' => $next['time'], 'rate' => $next['rate'],
            'reserve' => $next['reserve'], 'speed' => $clock['speed']]);
    }

    private static function members(array $base): array
    {
        $result = [];
        foreach (Fleets::findByIndex('owner_id', [(int)$base['owner_id']]) ?? [] as $row) {
            if ((int)($row['home_site_id'] ?? 0) !== (int)$base['id']
                && ((int)($row['current_site_id'] ?? 0) !== (int)$base['id'] || (float)($row['base_fuel_time'] ?? 0) <= 0)) continue;
            $fleet = FleetsData::getInstance()->get((int)$row['id']);
            if ($fleet !== null && ((int)($row['home_site_id'] ?? 0) === (int)$base['id']
                || self::isPostBattleGuard($fleet) && $fleet->getCurrentCoordinate()->getSiteId() === (int)$base['id'])) $result[] = $fleet;
        }
        return $result;
    }

    private static function standingAt(Fleet $fleet, Coordinate $coordinate): bool
    {
        return $fleet->getTotalShips() > 0
            && $fleet->getCurrentCoordinate()->toArray() === $coordinate->toArray()
            && (($fleet->getStatus() === Fleet::STATUS_IDLE && FleetCommands::getFleetQueue($fleet->getId()) === [])
                || self::isPostBattleGuard($fleet));
    }

    /** Только явно назначенная результатом боя платная охрана, не любая pause. */
    public static function postBattleGuard(array $position, ?array $command, ?array $clock): bool
    {
        $params = $command === null ? [] : FleetControlService::params($command);
        return $clock !== null && (int)($command['command_type'] ?? 0) === FleetCommandType::OBSERVE->value
            && (float)($command['execute_time'] ?? 0) === 0.0 && empty($command['executed'])
            && ($params['pause'] ?? null) === 'battle' && (int)($params['siteGuard'] ?? 0) > 0
            && ($position['type'] ?? null) === CoordinateType::OBJECT->value
            && in_array($position['objectType'] ?? null, [ObjectType::GAS_GIANT->value, ObjectType::ASTEROID_SECTOR->value], true)
            && ($position['siteId'] ?? null) === $params['siteGuard'] && ($params['target'] ?? null) === $position;
    }

    public static function isPostBattleGuard(Fleet $fleet): bool
    {
        return self::postBattleGuard($fleet->getCurrentCoordinate()->toArray(),
            FleetCommands::getNextPendingCommand($fleet->getId()), $fleet->getBaseFuelState());
    }

    private static function combatCapable(Fleet $fleet): bool
    {
        foreach ($fleet->getShips() as $ship) {
            if ($ship->getCount() > 0 && (float)(Vars::$CombatCaps[$ship->getShipTypeId()]['attack'] ?? 0) > 0) return true;
        }
        return false;
    }

    private static function hasGuard(array $base, array $members, float $time): bool
    {
        $at = FleetSurveyService::coordinate(self::site((int)$base['id']));
        foreach ($members as $fleet) if (self::standingAt($fleet, $at) && self::combatCapable($fleet)) return true;
        if (FleetMiningRuntimeService::guards((int)$base['id'], (int)$base['owner_id'])) return true;
        // Само прибытие друга/OBSERVE не даёт охрану. Нужна активная подтверждённая DEFEND.
        // Ресурсная DEFEND оплачивает свой бортовой clock, планетарная — снабжение хозяина.
        foreach (FleetCommands::getActiveDefenseCommands() as $command) {
            $params = FleetControlService::params($command);
            if (!isset($params['hold']) || (float)$command['execute_time'] <= $time
                || ($params['target'] ?? null) !== $at->toArray()) continue;
            $guard = FleetsData::getInstance()->get((int)$command['fleet_id']);
            if ($guard !== null && $guard->getCurrentCoordinate()->toArray() === $at->toArray()
                && $guard->getFuelCurrent() > 0 && self::combatCapable($guard)
                && Friends::areFriends($guard->getOwnerId(), (int)$base['owner_id'])) return true;
        }
        return false;
    }

    /** Preview не снимает контроль; учитывает только охрану, остающуюся после вылета. */
    public static function willReleaseOnDeparture(Fleet $fleet, float $time): bool
    {
        $base = ResourceBases::findExistingById($fleet->getHomeSiteId());
        if ($base === null || (int)$base['owner_id'] !== $fleet->getOwnerId()) return true;
        return !self::hasGuard($base, array_filter(self::members($base),
            static fn(Fleet $member): bool => $member->getId() !== $fleet->getId()), $time);
    }

    /** Только порядок событий на одной границе; права и состояние проверяет begin MINING. */
    public static function miningStartsAt(array $command, array $target, float $time): bool
    {
        if ((int)($command['command_type'] ?? 0) !== FleetCommandType::MINING->value
            || !empty($command['executed']) || $time <= 0 || (float)($command['execute_time'] ?? 0) !== $time) return false;
        $params = FleetControlService::params($command);
        return !isset($params['mining']) && !isset($params['pause'])
            && ($params['target'] ?? null) === $target;
    }

    /** Прибытие на свою базу ещё не STAY: сначала исполняем его due-команду. */
    public static function arrivalContinuesAt(array $command, array $target, float $time, float $positionTime): bool
    {
        if (!empty($command['executed']) || $time <= 0 || $positionTime !== $time
            || (float)($command['execute_time'] ?? 0) !== $time || (int)($target['siteId'] ?? 0) <= 0) return false;
        $params = FleetControlService::params($command);
        if (isset($params['pause'])) return false;
        $type = (int)($command['command_type'] ?? 0);
        if ($type === FleetCommandType::STAY->value) return ($params['at'] ?? null) === $target;
        // Только нулевой возврат, не вылет/длительный MOVE из сохранённого OBJECT.
        return $type === FleetCommandType::MOVE_IN_SYSTEM->value && !empty($params['returnLeg'])
            && (int)($params['homeSiteId'] ?? 0) === (int)$target['siteId']
            && ($params['target'] ?? null) === $target;
    }

    /** Виртуальное событие для существующего chronological scheduler, не строка FleetCommands. */
    public static function nextEvent(float $now, array &$failed = []): ?array
    {
        if (!self::isEnabled()) return null;
        $next = null;
        foreach (ResourceBases::getAll() as $base) {
            $id = -(int)$base['id'];
            if (self::battleAt((int)$base['id']) !== null) continue;
            if (isset($failed[$id])) continue;
            try {
                $members = self::members($base);
                $at = FleetSurveyService::coordinate(self::site((int)$base['id']));
                $eventTime = INF;
                $lossTime = (float)$base['updated_at'];
                foreach ($members as $fleet) {
                    $lossTime = max($lossTime, $fleet->getCurrentPositionTime(), (float)($fleet->getBaseFuelState()['time'] ?? 0));
                    if (!self::standingAt($fleet, $at)) continue;
                    $clock = $fleet->getBaseFuelState();
                    $due = $clock === null ? max((float)$base['created_at'], $fleet->getCurrentPositionTime())
                        : self::standingProjection($fleet->getFuelCurrent(), $clock, $clock['time'])['due'];
                    if ($due !== null) $eventTime = min($eventTime, $due);
                }
                if (!self::hasGuard($base, $members, $now)) {
                    foreach (FleetCommands::getActiveDefenseCommands() as $guard) {
                        $params = FleetControlService::params($guard);
                        if (isset($params['hold']) && ($params['target'] ?? null) === $at->toArray()) {
                            $lossTime = max($lossTime, min($now, (float)$guard['execute_time']));
                        }
                    }
                    // MINING begin и возврат MOVE→STAY — отдельные события, ещё не IDLE.
                    // Даём переходу одну штатную попытку на ТОЙ ЖЕ границе. Это не охрана:
                    // после ошибки failed исключит флот, и потеря базы выполнится в этом tick.
                    $transitioning = false;
                    foreach ($members as $fleet) {
                        if (isset($failed[$fleet->getId()]) || !self::combatCapable($fleet)
                            || $fleet->getCurrentCoordinate()->toArray() !== $at->toArray()) continue;
                        $active = FleetCommands::getActiveCommands($fleet->getId());
                        if (count($active) === 1 && (self::miningStartsAt($active[0], $at->toArray(), $lossTime)
                                || self::arrivalContinuesAt($active[0], $at->toArray(), $lossTime, $fleet->getCurrentPositionTime()))
                            && !FleetAttackService::mustWait($active[0])) {
                            $transitioning = true;
                            break;
                        }
                    }
                    if (!$transitioning) $eventTime = min($eventTime, $lossTime);
                }
                if ($eventTime > $now) continue;
                $event = ['id' => $id, 'fleet_id' => $id, 'command_type' => FleetCommandType::STAY->value,
                    'execute_time' => $eventTime, 'resource_base_id' => (int)$base['id']];
                if ($next === null || [$eventTime, $id] < [(float)$next['execute_time'], (int)$next['id']]) $next = $event;
            } catch (\Throwable $error) {
                $failed[$id] = true;
                Logger::getInstance()->error('Событие ресурсной базы отложено', ['siteId' => $base['id'], 'error' => $error->getMessage()]);
            }
        }
        return $next;
    }

    /** Вычитание только оплаченного интервала. Отключённые/offline Accounts не исключаются. */
    public static function settleWorld(float $time): void
    {
        if (!self::isEnabled()) return;
        foreach (ResourceBases::getAll() as $base) {
            if (self::battleAt((int)$base['id']) !== null) continue;
            try {
                $at = FleetSurveyService::coordinate(self::site((int)$base['id']));
                foreach (self::members($base) as $fleet) {
                    if (!self::standingAt($fleet, $at) || $fleet->getBaseFuelState() === null) continue;
                    try {
                        FleetControlService::commit([$fleet], static function () use ($fleet, $time): void {
                            self::settleStanding($fleet, $time);
                            $fleet->save();
                        });
                    } catch (\Throwable $error) {
                        Logger::getInstance()->error('Расход стоянки отложен', ['fleetId' => $fleet->getId(), 'error' => $error->getMessage()]);
                    }
                }
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Расход ресурсной базы отложен', ['siteId' => $base['id'], 'error' => $error->getMessage()]);
            }
        }
    }

    private static function account(int $ownerId): AccountData
    {
        $user = Users::findExistingById($ownerId);
        if ($user === null || (int)$user['account_id'] <= 0) throw new \LogicException('Владелец ресурсной базы недоступен.');
        return new AccountData((int)$user['account_id']);
    }

    private static function evacuate(Fleet $fleet, AccountData $owner, int $siteId, float $time): void
    {
        $speed = (float)($fleet->getBaseFuelState()['speed'] ?? 10.0);
        try { $fallback = self::fallbackPlanet($fleet); }
        catch (\LogicException) {
            self::pauseWithoutFallback($fleet, $siteId, $time);
            return;
        }
        $at = $fleet->getCurrentCoordinate();
        $home = self::planetCoordinate($fallback);
        if (self::isPostBattleGuard($fleet)) foreach (FleetCommands::getFleetQueue($fleet->getId()) as $old) {
            $params = FleetControlService::params($old); $params['cancelled'] = true;
            FleetControlService::patchCommand((int)$old['id'], ['executed' => 1,
                'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
        }
        $fleet->setHomePlanetId((int)$fallback['id']);
        $fleet->setStartCoordinate($at);
        $fleet->setTargetCoordinate($home);
        $fleet->setCurrentCoordinate($at, $time);
        $fleet->setStatus(Fleet::STATUS_MISSION);
        $fleet->setMission(FleetMissionType::RELOCATE);
        $fleet->setReturn(true);
        foreach (FleetMissionBuilder::buildReturn($at, $home) as $command) {
            FleetControlService::add($fleet->getId(), $command['type'], $command['params']
                + ['speedFactor' => $speed, 'returnLeg' => true, 'resourceBaseLost' => $siteId]);
        }
        FleetCommandsService::activateNextCommand($fleet, $owner);
        $fleet->save(); // Нехватка топлива остаётся обычной pause=fuel, не удалением флота.
    }

    /** Утрата последней базы не создаёт Planet0 и не разрешает бесплатный полёт. */
    public static function pauseWithoutFallback(Fleet $fleet, int $siteId, float $time): void
    {
        $active = FleetCommands::getActiveCommand($fleet->getId());
        $travelled = null;
        if ($active !== null && in_array((int)$active['command_type'], [11, 12], true) && $time < (float)$active['execute_time']) {
            $params = FleetControlService::params($active);
            unset($params['recallAfterTransition'], $params['resourceBaseLost']);
            $params['stopForLostBase'] = $siteId;
            FleetControlService::patchCommand((int)$active['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            $fleet->setBaseFuelState(null);
            $fleet->save();
            return;
        }
        if ($active !== null && (int)$active['command_type'] === FleetCommandType::MOVE_IN_SYSTEM->value) {
            $params = FleetControlService::params($active);
            $start = $fleet->getCurrentPositionTime();
            $time = max($start, min($time, (float)$active['execute_time']));
            $position = (new FleetFlightService())->calculateCurrentCoordinate($fleet, $active, $time);
            $fraction = min(1.0, max(0.0, ($time - $start) / max(.000001, (float)$active['execute_time'] - $start)));
            $weight = (float)($params['progressWeight'] ?? 1);
            $done = (float)($params['progressDone'] ?? 0);
            $travelled = min($weight, $done + (float)($params['progressSpan'] ?? $weight - $done) * $fraction);
            $fleet->setFuelCurrent(min($fleet->getFuelTotal(), $fleet->getFuelCurrent() + (float)($params['fuelCost'] ?? 0) * (1 - $fraction)));
            $fleet->setCurrentCoordinate($position, $time);
        }
        $future = FleetCommands::getFleetQueue($fleet->getId());
        foreach ($future as $old) {
            $params = FleetControlService::params($old);
            $params['cancelled'] = true;
            if ($travelled !== null && (int)$old['id'] === (int)$active['id']) $params['progressCompleted'] = $travelled;
            FleetControlService::patchCommand((int)$old['id'], ['executed' => 1, 'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
        }
        FleetControlService::add($fleet->getId(), FleetCommandType::OBSERVE, [
            'target' => $fleet->getCurrentCoordinate()->toArray(), 'pause' => 'base', 'lostBase' => $siteId,
            'planError' => 'Ресурсная база потеряна, собственная резервная планета или луна отсутствует.',
        ]);
        foreach ($future as $old) {
            $params = FleetControlService::params($old);
            if ($active !== null && (int)$old['id'] === (int)$active['id']) {
                if ($travelled !== null) $params['progressWeight'] = max(0.0, (float)($params['progressWeight'] ?? 1) - $travelled);
                foreach (['motionStart', 'motionEnd', 'resolvedTarget', 'startedAt', 'fuelCost', 'recallFlight', 'fuelStop', 'stopForLostBase', 'progressDone', 'progressSpan', 'progressCompleted'] as $key) unset($params[$key]);
            }
            FleetControlService::add($fleet->getId(), FleetCommandType::from((int)$old['command_type']), $params);
        }
        $fleet->setBaseFuelState(null);
        if ($fleet->getMission() === 0) $fleet->setMission(FleetMissionType::OBSERVE);
        $fleet->setStatus(Fleet::STATUS_MISSION);
        $fleet->save();
    }

    /** Единая операция по всем своим зависимым флотам; чужие ресурсы не меняются. */
    public static function processEvent(int $siteId, float $time): array
    {
        return \SPGame\Game\Repositories\MiningCheckpoints::worldLocked(static function () use ($siteId, $time): array {
            FleetMiningCheckpointService::recoverPending();
            return self::processBaseEvent($siteId, $time);
        }, true);
    }

    private static function processBaseEvent(int $siteId, float $time): array
    {
        if (self::battleAt($siteId) !== null) return [];
        FleetSurveyService::assertMiningReady($siteId);
        $base = ResourceBases::findExistingById($siteId);
        if ($base === null) return [];
        $members = self::members($base);
        $owner = self::account((int)$base['owner_id']);
        $at = FleetSurveyService::coordinate(self::site($siteId));
        $deleted = false;
        $notices = [];
        try {
            FleetControlService::commit($members, static function () use ($base, $members, $owner, $siteId, $time, $at, &$notices): void {
                if (ResourceBases::findExistingById($siteId) !== $base) throw new \LogicException('Владелец базы уже изменился.');
                foreach ($members as $fleet) {
                    if (!self::standingAt($fleet, $at)) continue;
                    if ($fleet->getBaseFuelState() === null) self::beginStanding($fleet, $owner, $time);
                    self::settleStanding($fleet, $time);
                    $clock = $fleet->getBaseFuelState();
                    if ($clock['rate'] > 0 && $fleet->getFuelCurrent() <= $clock['reserve'] + .000001) {
                        self::evacuate($fleet, $owner, $siteId, $time);
                        $notices[$fleet->getId()] = 'Достигнут топливный резерв эвакуации. Подготовлен возврат на резервную базу.';
                    }
                    $fleet->save();
                }
            });
            if (!self::hasGuard($base, $members, $time)) {
                // Journal удалённой добычи нельзя помещать внутрь rollback обычного
                // Fleet commit. Сначала durable rehome, затем свежие снимки утраты.
                foreach (FleetMiningRuntimeService::redirectLostHome($members, $siteId, $owner, $time) as $fleetId) {
                    $notices[$fleetId] = 'Домашняя ресурсная база потеряна. Добыча продолжается; домом назначена резервная планета или луна.';
                }
                $members = self::members($base);
                FleetControlService::commit($members, static function () use ($base, $members, $owner, $siteId, $time, $at, &$deleted, &$notices): void {
                    if (ResourceBases::findExistingById($siteId) !== $base || self::hasGuard($base, $members, $time)) {
                        throw new \LogicException('Условия потери ресурсной базы изменились.');
                    }
                    foreach ($members as $fleet) {
                        if ($fleet->getHomeSiteId() !== $siteId && !self::isPostBattleGuard($fleet)) continue;
                        if (self::standingAt($fleet, $at)) {
                            self::evacuate($fleet, $owner, $siteId, $time);
                        } else {
                            try { $fallback = self::fallbackPlanet($fleet); }
                            catch (\LogicException) {
                                self::pauseWithoutFallback($fleet, $siteId, $time);
                                $notices[$fleet->getId()] = 'Ресурсная база потеряна. Резервной планеты или луны нет: флот ожидает решения.';
                                continue;
                            }
                            $fleet->setHomePlanetId((int)$fallback['id']);
                            FleetCommandsService::redirectLostBaseReturn($fleet, $owner, $time);
                        }
                        $fleet->save();
                        $notices[$fleet->getId()] = 'Последняя охрана покинула ресурсный объект. Базой флота назначена резервная планета или луна.';
                    }
                    $deleted = true;
                    ResourceBases::delete(['id' => $siteId]);
                    if (ResourceBases::findExistingById($siteId) !== null) throw new \RuntimeException('Не удалось освободить ресурсную базу.');
                });
            }
        } catch (\Throwable $error) {
            if ($deleted) {
                try { ResourceBases::restoreDeletedRow($base); }
                catch (\Throwable $rollback) { Logger::getInstance()->critical('Не восстановлено владение ресурсной базой',
                    ['siteId' => $siteId, 'error' => $rollback->getMessage()]); }
            }
            throw $error;
        }
        foreach ($notices as $fleetId => $reason) {
            try { Notification::sendResourceBaseChange($owner, $fleetId, $at, $reason, $time); }
            catch (\Throwable $error) { Logger::getInstance()->error('Сообщение о ресурсной базе не отправлено',
                ['siteId' => $siteId, 'fleetId' => $fleetId, 'error' => $error->getMessage()]); }
        }
        return [(int)$owner['Account']['id']];
    }

    private static function balance(): array
    {
        static $balance;
        return $balance ??= require dirname(__DIR__, 3) . '/resources/fleet-mining.php';
    }

    public static function controlLimit(int $miningLevel): int
    {
        $rules = self::balance()['base_limits'];
        return $miningLevel < $rules['minimum_level'] ? 0
            : $rules['base'] + intdiv($miningLevel, $rules['level_step']);
    }

    public static function basedFleetLimit(int $logisticsLevel): int
    {
        return self::balance()['based_fleets']['base'] + max(0, $logisticsLevel);
    }

    public static function friendlyGuardLimit(int $logisticsLevel): int
    {
        return self::balance()['friendly_defenders']['base'] + max(0, $logisticsLevel);
    }

    private static function planet(int $id, int $ownerId): ?array
    {
        $row = $id > 0 ? Planets::findExistingById($id) : null;
        return $row !== null && (int)$row['owner_id'] === $ownerId
            && in_array((int)$row['planet_type'], [ObjectType::PLANET->value, ObjectType::MOON->value], true) ? $row : null;
    }

    private static function planetCoordinate(array $planet): Coordinate
    {
        return Coordinate::object((int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'],
            ObjectType::from((int)$planet['planet_type']));
    }

    /** Проверка адреса, без создания default-строки/переприписки/эвакуации. */
    public static function homeCoordinate(Fleet $fleet): Coordinate
    {
        $siteId = $fleet->getHomeSiteId();
        if ($siteId > 0) {
            if ($fleet->getHomePlanetId() !== 0) throw new \LogicException('У флота одновременно указаны планетарная и ресурсная базы.');
            $base = ResourceBases::findExistingById($siteId);
            if ($base === null || (int)$base['owner_id'] !== $fleet->getOwnerId()) {
                throw new \LogicException('Ресурсная база флота больше не принадлежит его владельцу.');
            }
            return FleetSurveyService::coordinate(self::site($siteId));
        }
        $planet = self::planet($fleet->getHomePlanetId(), $fleet->getOwnerId());
        if ($planet === null) throw new \LogicException('Планетарная база флота недоступна.');
        return self::planetCoordinate($planet);
    }

    /** Сначала сохранённая база; после потери луны — собственная планета, затем луна, по ID. */
    public static function fallbackPlanet(Fleet $fleet): array
    {
        $planet = self::planet($fleet->getFallbackPlanetId(), $fleet->getOwnerId());
        if ($planet !== null) return $planet;
        $candidates = array_values(array_filter(Planets::findByIndex('owner_id', [$fleet->getOwnerId()]) ?? [],
            static fn(array $row): bool => in_array((int)$row['planet_type'], [ObjectType::PLANET->value, ObjectType::MOON->value], true)));
        usort($candidates, static fn(array $a, array $b): int =>
            [(int)$a['planet_type'] === ObjectType::PLANET->value ? 0 : 1, (int)$a['id']]
            <=> [(int)$b['planet_type'] === ObjectType::PLANET->value ? 0 : 1, (int)$b['id']]);
        if ($candidates === []) throw new \LogicException('У флота нет доступной собственной резервной планеты или луны.');
        return $candidates[0];
    }

    public static function fallbackCoordinate(Fleet $fleet): Coordinate
    {
        return self::planetCoordinate(self::fallbackPlanet($fleet));
    }

    private static function site(int $siteId): array
    {
        $site = \SPGame\Game\Repositories\OrbitalResourceSites::findExistingById($siteId);
        if ($site === null) throw new \InvalidArgumentException('Ресурсный объект базы не найден.');
        // Проверяется также соответствие типа и адреса существующей записи объекта.
        return FleetSurveyService::site(FleetSurveyService::coordinate($site));
    }

    /** Старые рейсы сохраняют планетарные места; новые приписки не считают их ещё раз. */
    public static function baseSlots(AccountData $owner, int $siteId, ?int $excludeFleetId = null): array
    {
        $ownerId = (int)$owner['User']['id'];
        $base = ResourceBases::findExistingById($siteId);
        if ($base === null || (int)$base['owner_id'] !== $ownerId) throw new \LogicException('Ресурсная база недоступна для приписки.');
        return self::destinationSlots($owner, $siteId, $excludeFleetId);
    }

    private static function destinationSlots(AccountData $owner, int $siteId, ?int $excludeFleetId): array
    {
        $ownerId = (int)$owner['User']['id'];
        $target = FleetSurveyService::coordinate(self::site($siteId));
        $based = $incoming = 0;
        foreach (Fleets::findByIndex('owner_id', [$ownerId]) ?? [] as $row) {
            if ((int)$row['id'] === $excludeFleetId) continue;
            if ((int)($row['home_site_id'] ?? 0) === $siteId) {
                $based++;
            } elseif ((int)$row['status'] !== Fleet::STATUS_IDLE
                && in_array((int)$row['mission'], [FleetMissionType::RELOCATE->value, FleetMissionType::CAPTURE->value, FleetMissionType::MINING->value], true)) {
                foreach (FleetCommands::getFleetQueue((int)$row['id']) as $command) {
                    if ((int)$command['command_type'] !== FleetCommandType::STAY->value) continue;
                    $params = FleetControlService::params($command);
                    if (($params['at'] ?? null) === $target->toArray()) { $incoming++; break; }
                }
            }
        }
        $level = (int)($owner['Techs'][Vars::$resource[134]] ?? 0);
        return ['used' => $based + $incoming, 'max' => self::basedFleetLimit($level),
            'based' => $based, 'incoming' => $incoming, 'level' => $level];
    }

    /** Проверка цели без занятия. Повторяется при фактическом прибытии. */
    public static function prepareDestination(Fleet $fleet, AccountData $owner, Coordinate $target,
        bool $capture, bool $arriving = false): array
    {
        if (!self::isEnabled()) throw new \LogicException('Ресурсные базы ещё не включены на сервере.');
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        $row = Fleets::findExistingById($fleet->getId());
        if ($row === null || !array_key_exists('home_site_id', $row) || !array_key_exists('fallback_planet_id', $row)) {
            throw new \LogicException('Runtime-схема приписки ещё не включена.');
        }
        if ($fleet->getTotalShips() <= 0) throw new \LogicException('Пустой флот не может занять базу.');
        FleetBattleService::assertFleetAvailable($fleet->getId());
        $site = FleetSurveyService::site($target);
        $battle = self::battleAt((int)$site['id']);
        // Исключение только для исполнителя ранее отправленной передислокации.
        // Preview/send по-прежнему не могут направить новый флот в идущий бой.
        if ($battle !== null && (!$arriving || $capture || $fleet->getMission() !== FleetMissionType::RELOCATE->value
            || !FleetSpaceBattleService::canReinforceResourceRelocation($battle['state'], $fleet->getOwnerId(), $target->toArray()))) {
            throw new \LogicException('Ресурсный объект участвует в общем бою.');
        }
        if ($target->toArray() !== FleetSurveyService::coordinate($site)->toArray()) {
            throw new \InvalidArgumentException('Для ресурсной базы нужен адрес объекта без заданного вручную угла.');
        }
        $base = ResourceBases::findExistingById((int)$site['id']);
        $hostile = $base !== null && (int)$base['owner_id'] !== $fleet->getOwnerId();
        if ($hostile && !$capture) {
            throw new \LogicException('Объект уже занят другим игроком. Автоматическая атака не выполняется.');
        }
        if ($hostile) {
            $context = self::objectBattleContext($target, microtime(true));
            self::assertBattleSupported($context);
            foreach ($context['fleets'] as $defender) if ($defender->getOwnerId() === $fleet->getOwnerId()) {
                throw new \LogicException('Ваш флот обороняет эту базу. Сначала отзовите охрану.');
            }
        }
        if (!$capture && $base === null) throw new \LogicException('Передислокация возможна только на свою ресурсную базу.');
        if ($capture) {
            if ($base !== null && !$hostile && !$arriving) throw new \LogicException('Объект уже ваш: выберите передислокацию.');
            $combatCapable = false;
            foreach ($fleet->getShips() as $ship) {
                if ($ship->getCount() > 0 && (float)(Vars::$CombatCaps[$ship->getShipTypeId()]['attack'] ?? 0) > 0) {
                    $combatCapable = true;
                    break;
                }
            }
            if (!$combatCapable) throw new \LogicException('Для контроля базы нужен боеспособный флот.');
            $level = (int)($owner['Techs'][Vars::$resource[self::balance()['technology']]] ?? 0);
            if ($level < 1) throw new \LogicException('Для занятия базы нужна Внеземная добыча 1.');
            // Входящие занятия разных объектов резервируют лимит; одна цель считается один раз.
            $reserved = array_fill_keys(array_map(static fn(array $base): int => (int)$base['id'], ResourceBases::ownedBy($fleet->getOwnerId())), true);
            if (($base === null || $hostile) && count($reserved) >= self::controlLimit($level)) {
                throw new \LogicException('Достигнут лимит ресурсных баз. Повысьте Внеземную добычу.');
            }
            // При прибытии решает фактическое владение: после снижения технологии
            // конкурирующие старые рейсы не должны навечно блокировать друг друга.
            foreach ($arriving ? [] : (Fleets::findByIndex('owner_id', [$fleet->getOwnerId()]) ?? []) as $other) {
                if ((int)$other['id'] === $fleet->getId()) continue;
                foreach (FleetCommands::getFleetQueue((int)$other['id']) as $command) {
                    if (!in_array((int)$command['command_type'], [FleetCommandType::CAPTURE->value, FleetCommandType::MINING->value], true)) continue;
                    $id = (int)(FleetControlService::params($command)['target']['siteId'] ?? 0);
                    $control = $id > 0 ? ResourceBases::findExistingById($id) : null;
                    if ($id > 0 && ($control === null || (int)$control['owner_id'] !== $fleet->getOwnerId())) $reserved[$id] = true;
                }
            }
            if (!isset($reserved[(int)$site['id']]) && count($reserved) >= self::controlLimit($level)) {
                throw new \LogicException('Достигнут лимит ресурсных баз. Повысьте Внеземную добычу.');
            }
        }
        $slots = self::destinationSlots($owner, (int)$site['id'], $fleet->getId());
        $used = $arriving ? $slots['based'] : $slots['used'];
        if ($fleet->getHomeSiteId() !== (int)$site['id'] && $used >= $slots['max']) {
            throw new \LogicException('На ресурсной базе нет свободных мест. Повысьте Флотовую логистику.');
        }
        return ['siteId' => (int)$site['id'], 'fallbackPlanetId' => (int)self::fallbackPlanet($fleet)['id'], 'base' => $base];
    }

    public static function destinationReason(Fleet $fleet, AccountData $owner, Coordinate $target, bool $capture): ?string
    {
        try { self::prepareDestination($fleet, $owner, $target, $capture); return null; }
        catch (\InvalidArgumentException|\LogicException $error) { return $error->getMessage(); }
    }

    /** STAY ресурсной базы тоже исполняется отдельным due-событием, не внутри MOVE commit. */
    public static function isArrivalCommand(array $command): bool
    {
        if ((int)$command['command_type'] === FleetCommandType::CAPTURE->value) return true;
        if ((int)$command['command_type'] !== FleetCommandType::STAY->value) return false;
        $at = FleetControlService::params($command)['at'] ?? null;
        return is_array($at) && FleetSurveyService::isResource(Coordinate::fromArray($at));
    }

    private static function prepareArrival(array $command, Fleet $fleet, AccountData $owner): array
    {
        if (!self::isArrivalCommand($command) || (int)$command['fleet_id'] !== $fleet->getId()
            || FleetCommands::getActiveCommand($fleet->getId()) !== $command) {
            throw new \LogicException('Устаревшая команда прибытия к ресурсной базе.');
        }
        $capture = (int)$command['command_type'] === FleetCommandType::CAPTURE->value;
        if ($capture && $fleet->getMission() !== FleetMissionType::CAPTURE->value) {
            throw new \LogicException('Команда занятия не соответствует миссии флота.');
        }
        $params = FleetControlService::params($command);
        $target = Coordinate::fromArray($params[$capture ? 'target' : 'at']);
        if ($capture && (int)(ResourceBases::findExistingById((int)$target->getSiteId())['owner_id'] ?? $fleet->getOwnerId()) !== $fleet->getOwnerId()) {
            throw new \LogicException('Объект ещё защищается другим игроком.');
        }
        $prepared = self::prepareDestination($fleet, $owner, $target, $capture, true);
        if ($fleet->getCurrentCoordinate()->toArray() !== $target->toArray()) {
            throw new \LogicException('Флот ещё не прибыл к ресурсной базе.');
        }
        if (!$capture && $fleet->getMission() !== FleetMissionType::RELOCATE->value
            && $fleet->getHomeSiteId() !== $prepared['siteId']) {
            throw new \LogicException('Нельзя менять приписку обычной командой STAY.');
        }
        $queue = FleetCommands::getFleetQueue($fleet->getId());
        if (count($queue) !== ($capture ? 2 : 1) || (int)$queue[0]['id'] !== (int)$command['id']
            || ($capture && ((int)$queue[1]['command_type'] !== FleetCommandType::STAY->value
                || (float)$queue[1]['execute_time'] !== 0.0
                || (FleetControlService::params($queue[1])['at'] ?? null) !== $target->toArray()))) {
            throw new \LogicException('Прибытие к новой базе должно завершать маршрут.');
        }
        return $prepared + ['target' => $target, 'capture' => $capture];
    }

    /** Владение + приписка + окончание маршрута в одном локальном commit, без LOAD/UNLOAD. */
    public static function completeArrival(array $command, Fleet $fleet, AccountData $owner): bool
    {
        if (FleetCommands::getCommand((int)$command['id']) !== $command || (int)$command['executed']) return true;
        $params = FleetControlService::params($command);
        $combatCapture = (int)$command['command_type'] === FleetCommandType::CAPTURE->value && !empty($params['combatCapture']);
        try {
            if ($combatCapture) {
                $target = Coordinate::fromArray($params['target']);
                foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $previous) {
                    $attack = FleetControlService::params($previous);
                    if ((int)$previous['id'] >= (int)$command['id'] || empty($previous['executed'])
                        || (int)$previous['command_type'] !== FleetCommandType::ATTACK->value
                        || empty($attack['resourceCapture']) || ($attack['target'] ?? null) !== $target->toArray()) continue;
                    if (isset($attack['resourceFailure'])) throw new \LogicException($attack['resourceFailure']);
                    $result = $attack['resourceOutcome'] ?? null;
                    if ($result !== null && $result !== 'attacker') throw new \LogicException('Оборона объекта не уничтожена. Флот возвращается домой.');
                }
                $base = ResourceBases::findExistingById((int)$target->getSiteId());
                if ($base !== null && (int)$base['owner_id'] !== $fleet->getOwnerId()) {
                    self::prepareDestination($fleet, $owner, $target, true, true);
                    // Сначала штатная потеря старой базы со всеми отсутствующими
                    // приписанными флотами, затем новое занятие. Запас G/K не трофей.
                    self::processEvent((int)$base['id'], $fleet->getCurrentPositionTime());
                }
            }
            $prepared = self::prepareArrival($command, $fleet, $owner);
        }
        catch (\InvalidArgumentException|\LogicException $error) {
            if ($combatCapture) {
                Notification::sendResourceCaptureFailed($owner, $fleet, $error->getMessage(), $fleet->getCurrentPositionTime());
                FleetCommandsService::recall($fleet, $owner, max(microtime(true), $fleet->getCurrentPositionTime()));
                return true;
            }
            $params = FleetControlService::params($command);
            $params['planError'] = $error->getMessage();
            $params['pause'] = 'plan';
            FleetControlService::patchCommand((int)$command['id'], ['execute_time' => 0.0,
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            return true;
        }
        $battle = self::battleAt($prepared['siteId']);
        if ($battle !== null) {
            FleetSpaceBattleService::reinforceResourceRelocation($command, $fleet, $owner, $battle, $prepared);
            try {
                Notification::sendFleetResourceBaseArrival($owner, $fleet, $prepared['target'], (float)$command['execute_time']);
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Не удалось сообщить о прибытии подкрепления к базе',
                    ['fleetId' => $fleet->getId(), 'error' => $error->getMessage()]);
            }
            return true;
        }
        $created = null;
        $fleetBefore = Fleets::findExistingById($fleet->getId());
        try {
            FleetControlService::commit([$fleet], static function () use ($command, $fleet, $owner, $prepared, &$created): void {
                $fresh = self::prepareArrival($command, $fleet, $owner);
                if ($fresh['siteId'] !== $prepared['siteId'] || $fresh['base'] !== $prepared['base']
                    || $fresh['fallbackPlanetId'] !== $prepared['fallbackPlanetId']) {
                    throw new \LogicException('Условия прибытия к базе изменились.');
                }
                if ($fresh['base'] === null) {
                    $created = ['id' => $fresh['siteId'], 'owner_id' => $fleet->getOwnerId(),
                        'created_at' => (float)$command['execute_time'], 'updated_at' => (float)$command['execute_time']];
                    // Повторное занятие того же siteId отменяет ещё не сброшенный
                    // DELETE прежнего владения. Обычный add оставляет tombstone.
                    ResourceBases::restoreDeletedRow($created);
                    if (ResourceBases::findExistingById($fresh['siteId']) !== $created) {
                        throw new \RuntimeException('Владение ресурсной базой не сохранилось.');
                    }
                }
                $fleet->setHomeResourceSite($fresh['siteId'], $fresh['fallbackPlanetId']);
                $fleet->setCurrentCoordinate($fresh['target'], (float)$command['execute_time']);
                $fleet->setStartCoordinate(null);
                $fleet->setTargetCoordinate(null);
                $fleet->setMission(0);
                $fleet->setStatus(Fleet::STATUS_IDLE);
                $fleet->setReturn(false);
                self::beginStanding($fleet, $owner, (float)$command['execute_time'],
                    (float)(FleetControlService::params($command)['speedFactor'] ?? 10.0));
                $fleet->save();
                $row = Fleets::findExistingById($fleet->getId());
                if ($row === null || (int)$row['home_planet_id'] !== 0 || (int)$row['home_site_id'] !== $fresh['siteId']
                    || (int)$row['fallback_planet_id'] !== $fresh['fallbackPlanetId']) {
                    throw new \RuntimeException('Приписка к ресурсной базе не сохранилась.');
                }
                // Как у планетарного STAY: завершённая цепочка удаляется штатным RepositorySaver.
                FleetCommands::clearFleetQueue($fleet->getId());
                if (FleetCommands::getFleetCommandChain($fleet->getId()) !== []) {
                    throw new \RuntimeException('Не удалось завершить маршрут у ресурсной базы.');
                }
            });
        } catch (\Throwable $error) {
            if ($created !== null) {
                try {
                    // FleetControl уже попытался восстановить Fleet. При отказе его
                    // rollback не оставлять новую приписку с удалённой базой.
                    if (Fleets::findExistingById($fleet->getId()) !== $fleetBefore) {
                        throw new \RuntimeException('Fleet не восстановлен: реестр базы оставлен для восстановления согласованности.');
                    }
                    $current = ResourceBases::findExistingById((int)$created['id']);
                    if ($current !== null && $current !== $created) throw new \LogicException('Владелец базы изменился во время rollback.');
                    ResourceBases::discardUncommittedRow($created);
                    if (ResourceBases::findExistingById((int)$created['id']) !== null) throw new \RuntimeException('Не удалось убрать незавершённое занятие базы.');
                } catch (\Throwable $rollback) {
                    Logger::getInstance()->critical('Rollback занятия ресурсной базы не выполнен',
                        ['siteId' => $created['id'], 'fleetId' => $fleet->getId(), 'error' => $rollback->getMessage()]);
                }
            }
            throw $error;
        }
        try {
            Notification::sendFleetResourceBaseArrival($owner, $fleet, $prepared['target'], (float)$command['execute_time']);
        } catch (\Throwable $error) {
            // Сообщение не должно повторно применять уже завершённую приписку.
            Logger::getInstance()->error('Не удалось сообщить о прибытии к ресурсной базе',
                ['fleetId' => $fleet->getId(), 'siteId' => $prepared['siteId'], 'error' => $error->getMessage()]);
        }
        return true;
    }

    /** Prepare: только проверки, ни поля Fleet, ни Resources, ни база не меняются. */
    public static function prepareRebase(Fleet $fleet, AccountData $owner, int $siteId): array
    {
        if (!ResourceBases::isReady()) throw new \LogicException('Ресурсные базы ещё не включены на сервере.');
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        $row = Fleets::findExistingById($fleet->getId());
        if ($row === null || !array_key_exists('home_site_id', $row) || !array_key_exists('fallback_planet_id', $row)) {
            throw new \LogicException('Runtime-схема приписки ещё не включена.');
        }
        if ($fleet->getTotalShips() <= 0) throw new \LogicException('Пустой флот нельзя приписать к базе.');
        FleetBattleService::assertFleetAvailable($fleet->getId());
        $target = FleetSurveyService::coordinate(self::site($siteId));
        $current = $fleet->getCurrentCoordinate();
        if ($current->getType() !== CoordinateType::OBJECT || $current->toArray() !== $target->toArray()) {
            throw new \LogicException('Для приписки флот должен фактически прибыть к ресурсной базе.');
        }
        $pending = FleetCommands::getActiveCommand($fleet->getId()) ?? FleetCommands::getNextPendingCommand($fleet->getId());
        if ($pending !== null && (int)$pending['command_type'] !== FleetCommandType::STAY->value) {
            throw new \LogicException('Нельзя менять приписку во время другой команды флота.');
        }
        $slots = self::baseSlots($owner, $siteId, $fleet->getId());
        if ($fleet->getHomeSiteId() !== $siteId && $slots['used'] >= $slots['max']) {
            throw new \LogicException('На ресурсной базе нет свободных мест. Повысьте Флотовую логистику.');
        }
        $fallback = self::fallbackPlanet($fleet);
        return ['fleetId' => $fleet->getId(), 'ownerId' => $fleet->getOwnerId(), 'siteId' => $siteId,
            'fallbackPlanetId' => (int)$fallback['id'], 'before' => [
                $fleet->getHomePlanetId(), $fleet->getHomeSiteId(), $fleet->getFallbackPlanetId()]];
    }

    /** Commit только приписки. Не заменяет прибытие/STAY/контроль и не создаёт базу. */
    public static function rebase(Fleet $fleet, AccountData $owner, int $siteId): void
    {
        $prepared = self::prepareRebase($fleet, $owner, $siteId);
        FleetControlService::commit([$fleet], static function () use ($fleet, $owner, $siteId, $prepared): void {
            if (self::prepareRebase($fleet, $owner, $siteId) !== $prepared) throw new \LogicException('Условия приписки изменились.');
            $fleet->setHomeResourceSite($siteId, $prepared['fallbackPlanetId']);
            $fleet->save();
            $row = Fleets::findExistingById($fleet->getId());
            if ($row === null || (int)$row['home_planet_id'] !== 0 || (int)($row['home_site_id'] ?? 0) !== $siteId
                || (int)($row['fallback_planet_id'] ?? 0) !== $prepared['fallbackPlanetId']) {
                throw new \RuntimeException('Ресурсная приписка не сохранилась в runtime.');
            }
        });
    }
}
