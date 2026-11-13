<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\MiningCheckpoints as Journal;
use SPGame\Game\Enums\FleetCommandType as Command;

/** Связка общей расчётной фазы с ограниченными journal-строками. Без записи/миссий. */
final class FleetMiningPhaseService
{
    private const RESOURCES = [901 => 'metal', 902 => 'crystal', 903 => 'deuterium'];

    /** Логический G с дробным остатком; без повторного начисления активной добычи. */
    public static function stock(array $site, float $time, ?array $previous): array
    {
        return FleetSurveyService::stock(self::restoreStock($site, $time, $previous), $time);
    }

    /** Новое знание и receipt применяются вместе со всеми носителями carry. */
    public static function survey(array $snapshot, array $workers, float $time, array $previous, array $receipt): array
    {
        $result = json_decode($receipt['state'], true, 512, JSON_THROW_ON_ERROR);
        $known = $snapshot['knowledge']; $after = $result['knowledge'] ?? [];
        if (($result['phase'] ?? null) !== 'prepared' || !empty($result['miningApplied'])
            || ($result['mining']['siteId'] ?? null) !== $snapshot['site']['id']
            || ($result['mining']['ownerId'] ?? null) !== $known['owner_id']
            || ($result['mining']['at'] ?? null) != $time || ($after['id'] ?? null) !== $known['id']
            || ($after['owner_id'] ?? null) !== $known['owner_id'] || ($after['site_id'] ?? null) !== $known['site_id']
            || ($after['reserve_time'] ?? null) != $time || ($after['observed_at'] ?? null) != $time
            || self::number($after['coverage'] ?? null) < $known['coverage'] || $after['coverage'] > 1
            || (json_decode($after['state'] ?? '{}', true)['lastToken'] ?? null) !== ($receipt['token'] ?? null)) {
            throw new \LogicException('Другой/уже применённый результат дообследования.');
        }
        $baseline = self::build($snapshot, $workers, $time, $time, $previous);
        if ($baseline['phase']['events'] !== []) throw new \LogicException('Сначала обработайте границу добычи.');
        $snapshot['knowledge'] = $after;
        $plan = self::build($snapshot, $workers, $time, $time, $previous);
        foreach ($plan['entries'] as &$entry) if ($entry['kind'] === 'knowledge') {
            $entry['after'] += array_intersect_key($after, array_flip(['coverage', 'observed_at']));
            foreach ($entry['after'] as $field => $_) { $entry['before'][$field] = $known[$field]; unset($entry['guard'][$field]); }
        }
        unset($entry);
        $result['miningApplied'] = true;
        $plan['entries'][] = self::entry('survey', $receipt, ['state' => self::json($result)], ['token']);
        $plan['metadata']['survey'] = ['id' => $receipt['id'], 'token' => $receipt['token']];
        Journal::encodeEntries($plan['entries']);
        return $plan;
    }

