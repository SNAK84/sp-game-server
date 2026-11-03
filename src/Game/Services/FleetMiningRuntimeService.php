<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{MiningCheckpoints as Journal, OrbitalResourceSites as Sites,
    ResourceSurveys as Knowledge, SurveyResults, ResourceBases, FleetCommands, Fleets, FleetsShips, Vars, Users};
use SPGame\Game\Space\Coordinate;

/** Общая добыча объекта через существующие due-команды, без собственного таймера. */
final class FleetMiningRuntimeService
{
    public static function options(array $input, bool $gas): array
    {
        $balance = require dirname(__DIR__, 3) . '/resources/fleet-mining.php';
        $mode = $input['mode'] ?? ($gas ? 'deuterium' : 'mix');
        $duration = $input['duration'] ?? $balance['default_duration'];
        $finish = $input['finish'] ?? 'return';
        $speed = $input['speedFactor'] ?? 10;
        if (!in_array($mode, $gas ? ['deuterium'] : $balance['belt_modes'], true)
            || !is_int($duration) || !in_array($duration, $balance['durations'], true)
            || !in_array($finish, $balance['finish_options'], true)
            || (!is_int($speed) && !is_float($speed)) || !is_finite((float)$speed) || $speed < 1 || $speed > 10) {
            throw new \InvalidArgumentException('Некорректные режим, длительность или завершение добычи.');
        }
        return ['mode' => $mode, 'duration' => $duration, 'finish' => $finish, 'speedFactor' => (float)$speed];
    }

    public static function rules(): array
    {
        $balance = require dirname(__DIR__, 3) . '/resources/fleet-mining.php';
        return array_intersect_key($balance, array_flip(['belt_modes', 'durations', 'default_duration', 'finish_options']));
    }

    /** Права, корпус и обследование проверяются и при отправке, и при прибытии. */
    public static function prepare(Fleet $fleet, AccountData $owner, Coordinate $target, array $input = [], bool $arriving = false): array
    {
        if (!ResourceBaseService::isEnabled() || $fleet->getOwnerId() !== (int)$owner['User']['id']) {
            throw new \LogicException('Ресурсные базы недоступны.');
        }
        $site = FleetSurveyService::site($target);
        if ($target->toArray() !== FleetSurveyService::coordinate($site)->toArray()) throw new \InvalidArgumentException('Выберите объект или сектор без ручного угла.');
        if (ResourceBaseService::battleAt((int)$site['id']) !== null) throw new \LogicException('У объекта идёт бой.');
        $options = self::options($input, (int)$site['site_type'] === Sites::GAS_GIANT);
        $known = Knowledge::findByIndex('owner_site', [$fleet->getOwnerId(), (int)$site['id']]);
        if ((float)($known['coverage'] ?? 0) <= 0) throw new \LogicException('Сначала обследуйте ресурсный объект.');
        $ships = []; foreach ($fleet->getShips() as $id => $ship) $ships[$id] = $ship->getCount();
        $rates = FleetMiningService::rates($site, $ships, (int)($owner['Techs'][Vars::$resource[136]] ?? 0), $options['mode']);
        if (array_sum($rates) <= 0) throw new \LogicException('Для этого объекта нет подходящих добывающих кораблей.');
        $base = ResourceBases::findExistingById((int)$site['id']);
        if ($base !== null && (int)$base['owner_id'] !== $fleet->getOwnerId()) throw new \LogicException('Объект занят другим игроком. Добыча не начинает атаку.');
        if ($base === null || $options['finish'] === 'stay') {
            ResourceBaseService::prepareDestination($fleet, $owner, $target, $base === null, $arriving);
        }
        return $options + ['rates' => $rates, 'coverage' => (float)$known['coverage']];
    }

    public static function reason(Fleet $fleet, AccountData $owner, Coordinate $target): ?string
    {
        try { self::prepare($fleet, $owner, $target); return null; }
        catch (\InvalidArgumentException|\LogicException $error) { return $error->getMessage(); }
    }

    /** Только расчёт своего расхода/мощности. Общий запас объекта не раскрывается. */
    public static function preview(Fleet $fleet, AccountData $owner, Coordinate $target, array $input): array
    {
        $options = self::prepare($fleet, $owner, $target, $input);
        $rate = self::fuelPerHour($fleet, $owner);
        return $options + ['fuelPerHour' => $rate, 'workFuel' => ceil($rate * $options['duration'] / 3600)];
    }

    private static function fuelPerHour(Fleet $fleet, AccountData $owner): float
    {
        $factor = (float)(\SPGame\Game\Repositories\Config::getValue('FleetHoldFuelPerHourFactor') ?? .1);
        if (!is_finite($factor) || $factor < 0) throw new \LogicException('Некорректный расход добычи.');
        return $fleet->getTotalFuelConsumption($owner) * $factor;
    }

    public static function state(Fleet $fleet): ?array
    {
        $command = FleetCommands::getActiveCommand($fleet->getId());
        $params = $command === null ? [] : FleetControlService::params($command);
        $run = $params['mining'] ?? null;
        if ((int)($command['command_type'] ?? 0) !== Command::MINING->value || ($run['phase'] ?? null) !== 'working') return null;
        $site = Sites::findExistingById((int)$run['siteId']);
        return ['startedAt' => $run['startedAt'], 'endsAt' => $run['endsAt'], 'finish' => $run['finish'],
            'mode' => $run['mode'], 'at' => $params['miningCheckpoint']['at'], 'until' => (float)$command['execute_time'],
            'rates' => self::distribution($run, $site), 'extracted' => $run['extracted'],
            'fuelPerHour' => $run['fuelRate'], 'fuelReserve' => $run['fuelReserve']];
    }

