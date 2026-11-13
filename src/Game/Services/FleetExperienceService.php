<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

/**
 * Чистые расчёты опыта: не меняют Fleet, repositories или marker команды.
 * Результат боя должен применяться вызывающим кодом вместе с остальным commit.
 */
final class FleetExperienceService
{
    public const MAX_PER_SHIP = 1000.0;
    public const MAX_GAIN_PER_BATTLE = 20.0;

    public static function average(int $count, float $experience): float
    {
        self::number($experience, 'experience');
        if ($count < 0) {
            throw new \InvalidArgumentException('Количество кораблей не может быть отрицательным.');
        }
        return $count > 0 ? $experience / $count : 0.0;
    }

    /** Прибавки к коэффициенту базовой характеристики, не множители текущей. */
    public static function bonuses(float $averageExperience): array
    {
        self::number($averageExperience, 'averageExperience');
        $experience = min(self::MAX_PER_SHIP, $averageExperience);
        $factor = 1.1 * $experience / (100.0 + $experience);
        return ['attack' => .10 * $factor, 'hull' => .10 * $factor, 'shield' => .05 * $factor];
    }

    /**
     * Характеристики ОДНОГО корабля; факторы уже включают технологии, но не опыт.
     * Сводный опыт Fleet в эту формулу не передаётся вторым бонусом.
     * @param array{attack:float|int,hull:float|int,shield:float|int} $base
     * @param array{attack:float|int,hull:float|int,shield:float|int} $factors
     */
    public static function combatStats(array $base, array $factors, float $averageExperience): array
    {
        $bonuses = self::bonuses($averageExperience);
        $result = [];
        foreach ($bonuses as $key => $bonus) {
            $value = self::number($base[$key] ?? null, 'base.' . $key);
            $factor = self::number($factors[$key] ?? null, 'factors.' . $key);
            $result[$key] = self::number($value * ($factor + $bonus), 'stats.' . $key);
        }
        return $result;
    }

    /** Взвешенный по цене металл+кристалл показатель, без самостоятельного XP. */
    public static function fleetAverage(array $groups): float
    {
        $weightedExperience = 0.0;
        $value = 0.0;
        foreach ($groups as $group) {
            $count = self::count($group['count'] ?? null, 'count');
            $experience = self::number($group['experience'] ?? null, 'experience');
            if ($count === 0) {
                continue;
            }
            $cost = self::number($group['unitCost'] ?? null, 'unitCost');
            if ($cost <= 0.0) {
                throw new \InvalidArgumentException('Для оценки опыта нужна положительная стоимость корабля.');
            }
            $weightedExperience += $cost * $experience;
            $value += $cost * $count;
        }
        self::number($weightedExperience, 'weightedExperience');
        self::number($value, 'fleetValue');
        return $value > 0.0 ? $weightedExperience / $value : 0.0;
    }

    /** Оценка угрозы для награды, не расчёт результата боя или скорострельности. */
    public static function sidePower(float $attack, float $hull, float $shield): float
    {
        self::number($attack, 'attack');
        self::number($hull, 'hull');
        self::number($shield, 'shield');
        return sqrt(self::number($attack * ($hull + $shield), 'powerSquared'));
    }

