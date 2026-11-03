<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\OrbitalResourceSites as Sites;

/**
 * Чистое расчётное ядро5Б. Не сохраняет G/K/груз и не включает миссию.
 * Одна фаза заканчивается у ПЕРВОЙ границы; последующие решения принимает
 * будущий обработчик после общего checkpoint всех участников объекта.
 */
final class FleetMiningService
{
    private const RESOURCES = [901 => 'metal', 902 => 'crystal', 903 => 'deuterium'];

    /** Возврат домой ИЛИ эвакуация: альтернативные пути, не двойной расход. */
    public static function returnFuelReserve(float $home, float $evacuation = 0.0): float
    {
        foreach ([$home, $evacuation] as $value) {
            if (!is_finite($value) || $value < 0) throw new \InvalidArgumentException('Некорректный резерв топлива добычи.');
        }
        return ceil(max($home, $evacuation));
    }

    /** Производительность в час; сопровождение не превращается в добытчиков. */
    public static function rates(array $site, array $ships, int $level, string $mode): array
    {
        if ($level < 0) throw new \InvalidArgumentException('Уровень добычи не может быть отрицательным.');
        $type = self::siteType($site['site_type'] ?? null);
        $balance = self::balance();
        $target = $type === Sites::GAS_GIANT ? 'gas_giant' : 'asteroid_sector';
        $allowed = $type === Sites::GAS_GIANT ? ['deuterium'] : $balance['belt_modes'];
        if (!in_array($mode, $allowed, true)) throw new \InvalidArgumentException('Режим добычи не соответствует объекту.');
        $production = 0.0;
        foreach ($ships as $id => $count) {
            if (!is_int($count) || $count < 0) throw new \InvalidArgumentException('Количество кораблей должно быть целым и неотрицательным.');
            $definition = $balance['ships'][$id] ?? null;
            if ($count === 0 || $definition === null || $definition['target_type'] !== $target) continue;
            if ($level < $definition['requirements'][$balance['technology']]) {
                throw new \InvalidArgumentException('Недостаточный уровень Внеземной добычи для этого корпуса.');
            }
            $production += $count * $definition['rate_per_hour'] * (1 + $balance['yield_bonus_per_level'] * $level);
        }
        $production = self::number($production, 'производительность');
        $rates = array_fill_keys(array_keys(self::RESOURCES), 0.0);
        if ($mode !== 'mix') {
            $rates[array_search($mode, self::RESOURCES, true)] = $production;
            return $rates;
        }
        $metal = self::number($site['metal_rate'] ?? null, 'восполнение металла');
        $crystal = self::number($site['crystal_rate'] ?? null, 'восполнение кристалла');
        $total = self::number($metal + $crystal, 'восполнение пояса');
        if ($total <= 0) throw new \InvalidArgumentException('Для смеси нужны скорости восполнения пояса.');
        $rates[901] = $production * ($metal / $total);
        $rates[902] = max(0.0, $production - $rates[901]);
        return $rates;
    }