    /** SQL-событие не раньше границы; положительный интервал не схлопывается в ноль. */
    public static function due(float $time, ?float $after = null): float
    {
        if (!is_finite($time) || $time <= 0) throw new \InvalidArgumentException('Некорректное время добычи.');
        if ($after !== null && (!is_finite($after) || $after <= 0 || $time < $after)) {
            throw new \InvalidArgumentException('Некорректное начало интервала добычи.');
        }
        $result = (float)sprintf('%.6F', $time);
        if ($result < $time) $result = (float)sprintf('%.6F', $result + .000001);
        // При epoch~1.8e9 положительный остаток меньше ULP теряется уже в
        // start+elapsed, до SQL. Следующий tick должен пересечь эту границу;
        // сам phase по-прежнему начисляет только ТОЧНЫЙ elapsed до события.
        return $after !== null && $result <= $after ? self::due($after + .000001) : $result;
    }

    /** Постоянная общая мощность смеси перераспределяется, не удваивается. */
    public static function distribution(array $run, array $site): array
    {
        $ids = match ($run['mode']) { 'metal' => [901], 'crystal' => [902], 'deuterium' => [903], 'mix' => [901, 902] };
        $ids = array_values(array_diff($ids, $run['blocked']));
        $rates = [901 => 0.0, 902 => 0.0, 903 => 0.0];
        if ($ids === []) return $rates;
        if (count($ids) === 1) { $rates[$ids[0]] = (float)$run['production']; return $rates; }
        $total = (float)$site['metal_rate'] + (float)$site['crystal_rate'];
        if ($total <= 0) throw new \LogicException('Нет пропорции восполнения смеси.');
        $rates[901] = $run['production'] * ((float)$site['metal_rate'] / $total);
        $rates[902] = max(0.0, $run['production'] - $rates[901]);
        return $rates;
    }

    /** Решения только ПОСЛЕ общего расчётного интервала. Никаких записей. */
    public static function boundaries(array $runs, array $phase, array $site): array
    {
        foreach ($phase['events'] as $event) {
            if (isset($event['fleetId'])) {
                $runs[$event['fleetId']]['phase'] = 'closing';
                $runs[$event['fleetId']]['reason'] = $event['kind'];
                continue;
            }
            foreach ($runs as &$run) {
                if ($run['phase'] !== 'working' || self::distribution($run, $site)[$event['resourceId']] <= 0) continue;
                $run['blocked'][] = $event['resourceId'];
                if ($run['mode'] !== 'mix' || array_sum(self::distribution($run, $site)) <= 0) {
                    $run['phase'] = 'closing'; $run['reason'] = $event['kind'];
                }
            }
            unset($run);
        }
        return $runs;
    }

    /** Неинтегрированная мутация не может обойти общий экономический checkpoint. */
    public static function assertAvailable(int $fleetId): void
    {
        $command = FleetCommands::getActiveCommand($fleetId);
        if ($command !== null && (int)$command['command_type'] === Command::MINING->value
            && isset(FleetControlService::params($command)['mining'])) {
            throw new \LogicException('Флот добывает ресурсы. Сначала отзовите его.');
        }
    }

    public static function hasMining(int $fleetId): bool
    {
        $command = FleetCommands::getActiveCommand($fleetId);
        return $command !== null && (int)$command['command_type'] === Command::MINING->value;
    }

    /** Рабочая фаза закрывается до боя независимо от приписки/продолжения. */
    public static function canInterruptForBattle(?Fleet $fleet): bool
    {
        if ($fleet === null) return false;
        $command = FleetCommands::getActiveCommand($fleet->getId());
        $run = $command === null ? null : (FleetControlService::params($command)['mining'] ?? null);
        return $command !== null
            && (int)$command['command_type'] === Command::MINING->value
            && ($run['phase'] ?? null) === 'working' && in_array($run['finish'] ?? null, ['return', 'stay'], true);
    }

    /** Durable выход ДО боевого snapshot. Старый MINING не может пережить freeze/rollback боя. */
    public static function interruptForBattle(Fleet $fleet, float $time): void
    {
        self::interruptManyForBattle([$fleet], $time);
    }

