<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{FleetCommands, FleetAttackGroups, Fleets, Planets, PlanetResources, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Локальный runtime commit управления одним Fleet; не механизм доставки PlayerQueue. */
final class FleetControlService
{
    public const PLAN_LEVEL = 3;
    public const EDIT_LEVEL = 6;
    public const CARGO_REFUEL_LEVEL = 3;

    public static function capabilities(AccountData $owner): array
    {
        $caps = self::capabilitiesForLevels((int)($owner['Techs']['computer_tech'] ?? 0),
            (int)($owner['Techs']['fleet_logistics_tech'] ?? 0));
        return $caps + ['cargoRefuelLevel' => self::CARGO_REFUEL_LEVEL,
            'holdFuelFactor' => (float)(\SPGame\Game\Repositories\Config::getValue('FleetHoldFuelPerHourFactor') ?? .1)];
    }

    /** Чистые пороги: общие для игровой проверки и справки по будущим уровням. */
    public static function capabilitiesForLevels(int $computer, int $logistics): array
    {
        return ['plan' => $computer >= self::PLAN_LEVEL, 'edit' => $computer >= self::EDIT_LEVEL,
            'maxStages' => $computer >= self::PLAN_LEVEL ? min(12, $computer) : 0,
            'hold' => $logistics >= 1, 'cargoRefuel' => $logistics >= self::CARGO_REFUEL_LEVEL,
            'interceptStop' => $computer >= 6, 'interceptStopLevel' => 6];
    }

    public static function assertCargoRefuel(AccountData $owner): void
    {
        if (!self::capabilities($owner)['cargoRefuel']) {
            throw new \LogicException('Для дозаправки из трюма нужна Флотовая логистика ' . self::CARGO_REFUEL_LEVEL . '.');
        }
    }

    public static function params(array $command): array
    {
        return is_array($command['params']) ? $command['params'] : json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function patchCommand(int $id, array $patch): void
    {
        FleetCommands::update(['id' => $id] + $patch);
        $saved = FleetCommands::findExistingById($id);
        foreach ($patch as $key => $value) {
            if ($saved === null || $saved[$key] != $value) throw new \RuntimeException('Не удалось сохранить изменение команды флота.');
        }
    }

    public static function add(int $id, Command $type, array $params): array
    {
        $row = FleetCommands::addCommand($id, $type, $params);
        if (FleetCommands::findExistingById((int)$row['id']) !== $row) {
            FleetCommands::discardUncommittedRow($row);
            throw new \RuntimeException('Не удалось создать команду флота.');
        }
        return $row;
    }

    public static function pause(array $command, string $reason): void
    {
        $params = self::pauseParams(self::params($command), $reason);
        $row = Fleets::getById((int)$command['fleet_id']);
        if ($reason === 'fuel' && !empty($params['returnLeg'])
            && in_array((int)($row['mission'] ?? 0), [1,3,5,7], true)) $params['blockedAfterBattle'] = true;
        self::patchCommand((int)$command['id'], ['execute_time' => 0.0,
            'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    private static function pauseParams(array $params, string $reason): array
    {
        if (in_array($reason, ['fuel', 'load'], true) && ($params['pause'] ?? null) !== $reason) {
            $key = $reason === 'load' ? 'loadEpisode' : 'fuelEpisode';
            $params[$key] = (int)($params[$key] ?? 0) + 1;
        }
        $params['pause'] = $reason;
        return $params;
    }

    /** Только после игровых commit/rollback. Доставка — через обычные Messages/PlayerQueue. */
    public static function notifyFuelStops(?float $time = null): void
    {
        foreach (FleetCommands::getAll() as $command) {
            if ((int)$command['executed'] || (float)$command['execute_time'] > 0) continue;
            try {
                $params = self::params($command);
                $loading = ($params['pause'] ?? null) === 'load';
                if (!$loading && ($params['pause'] ?? null) !== 'fuel') continue;
                $prefix = $loading ? 'load' : 'fuel';
                $episode = $command['id'] . ':' . (int)($params[$prefix . 'Episode'] ?? 0);
                if (($params[$prefix . 'Notified'] ?? null) === $episode) continue;
                if (Fleets::findExistingById((int)$command['fleet_id']) === null) continue;
                $fleet = FleetsData::getInstance()->get((int)$command['fleet_id']);
                if ($fleet === null || FleetCommands::getActiveCommand($fleet->getId()) !== null
                    || (int)(FleetCommands::getNextPendingCommand($fleet->getId())['id'] ?? 0) !== (int)$command['id']) continue;
                $user = \SPGame\Game\Repositories\Users::findExistingById($fleet->getOwnerId());
                if ($user === null || (int)($user['account_id'] ?? 0) <= 0
                    || \SPGame\Game\Repositories\Accounts::findExistingById((int)$user['account_id']) === null
                    || ((int)$user['current_planet'] <= 0 && (int)$user['main_planet'] <= 0)) {
                    throw new \LogicException('Владелец остановленного флота недоступен.');
                }
                $owner = new AccountData((int)($user['account_id'] ?? 0));
                $row = Notification::prepareFleetFuelStop($fleet, $command, $episode, $time ?? microtime(true), self::capabilities($owner)['cargoRefuel']);
                if ($loading) {
                    $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                    $data['LoadReason'] = $params['planError'] ?? 'Погрузка недоступна.';
                    $row['subject'] = 'subject.Transport.LoadStop';
                    $row['sample'] = 'FleetLoadStopped';
                    $row['data'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
                // Если запись сообщения удалась, но сохранение marker/push — нет,
                // повтор использует то же сообщение, а не создаёт дубль.
                $message = null;
                foreach (\SPGame\Game\Repositories\Messages::findByIndex('to', (string)$fleet->getOwnerId()) ?? [] as $old) {
                    if ($old['sample'] === $row['sample']
                        && (json_decode($old['data'], true, 512, JSON_THROW_ON_ERROR)['FuelEpisode'] ?? null) === $episode) {
                        $message = $old;
                        break;
                    }
                }
                if ($message === null) {
                    $id = \SPGame\Game\Repositories\Messages::add($row);
                    $message = \SPGame\Game\Repositories\Messages::findExistingById((int)$id);
                    if ($message === null || $message['data'] !== $row['data']) throw new \RuntimeException('Не сохранено уведомление об остановке флота.');
                }
                Notification::pushMessages($owner);
                $params[$prefix . 'Notified'] = $episode;
                $encoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                // Не позволяем Swoole обрезать JSON, если старая команда уже заполнила колонку params.
                if (strlen($encoded) > 2048) throw new \RuntimeException('Нет места для marker уведомления в params команды.');
                self::patchCommand((int)$command['id'], ['params' => $encoded]);
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Уведомление об остановке Fleet отложено', [
                    'fleetId' => $command['fleet_id'], 'commandId' => $command['id'], 'message' => $error->getMessage()]);
            }
        }
    }

    public static function state(Fleet $fleet): array
    {
        $next = FleetCommands::getActiveCommand($fleet->getId()) ?? FleetCommands::getNextPendingCommand($fleet->getId());
        $pause = $next === null ? null : (self::params($next)['pause'] ?? null);
        $params = $next === null ? [] : self::params($next);
        // Доставка сообщения не меняет маршрут и не должна инвалидировать
        // редактор сразу после snapshot остановившегося флота.
        $versionChain = FleetCommands::getFleetCommandChain($fleet->getId());
        foreach ($versionChain as &$row) {
            $p = self::params($row);
            unset($p['fuelNotified'], $p['loadNotified'], $p['interceptNotified']);
            $row['params'] = json_encode($p, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['paused' => $pause !== null, 'reason' => $pause, 'error' => $params['planError'] ?? null,
            'loading_pending' => FleetLoadingService::canResumeAtSource($fleet),
            'stop_pending' => !empty($params['stopAfterTransition']),
            'can_continue' => count(FleetCommands::getFleetQueue($fleet->getId())) > 1,
            'holding' => $next !== null && (int)$next['command_type'] === Command::OBSERVE->value && (float)$next['execute_time'] > 0,
            'holdRate' => (float)($params['hold']['rate'] ?? 0), 'fuelTime' => (float)($params['hold']['lastAt'] ?? $fleet->getCurrentPositionTime()),
            'steps' => FleetPlanService::steps($fleet),
            'planActiveStage' => FleetPlanService::activeStage($fleet),
            'version' => hash('sha256', json_encode($versionChain, JSON_THROW_ON_ERROR))];
    }

    /** Совместимость со старыми ручными остановками без progress metadata. Только чтение. */
    private static function legacyProgress(array $commands): array
    {
        $weights = $completed = [];
        foreach ($commands as $index => $hold) {
            if ((int)$hold['command_type'] !== Command::OBSERVE->value) continue;
            $holdTime = (float)(self::params($hold)['hold']['startedAt'] ?? 0);
            if ($holdTime <= 0) continue;
            for ($i = $index - 1; $i >= 0; $i--) {
                $old = $commands[$i];
                if (!in_array((int)$old['command_type'], [10, 11, 12], true)) continue;
                $params = self::params($old);
                if (empty($params['cancelled']) || isset($params['progressCompleted'])) continue;
                $start = (float)($params['startedAt'] ?? 0);
                $end = (float)$old['execute_time'];
                if ($start <= 0 || $end <= $start || $holdTime < $start || $holdTime > $end) continue;
                for ($j = $index + 1; isset($commands[$j]); $j++) {
                    $next = $commands[$j];
                    if (!in_array((int)$next['command_type'], [10, 11, 12], true)) continue;
                    $nextParams = self::params($next);
                    if (($nextParams['target'] ?? null) !== ($params['target'] ?? null)) break;
                    $weight = (float)($weights[$old['id']] ?? $params['progressWeight'] ?? 1);
                    $done = min($weight, max(0.0, (float)($params['progressDone'] ?? 0)));
                    $span = (float)($params['progressSpan'] ?? $weight - $done);
                    $travelled = min($weight, $done + $span * ($holdTime - $start) / ($end - $start));
                    $completed[$old['id']] = $travelled;
                    if (!isset($nextParams['progressWeight'])) $weights[$next['id']] = max(0.0, $weight - $travelled);
                    break;
                }
                break;
            }
        }
        return [$weights, $completed];
    }

    /** Весь маршрут, включая возврат: вес MOVE не зависит от времени удержания/боя. */
    public static function routeProgress(Fleet $fleet, ?array $commands = null): array
    {
        $commands ??= FleetCommands::getFleetCommandChain($fleet->getId());
        [$weights, $legacyCompleted] = self::legacyProgress($commands);
        $total = $completed = 0.0;
        $active = null;
        $boundaries = [];
        foreach ($commands as $command) {
            if (!in_array((int)$command['command_type'], [10, 11, 12], true)) continue;
            $params = self::params($command);
            if (!empty($params['cancelled'])) {
                $travelled = max(0.0, (float)($params['progressCompleted'] ?? $legacyCompleted[$command['id']] ?? 0));
                $total += $travelled;
                $completed += $travelled;
                continue;
            }
            $weight = max(0.0, (float)($params['progressWeight'] ?? $weights[$command['id']] ?? 1));
            $total += $weight;
            $boundaries[] = $total;
            if ((int)$command['executed']) {
                $completed += $weight;
                continue;
            }
            $done = min($weight, max(0.0, (float)($params['progressDone'] ?? 0)));
            $completed += $done;
            if ((float)$command['execute_time'] > 0 && !isset($params['pause'])) {
                $active = ['started_at' => (float)($params['startedAt'] ?? $fleet->getCurrentPositionTime()),
                    'ends_at' => (float)$command['execute_time'],
                    'span' => min($weight - $done, max(0.0, (float)($params['progressSpan'] ?? $weight - $done)))];
            }
        }
        return ['completed' => $completed, 'total' => $total, 'active' => $active, 'boundaries' => $boundaries];
    }

    public static function assertAction(Fleet $fleet, AccountData $owner, string $action): void
    {
        if ($fleet->getOwnerId() !== (int)$owner['User']['id']) throw new \InvalidArgumentException('Флот недоступен.');
        FleetBattleService::assertFleetAvailable($fleet->getId());
        $caps = self::capabilities($owner);
        if ($action === 'SetFleetCommands' && $fleet->getHomeSiteId() > 0) {
            throw new \LogicException('Редактор маршрута ресурсной базы ещё недоступен. Используйте обычное задание.');
        }
        if ($action === 'StopFleet' && !$caps['hold']) throw new \LogicException('Для остановки нужна Флотовая логистика 1.');
        if ($action === 'SetFleetCommands' && !($fleet->getStatus() === Fleet::STATUS_IDLE ? $caps['plan'] : $caps['edit'])) {
            throw new \LogicException('Для редактора нужна Компьютерная технология ' . self::PLAN_LEVEL
                . ', а в полёте — ' . self::EDIT_LEVEL . '.');
        }
        if ($action === 'ResumeFleet') {
            $state = self::state($fleet);
            if ($state['reason'] === 'base') throw new \LogicException('Сначала требуется назначить доступную собственную базу флота.');
            if ((!$state['paused'] && !$state['holding']) || ($state['holding'] && !$state['can_continue'])) {
                throw new \LogicException('Для продолжения задайте новый маршрут.');
            }
        }
        if ($action === 'StopFleet') {
            $active = FleetCommands::getActiveCommand($fleet->getId());
            if ($active === null || !in_array((int)$active['command_type'], [10,11,12], true)) {
                throw new \LogicException('Остановить можно флот на участке перелёта.');
            }
        }
    }

    /** Подсказка UI; выполнение всё равно повторяет серверную проверку. */
    public static function actions(Fleet $fleet, AccountData $owner): array
    {
        $actions = [];
        $active = FleetCommands::getActiveCommand($fleet->getId());
        foreach (['StopFleet', 'ResumeFleet', 'SetFleetCommands'] as $action) {
            try {
                self::assertAction($fleet, $owner, $action);
                if ($action === 'SetFleetCommands' && $active !== null
                    && !in_array((int)$active['command_type'], [10,11,12,Command::OBSERVE->value,Command::DEFEND->value,Command::EXPEDITION->value,Command::RESEARCH->value], true)) {
                    throw new \LogicException('Редактирование недоступно во время текущего действия.');
                }
                $actions[$action] = true;
            } catch (\InvalidArgumentException|\LogicException) {
                $actions[$action] = false;
            }
        }
        return $actions;
    }

    /** Восстанавливает только затронутые Fleet/commands и, при старте, три ресурса планеты. */
    public static function commit(array $fleets, callable $operation, ?AccountData $owner = null, ?array $planGroups = null): mixed
    {
        $snapshots = [];
        foreach ($fleets as $fleet) $snapshots[$fleet->getId()] = [Fleets::getById($fleet->getId()), FleetCommands::getFleetCommandChain($fleet->getId())];
        $resources = [];
        if ($owner !== null) foreach ([901,902,903] as $id) $resources[$id] = (float)$owner['Resources'][$id]['count'];
        try {
            $result = $operation();
            foreach ($fleets as $fleet) {
                $row = Fleets::getById($fleet->getId());
                if ($row === null || abs((float)$row['fuel_current'] - $fleet->getFuelCurrent()) > .000001
                    || abs((float)$row['fuel_total'] - $fleet->getFuelTotal()) > .000001
                    || abs((float)$row['current_position_time'] - $fleet->getCurrentPositionTime()) > .000001
                    || (int)$row['mission'] !== $fleet->getMission()
                    || (int)$row['status'] !== $fleet->getStatus()
                    || (bool)$row['is_return'] !== $fleet->isReturn()) throw new \RuntimeException('Runtime Fleet не сохранился.');
                $baseClock = $fleet->getBaseFuelState();
                foreach (['home_planet_id' => $fleet->getHomePlanetId(), 'home_site_id' => $fleet->getHomeSiteId(),
                    'fallback_planet_id' => $fleet->getFallbackPlanetId()] as $field => $value) {
                    // У старого планетарного Fleet fallback=0 означает home_planet_id.
                    if ($field === 'fallback_planet_id' && (int)($row[$field] ?? 0) === 0 && $fleet->getHomeSiteId() === 0) continue;
                    if (isset($row[$field]) && (int)$row[$field] !== $value) throw new \RuntimeException('Домашний адрес Fleet не сохранился.');
                }
                foreach (['time', 'rate', 'reserve', 'speed'] as $key) {
                    if (isset($row['base_fuel_' . $key]) && (float)$row['base_fuel_' . $key] !== (float)($baseClock[$key] ?? 0)) {
                        throw new \RuntimeException('Checkpoint стоянки Fleet не сохранился.');
                    }
                }
                $current = $fleet->getCurrentCoordinate();
                $fields = ['current_coordinate_type' => $current->getType()->value,
                    'current_galaxy' => $current->getGalaxy(), 'current_system' => $current->getSystem()];
                if ($current->getType() === CoordinateType::POINT) $fields += ['current_distance' => $current->getDistance(), 'current_angle' => $current->getAngle()];
                else $fields += ['current_orbit' => $current->getOrbit(), 'current_object_type' => $current->getObjectType()?->value ?? 0,
                    'current_site_id' => $current->getSiteId() ?? 0];
                foreach ($fields as $field => $value) if (abs((float)$row[$field] - (float)$value) > .000001) {
                    throw new \RuntimeException('Runtime позиция Fleet не сохранилась.');
                }
                foreach ($fleet->getResources() as $id => $resource) {
                    $field = 'resource_' . \SPGame\Game\Repositories\Vars::$resource[$id];
                    if (abs((float)$row[$field] - $resource->getCount()) > .000001) throw new \RuntimeException('Runtime груз не сохранился.');
                }
            }
            if ($owner !== null) {
                $row = PlanetResources::findExistingById((int)$owner['Planet']['id']);
                foreach ([901,902,903] as $id) {
                    if ($row === null || abs((float)$row[Vars::$resource[$id]] - (float)$owner['Resources'][$id]['count']) > .000001) {
                        throw new \RuntimeException('Runtime погрузка ресурсов не сохранилась.');
                    }
                }
            }
            return $result;
        }
        catch (\Throwable $error) {
            // Группа может появиться при активации этапа внутри этого commit.
            // Сохраняем её ID до восстановления старой цепочки команд.
            if ($planGroups !== null) foreach ($fleets as $fleet) {
                $oldCommands = array_column($snapshots[$fleet->getId()][1], null, 'id');
                foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $command) {
                    $params = self::params($command);
                    $groupId = (int)($params['groupId'] ?? 0);
                    $old = $oldCommands[$command['id']] ?? null;
                    // После LOAD активируется уже существующая строка плана;
                    // новый groupId может появиться без добавления команды.
                    if ($old !== null && (int)(self::params($old)['groupId'] ?? 0) === $groupId) continue;
                    if ($groupId > 0 && !array_key_exists($groupId, $planGroups)) $planGroups[$groupId] = null;
                }
            }
            try {
                foreach ($fleets as $fleet) {
                    [$row, $commands] = $snapshots[$fleet->getId()];
                    $ids = array_column($commands, 'id');
                    foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $added) {
                        if (!in_array($added['id'], $ids, true)) FleetCommands::discardUncommittedRow($added);
                    }
                    foreach ($commands as $old) self::patchCommand((int)$old['id'], $old);
                    Fleets::update($row);
                    if (Fleets::getById($fleet->getId()) != $row) throw new \RuntimeException('Не удалось восстановить Fleet.');
                    $fleet->reloadFromRuntime();
                }
                if ($owner !== null) {
                    foreach ($resources as $id => $count) $owner['Resources'][$id]['count'] = $count;
                    $owner->save();
                    $row = PlanetResources::findExistingById((int)$owner['Planet']['id']);
                    foreach ($resources as $id => $count) if ($row === null || abs((float)$row[Vars::$resource[$id]] - $count) > .000001) {
                        throw new \RuntimeException('Не удалось восстановить ресурсы погрузки.');
                    }
                }
            } catch (\Throwable $rollback) {
                Logger::getInstance()->error('CRITICAL: rollback управления Fleet не выполнен',
                    ['fleetIds' => array_keys($snapshots), 'message' => $rollback->getMessage(), 'originalMessage' => $error->getMessage()]);
            }
            foreach ($planGroups ?? [] as $id => $before) {
                try {
                    $current = FleetAttackGroups::findExistingById((int)$id);
                    if ($before !== null) FleetAttackGroups::store($before);
                    elseif ($current !== null) FleetAttackGroups::discardUncommittedRow($current);
                } catch (\Throwable $rollback) {
                    Logger::getInstance()->error('CRITICAL: rollback группы при сохранении маршрута',
                        ['groupId' => $id, 'message' => $rollback->getMessage(), 'originalMessage' => $error->getMessage()]);
                }
            }
            throw $error;
        }
    }

    public static function stop(Fleet $fleet, AccountData $owner, float $time): void
    {
        self::assertAction($fleet, $owner, 'StopFleet');
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ((int)$active['command_type'] !== Command::MOVE_IN_SYSTEM->value) {
            $params = self::params($active); $params['stopAfterTransition'] = true;
            self::patchCommand((int)$active['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            return;
        }
        self::commit([$fleet], static function () use ($fleet, $owner, $active, $time): void {
            $params = self::params($active);
            $start = $fleet->getCurrentPositionTime();
            $time = max($start, min($time, (float)$active['execute_time']));
            $position = (new FleetFlightService())->calculateCurrentCoordinate($fleet, $active, $time);
            $progress = min(1.0, max(0.0, ($time - $start) / max(.000001, (float)$active['execute_time'] - $start)));
            $weight = (float)($params['progressWeight'] ?? 1);
            $done = (float)($params['progressDone'] ?? 0);
            $params['progressCompleted'] = min($weight, $done + (float)($params['progressSpan'] ?? $weight - $done) * $progress);
            self::patchCommand((int)$active['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            $fleet->setFuelCurrent(min($fleet->getFuelTotal(), $fleet->getFuelCurrent() + (float)($params['fuelCost'] ?? 0) * (1 - $progress)));
            $fleet->setCurrentCoordinate($position, $time);
            $fleet->save();
            self::holdBefore($active, $fleet, $owner);
        });
    }

    /** Добровольная остановка оплачивается как OBSERVE; оставшийся маршрут сохраняется. */
    public static function holdBefore(array $next, Fleet $fleet, AccountData $owner): void
    {
        self::commit([$fleet], static function () use ($next, $fleet, $owner): void {
            $future = FleetCommands::getFleetQueue($fleet->getId());
            foreach ($future as $old) {
                $p = self::params($old); $p['cancelled'] = true;
                if ((int)$old['id'] === (int)$next['id'] && !isset($p['progressCompleted'])) {
                    $p['progressCompleted'] = (float)($p['progressDone'] ?? 0);
                }
                self::patchCommand((int)$old['id'], ['executed' => 1, 'params' => json_encode($p, JSON_THROW_ON_ERROR)]);
            }
            $hold = self::add($fleet->getId(), Command::OBSERVE, ['target' => $fleet->getCurrentCoordinate()->toArray()]);
            foreach ($future as $old) {
                $p = self::params($old);
                if ((int)$old['id'] === (int)$next['id']) {
                    $p['progressWeight'] = max(0.0, (float)($p['progressWeight'] ?? 1) - (float)($p['progressCompleted'] ?? $p['progressDone'] ?? 0));
                    foreach (['motionStart','motionEnd','resolvedTarget','startedAt','fuelCost','recallFlight','fuelStop','pause','stopAfterTransition','progressCompleted','progressDone','progressSpan'] as $key) unset($p[$key]);
                }
                self::add($fleet->getId(), Command::from((int)$old['command_type']), $p);
            }
            self::beginHold($hold, $fleet, $owner);
        });
    }

    public static function resumeMode(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, ['available', 'segment', 'route'], true)) {
            throw new \InvalidArgumentException('Выберите вариант продолжения полёта.');
        }
        return $mode;
    }

    /** Проверка до ACK; EventLoop рассчитывает запас повторно по свежему runtime. */
    public static function prepareResume(Fleet $fleet, AccountData $owner, mixed $mode, float $time): string
    {
        self::assertAction($fleet, $owner, 'ResumeFleet');
        $mode = self::resumeMode($mode);
        // Повтор погрузки может сам пополнить пустой бак. Это не дозаправка из трюма.
        if ($mode === 'available' && FleetLoadingService::canResumeAtSource($fleet)) return $mode;
        if ($mode !== 'available') self::assertCargoRefuel($owner);
        $fuel = FleetTransferService::physicalFuel($fleet, $time);
        $cargo = (float)(($fleet->getResources()[903] ?? null)?->getCount() ?? 0);
        if ($mode === 'available' && $fuel <= .000001) throw new \LogicException('В баке нет топлива для продолжения.');
        if ($mode === 'segment' && $fuel + $cargo <= .000001) throw new \LogicException('В баке и трюме нет дейтерия.');
        if ($mode === 'route' && self::remainingRouteFuel($fleet, $owner) - $fuel - $cargo > .000001) {
            throw new \LogicException('Дейтерия в баке и трюме недостаточно для оставшегося маршрута.');
        }
        return $mode;
    }

    /** Верхний расчётный запас: штатный one-way preview для каждой оставшейся цели. */
    public static function remainingRouteFuel(Fleet $fleet, AccountData $owner): float
    {
        $commands = FleetCommands::getFleetQueue($fleet->getId());
        $cursor = $fleet->getCurrentCoordinate();
        if ($cursor->getType() === CoordinateType::FLEET) $cursor = $cursor->getLastKnownPosition();
        $mission = new FleetMissionService();
        $fuel = 0.0;
        $covered = [];
        foreach ($commands as $index => $command) {
            if (isset($covered[$index])) continue;
            $type = Command::from((int)$command['command_type']);
            if ($type === Command::RESEARCH) {
                $target = FleetCommandsService::getCommandTarget($command);
                if ($target === null) throw new \LogicException('Нет объекта обследования.');
                $params = self::params($command);
                $fuel += isset($params['survey'])
                    ? max(0.0, (float)$params['survey']['endsAt'] - microtime(true)) * FleetDefendService::fuelRate($fleet, $owner)
                    : FleetSurveyService::estimate($fleet, $owner, $target)['fuel'];
                continue;
            }
            if ($type === Command::OBSERVE) {
                if ($index === 0 && count($commands) > 1) continue; // Завершаем текущее удержание.
                throw new \LogicException('Бессрочное удержание не имеет конечного расхода: выберите запас до следующего участка.');
            }
            if (!in_array($type, [Command::MOVE_IN_SYSTEM, Command::MOVE_BETWEEN_SYSTEMS, Command::MOVE_BETWEEN_GALAXIES], true)) continue;
            $params = self::params($command);
            $target = FleetCommandsService::getCommandTarget($command);
            if ($target === null) throw new \LogicException('Не указана цель оставшегося участка.');
            $speed = (float)($params['groupSpeedCap'] ?? $params['speedFactor'] ?? 10);
            $preview = $mission->getTravelInfo($fleet, FleetTarget::fromCoordinates($target->toArray()), $owner, $speed, origin: $cursor);
            if ($preview === null) throw new \LogicException('Не удалось рассчитать топливо до одной из целей маршрута.');
            $fuel += max((float)$preview['fuel']['max'], (float)($params['recallFlight']['fuelCost'] ?? 0));
            // Один адресный перелёт представлен MOVE → переход → MOVE.
            // Preview уже учитывает все три части; не складываем их повторно.
            if ($cursor->getGalaxy() !== $target->getGalaxy() || $cursor->getSystem() !== $target->getSystem()) {
                $until = $type === Command::MOVE_IN_SYSTEM ? 2 : 1;
                for ($offset = 1; $offset <= $until && isset($commands[$index + $offset]); $offset++) {
                    $next = $commands[$index + $offset];
                    $p = self::params($next);
                    if (!in_array((int)$next['command_type'], [10, 11, 12], true)
                        || ($p['target'] ?? null) !== ($params['target'] ?? null)
                        || ($p['planStepId'] ?? null) !== ($params['planStepId'] ?? null)) break;
                    $covered[$index + $offset] = true;
                }
            }
            $cursor = $target->getType() === CoordinateType::FLEET ? $target->getLastKnownPosition() : $target;
        }
        if (!is_finite($fuel)) throw new \LogicException('Некорректный расчёт топлива маршрута.');
        return ceil($fuel);
    }

    private static function resumeNext(Fleet $fleet, AccountData $owner, string $mode): void
    {
        if ($mode === 'route') {
            $missing = max(0.0, self::remainingRouteFuel($fleet, $owner) - $fleet->getFuelCurrent());
            $cargo = $fleet->getResources()[903];
            if ($missing - $cargo->getCount() > .000001) throw new \LogicException('Дейтерия в трюме недостаточно для оставшегося маршрута.');
            $cargo->setCount(max(0.0, $cargo->getCount() - $missing));
            $fleet->setFuelTotal(max($fleet->getFuelTotal(), $fleet->getFuelCurrent() + $missing));
            $fleet->setFuelCurrent($fleet->getFuelCurrent() + $missing);
        }
        foreach (FleetCommands::getFleetQueue($fleet->getId()) as $next) {
            if (!in_array((int)$next['command_type'], [10, 11, 12], true)) continue;
            $params = self::params($next);
            unset($params['fuelPolicy']);
            if ($mode === 'available') $params['fuelPolicy'] = 'available';
            self::patchCommand((int)$next['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
        }
        FleetCommandsService::activateNextCommand($fleet, $owner, $mode === 'segment');
        $state = self::state($fleet);
        if ($state['reason'] === 'fuel' && $mode !== 'available') {
            throw new \LogicException('Топлива для продолжения всё ещё недостаточно.');
        }
        if ($mode === 'route' && $state['paused'] && $state['reason'] !== 'fuel') {
            throw new \LogicException($state['error'] ?? 'Оставшийся маршрут недоступен: дозаправка отменена.');
        }
        $fleet->save();
    }

    /** Запас заканчивается внутри MOVE: цель/следующие действия остаются pending. */
    public static function finishFuelLimitedMove(array $command, Fleet $fleet, Coordinate $position, float $time): void
    {
        self::commit([$fleet], static function () use ($command, $fleet, $position, $time): void {
            $params = self::params($command);
            $weight = (float)($params['progressWeight'] ?? 1);
            $done = (float)($params['progressDone'] ?? 0);
            $params['progressDone'] = min($weight, $done + (float)($params['progressSpan'] ?? 0));
            foreach (['fuelStop', 'motionStart', 'motionEnd', 'resolvedTarget', 'startedAt', 'fuelCost', 'recallFlight', 'progressSpan'] as $key) unset($params[$key]);
            $params = self::pauseParams($params, 'fuel');
            $fleet->setCurrentCoordinate($position, $time);
            $fleet->save();
            self::patchCommand((int)$command['id'], ['execute_time' => 0.0,
                'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
        });
    }

    public static function resume(Fleet $fleet, AccountData $owner, float $time, bool $useCargoFuel = false, ?string $fuelMode = null): void
    {
        self::assertAction($fleet, $owner, 'ResumeFleet');
        $mode = $fuelMode === null ? ($useCargoFuel ? 'segment' : 'legacy') : self::prepareResume($fleet, $owner, $fuelMode, $time);
        if (in_array($mode, ['segment', 'route'], true)) self::assertCargoRefuel($owner);
        self::commit([$fleet], static function () use ($fleet, $owner, $time, $mode): void {
            // Послебоевая пауза оплачивается тем же clock, что стоянка базы.
            // Снимаем его только внутри компенсируемого продолжения маршрута.
            if (ResourceBaseService::isPostBattleGuard($fleet)) {
                $time = max($time, (float)$fleet->getBaseFuelState()['time']);
                ResourceBaseService::settleStanding($fleet, $time);
                $fleet->setBaseFuelState(null);
            }
            // Старая остановка могла быть создана до progress metadata.
            // Нормализуем только при явном действии, не при рендере страницы.
            $chain = FleetCommands::getFleetCommandChain($fleet->getId());
            [$weights, $completed] = self::legacyProgress($chain);
            foreach ($chain as $old) {
                $params = self::params($old);
                if (isset($weights[$old['id']])) $params['progressWeight'] = $weights[$old['id']];
                if (isset($completed[$old['id']])) $params['progressCompleted'] = $completed[$old['id']];
                if (isset($weights[$old['id']]) || isset($completed[$old['id']])) {
                    self::patchCommand((int)$old['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
                }
            }
            $active = FleetCommands::getActiveCommand($fleet->getId());
            if ($active !== null && (int)$active['command_type'] === Command::OBSERVE->value) {
                $time = max($time, (float)(self::params($active)['hold']['lastAt'] ?? 0));
                self::settleHold($fleet, $time);
                self::patchCommand((int)$active['id'], ['executed' => 1]);
                $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), max($time, $fleet->getCurrentPositionTime()));
                self::resumeNext($fleet, $owner, $mode);
                return;
            }
            $pending = FleetCommands::getNextPendingCommand($fleet->getId());
            $params = self::params($pending); unset($params['pause'], $params['blockedAfterBattle']);
            $time = max($time, (float)($params['hold']['lastAt'] ?? 0));
            if ((int)$pending['command_type'] === Command::OBSERVE->value
                && count(FleetCommands::getFleetQueue($fleet->getId())) === 1) {
                if ($fleet->getFuelCurrent() <= .000001) throw new \LogicException('Для продолжения удержания нужно топливо.');
                self::patchCommand((int)$pending['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
                self::beginHold(FleetCommands::getCommand((int)$pending['id']), $fleet, $owner, $time);
                return;
            }
            $patch = ['params' => json_encode($params, JSON_THROW_ON_ERROR)];
            if ((int)$pending['command_type'] === Command::OBSERVE->value) $patch['executed'] = 1;
            self::patchCommand((int)$pending['id'], $patch);
            $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), max($time, $fleet->getCurrentPositionTime()));
            self::resumeNext($fleet, $owner, $mode);
        });
    }

    /** Расход хранится как fuel на lastAt + rate; новый snapshot и UI не мутируют baseline. */
    public static function beginHold(array $command, Fleet $fleet, AccountData $owner, ?float $time = null): ?array
    {
        $time ??= $fleet->getCurrentPositionTime();
        return self::commit([$fleet], static function () use ($command, $fleet, $owner, $time): ?array {
            $point = FleetRendezvousService::position($fleet, $time);
            $fleet->setCurrentCoordinate($point, $time);
            $factor = (float)(\SPGame\Game\Repositories\Config::getValue('FleetHoldFuelPerHourFactor') ?? .1);
            if (!is_finite($factor) || $factor < 0) throw new \LogicException('Некорректный расход удержания.');
            $rate = $fleet->getTotalFuelConsumption($owner) * $factor / 3600;
            $params = self::params($command);
            $params['hold'] = ['startedAt' => $time, 'lastAt' => $time, 'rate' => $rate];
            unset($params['pause']);
            $end = $rate > 0 && $fleet->getFuelCurrent() > .000001 ? $time + $fleet->getFuelCurrent() / $rate : 0.0;
            if ($end === 0.0) $params = self::pauseParams($params, $rate > 0 ? 'fuel' : 'hold');
            self::patchCommand((int)$command['id'], ['execute_time' => $end, 'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            $fleet->save();
            return $end > 0 ? FleetCommands::getCommand((int)$command['id']) : null;
        });
    }

    public static function settleHold(Fleet $fleet, float $time): void
    {
        if ($fleet->getBaseFuelState() !== null) {
            ResourceBaseService::settleStanding($fleet, max($time, (float)$fleet->getBaseFuelState()['time']));
            return;
        }
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ($active === null || !in_array((int)$active['command_type'], [Command::OBSERVE->value, Command::RESEARCH->value], true)) return;
        $params = self::params($active);
        if (!isset($params['hold'])) return;
        $last = (float)$params['hold']['lastAt'];
        if ($time < $last) throw new \LogicException('Удержание нельзя актуализировать в прошлое.');
        $fuel = max(0.0, $fleet->getFuelCurrent() - (float)$params['hold']['rate'] * ($time - $last));
        $fleet->setFuelCurrent($fuel);
        $params['hold']['lastAt'] = $time;
        self::patchCommand((int)$active['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    }

    public static function exhaustHold(array $command, Fleet $fleet): void
    {
        self::commit([$fleet], static function () use ($command, $fleet): void {
            self::settleHold($fleet, (float)$command['execute_time']);
            self::pause(FleetCommands::getCommand((int)$command['id']), 'fuel');
            $fleet->save();
        });
    }

    /** Будущий маршрут начинается после текущего участка, который редактор не меняет. */
    public static function planOrigin(Fleet $fleet): Coordinate
    {
        $active = FleetCommands::getActiveCommand($fleet->getId());
        return $active === null || !in_array((int)$active['command_type'], [10,11,12], true)
            ? $fleet->getCurrentCoordinate()
            : (new FleetFlightService())->resolveCommandEndCoordinate(
                Command::from((int)$active['command_type']), $fleet->getCurrentCoordinate(),
                FleetCommandsService::getCommandTarget($active), self::params($active),
                $fleet->getCurrentPositionTime(), (float)$active['execute_time']);
    }

    /** Редактор принимает намерения, а не произвольные LOAD/SQL/event time от клиента. */
    public static function preparePlan(Fleet $fleet, AccountData $owner, array $input): array
    {
        self::assertAction($fleet, $owner, 'SetFleetCommands');
        if ($fleet->getHomeSiteId() > 0) {
            throw new \LogicException('Редактор маршрута ресурсной базы ещё не подключён. Используйте отдельную миссию.');
        }
        $version = $input['version'] ?? null;
        if (!is_string($version) || !hash_equals(self::state($fleet)['version'], $version)) {
            throw new \LogicException('Команды флота изменились. Откройте редактор заново.');
        }
        $steps = $input['steps'] ?? null;
        if (!is_array($steps) || !array_is_list($steps) || count($steps) < 1 || count($steps) > 12
            || array_filter($steps, static fn($step) => !is_array($step))) {
            throw new \InvalidArgumentException('В плане должно быть от 1 до 12 этапов.');
        }
        $maxStages = self::capabilities($owner)['maxStages'];
        if (FleetPlanService::stageCount($fleet, $steps) > $maxStages) {
            throw new \LogicException('Компьютерная технология позволяет не более ' . $maxStages
                . ' оставшихся этапов, включая текущее действие и возврат.');
        }
        $idle = $fleet->getStatus() === Fleet::STATUS_IDLE;
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ($active !== null && !in_array((int)$active['command_type'], [10,11,12,Command::OBSERVE->value,Command::DEFEND->value,Command::EXPEDITION->value,Command::RESEARCH->value], true)) {
            throw new \LogicException('Редактор недоступен во время исполнения боевой команды.');
        }
        if ($idle) {
            FleetsServices::getManageableFleet($fleet->getId(), $owner, true);
            FleetsServices::assertMissionSlot($owner);
        }
        $home = Planets::findExistingById($fleet->getHomePlanetId());
        if ($home === null || (int)$home['owner_id'] !== $fleet->getOwnerId()) throw new \LogicException('База флота недоступна.');
        $base = Coordinate::object((int)$home['galaxy'], (int)$home['system'], (int)$home['planet'], ObjectType::from((int)$home['planet_type']));
        $cursor = self::planOrigin($fleet);
        $commands = FleetPlanService::compile($fleet, $owner, $steps, $cursor, $base);
        $load = ['fuel' => 0.0, 'resources' => [901 => 0.0,902 => 0.0,903 => 0.0]];
        if ($idle) {
            if (isset($input['resources']) && !is_array($input['resources'])) throw new \InvalidArgumentException('Некорректные ресурсы плана.');
            foreach (['fuel' => $input['fuel'] ?? 0, 901 => $input['resources'][901] ?? 0,
                902 => $input['resources'][902] ?? 0, 903 => $input['resources'][903] ?? 0] as $key => $amount) {
                if ((!is_int($amount) && !is_float($amount)) || !is_finite((float)$amount) || $amount < 0) {
                    throw new \InvalidArgumentException('Некорректная погрузка для плана.');
                }
                if ($key === 'fuel') $load['fuel'] = (float)$amount; else $load['resources'][$key] = (float)$amount;
            }
            foreach ($load['resources'] as $id => $count) {
                if ($count + ($id === 903 ? $load['fuel'] : 0) > (float)$owner['Resources'][$id]['count']) {
                    throw new \LogicException('На планете недостаточно ресурсов.');
                }
            }
            if ($fleet->getUsedCapacity() + $fleet->getFuelCurrent() + array_sum($load['resources']) + $load['fuel'] > $fleet->getCapacity()) {
                throw new \LogicException('Груз и топливо превышают вместимость.');
            }
            array_unshift($commands, ['type' => Command::LOAD, 'params' => ['target' => $base->toArray(),
                'sourcePlanetId' => (int)$home['id']] + $load]);
        }
        return $commands;
    }

    public static function savePlan(Fleet $fleet, AccountData $owner, array $input, float $time): void
    {
        $commands = self::preparePlan($fleet, $owner, $input);
        $idle = $fleet->getStatus() === Fleet::STATUS_IDLE;
        $groups = [];
        foreach ($commands as $command) {
            $id = (int)($command['params']['planStep']['group']['id'] ?? 0);
            if ($id > 0) $groups[$id] = FleetAttackGroups::findExistingById($id);
        }
        self::commit([$fleet], static function () use ($fleet, $owner, $commands, $idle, $time): void {
            $active = FleetCommands::getActiveCommand($fleet->getId());
            $paused = self::state($fleet)['paused'];
            $siteGuard = ResourceBaseService::isPostBattleGuard($fleet);
            foreach (FleetCommands::getFleetQueue($fleet->getId()) as $old) {
                if ($active !== null && (int)$active['id'] === (int)$old['id']) continue;
                $params = self::params($old); $params['cancelled'] = true;
                if (!empty($params['progressDone'])) $params['progressCompleted'] = (float)$params['progressDone'];
                self::patchCommand((int)$old['id'], ['executed' => 1, 'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            }
            if ($paused) self::add($fleet->getId(), Command::OBSERVE, [
                'target' => $fleet->getCurrentCoordinate()->toArray(), 'pause' => $siteGuard ? 'battle' : 'manual',
            ] + ($siteGuard ? ['siteGuard' => $fleet->getCurrentCoordinate()->getSiteId()] : []));
            foreach ($commands as $command) self::add($fleet->getId(), $command['type'], $command['params']);
            if ($idle) {
                $fleet->setStartCoordinate($fleet->getCurrentCoordinate());
                $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), max($time, $fleet->getCurrentPositionTime()));
            }
            // Активный участок и его миссия остаются неизменными до завершения.
            if ($active === null) $fleet->setMission((int)($commands[0]['params']['planMission'] ?? Mission::OBSERVE->value));
            $fleet->setStatus(Fleet::STATUS_MISSION);
            foreach ($commands as $command) if ($active === null && in_array($command['type'], [Command::MOVE_IN_SYSTEM,Command::MOVE_BETWEEN_SYSTEMS,Command::MOVE_BETWEEN_GALAXIES], true)) {
                $target = $command['params']['target'];
                $target = $target instanceof Coordinate ? $target : Coordinate::fromArray($target);
                $target = FleetPlanService::runtimeTarget($fleet, $target, $time);
                $fleet->setTargetCoordinate($target, $target->getType() === CoordinateType::FLEET ? $time : null);
                break;
            }
            if ($active === null) FleetCommandsService::activateNextCommand($fleet, $owner);
            $fleet->save();
        }, $idle ? $owner : null, $groups);
    }
}