    /** Потеря удалённого дома: нулевой rebase ВСЕХ носителей carry, без новой добычи. */
    public static function rehome(array $snapshot, array $workers, float $time, array $previous,
        int $lostSiteId, array $homes): array
    {
        if ($lostSiteId <= 0 || $lostSiteId === $snapshot['site']['id'] || $homes === []) {
            throw new \InvalidArgumentException('Нужна потеря другого ресурсного дома.');
        }
        foreach ($homes as $id => $home) {
            $row = $snapshot['fleets'][$id] ?? [];
            if (!isset($workers[$id]) || ($row['home_site_id'] ?? null) !== $lostSiteId
                || !is_int($home['planetId'] ?? null) || $home['planetId'] <= 0) {
                throw new \LogicException('Другой добытчик/резервный дом.');
            }
            $workers[$id]['fuelReserve'] = self::number($home['fuelReserve'] ?? null);
        }
        $plan = self::build($snapshot, $workers, $time, $time, $previous);
        foreach ($plan['entries'] as &$entry) {
            if ($entry['kind'] === 'fleet' && isset($homes[$entry['id']])) {
                $home = $homes[$entry['id']];
                $entry['after'] += ['home_planet_id' => $home['planetId'], 'home_site_id' => 0,
                    'fallback_planet_id' => $home['planetId'], 'base_fuel_time' => 0.0,
                    'base_fuel_rate' => 0.0, 'base_fuel_reserve' => 0.0, 'base_fuel_speed' => 0.0];
                foreach ($entry['after'] as $key => $_) {
                    $entry['before'][$key] = $snapshot['fleets'][$entry['id']][$key];
                    unset($entry['guard'][$key]);
                }
            } elseif ($entry['kind'] === 'command' && isset($homes[$entry['guard']['fleet_id']])) {
                $params = json_decode($entry['after']['params'], true, 512, JSON_THROW_ON_ERROR);
                $params['miningHomeLost'] = ['siteId' => $lostSiteId,
                    'planetId' => $homes[$entry['guard']['fleet_id']]['planetId'], 'at' => $time];
                $entry['after']['params'] = self::json($params);
            }
        }
        unset($entry);
        $plan['metadata']['homeLost'] = ['siteId' => $lostSiteId, 'fleetCount' => count($homes)];
        Journal::encodeEntries($plan['entries']);
        return $plan;
    }

    /** Слияние после settle: корабли, оба баланса и receipt становятся одним after. */
    public static function merge(array $snapshot, array $workers, float $time, array $previous,
        array $source, array $command, array $worker, array $shipEntries, float $experience): array
    {
        $raw = $snapshot;
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $id = $params['target']['fleetId'] ?? 0;
        if (($params['target']['type'] ?? null) !== \SPGame\Game\Space\CoordinateType::FLEET->value
            || !isset($workers[$id]) || isset($workers[$source['id']])
            || ($source['owner_id'] ?? null) !== $snapshot['knowledge']['owner_id']
            || ($command['fleet_id'] ?? null) !== $source['id'] || ($command['executed'] ?? null) !== 0
            || ($command['command_type'] ?? null) !== \SPGame\Game\Enums\FleetCommandType::MERGE->value
            || isset($params['miningMerge']) || $shipEntries === []) {
            throw new \LogicException('Другой/завершённый контекст слияния с добытчиком.');
        }
        $baseline = self::build($snapshot, $workers, $time, $time, $previous);
        if ($baseline['phase']['events'] !== []) throw new \LogicException('Сначала обработайте границу добычи.');
        $target = &$snapshot['fleets'][$id];
        $target['fuel_current'] = $baseline['phase']['fleets'][$id]['fuel'] + self::number($source['fuel_current']);
        $total = max($target['fuel_current'], self::number($target['fuel_total']) + self::number($source['fuel_total']));
        $target['fuel_total'] = (float)sprintf('%.6F', $total);
        if ($target['fuel_total'] < $total) $target['fuel_total'] = (float)sprintf('%.6F', $target['fuel_total'] + .000001);
        $extra = ['fuel_total' => $target['fuel_total'], 'experience' => self::number($experience)];
        $sourceAfter = ['fuel_current' => 0.0, 'fuel_total' => 0.0, 'experience' => 0.0];
        foreach (['metal', 'crystal', 'deuterium', 'credit', 'doubloon'] as $name) {
            $field = 'resource_' . $name;
            $target[$field] = self::number($target[$field] ?? 0.0) + self::number($source[$field] ?? 0.0);
            if (!is_finite($target[$field])) throw new \OverflowException('Переполнение груза при слиянии.');
            $sourceAfter[$field] = 0.0;
            if (!in_array($name, self::RESOURCES, true)) $extra[$field] = $target[$field];
        }
        unset($target);
        $targetParams = json_decode($snapshot['commands'][$id]['params'], true, 512, JSON_THROW_ON_ERROR);
        unset($targetParams['miningCheckpoint']);
        $snapshot['commands'][$id]['params'] = self::json($targetParams);
        $workers[$id] = $worker;
        $plan = self::build($snapshot, $workers, $time, $time, $previous);
        foreach ($plan['entries'] as &$entry) if ($entry['kind'] === 'fleet' && $entry['id'] === $id) {
            $entry['after'] += $extra;
        }
        unset($entry);
        $plan['entries'][] = self::entry('fleet', $source, $sourceAfter,
            ['owner_id', 'status', 'mission', 'home_planet_id', 'home_site_id', 'current_position_time']);
        $params['miningMerge'] = ['receiverId' => $id, 'siteId' => $snapshot['site']['id']];
        $plan['entries'][] = self::entry('command', $command, ['params' => self::json($params)],
            ['fleet_id', 'command_type', 'executed', 'execute_time', 'create_time']);
        // Узкие ship-части не переносятся в следующую фазу: carry принадлежит Fleet/команде.
        $seen = [];
        foreach ($shipEntries as $entry) {
            if (($entry['kind'] ?? null) !== 'ships' || !in_array($entry['id'], [$id, $source['id']], true)
                || ($entry['guard']['owner_id'] ?? null) !== $source['owner_id']) {
                throw new \LogicException('Корабли другого участника слияния.');
            }
            if ($entry['id'] === $source['id']) foreach ($entry['after'] as $field => $value) {
                if ($value != (str_ends_with($field, '_morale') ? 1 : 0)) throw new \LogicException('Донор должен сохранить пустой состав.');
            }
            $seen[$entry['id']] = true;
            $plan['entries'][] = $entry;
        }
        if (count($seen) !== 2) throw new \LogicException('Нет обоих составов слияния.');
        foreach ($plan['entries'] as &$entry) {
            if ($entry['kind'] === 'ships') continue;
            $before = match ($entry['kind']) {
                'site' => $raw['site'], 'knowledge' => $raw['knowledge'],
                'fleet' => $entry['id'] === $source['id'] ? $source : $raw['fleets'][$entry['id']],
                'command' => $entry['id'] === $command['id'] ? $command : $raw['commands'][$entry['guard']['fleet_id']],
            };
            foreach ($entry['after'] as $field => $_) { $entry['before'][$field] = $before[$field]; unset($entry['guard'][$field]); }
        }
        unset($entry);
        $plan['metadata']['merge'] = ['sourceId' => $source['id'], 'receiverId' => $id, 'commandId' => $command['id']];
        Journal::encodeEntries($plan['entries']);
        return $plan;
    }