    /** Все участвующие добытчики закрываются одним batch после общего settle. */
    public static function interruptManyForBattle(array $fleets, float $time): void
    {
        Journal::worldLocked(static function () use ($fleets, $time): void {
            FleetMiningCheckpointService::recoverPending();
            $ids = []; $siteId = $ownerId = null;
            foreach ($fleets as $fleet) {
                if (!self::canInterruptForBattle($fleet)) throw new \LogicException('Боевое прерывание этой добычи пока не подключено.');
                $command = FleetCommands::getActiveCommand($fleet->getId());
                $currentSite = (int)FleetControlService::params($command)['mining']['siteId'];
                if ($siteId !== null && ($siteId !== $currentSite || $ownerId !== $fleet->getOwnerId())) {
                    throw new \LogicException('Разные объекты/владельцы общего закрытия добычи.');
                }
                $siteId = $currentSite; $ownerId = $fleet->getOwnerId(); $ids[] = $fleet->getId();
            }
            if ($ids === []) return;
            self::settleSite($siteId, $time);
            // Если работа уже естественно закончилась, её обычный handoff приоритетнее боя.
            foreach ($fleets as $fleet) if (!self::canInterruptForBattle($fleet)) throw new \LogicException('Добыча уже завершилась; требуется новая встреча.');
            FleetMiningCheckpointService::closePhase($siteId, $ownerId, bin2hex(random_bytes(16)), $ids, 'battle');
            $user = Users::findExistingById($ownerId) ?? throw new \LogicException('Нет владельца добытчиков.');
            $owner = new AccountData((int)$user['account_id']);
            foreach ($fleets as $fleet) Notification::sendMiningResult($owner, $fleet, FleetCommands::getNextPendingCommand($fleet->getId()));
        }, true);
    }

    /** Durable смена удалённого дома ДО обычного commit потери базы. Не откатывать journal. */
    public static function redirectLostHome(array $fleets, int $lostSiteId, AccountData $owner, float $time): array
    {
        return Journal::worldLocked(static function () use ($fleets, $lostSiteId, $owner, $time): array {
            FleetMiningCheckpointService::recoverPending();
            $sites = []; $changed = [];
            foreach ($fleets as $fleet) {
                if ($fleet->getHomeSiteId() !== $lostSiteId || !self::hasMining($fleet->getId())) continue;
                $command = FleetCommands::getActiveCommand($fleet->getId());
                $run = FleetControlService::params($command)['mining'] ?? null;
                if ($run !== null && $run['siteId'] !== $lostSiteId) $sites[(int)$run['siteId']] = true;
            }
            foreach (array_keys($sites) as $siteId) {
                self::settleSite($siteId, $time);
                $commands = self::commands($siteId, 'working');
                $runs = $homes = $withoutHome = [];
                foreach ($commands as $id => $command) {
                    $runs[$id] = FleetControlService::params($command)['mining'];
                    $fleet = FleetsData::getInstance()->get($id);
                    if ($fleet->getHomeSiteId() !== $lostSiteId) continue;
                    try { $fallback = ResourceBaseService::fallbackPlanet($fleet); }
                    catch (\LogicException) { $withoutHome[] = $id; continue; }
                    $reserve = self::fuelReserve($fleet, $owner, $fleet->getCurrentCoordinate(), $time,
                        $runs[$id]['speedFactor'], ResourceBaseService::fallbackCoordinate($fleet));
                    $homes[$id] = ['planetId' => (int)$fallback['id'], 'fuelReserve' => $reserve];
                    $runs[$id]['fuelReserve'] = $reserve;
                }
                if ($homes !== []) {
                    $snapshot = self::snapshot($siteId, (int)$owner['User']['id'], $commands);
                    $previous = self::previous($siteId);
                    $plan = FleetMiningPhaseService::rehome($snapshot, self::workers($runs, $snapshot['site']),
                        $time, $previous, $lostSiteId, $homes);
                    self::publish($snapshot, $runs, $plan, $previous);
                    self::closePending($siteId);
                    array_push($changed, ...array_keys($homes));
                }
                // Без собственного fallback нельзя продолжать расход/добычу. Обычная
                // потеря базы затем создаст pause=base, уже без живого mining carry.
                $withoutHome = array_values(array_intersect($withoutHome, array_keys(self::commands($siteId, 'working'))));
                if ($withoutHome !== []) FleetMiningCheckpointService::closePhase($siteId, (int)$owner['User']['id'],
                    bin2hex(random_bytes(16)), $withoutHome, 'control');
            }
            return $changed;
        }, true);
    }

    /** Согласовать G/K до снимка обследования, даже если обследует другой игрок. */
    public static function surveyContext(int $siteId, int $ownerId, float $time, ?string $surveyToken = null): array
    {
        self::settleSite($siteId, $time, $surveyToken);
        $site = Sites::findExistingById($siteId) ?? throw new \LogicException('Объект обследования исчез.');
        $commands = self::commands($siteId, 'working');
        $run = $commands === [] ? null : FleetControlService::params(reset($commands))['mining'];
        return ['site' => $site, 'stock' => FleetMiningPhaseService::stock($site, $time, self::previous($siteId)),
            'mining' => ($run['ownerId'] ?? null) === $ownerId ? ['siteId' => $siteId, 'ownerId' => $ownerId, 'at' => $time] : null];
    }