    /**
     * Подготовить общий момент начала работы из серверных строк G и K.
     * Пассивное восполнение допустимо только ДО первой работы/после уже
     * сохранённого общего checkpoint, а не поверх неучтённой активной добычи.
     */
    public static function baseline(array $site, array $knowledge, float $time): array
    {
        self::number($time, 'время');
        if (!is_int($site['id'] ?? null) || $site['id'] <= 0
            || !is_int($knowledge['owner_id'] ?? null) || $knowledge['owner_id'] <= 0
            || ($knowledge['site_id'] ?? null) !== $site['id']) {
            throw new \InvalidArgumentException('Обследование не относится к ресурсному объекту.');
        }
        $type = self::siteType($site['site_type'] ?? null);
        $coverage = self::number($knowledge['coverage'] ?? null, 'доля обследования');
        if ($coverage > 1) throw new \InvalidArgumentException('Доля обследования превышает единицу.');
        $stockTime = self::number($site['resource_update_time'] ?? null, 'время общего запаса');
        $reserveTime = self::number($knowledge['reserve_time'] ?? null, 'время личного резерва');
        if ($time < max($stockTime, $reserveTime)) throw new \LogicException('Добычу нельзя пересчитать в прошлое.');
        $personal = json_decode($knowledge['state'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($personal)) throw new \InvalidArgumentException('Нет состояния личного обследования.');
        $resources = [];
        foreach (self::RESOURCES as $id => $key) {
            $capacity = self::number($site[$key . '_capacity'] ?? null, 'ёмкость');
            $rate = self::number($site[$key . '_rate'] ?? null, 'восполнение');
            $stock = self::number($site[$key . '_stock'] ?? null, 'общий запас');
            $reserve = self::number($personal['reserve'][$id] ?? null, 'личный резерв');
            if ($stock > $capacity || $reserve > $coverage * $capacity + 1e-8) {
                throw new \InvalidArgumentException('Запас превышает допустимую ёмкость.');
            }
            if (($type === Sites::GAS_GIANT && $id !== 903 || $type === Sites::ASTEROID_SECTOR && $id === 903)
                && ($capacity > 0 || $rate > 0 || $stock > 0 || $reserve > 0)) {
                throw new \InvalidArgumentException('Ресурс не соответствует типу объекта.');
            }
            $resources[$id] = [
                'stock' => min($capacity, self::number($stock + $rate * (($time - $stockTime) / 3600), 'пополненный общий запас')),
                'reserve' => min($coverage * $capacity, self::number($reserve + $coverage * $rate
                    * (($time - $reserveTime) / 3600), 'пополненный личный резерв')),
                'capacity' => $capacity, 'rate' => $rate,
            ];
        }
        return ['siteId' => $site['id'], 'ownerId' => $knowledge['owner_id'], 'type' => $type,
            'time' => $time, 'coverage' => $coverage, 'resources' => $resources];
    }

    /**
     * Все флоты — одного владельца и объекта, на одном checkpoint.
     * rates/fuelRate — в час; free — трюм уже за вычетом груза И бака.
     * rates фиксированы для фазы; при истощении компонента обработчик должен
     * явно перераспределить смесь, а не выдать результат за весь интервал.
     *
     * @param array<int,array{rates:array,free:float,fuel:float,fuelRate:float,fuelReserve:float,endsAt:float}> $fleets
     * @return array{state:array,fleets:array,cargo:array,events:array,elapsed:float}
     */
    public static function phase(array $state, array $fleets, float $until): array
    {
        $start = self::number($state['time'] ?? null, 'начало фазы');
        self::number($until, 'конец фазы');
        if ($until < $start) throw new \LogicException('Конец фазы предшествует началу.');
        $coverage = self::number($state['coverage'] ?? null, 'доля обследования');
        if ($coverage > 1) throw new \InvalidArgumentException('Доля обследования превышает единицу.');
        $resources = [];
        foreach (self::RESOURCES as $id => $key) {
            $resource = $state['resources'][$id] ?? [];
            foreach (['stock', 'reserve', 'capacity', 'rate'] as $field) {
                $resource[$field] = self::number($resource[$field] ?? null, $key . '/' . $field);
            }
            if ($resource['stock'] > $resource['capacity'] || $resource['reserve'] > $coverage * $resource['capacity'] + 1e-8) {
                throw new \InvalidArgumentException('Запас фазы превышает ёмкость.');
            }
            $resources[$id] = $resource;
        }
        $elapsed = $until - $start;
        $events = [];
        // Допуск только для одновременных событий, не округление количества.
        $consider = static function (float $seconds, array $event) use (&$elapsed, &$events): void {
            if (!is_finite($seconds) || $seconds < 0) throw new \InvalidArgumentException('Некорректное время границы добычи.');
            if ($seconds > $elapsed + 1e-8) return;
            if ($seconds < $elapsed - 1e-8) $events = [];
            $elapsed = min($elapsed, $seconds);
            $events[] = $event;
        };
        $demand = array_fill_keys(array_keys(self::RESOURCES), 0.0);
        ksort($fleets, SORT_NUMERIC);
        foreach ($fleets as $id => &$fleet) {
            if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Некорректный ID добытчика.');
            foreach (['free', 'fuel', 'fuelRate', 'fuelReserve', 'endsAt'] as $field) {
                $fleet[$field] = self::number($fleet[$field] ?? null, 'флот/' . $field);
            }
            if ($fleet['endsAt'] < $start) throw new \LogicException('Добывающий флот уже завершил работу до checkpoint.');
            $consider($fleet['endsAt'] - $start, ['kind' => 'duration', 'fleetId' => $id]);
            if (!is_array($fleet['rates'] ?? null) || array_diff(array_keys($fleet['rates']), array_keys(self::RESOURCES))) {
                throw new \InvalidArgumentException('Некорректный набор добываемых ресурсов.');
            }
            foreach (self::RESOURCES as $resourceId => $_) {
                $fleet['rates'][$resourceId] = self::number($fleet['rates'][$resourceId] ?? 0, 'добыча ресурса');
                $demand[$resourceId] = self::number($demand[$resourceId] + $fleet['rates'][$resourceId], 'общая добыча');
            }
            $production = self::number(array_sum($fleet['rates']), 'добыча флота');
            if ($production <= 0) throw new \InvalidArgumentException('У добывающего флота нет производительности.');
            if ($fleet['free'] <= 0) $consider(0, ['kind' => 'cargo', 'fleetId' => $id]);
            elseif ($production > $fleet['fuelRate']) {
                $consider($fleet['free'] / ($production - $fleet['fuelRate']) * 3600, ['kind' => 'cargo', 'fleetId' => $id]);
            }
            // Сожжённое топливо освобождает трюм, но не прибавляется к добыче.
            if ($fleet['fuel'] < $fleet['fuelReserve']
                || ($fleet['fuelRate'] > 0 && $fleet['fuel'] <= $fleet['fuelReserve'])) {
                $consider(0, ['kind' => 'fuel', 'fleetId' => $id]);
            } elseif ($fleet['fuelRate'] > 0) {
                $consider(($fleet['fuel'] - $fleet['fuelReserve']) / $fleet['fuelRate'] * 3600,
                    ['kind' => 'fuel', 'fleetId' => $id]);
            }
        }
        unset($fleet);
        foreach ($resources as $id => $resource) {
            if ($demand[$id] <= 0) continue;
            foreach (['stock' => $resource['rate'], 'reserve' => $coverage * $resource['rate']] as $field => $regeneration) {
                if ($resource[$field] <= 0) $consider(0, ['kind' => $field, 'resourceId' => $id]);
                elseif ($demand[$id] > $regeneration) {
                    $consider($resource[$field] / ($demand[$id] - $regeneration) * 3600,
                        ['kind' => $field, 'resourceId' => $id]);
                }
            }
        }
        $hours = $elapsed / 3600;
        $cargo = [];
        foreach ($fleets as $id => &$fleet) {
            $cargo[$id] = array_map(static fn(float $rate): float => self::number($rate * $hours, 'добытый груз'), $fleet['rates']);
            $fleet['fuel'] = max(0.0, $fleet['fuel'] - $fleet['fuelRate'] * $hours);
            $fleet['free'] = max(0.0, self::number($fleet['free'] + $fleet['fuelRate'] * $hours, 'освободившийся трюм') - array_sum($cargo[$id]));
        }
        unset($fleet);
        foreach ($resources as $id => &$resource) {
            $resource['stock'] = min($resource['capacity'], max(0.0,
                $resource['stock'] + ($resource['rate'] - $demand[$id]) * $hours));
            $resource['reserve'] = min($coverage * $resource['capacity'], max(0.0,
                $resource['reserve'] + ($coverage * $resource['rate'] - $demand[$id]) * $hours));
        }
        unset($resource);
        $state['resources'] = $resources;
        $state['time'] = $start + $elapsed;
        return compact('state', 'fleets', 'cargo', 'events', 'elapsed');
    }

    private static function balance(): array
    {
        static $balance;
        return $balance ??= require dirname(__DIR__, 3) . '/resources/fleet-mining.php';
    }

    /**
     * Решение восстановления одной строки, БЕЗ записи. Разные сущности могут
     * быть сохранены как before/after; after повторно не начисляется.
     * Неизвестное значение/другой владелец не перезаписываются. Числа должны
     * быть подготовлены с точностью целевых SQL-полей будущим commit-адаптером.
     */
    public static function recoveryPatch(array $current, array $entry): ?array
    {
        \SPGame\Game\Repositories\MiningCheckpoints::encodeEntries([$entry]);
        if (($current['id'] ?? null) !== $entry['id']) throw new \LogicException('Изменилась идентичность сущности добычи.');
        $matches = static function (array $expected) use ($current): bool {
            foreach ($expected as $field => $value) {
                if (!array_key_exists($field, $current)) return false;
                $actual = $current[$field];
                if ((is_int($value) || is_float($value)) && (is_int($actual) || is_float($actual))) {
                    // Не использовать относительный epsilon для миллиардных грузов:
                    // он мог бы принять реально изменённое состояние за наше after.
                    if (!is_finite((float)$actual) || $actual != $value) return false;
                } elseif ($actual !== $value) return false;
            }
            return true;
        };
        if (!$matches($entry['guard'])) throw new \LogicException('Контекст checkpoint добычи изменён извне.');
        if ($matches($entry['after'])) return null;
        if ($matches($entry['before'])) return ['id' => $entry['id']] + $entry['after'];
        throw new \LogicException('Неизвестное состояние экономики при восстановлении добычи.');
    }

    private static function siteType(mixed $type): int
    {
        if (!in_array($type, [Sites::GAS_GIANT, Sites::ASTEROID_SECTOR], true)) {
            throw new \InvalidArgumentException('Неизвестный тип добываемого объекта.');
        }
        return $type;
    }

    private static function number(mixed $value, string $name): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) {
            throw new \InvalidArgumentException('Некорректное число: ' . $name . '.');
        }
        return (float)$value;
    }
}