    /** Передача своему работающему добытчику: новый baseline и receipt в одном batch. */
    public static function transfer(array $snapshot, array $workers, float $time, array $previous,
        array $source, array $command): array
    {
        $raw = $snapshot;
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $id = $params['target']['fleetId'] ?? 0;
        if (($params['target']['type'] ?? null) !== \SPGame\Game\Space\CoordinateType::FLEET->value
            || !isset($workers[$id]) || isset($workers[$source['id']])
            || ($source['owner_id'] ?? null) !== $snapshot['knowledge']['owner_id']
            || ($command['fleet_id'] ?? null) !== $source['id'] || ($command['executed'] ?? null) !== 0
            || ($command['command_type'] ?? null) !== \SPGame\Game\Enums\FleetCommandType::UNLOAD->value
            || isset($params['miningTransfer'])) throw new \LogicException('Другой/завершённый контекст передачи добытчику.');
        $baseline = self::build($snapshot, $workers, $time, $time, $previous);
        if ($baseline['phase']['events'] !== []) throw new \LogicException('Сначала обработайте границу добычи.');
        $cargo = [];
        foreach (self::RESOURCES as $resource => $name) $cargo[$resource] = self::number($source['resource_' . $name]);
        $factor = array_sum($cargo) > 0
            ? min(FleetPlanService::percentage($params['percent'] ?? 100) / 100, $baseline['phase']['fleets'][$id]['free'] / array_sum($cargo)) : 0.0;
        $delivered = array_map(static fn($count) => $count * $factor, $cargo);
        $target = &$snapshot['fleets'][$id];
        // Материализация старого carry только на копии; запись произойдёт одним journal after.
        $target['fuel_current'] = $baseline['phase']['fleets'][$id]['fuel'];
        foreach (self::RESOURCES as $resource => $name) {
            if ($resource === 903 && !empty($params['refuel'])) $target['fuel_current'] += $delivered[$resource];
            else $target['resource_' . $name] += $delivered[$resource];
        }
        $total = max((float)$target['fuel_total'], $target['fuel_current']);
        $totalStored = (float)sprintf('%.6F', $total);
        if ($totalStored < $total) $totalStored = (float)sprintf('%.6F', $totalStored + .000001);
        $target['fuel_total'] = $totalStored;
        unset($target);
        $targetParams = json_decode($snapshot['commands'][$id]['params'], true, 512, JSON_THROW_ON_ERROR);
        unset($targetParams['miningCheckpoint']);
        $snapshot['commands'][$id]['params'] = self::json($targetParams);
        $plan = self::build($snapshot, $workers, $time, $time, $previous);
        foreach ($plan['entries'] as &$entry) if ($entry['kind'] === 'fleet' && $entry['id'] === $id) {
            $entry['after']['fuel_total'] = $totalStored;
            $entry['before']['fuel_total'] = $raw['fleets'][$id]['fuel_total'];
        }
        unset($entry);
        $sourceAfter = [];
        foreach (self::RESOURCES as $resource => $name) $sourceAfter['resource_' . $name] = max(0.0, $cargo[$resource] - $delivered[$resource]);
        $plan['entries'][] = self::entry('fleet', $source, $sourceAfter,
            ['owner_id', 'status', 'mission', 'home_planet_id', 'home_site_id', 'current_coordinate_type',
                'current_galaxy', 'current_system', 'current_distance', 'current_angle', 'current_position_time']);
        $params['miningTransfer'] = ['receiverId' => $id, 'siteId' => $snapshot['site']['id'], 'resources' => $delivered];
        // Команда остаётся due: отдельный handoff может повторяться, передача — уже нет.
        $plan['entries'][] = self::entry('command', $command, ['params' => self::json($params)],
            ['fleet_id', 'command_type', 'executed', 'execute_time', 'create_time']);
        $plan['metadata']['transfer'] = ['sourceId' => $source['id'], 'receiverId' => $id, 'commandId' => $command['id']];
        $plan['delivered'] = $delivered;
        foreach ($plan['entries'] as &$entry) {
            $before = match ($entry['kind']) {
                'site' => $raw['site'], 'knowledge' => $raw['knowledge'],
                'fleet' => $entry['id'] === $source['id'] ? $source : $raw['fleets'][$entry['id']],
                'command' => $entry['id'] === $command['id'] ? $command : $raw['commands'][$entry['guard']['fleet_id']],
            };
            foreach ($entry['after'] as $field => $_) { $entry['before'][$field] = $before[$field]; unset($entry['guard'][$field]); }
        }
        unset($entry);
        Journal::encodeEntries($plan['entries']);
        return $plan;
    }