    /** Prepared receipt удерживает часы объекта; applied marker входит в тот же journal. */
    public static function survey(array $receipt, bool $validateOnly = false): void
    {
        Journal::worldLocked(static function () use ($receipt, $validateOnly): void {
            FleetMiningCheckpointService::recoverPending();
            if (!$validateOnly) {
                $current = SurveyResults::findExistingById((int)$receipt['id']);
                if ($current === null || $current['token'] !== $receipt['token']) throw new \LogicException('Результат обследования исчез/изменился.');
                $receipt = $current; // Recovery мог уже применить marker этого результата.
            }
            $result = json_decode($receipt['state'], true, 512, JSON_THROW_ON_ERROR);
            if (!empty($result['miningApplied'])) return;
            $context = $result['mining']; $siteId = (int)$context['siteId']; $time = (float)$context['at'];
            if (!$validateOnly) self::settleSite($siteId, $time, $receipt['token']);
            $commands = self::commands($siteId, 'working');
            if ($commands === []) throw new \LogicException('Изменился состав дообследуемых добытчиков.');
            $snapshot = self::snapshot($siteId, (int)$context['ownerId'], $commands);
            $runs = []; foreach ($commands as $id => $command) $runs[$id] = FleetControlService::params($command)['mining'];
            $previous = self::previous($siteId);
            $plan = FleetMiningPhaseService::survey($snapshot, self::workers($runs, $snapshot['site']), $time, $previous, $receipt);
            if ($validateOnly) return;
            $snapshot['survey'] = $receipt;
            self::publish($snapshot, $runs, $plan, $previous);
            self::closePending($siteId);
            \SPGame\Core\Logger::getInstance()->info('Дообследование во время добычи применено',
                ['siteId' => $siteId, 'ownerId' => $context['ownerId'], 'token' => $receipt['token'], 'at' => $time,
                    'coverage' => $result['knowledge']['coverage'], 'fleetIds' => array_keys($runs)]);
        }, true);
    }

    /** Доставка не снимает добычу/паузу: изменяется только её authoritative baseline. */
    public static function transfer(array $command, Fleet $source, Fleet $target): array
    {
        return Journal::worldLocked(static function () use ($command, $source, $target): array {
            FleetMiningCheckpointService::recoverPending();
            if ($source->getOwnerId() !== $target->getOwnerId()) throw new \LogicException('Доставка добытчику другого игрока пока не подключена.');
            FleetBattleService::assertFleetAvailable($source->getId());
            FleetBattleService::assertFleetAvailable($target->getId(), true);
            if (FleetCommands::getCommand((int)$command['id']) !== $command) throw new \LogicException('Команда доставки изменилась.');
            $time = $source->getCurrentPositionTime();
            if ($time < $target->getCurrentPositionTime()) throw new \LogicException('Состояние добытчика уже изменилось; требуется новая встреча.');
            if (FleetRendezvousService::distance($source->getCurrentCoordinate(), FleetRendezvousService::position($target, $time), $time) > .05) {
                throw new \LogicException('Передача добытчику возможна только в точке встречи.');
            }
            $active = FleetCommands::getActiveCommand($target->getId());
            $run = FleetControlService::params($active)['mining'] ?? null;
            if ($run === null) throw new \LogicException('Добытчик ещё не начал работу.');
            $siteId = (int)$run['siteId']; self::settleSite($siteId, $time);
            $commands = self::commands($siteId, 'working');
            if (!isset($commands[$target->getId()])) throw new \LogicException('Добытчик завершает работу; повторите передачу после завершения команды.');
            $snapshot = self::snapshot($siteId, $source->getOwnerId(), $commands);
            $runs = []; foreach ($commands as $id => $row) $runs[$id] = FleetControlService::params($row)['mining'];
            $previous = self::previous($siteId);
            $sourceRow = Fleets::findExistingById($source->getId());
            $plan = FleetMiningPhaseService::transfer($snapshot, self::workers($runs, $snapshot['site']), $time, $previous, $sourceRow, $command);
            $snapshot['fleets'][$source->getId()] = $sourceRow;
            $snapshot['commands'][$source->getId()] = $command;
            self::publish($snapshot, $runs, $plan, $previous);
            self::closePending($siteId);
            \SPGame\Core\Logger::getInstance()->info('Передача работающему добытчику применена', ['sourceId' => $source->getId(),
                'receiverId' => $target->getId(), 'siteId' => $siteId, 'at' => $time, 'resources' => $plan['delivered']]);
            return ['fleetTransfer' => true, 'receiverId' => $target->getId(), 'resources' => $plan['delivered']];
        }, true);
    }

    public static function guards(int $siteId, int $ownerId): bool
    {
        foreach (self::commands($siteId) as $command) {
            $run = FleetControlService::params($command)['mining'];
            // Экономический close ещё не означает уход физически стоящего флота.
            // Иначе между close и STAY/возвратом scheduler может освободить базу.
            if (!in_array($run['phase'], ['working', 'closing', 'finished'], true)) continue;
            $fleet = FleetsData::getInstance()->get((int)$command['fleet_id']);
            if ($fleet === null || $fleet->getOwnerId() !== $ownerId) continue;
            foreach ($fleet->getShips() as $ship) if ($ship->getCount() > 0
                && (float)(Vars::$CombatCaps[$ship->getShipTypeId()]['attack'] ?? 0) > 0) return true;
        }
        return false;
    }