    /**
     * Награда одной стороны по неизменному snapshot боя.
     *
     * В groups входят ВСЕ группы стороны, в том числе погибшие и планетарные.
     * destroyedValue — стоимость невосстановленных потерь противника (металл +
     * кристалл). contribution — доля этой стоимости, атрибутированная группе
     * боевым расчётом по урону корпусу, не щитам/последнему выстрелу.
     *
     * @param array<array-key,array{before:int,after:int,experience:float|int,
     *   unitCost:float|int,contribution:float|int,eligible:bool}> $groups
     */
    public static function calculateRewards(
        array $groups,
        float $ownPower,
        float $enemyPower,
        float $destroyedValue
    ): array {
        self::number($ownPower, 'ownPower');
        self::number($enemyPower, 'enemyPower');
        self::number($destroyedValue, 'destroyedValue');
        $totalValue = 0.0;
        $contributions = 0.0;
        $prepared = [];
        foreach ($groups as $key => $group) {
            $before = self::count($group['before'] ?? null, 'before');
            $after = self::count($group['after'] ?? null, 'after');
            $experience = self::number($group['experience'] ?? null, 'experience');
            $cost = self::number($group['unitCost'] ?? null, 'unitCost');
            $contribution = self::number($group['contribution'] ?? null, 'contribution');
            $eligible = $group['eligible'] ?? null;
            if (!is_bool($eligible) || $after > $before || $cost <= 0.0
                || ($before === 0 && ($experience > 0.0 || $contribution > 0.0))
                || (!$eligible && $experience > 0.0)) {
                throw new \InvalidArgumentException('Некорректная группа для расчёта опыта.');
            }
            $average = self::average($before, $experience);
            if ($average > self::MAX_PER_SHIP + 1e-7) {
                // Не сбрасываем старые данные молча: до применения боя их
                // необходимо диагностировать, а не превращать в новый баланс.
                throw new \InvalidArgumentException('Опыт кораблей превышает согласованный предел.');
            }
            $value = self::number($before * $cost, 'groupValue');
            $totalValue += $value;
            $contributions += $contribution;
            $prepared[$key] = compact('before', 'after', 'experience', 'cost', 'eligible', 'average', 'value', 'contribution');
        }
        self::number($totalValue, 'totalValue');
        self::number($contributions, 'contributions');
        if (($destroyedValue > 0.0 && $contributions <= 0.0)
            || ($destroyedValue === 0.0 && $contributions > 0.0)
            || abs($contributions - $destroyedValue) > max(1e-6, $destroyedValue * 1e-9)) {
            throw new \InvalidArgumentException('Вклад групп не совпадает с уничтоженной стоимостью противника.');
        }

        $risk = $ownPower > 0.0 ? min(1.0, $enemyPower / $ownPower) ** 2 : 0.0;
        $budget = self::MAX_GAIN_PER_BATTLE * min($destroyedValue, $totalValue) * $risk;
        self::number($budget, 'budget');
        $result = [];
        $awardedValue = 0.0;
        foreach ($prepared as $key => $group) {
            // Нормализация на проверенную сумму не раздувает общий бюджет
            // из-за допустимой погрешности атрибуции дробного урона.
            $weight = $totalValue > 0.0 && $contributions > 0.0
                ? .8 * ($group['contribution'] / $contributions) + .2 * ($group['value'] / $totalValue)
                : 0.0;
            $gain = $group['eligible'] && $group['value'] > 0.0
                ? min(self::MAX_GAIN_PER_BATTLE, $budget * $weight / $group['value'],
                    max(0.0, self::MAX_PER_SHIP - $group['average']))
                : 0.0;
            $retained = $group['after'] === $group['before']
                ? $group['experience'] : $group['average'] * $group['after'];
            $gained = $gain * $group['after'];
            $experience = $retained + $gained;
            $awardedValue += $gained * $group['cost'];
            $result[$key] = [
                'count' => $group['after'],
                'experience' => $experience,
                'average' => self::average($group['after'], $experience),
                'retained' => $retained,
                'lost' => max(0.0, $group['experience'] - $retained),
                'gained' => $gained,
                'gainPerShip' => $group['after'] > 0 ? $gain : 0.0,
            ];
        }
        self::number($awardedValue, 'awardedValue');
        return ['riskFactor' => $risk, 'budget' => $budget, 'awardedValue' => $awardedValue, 'groups' => $result];
    }

    private static function number(mixed $value, string $field): float
    {
        if (!(is_int($value) || is_float($value)) || !is_finite((float)$value) || $value < 0) {
            throw new \InvalidArgumentException("Некорректное конечное неотрицательное значение {$field}.");
        }
        return (float)$value;
    }

    private static function count(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException("Некорректное количество {$field}.");
        }
        return $value;
    }
}