    /**
     * Закрыть уже сохранённую фазу БЕЗ нового интервала/дельты.
     * rows[kind][id] — актуальные серверные строки всех записей batch.
     * Экономическая часть выхода: состояние/следующую команду задаёт обработчик.
     * Бак после materialize требует unscaled DOUBLE, иначе commit откажет ДО prepare.
     */
    public static function close(array $batch, array $rows, array $fleetIds, string $reason): array
    {
        $head = $batch['head'];
        $meta = json_decode($head['metadata'], true, 512, JSON_THROW_ON_ERROR)['miningCheckpoint'] ?? null;
        if (($head['phase'] ?? null) !== 'applied' || !is_array($meta) || ($meta['version'] ?? null) !== 1
            || self::number($meta['at'] ?? null) !== (float)$head['checkpoint_time'] || $fleetIds === []
            || count(array_unique($fleetIds, SORT_REGULAR)) !== count($fleetIds)
            || !in_array($reason, ['duration', 'cargo', 'fuel', 'stock', 'reserve', 'recall', 'control', 'battle'], true)) {
            throw new \InvalidArgumentException('Нет завершённой общей фазы/причины выхода из добычи.');
        }
        foreach ($fleetIds as $id) if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Некорректный ID выхода из добычи.');
        // Прошлый batch мог также доставить груз. Донор и уже вышедший добытчик
        // не владеют carry текущей фазы и могут законно продолжать свой маршрут.
        $activeIds = [];
        foreach ($batch['entries'] as $entry) if ($entry['kind'] === 'command') {
            $params = json_decode($entry['after']['params'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            if (isset($params['miningCheckpoint'])) $activeIds[$entry['guard']['fleet_id']] = true;
        }
        $entries = $positions = $commands = [];
        foreach ($batch['entries'] as $entry) {
            if (in_array($entry['kind'], ['ships', 'survey'], true) || $entry['kind'] === 'fleet' && !isset($activeIds[$entry['id']])
                || $entry['kind'] === 'command' && !isset($activeIds[$entry['guard']['fleet_id']])) continue;
            $row = $rows[$entry['kind']][$entry['id']] ?? null;
            if ($row === null || FleetMiningService::recoveryPatch($row, $entry) !== null) {
                throw new \LogicException('Состояние изменилось после общей фазы, сначала требуется новый checkpoint.');
            }
            $entry['before'] = $entry['after']; // Все участники должны быть в известном after.
            $positions[$entry['kind']][$entry['id']] = count($entries);
            if ($entry['kind'] === 'command') {
                $fleetId = $row['fleet_id'];
                if (isset($commands[$fleetId])) throw new \LogicException('Несколько команд одного добытчика.');
                $commands[$fleetId] = $row;
            }
            $entries[] = $entry;
        }
        foreach ($fleetIds as $id) {
            $fleet = $rows['fleet'][$id] ?? null; $command = $commands[$id] ?? null;
            if ($fleet === null || $command === null || !isset($positions['fleet'][$id])) {
                throw new \LogicException('Добытчик не входит в общую фазу.');
            }
            $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
            $ledger = $params['miningCheckpoint'] ?? null;
            if (!is_array($ledger) || ($ledger['version'] ?? null) !== 1 || ($ledger['siteId'] ?? null) !== $head['site_id']
                || ($ledger['ownerId'] ?? null) !== $head['owner_id'] || ($fleet['owner_id'] ?? null) !== $head['owner_id']
                || self::number($ledger['at'] ?? null) !== (float)$meta['at']
                || self::number($ledger['storedFuel'] ?? null) !== (float)$fleet['fuel_current']) {
                throw new \LogicException('Другой/уже закрытый контекст остатка бака.');
            }
            $carry = self::number($ledger['fuelCarry'] ?? null);
            if ($carry >= .0000011) throw new \LogicException('Повреждён дробный остаток бака.');
            $fuel = (float)$fleet['fuel_current'] + $carry;
            if ($fuel > self::number($fleet['fuel_total'] ?? null)) throw new \LogicException('Остаток превышает загруженное топливо.');
            $index = $positions['fleet'][$id];
            $entries[$index]['after']['fuel_current'] = $fuel;
            if (!array_key_exists('fuel_total', $entries[$index]['after'])) {
                $entries[$index]['guard']['fuel_total'] = (float)$fleet['fuel_total'];
            }
            unset($params['miningCheckpoint']);
            $params['miningClosed'] = ['source' => $head['token'], 'at' => (float)$meta['at'], 'reason' => $reason];
            $commandIndex = $positions['command'][$command['id']];
            if (isset($params['mining'])) {
                $params['mining']['phase'] = 'finished';
                $params['mining']['reason'] = $reason;
                $params['mining']['endedAt'] = (float)$meta['at'];
                $entries[$commandIndex]['before']['execute_time'] = $command['execute_time'];
                $entries[$commandIndex]['after']['execute_time'] = FleetMiningRuntimeService::due((float)$meta['at']);
                unset($entries[$commandIndex]['guard']['execute_time']);
            }
            if ($reason === 'battle') {
                // Приписка и выбранное продолжение не меняются. После результата
                // боя выжившему назначается отдельная платная стоянка.
                if ((int)($command['command_type'] ?? 0) !== Command::MINING->value
                    || !in_array($params['mining']['finish'] ?? null, ['return', 'stay'], true)
                    || (int)($params['mining']['siteId'] ?? 0) !== (int)$head['site_id']) {
                    throw new \LogicException('Боевое прерывание этой добычи пока не подключено.');
                }
                $params['pause'] = 'battle';
                unset($params['planStep'], $params['miningRequest'], $params['hold']);
                $entries[$commandIndex]['before']['command_type'] = $command['command_type'];
                $entries[$commandIndex]['after']['command_type'] = Command::OBSERVE->value;
                $entries[$commandIndex]['after']['execute_time'] = 0.0;
                unset($entries[$commandIndex]['guard']['command_type']);
            }
            $entries[$commandIndex]['after']['params'] = self::json($params);
        }
        Journal::encodeEntries($entries);
        return ['entries' => $entries, 'metadata' => ['miningCheckpoint' => $meta, 'closedFrom' => $head['token']]];
    }

    /**
     * snapshot: site, knowledge, fleets[id], commands[fleetId].
     * workers[id]: rates, capacity (с баком), fuelRate/час, fuelReserve, endsAt.
     * previous — полный проверенный applied journal, не клиентский snapshot.
     * Все реальные проверки доступности/физического присутствия делает будущая миссия.
     */
    public static function build(array $snapshot, array $workers, float $from, float $until, ?array $previous = null): array
    {
        if (!is_finite($from) || !is_finite($until) || $from < 0 || $until < $from || $workers === []
            || count($workers) > intdiv(Journal::MAX_ENTRIES - 2, 2)) {
            throw new \InvalidArgumentException('Некорректная граница/число участников фазы добычи.');
        }
        $site = $snapshot['site']; $known = $snapshot['knowledge'];
        $siteId = $site['id']; $ownerId = $known['owner_id'];
        if (!is_int($known['id'] ?? null) || $known['id'] <= 0) throw new \InvalidArgumentException('Нет серверной строки обследования.');
        $logicalSite = self::restoreStock($site, $from, $previous);
        $state = FleetMiningService::baseline($logicalSite, $known, $from);
        $fleetRows = $snapshot['fleets']; $commands = $snapshot['commands'];
        if (array_diff_key($workers, $fleetRows) || array_diff_key($workers, $commands)
            || array_diff_key($fleetRows, $workers) || array_diff_key($commands, $workers)) {
            throw new \InvalidArgumentException('Нужны все флоты и команды общей фазы, без лишних участников.');
        }
        $phaseWorkers = $params = [];
        ksort($workers, SORT_NUMERIC);
        foreach ($workers as $id => $config) {
            $row = $fleetRows[$id]; $command = $commands[$id];
            if (!is_int($id) || $id <= 0 || ($row['id'] ?? null) !== $id || ($row['owner_id'] ?? null) !== $ownerId
                || ($command['fleet_id'] ?? null) !== $id || !is_int($command['id'] ?? null) || $command['id'] <= 0
                || ($command['executed'] ?? null) !== 0) throw new \InvalidArgumentException('Другой/завершённый участник фазы.');
            $params[$id] = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($params[$id])) throw new \InvalidArgumentException('Нет параметров команды фазы.');
            $fuel = self::number($row['fuel_current']);
            $ledger = $params[$id]['miningCheckpoint'] ?? null;
            if ($ledger !== null) {
                if (!is_array($ledger) || ($ledger['version'] ?? null) !== 1 || ($ledger['siteId'] ?? null) !== $siteId
                    || ($ledger['ownerId'] ?? null) !== $ownerId || self::number($ledger['at'] ?? null) !== $from
                    || self::number($ledger['storedFuel'] ?? null) !== $fuel || $previous === null) {
                    throw new \LogicException('Изменился контекст дробного остатка добычи.');
                }
                $carry = self::number($ledger['fuelCarry'] ?? null);
                if ($carry >= .0000011) throw new \LogicException('Некорректный дробный остаток бака.');
                $previousMeta = json_decode($previous['head']['metadata'], true, 512, JSON_THROW_ON_ERROR)['miningCheckpoint'] ?? null;
                if (!is_array($previousMeta) || ($previousMeta['at'] ?? null) != $from
                    || (float)$known['reserve_time'] !== $from) throw new \LogicException('Разошлись общие часы работающих добытчиков.');
                $fuel += $carry;
            }
            $capacity = self::number($config['capacity'] ?? null);
            $used = $fuel;
            foreach (self::RESOURCES as $name) $used += self::number($row['resource_' . $name] ?? null);
            $used += self::number($row['resource_doubloon'] ?? 0.0); // Кредиты, в отличие от дублонов, невесомы.
            // Единственный допуск — машинное сложение в граничном полном трюме,
            // не сравнение journal-состояний и не разрешение лишнего груза.
            if ($used > $capacity + 1e-8) throw new \LogicException('Груз и логический бак превышают вместимость добытчика.');
            $phaseWorkers[$id] = $config + ['fuel' => $fuel, 'free' => max(0.0, $capacity - $used)];
            // Config не может подменять вычисленные из server row balances.
            $phaseWorkers[$id]['fuel'] = $fuel;
            $phaseWorkers[$id]['free'] = max(0.0, $capacity - $used);
        }
        $phase = FleetMiningService::phase($state, $phaseWorkers, $until);
        $end = $phase['state']['time']; $time = (float)sprintf('%.6F', $end);
        $siteAfter = ['resource_update_time' => $time]; $stockCarry = [];
        $personal = json_decode($known['state'], true, 512, JSON_THROW_ON_ERROR);
        foreach (self::RESOURCES as $resourceId => $name) {
            [$siteAfter[$name . '_stock'], $stockCarry[$resourceId]] = self::split($phase['state']['resources'][$resourceId]['stock']);
            $personal['reserve'][$resourceId] = $phase['state']['resources'][$resourceId]['reserve'];
        }
        $entries = [self::entry('site', $site, $siteAfter, ['site_type', 'galaxy', 'system', 'orbit', 'sector',
            'metal_capacity', 'crystal_capacity', 'deuterium_capacity', 'metal_rate', 'crystal_rate', 'deuterium_rate']),
            self::entry('knowledge', $known, ['reserve_time' => $end, 'state' => self::json($personal)],
                ['owner_id', 'site_id', 'coverage', 'observed_at'])];
        foreach ($workers as $id => $config) {
            $row = $fleetRows[$id]; $after = ['current_position_time' => $time];
            [$after['fuel_current'], $carry] = self::split($phase['fleets'][$id]['fuel']);
            foreach (self::RESOURCES as $resourceId => $name) {
                $after['resource_' . $name] = $row['resource_' . $name] + $phase['cargo'][$id][$resourceId];
            }
            $events = array_values(array_map(static fn($event) => $event['kind'], array_filter($phase['events'],
                static fn($event) => ($event['fleetId'] ?? null) === $id)));
            unset($params[$id]['miningClosed']); // Новая работа не наследует receipt прежнего выхода.
            $params[$id]['miningCheckpoint'] = ['version' => 1, 'siteId' => $siteId, 'ownerId' => $ownerId,
                'at' => $end, 'storedFuel' => $after['fuel_current'], 'fuelCarry' => $carry,
                'rates' => $config['rates'], 'fuelRate' => $config['fuelRate'], 'fuelReserve' => $config['fuelReserve'],
                'capacity' => $config['capacity'], 'endsAt' => $config['endsAt'], 'events' => $events];
            $entries[] = self::entry('fleet', $row, $after, ['owner_id', 'status', 'mission', 'home_planet_id', 'home_site_id',
                'current_coordinate_type', 'current_galaxy', 'current_system', 'current_orbit', 'current_object_type',
                'current_site_id']);
            $entries[] = self::entry('command', $commands[$id], ['params' => self::json($params[$id])],
                ['fleet_id', 'command_type', 'execute_time', 'executed']);
        }
        $metadata = ['miningCheckpoint' => ['version' => 1, 'at' => $end, 'stockCarry' => $stockCarry,
            'events' => array_values(array_filter($phase['events'], static fn($event) => !isset($event['fleetId'])))]];
        Journal::encodeEntries($entries); // Размеры/JSON проверены до любых эффектов.
        if (strlen(self::json($metadata)) > Journal::MAX_METADATA_BYTES) throw new \OverflowException('Metadata фазы не помещается в journal.');
        return compact('entries', 'metadata', 'phase');
    }

    private static function restoreStock(array $site, float $from, ?array $previous): array
    {
        if ($previous === null) return $site;
        $head = $previous['head'];
        if (($head['phase'] ?? null) !== 'applied' || ($head['site_id'] ?? null) !== $site['id']) {
            throw new \LogicException('Предыдущая фаза ещё не применена или относится к другому объекту.');
        }
        $meta = json_decode($head['metadata'], true, 512, JSON_THROW_ON_ERROR)['miningCheckpoint'] ?? null;
        if ($meta === null) return $site; // Совместимость с journal до фазового обработчика.
        if (!is_array($meta) || ($meta['version'] ?? null) !== 1 || self::number($meta['at'] ?? null) !== (float)$head['checkpoint_time']
            || $from < $meta['at']) throw new \LogicException('Повреждён checkpoint времени добычи.');
        $anchor = null;
        foreach ($previous['entries'] as $entry) if ($entry['kind'] === 'site') $anchor = $entry;
        if ($anchor === null || FleetMiningService::recoveryPatch($site, $anchor) !== null) {
            throw new \LogicException('Общий запас изменён вне последнего checkpoint.');
        }
        foreach (self::RESOURCES as $resourceId => $name) {
            $carry = self::number($meta['stockCarry'][$resourceId] ?? null);
            if ($carry >= .0000011) throw new \LogicException('Некорректный дробный остаток общего запаса.');
            $site[$name . '_stock'] += $carry;
        }
        $site['resource_update_time'] = (float)$meta['at'];
        return $site;
    }

    /** Храним вниз до микроресурса; положительный остаток никогда не теряется. */
    private static function split(float $value): array
    {
        self::number($value);
        $stored = (float)sprintf('%.6F', $value);
        if ($stored > $value) $stored = (float)sprintf('%.6F', max(0.0, $stored - .000001));
        return [$stored, max(0.0, $value - $stored)];
    }

    private static function entry(string $kind, array $row, array $after, array $guardFields): array
    {
        $before = array_intersect_key($row, $after);
        if (count($before) !== count($after)) throw new \InvalidArgumentException('Нет исходных полей checkpoint.');
        return ['kind' => $kind, 'id' => $row['id'], 'before' => $before, 'after' => $after,
            'guard' => array_intersect_key($row, array_flip($guardFields))];
    }

    private static function number(mixed $value): float
    {
        if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value) || $value < 0) {
            throw new \InvalidArgumentException('Некорректное количество фазы добычи.');
        }
        return (float)$value;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