    /** Обычный донор присоединяется к своему работающему добытчику, не останавливая его. */
    public static function merge(array $command, Fleet $source, Fleet $target, AccountData $owner): void
    {
        Journal::worldLocked(static function () use ($command, $source, $target, $owner): void {
            FleetMiningCheckpointService::recoverPending();
            FleetMergeService::receiver($source, $target->getId());
            if (FleetCommands::getCommand((int)$command['id']) !== $command) throw new \LogicException('Команда слияния изменилась.');
            $time = $source->getCurrentPositionTime();
            if ($time < $target->getCurrentPositionTime()
                || FleetRendezvousService::distance($source->getCurrentCoordinate(), FleetRendezvousService::position($target, $time), $time) > .05) {
                throw new \LogicException('Слияние с добытчиком возможно только в актуальной точке встречи.');
            }
            $active = FleetCommands::getActiveCommand($target->getId());
            $run = FleetControlService::params($active)['mining'] ?? null;
            if ($run === null) throw new \LogicException('Добытчик ещё не начал работу.');
            $siteId = (int)$run['siteId']; self::settleSite($siteId, $time);
            $commands = self::commands($siteId, 'working');
            // Естественный close обрабатывается scheduler, после него доступно обычное слияние.
            if (!isset($commands[$target->getId()])) throw new \LogicException('Добытчик завершает работу; ожидается завершение команды.');
            $snapshot = self::snapshot($siteId, $source->getOwnerId(), $commands);
            $runs = []; foreach ($commands as $id => $row) $runs[$id] = FleetControlService::params($row)['mining'];
            $workers = self::workers($runs, $snapshot['site']);
            $combined = FleetMergeService::combinedShips($source, $target);
            $shipEntries = []; $snapshot['ships'] = [];
            foreach ([$target, $source] as $fleet) {
                $row = FleetsShips::findExistingById($fleet->getId()); $snapshot['ships'][$fleet->getId()] = $row;
                foreach (Vars::$reslist['fleet'] as $shipId) {
                    $name = Vars::$resource[$shipId];
                    if ((($source->getShips()[$shipId] ?? null)?->getCount() ?? 0) <= 0) continue;
                    $after = [];
                    foreach (['count', 'experience', 'damage', 'morale', 'status'] as $suffix) {
                        $field = $name . '_' . $suffix;
                        $after[$field] = $fleet === $target ? $combined[$field] : ($suffix === 'morale' ? 1.0 : ($suffix === 'count' || $suffix === 'status' ? 0 : 0.0));
                    }
                    // Явный образ существующей SQL-схемы (6 знаков), не неявное округление saver.
                    $after = FleetsShips::checkpointStorageValues($after);
                    if ($fleet === $target) $combined = array_replace($combined, $after);
                    $shipEntries[] = ['kind' => 'ships', 'id' => $fleet->getId(), 'group' => $name,
                        'before' => array_intersect_key($row, $after), 'after' => $after,
                        'guard' => ['fleet_id' => $fleet->getId(), 'owner_id' => $fleet->getOwnerId()]];
                }
            }
            $preview = $target->projectShips($combined);
            $run = &$runs[$target->getId()];
            $counts = []; foreach ($preview->getShips() as $id => $ship) $counts[$id] = $ship->getCount();
            $rates = FleetMiningService::rates($snapshot['site'], $counts, (int)($owner['Techs'][Vars::$resource[136]] ?? 0), $run['mode']);
            $reserve = self::fuelReserve($preview, $owner, $target->getCurrentCoordinate(), $time, $run['speedFactor']);
            $run['production'] = array_sum($rates); $run['capacity'] = $preview->getCapacity();
            $run['fuelRate'] = self::fuelPerHour($preview, $owner);
            $run['fuelReserve'] = $reserve;
            unset($run);
            $newWorker = self::workers($runs, $snapshot['site'])[$target->getId()];
            $previous = self::previous($siteId); $sourceRow = Fleets::findExistingById($source->getId());
            $experience = Fleets::checkpointStorageValues(['experience' => $preview->getExperience()])['experience'];
            $plan = FleetMiningPhaseService::merge($snapshot, $workers, $time, $previous,
                $sourceRow, $command, $newWorker, $shipEntries, $experience);
            $snapshot['fleets'][$source->getId()] = $sourceRow; $snapshot['commands'][$source->getId()] = $command;
            self::publish($snapshot, $runs, $plan, $previous);
            self::closePending($siteId);
            \SPGame\Core\Logger::getInstance()->info('Слияние с работающим добытчиком применено',
                ['sourceId' => $source->getId(), 'receiverId' => $target->getId(), 'siteId' => $siteId,
                    'at' => $time, 'production' => $runs[$target->getId()]['production'], 'fuelRate' => $runs[$target->getId()]['fuelRate']]);
        }, true);
    }

    public static function complete(array $command, Fleet $fleet, AccountData $owner): bool
    {
        return Journal::worldLocked(static function () use ($command, $fleet, $owner): bool {
            FleetMiningCheckpointService::recoverPending();
            if (FleetCommands::findExistingById((int)$command['id']) !== $command) return true;
            $params = FleetControlService::params($command);
            try {
                if (!isset($params['mining'])) self::begin($command, $fleet, $owner);
                elseif ($params['mining']['phase'] === 'finished') self::finish($command, $fleet, $owner);
                else self::settleSite((int)$params['mining']['siteId'], (float)$command['execute_time']);
            } catch (\InvalidArgumentException|\LogicException $error) {
                // После опубликованного journal сначала roll-forward, не новая отмена.
                FleetMiningCheckpointService::recoverPending();
                $fresh = FleetCommands::getCommand((int)$command['id']);
                if ($fresh === null) throw $error;
                $p = FleetControlService::params($fresh);
                if (isset($p['mining']) && $p['mining']['phase'] === 'working') throw $error;
                $p['miningRejected'] = $error->getMessage();
                FleetControlService::patchCommand((int)$fresh['id'], ['params' => self::json($p)]);
                Notification::sendMiningRejected($owner, $fleet, $fresh, $error->getMessage());
                FleetCommandsService::recall($fleet, $owner, max(microtime(true), $fleet->getCurrentPositionTime()));
            }
            return true;
        }, true);
    }

    /** Все работающие участники приведены к общему моменту ДО изменения состава. */
    public static function settleSite(int $siteId, float $until, ?string $surveyToken = null): void
    {
        Journal::worldLocked(static function () use ($siteId, $until, $surveyToken): void {
            FleetMiningCheckpointService::recoverPending();
            FleetSurveyService::assertMiningReady($siteId, $surveyToken);
            self::closePending($siteId);
            for ($i = 0; $i < 2048; ++$i) {
                $commands = self::commands($siteId, 'working');
                if ($commands === []) return;
                $runs = []; foreach ($commands as $command) $runs[$command['fleet_id']] = FleetControlService::params($command)['mining'];
                $ownerId = reset($runs)['ownerId'];
                $snapshot = self::snapshot($siteId, $ownerId, $commands);
                $first = FleetControlService::params(reset($commands))['miningCheckpoint'];
                $from = (float)$first['at'];
                if ($until < $from) throw new \LogicException('Добычу нельзя актуализировать в прошлое.');
                $workers = self::workers($runs, $snapshot['site']);
                $previous = self::previous($siteId);
                $plan = FleetMiningPhaseService::build($snapshot, $workers, $from, $until, $previous);
                self::publish($snapshot, $runs, $plan, $previous);
                self::closePending($siteId);
                if ($plan['phase']['state']['time'] >= $until) return;
            }
            throw new \RuntimeException('Добыча не достигла устойчивой границы за ограниченное число фаз.');
        }, true);
    }

    /** Экономический выход перед обычным Recall pipeline. Не создаёт маршрут. */
    public static function stop(Fleet $fleet, float $time, AccountData $owner): array
    {
        return Journal::worldLocked(static function () use ($fleet, $time, $owner): array {
            $command = FleetCommands::getActiveCommand($fleet->getId());
            $siteId = (int)FleetControlService::params($command)['mining']['siteId'];
            self::settleSite($siteId, $time);
            $command = FleetCommands::getActiveCommand($fleet->getId());
            $params = FleetControlService::params($command);
            if ($params['mining']['phase'] !== 'finished') {
                FleetMiningCheckpointService::closePhase($siteId, $fleet->getOwnerId(), bin2hex(random_bytes(16)), [$fleet->getId()], 'recall');
            }
            $command = FleetCommands::getCommand((int)$command['id']);
            $run = FleetControlService::params($command)['mining'];
            Notification::sendMiningResult($owner, $fleet, $command);
            self::touchBase($siteId, (float)$run['endedAt']);
            \SPGame\Core\Logger::getInstance()->info('Добыча ресурсов прервана', ['fleetId' => $fleet->getId(),
                'siteId' => $siteId, 'reason' => 'recall', 'endedAt' => $run['endedAt'],
                'extracted' => $run['extracted'], 'fuelSpent' => $run['fuelSpent']]);
            return $command;
        }, true);
    }

    private static function begin(array $command, Fleet $fleet, AccountData $owner): void
    {
        $params = FleetControlService::params($command);
        $target = Coordinate::fromArray($params['target']); $site = FleetSurveyService::site($target);
        $siteId = (int)$site['id']; $ownerId = $fleet->getOwnerId();
        if ($fleet->getCurrentCoordinate()->toArray() !== FleetSurveyService::coordinate($site)->toArray()) throw new \LogicException('Флот ещё не прибыл к объекту добычи.');
        FleetBattleService::assertFleetAvailable($fleet->getId());
        $options = self::prepare($fleet, $owner, $target, $params['miningRequest'] ?? [], true);
        $level = (int)($owner['Techs'][Vars::$resource[136]] ?? 0);
        $ships = []; foreach ($fleet->getShips() as $id => $ship) $ships[$id] = $ship->getCount();
        $rates = FleetMiningService::rates($site, $ships, $level, $options['mode']);
        if (array_sum($rates) <= 0) throw new \LogicException('Нет подходящих добывающих кораблей.');
        $time = $fleet->getCurrentPositionTime();
        if (ResourceBases::findExistingById($siteId) === null) {
            $row = ['id' => $siteId, 'owner_id' => $ownerId, 'created_at' => $time, 'updated_at' => $time];
            // Объект мог только что освободиться: новый контроль отменяет DELETE.
            ResourceBases::restoreDeletedRow($row);
            if (ResourceBases::findExistingById($siteId) !== $row) throw new \RuntimeException('Не сохранён контроль объекта добычи.');
        }
        // Контроль должен пережить restart раньше первого durable экономического after.
        ResourceBases::syncToDatabase();
        if ((int)(ResourceBases::checkpointDurableRow($siteId)['owner_id'] ?? 0) !== $ownerId) throw new \RuntimeException('Не подтверждён контроль объекта в MySQL.');
        // На своей базе MINING начинается сразу после SendMission, до обычного
        // saver. Journal обновляет существующие строки, поэтому новая команда
        // должна получить durable identity раньше первого checkpoint.
        FleetCommands::syncToDatabase();
        if (FleetCommands::checkpointDurableRow((int)$command['id']) === null) throw new \RuntimeException('Не сохранена команда новой добычи.');
        self::settleSite($siteId, $time);
        $commands = self::commands($siteId, 'working'); $commands[$fleet->getId()] = $command;
        $snapshot = self::snapshot($siteId, $ownerId, $commands); $raw = $snapshot;
        if ((float)$snapshot['knowledge']['coverage'] <= 0) throw new \LogicException('Сначала обследуйте ресурсный объект.');
        $reserve = self::fuelReserve($fleet, $owner, $target, $time, $options['speedFactor']);
        $run = ['version' => 1, 'phase' => 'working', 'siteId' => $siteId, 'ownerId' => $ownerId,
            'mode' => $options['mode'], 'finish' => $options['finish'], 'speedFactor' => $options['speedFactor'],
            'startedAt' => $time, 'endsAt' => $time + $options['duration'], 'blocked' => [],
            'production' => array_sum($rates), 'capacity' => $fleet->getCapacity(),
            'fuelRate' => self::fuelPerHour($fleet, $owner),
            'fuelReserve' => $reserve, 'extracted' => [901 => 0.0, 902 => 0.0, 903 => 0.0], 'fuelSpent' => 0.0];
        $runs = [];
        foreach ($commands as $id => $row) $runs[$id] = $id === $fleet->getId() ? $run : FleetControlService::params($row)['mining'];
        $clock = $fleet->getBaseFuelState();
        if ($clock !== null) $snapshot['fleets'][$fleet->getId()]['fuel_current'] = ResourceBaseService::standingProjection(
            $fleet->getFuelCurrent(), $clock, $time)['fuel'];
        $params['mining'] = $run;
        $snapshot['commands'][$fleet->getId()]['params'] = self::json($params);
        $plan = FleetMiningPhaseService::build($snapshot, self::workers($runs, $site), $time, $time, self::previous($siteId));
        foreach ($plan['entries'] as &$entry) if ($entry['kind'] === 'fleet' && $entry['id'] === $fleet->getId()) {
            foreach (['time', 'rate', 'reserve', 'speed'] as $key) $entry['after']['base_fuel_' . $key] = 0.0;
        }
        unset($entry);
        self::publish($raw, $runs, $plan, self::previous($siteId));
        self::closePending($siteId);
    }

    private static function fuelReserve(Fleet $fleet, AccountData $owner, Coordinate $origin, float $time, float $speed,
        ?Coordinate $home = null): float
    {
        $home ??= ResourceBaseService::homeCoordinate($fleet);
        $travel = (new FleetMissionService())->getTravelInfo($fleet, FleetTarget::fromCoordinates($home->toArray()),
            $owner, $speed, null, $origin, $time);
        if ($travel === null) throw new \LogicException('Не рассчитан топливный резерв возврата.');
        $evacuation = $fleet->getHomeSiteId() > 0
            ? ResourceBaseService::evacuationReserve($fleet, $owner, $origin, $time, $speed) : 0.0;
        return FleetMiningService::returnFuelReserve((float)$travel['fuel']['max'], $evacuation);
    }

    private static function workers(array $runs, array $site): array
    {
        $workers = [];
        foreach ($runs as $id => $run) $workers[$id] = ['rates' => self::distribution($run, $site),
            'capacity' => $run['capacity'], 'fuelRate' => $run['fuelRate'], 'fuelReserve' => $run['fuelReserve'], 'endsAt' => $run['endsAt']];
        return $workers;
    }

    /** Выбрать следующую границу на копии, затем опубликовать ТОЛЬКО текущий after. */
    private static function publish(array $snapshot, array $runs, array $plan, ?array $previous): void
    {
        foreach ($runs as $id => &$run) {
            foreach ([901,902,903] as $resource) $run['extracted'][$resource] += $plan['phase']['cargo'][$id][$resource];
            $run['fuelSpent'] += $run['fuelRate'] * $plan['phase']['elapsed'] / 3600;
        }
        unset($run);
        $runs = self::boundaries($runs, $plan['phase'], $snapshot['site']);
        $nextWorkers = self::workers(array_filter($runs, static fn($r) => $r['phase'] === 'working'), $snapshot['site']);
        $at = (float)$plan['phase']['state']['time']; $next = self::due($at);
        if ($nextWorkers !== []) {
            foreach ($nextWorkers as $id => &$worker) $worker = array_replace($plan['phase']['fleets'][$id], $worker);
            unset($worker);
            $forecast = FleetMiningService::phase($plan['phase']['state'], $nextWorkers, min(array_column($nextWorkers, 'endsAt')));
            $next = self::due((float)$forecast['state']['time'], $forecast['elapsed'] > 0 ? $at : null);
            $next = min($next, self::due($at + 60, $at));
        }
        foreach ($plan['entries'] as &$entry) {
            $source = match ($entry['kind']) { 'site' => $snapshot['site'], 'knowledge' => $snapshot['knowledge'],
                'fleet' => $snapshot['fleets'][$entry['id']],
                'ships' => $snapshot['ships'][$entry['id']],
                'survey' => $snapshot['survey'],
                'command' => $snapshot['commands'][$entry['guard']['fleet_id']] };
            if ($entry['kind'] === 'command' && isset($runs[$source['fleet_id']])) {
                $params = json_decode($entry['after']['params'], true, 512, JSON_THROW_ON_ERROR);
                $run = $runs[$source['fleet_id']]; $params['mining'] = $run;
                $entry['after']['params'] = self::json($params);
                $entry['after']['execute_time'] = $run['phase'] === 'closing' ? self::due($at) : $next;
            }
            foreach ($entry['after'] as $field => $_) { $entry['before'][$field] = $source[$field]; unset($entry['guard'][$field]); }
        }
        unset($entry);
        FleetMiningCheckpointService::commit((int)$snapshot['site']['id'], (int)$snapshot['knowledge']['owner_id'],
            (int)($previous['head']['revision'] ?? 0), bin2hex(random_bytes(16)), $at, $plan['entries'], $plan['metadata'] + ['runtime' => 1]);
        \SPGame\Core\Logger::getInstance()->info('Добыча ресурсов: общий checkpoint применён',
            ['siteId' => $snapshot['site']['id'], 'at' => $at, 'elapsed' => $plan['phase']['elapsed'],
                'fleetIds' => array_keys($runs), 'events' => $plan['phase']['events']]);
    }

    private static function closePending(int $siteId): void
    {
        $groups = [];
        foreach (self::commands($siteId, 'closing') as $command) {
            $run = FleetControlService::params($command)['mining'];
            $groups[$run['reason']]['owner'] = $run['ownerId'];
            $groups[$run['reason']]['ids'][] = (int)$command['fleet_id'];
        }
        foreach ($groups as $reason => $group) FleetMiningCheckpointService::closePhase(
            $siteId, $group['owner'], bin2hex(random_bytes(16)), $group['ids'], $reason);
    }

    private static function finish(array $command, Fleet $fleet, AccountData $owner): void
    {
        Notification::sendMiningResult($owner, $fleet, $command);
        $run = FleetControlService::params($command)['mining'];
        self::touchBase((int)$run['siteId'], (float)$run['endedAt']);
        FleetControlService::commit([$fleet], static function () use ($command, $fleet, $owner): void {
            $run = FleetControlService::params($command)['mining'];
            FleetCommands::markExecuted((int)$command['id']);
            $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), self::due((float)$run['endedAt']));
            $next = FleetCommands::getNextPendingCommand($fleet->getId());
            if ($next === null) throw new \LogicException('Нет заранее подготовленного продолжения добычи.');
            if ($run['finish'] === 'stay') {
                if ((int)$next['command_type'] !== Command::STAY->value
                    || (FleetControlService::params($next)['at'] ?? null) !== $fleet->getCurrentCoordinate()->toArray()) {
                    throw new \LogicException('Неверная стоянка после добычи.');
                }
                $destination = ResourceBaseService::prepareDestination($fleet, $owner, $fleet->getCurrentCoordinate(), false, true);
                // Приписка входит в тот же commit, что завершение MINING. Иначе
                // visiting miner уже не охрана, а STAY ещё не член базы: scheduler
                // успевает снять контроль между двумя due-событиями.
                $fleet->setHomeResourceSite((int)$destination['siteId'], (int)$destination['fallbackPlanetId']);
                $fleet->setMission(Mission::RELOCATE);
            } elseif (!isset(FleetControlService::params($command)['planStep'])) $fleet->setReturn(true);
            FleetCommandsService::activateNextCommand($fleet, $owner);
            $fleet->save();
        });
        $run = FleetControlService::params($command)['mining'];
        \SPGame\Core\Logger::getInstance()->info('Добыча ресурсов завершена', ['fleetId' => $fleet->getId(),
            'siteId' => $run['siteId'], 'reason' => $run['reason'], 'finish' => $run['finish'],
            'startedAt' => $run['startedAt'], 'endedAt' => $run['endedAt'], 'extracted' => $run['extracted'], 'fuelSpent' => $run['fuelSpent']]);
    }

    private static function touchBase(int $siteId, float $time): void
    {
        $base = ResourceBases::findExistingById($siteId);
        if ($base !== null) ResourceBases::update(['id' => $siteId, 'updated_at' => max((float)$base['updated_at'], $time)]);
    }

    private static function commands(int $siteId, ?string $phase = null): array
    {
        $result = [];
        foreach (FleetCommands::findByIndex('command_type', [Command::MINING->value]) ?? [] as $command) {
            if ((int)$command['executed']) continue;
            $run = FleetControlService::params($command)['mining'] ?? null;
            if (($run['siteId'] ?? null) === $siteId && ($phase === null || $run['phase'] === $phase)) $result[(int)$command['fleet_id']] = $command;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private static function snapshot(int $siteId, int $ownerId, array $commands): array
    {
        $snapshot = ['site' => Sites::findExistingById($siteId), 'knowledge' => Knowledge::findByIndex('owner_site', [$ownerId, $siteId]),
            'fleets' => [], 'commands' => $commands];
        if ($snapshot['site'] === null || $snapshot['knowledge'] === null) throw new \LogicException('Нет объекта или личного обследования.');
        if ((int)(ResourceBases::findExistingById($siteId)['owner_id'] ?? 0) !== $ownerId) throw new \LogicException('Ресурсный объект больше не принадлежит добытчику.');
        foreach ($commands as $id => $command) {
            $row = Fleets::findExistingById($id);
            if ($row === null || (int)$row['owner_id'] !== $ownerId) throw new \LogicException('Изменился состав добытчиков.');
            $snapshot['fleets'][$id] = $row;
        }
        return $snapshot;
    }

    private static function previous(int $siteId): ?array
    {
        return Journal::locked($siteId, static fn() => Journal::head($siteId) === null ? null : Journal::load($siteId));
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
