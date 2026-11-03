<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

/** Чистый бой по подготовленным группам: без repositories, команд и сохранения. */
final class FleetCombatService
{
    public const DEFAULT_ROUNDS = 10;
    // Ограничение работы, а не усечение результата: при превышении расчёт отклоняется.
    public const MAX_PACKETS = 100000;
    public const MAX_TARGET_VISITS = 1000000;
    private const DOMES = [409, 408, 407];
    private const MAX_EXACT_COUNT = 9007199254740991;
    private const BURST_MIN_RATIO = 32;

    /**
     * Ключ группы — уникальный внутри стороны ID, например fleet:17:204.
     * type, count — int; kind — fleet|planet|defense; experience — сумма опыта.
     * attack/hull/shield — характеристики ОДНОЙ единицы, уже с технологиями/опытом.
     * unitCost — базовая цена металл+кристалл. Все группы находятся в одной точке боя.
     * rapidfire — текущий формат Vars [стреляющий тип => [выбранный тип => выстрелы]].
     * roll нужен для повторяемых тестов; сервер по умолчанию использует random_int.
     */
    public static function calculate(
        array $attackers, array $defenders, array $rapidfire = [],
        int $maxRounds = self::DEFAULT_ROUNDS, ?callable $roll = null
    ): array {
        $state = self::startBattle($attackers, $defenders, $rapidfire, $maxRounds);
        while (!self::isFinished($state)) {
            $state = self::calculateRound($state, $roll);
        }
        return self::getResult($state);
    }

    /** Сериализуемое состояние; корпус и численные поправки сохраняются между заданиями. */
    public static function startBattle(
        array $attackers, array $defenders, array $rapidfire = [], int $maxRounds = self::DEFAULT_ROUNDS
    ): array {
        if ($maxRounds < 1 || $maxRounds > 20) {
            throw new \InvalidArgumentException('Число раундов должно быть от 1 до 20.');
        }
        $work = 0;
        self::spendWork($work, 6 * (count($attackers) + count($defenders)));
        $a = self::prepare($attackers);
        $d = self::prepare($defenders);
        self::validateRapidfire($rapidfire);
        return ['version' => 1, 'a' => $a, 'd' => $d, 'rapidfire' => $rapidfire,
            'damageA' => [], 'damageD' => [], 'rounds' => [], 'roundLimit' => $maxRounds,
            'packets' => 0, 'work' => $work, 'powerA' => self::power($a), 'powerD' => self::power($d)];
    }

    /** Только один раунд. Вход не мутируется, в том числе при исключении. */
    public static function calculateRound(array $state, ?callable $roll = null): array
    {
        if (self::isFinished($state)) return $state;
        $roll ??= static fn(int $min, int $max): int => random_int($min, $max);
        $a = &$state['a'];
        $d = &$state['d'];
        foreach ([&$a, &$d] as &$side) {
            foreach ($side as &$group) {
                $group['shieldRemaining'] = self::number($group['count'] * $group['shield']);
                $group['shieldCorrection'] = 0.0;
            }
            unset($group);
        }
        unset($side);
        // Обе стороны стреляют составом на начало раунда, даже при гибели в нём.
        $startA = $a;
        $startD = $d;
        $shotsA = self::fire($startA, $d, $state['rapidfire'], $roll,
            $state['damageA'], $state['packets'], $state['work']);
        $shotsD = self::fire($startD, $a, $state['rapidfire'], $roll,
            $state['damageD'], $state['packets'], $state['work']);
        $state['rounds'][] = ['number' => count($state['rounds']) + 1,
            'attacker' => self::snapshot($a), 'defender' => self::snapshot($d),
            'shotsAttacker' => $shotsA, 'shotsDefender' => $shotsD];
        return $state;
    }

    /** Новые группы вступают только между раундами; старые повреждения не сбрасываются. */
    public static function addReinforcements(array $state, string $side, array $groups): array
    {
        if (!in_array($side, ['attacker', 'defender'], true) || self::isFinished($state)) {
            throw new \LogicException('Подкрепление недоступно для завершённого боя или неизвестной стороны.');
        }
        $key = $side === 'attacker' ? 'a' : 'd';
        $powerKey = $side === 'attacker' ? 'powerA' : 'powerD';
        $prepared = self::prepare($groups);
        foreach ($prepared as $id => $group) {
            if (array_key_exists($id, $state[$key])) {
                throw new \LogicException('Боевая группа уже участвовала в этом бою.');
            }
        }
        self::spendWork($state['work'], 6 * count($prepared));
        $state[$key] += $prepared;
        ksort($state[$key], SORT_STRING);
        self::amount($state[$key]);
        $state[$powerKey] = self::number($state[$powerKey] + self::power($prepared));
        return $state;
    }

    public static function isFinished(array $state): bool
    {
        return self::finishReason($state) !== null;
    }

    private static function finishReason(array $state): ?string
    {
        if (($state['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Неизвестная версия состояния боя.');
        }
        if (self::amount($state['a']) === 0 || self::amount($state['d']) === 0) return 'elimination';
        if (count($state['rounds']) >= $state['roundLimit']) return 'round_limit';
        if (!self::canFire($state['a']) && !self::canFire($state['d'])) return 'no_attack';
        return null;
    }

    public static function getResult(array $state): array
    {
        $reason = self::finishReason($state);
        if ($reason === null) throw new \LogicException('Бой ещё не завершён.');
        $leftA = self::amount($state['a']);
        $leftD = self::amount($state['d']);
        $outcome = $leftA > 0 && $leftD === 0 ? 'attacker'
            : ($leftD > 0 && $leftA === 0 ? 'defender' : 'draw');
        return ['outcome' => $outcome, 'reason' => $reason, 'roundLimit' => $state['roundLimit'],
            'rounds' => $state['rounds'], 'packets' => $state['packets'], 'targetVisits' => $state['work'],
            'attacker' => self::resultSide($state['a'], $state['powerA'], $state['damageA']),
            'defender' => self::resultSide($state['d'], $state['powerD'], $state['damageD'])];
    }

    /**
     * Данные для FleetExperienceService::calculateRewards(), НЕ применение награды.
     * Вызывающий код сначала определяет восстановление обороны и передаёт количества
     * restoredDefenses[attacker|defender][groupId]. Политика восстановления здесь не задана.
     */
    public static function experienceInputs(array $battle, array $restoredDefenses = []): array
    {
        $final = [];
        foreach ($restoredDefenses as $side => $counts) {
            if (!in_array($side, ['attacker', 'defender'], true) || !is_array($counts)) {
                throw new \InvalidArgumentException('Некорректная сторона восстановления обороны.');
            }
            foreach ($counts as $id => $count) {
                $group = $battle[$side]['groups'][$id] ?? null;
                if ($group === null || $group['kind'] !== 'defense' || !is_int($count)
                    || $count < 0 || $count > $group['before'] - $group['after']) {
                    throw new \InvalidArgumentException('Некорректное восстановление обороны.');
                }
            }
        }
        foreach (['attacker' => 'defender', 'defender' => 'attacker'] as $side => $enemy) {
            $groups = [];
            foreach ($battle[$side]['groups'] as $id => $group) {
                $groups[$id] = ['before' => $group['before'],
                    'after' => $group['after'] + ($restoredDefenses[$side][$id] ?? 0),
                    'experience' => $group['experience'], 'unitCost' => $group['unitCost'],
                    'contribution' => 0.0, 'eligible' => $group['kind'] === 'fleet'];
            }
            $destroyedValue = 0.0;
            foreach ($battle[$enemy]['groups'] as $target => $group) {
                $lost = $group['before'] - $group['after'] - ($restoredDefenses[$enemy][$target] ?? 0);
                if ($lost === 0) continue;
                $value = self::number($lost * $group['unitCost']);
                $destroyedValue = self::number($destroyedValue + $value);
                $totalDamage = 0.0;
                foreach ($groups as $source => $_) {
                    $totalDamage = self::number($totalDamage + ($battle[$side]['hullDamage'][$source][$target] ?? 0.0));
                }
                if ($totalDamage <= 0.0) throw new \LogicException('Потери не подтверждены уроном корпусу.');
                foreach ($groups as $source => &$reward) {
                    $damage = $battle[$side]['hullDamage'][$source][$target] ?? 0.0;
                    $reward['contribution'] = self::number($reward['contribution'] + $value * ($damage / $totalDamage));
                }
                unset($reward);
            }
            $final[$side] = ['groups' => $groups, 'ownPower' => $battle[$side]['power'],
                'enemyPower' => $battle[$enemy]['power'], 'destroyedValue' => $destroyedValue];
        }
        return $final;
    }

    private static function prepare(array $groups): array
    {
        $result = [];
        foreach ($groups as $id => $group) {
            if ((string)$id === '' || !is_array($group) || !is_int($group['type'] ?? null)
                || $group['type'] <= 0 || !is_int($group['count'] ?? null)
                || $group['count'] < 0 || $group['count'] > self::MAX_EXACT_COUNT
                || !in_array($group['kind'] ?? null, ['fleet', 'planet', 'defense'], true)) {
                throw new \InvalidArgumentException('Некорректная боевая группа.');
            }
            $dome = in_array($group['type'], self::DOMES, true);
            if ($dome && $group['kind'] !== 'defense') {
                throw new \InvalidArgumentException('Купол должен относиться к наземной обороне.');
            }
            foreach (['attack', 'hull', 'shield', 'unitCost'] as $field) {
                $group[$field] = self::number($group[$field] ?? null);
            }
            if ($group['hull'] <= 0.0 || $group['unitCost'] <= 0.0) {
                throw new \InvalidArgumentException('Корпус и цена группы должны быть положительными.');
            }
            $group['experience'] = self::number($group['experience'] ?? 0.0);
            if (($group['count'] === 0 && $group['experience'] > 0.0)
                || ($group['kind'] !== 'fleet' && $group['experience'] > 0.0)
                || FleetExperienceService::average($group['count'], $group['experience']) > FleetExperienceService::MAX_PER_SHIP) {
                throw new \InvalidArgumentException('Некорректный опыт боевой группы.');
            }
            // Техническая атака 1 у куполов в Vars не является вооружением.
            if ($dome) $group['attack'] = 0.0;
            $group['before'] = $group['count'];
            $group['hullRemaining'] = self::number($group['count'] * $group['hull']);
            $group['shieldRemaining'] = self::number($group['count'] * $group['shield']);
            $group['hullCorrection'] = $group['shieldCorrection'] = 0.0;
            $result[$id] = $group;
        }
        ksort($result, SORT_STRING);
        self::amount($result);
        return $result;
    }

    private static function fire(
        array $sources, array &$targets, array $rapidfire, callable $roll, array &$ledger, int &$packets, int &$work
    ): array {
        $weights = self::targetWeights($targets, $work);
        $order = array_keys(array_filter($sources,
            static fn(array $g): bool => $g['count'] > 0 && $g['attack'] > 0.0));
        // Порядок групп не должен постоянно отдавать преимущество одному ID флота.
        for ($i = count($order) - 1; $i > 0; --$i) {
            $j = self::roll($roll, 0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        $shots = [];
        foreach ($order as $sourceId) {
            $source = $sources[$sourceId];
            // 50 / 100 * 110 = 55, без артефакта 50 * 1.1 и большого промежуточного произведения.
            $attack = self::number($source['attack'] / 100 * self::roll($roll, 80, 120));
            $remaining = $source['count'];
            $burstBlocked = -1;
            while ($remaining > 0 && $weights['total'] > 0) {
                if ($weights['alive'] > 1 && $weights['domeCount'] === 0
                    // При небольшом перевесе проверка окна дороже обычных пакетов.
                    && intdiv($remaining, self::BURST_MIN_RATIO) >= $weights['total']
                    && $burstBlocked !== $weights['revision']) {
                    if (self::burst($source, $sourceId, $attack, $remaining, $targets, $weights,
                        $rapidfire, $roll, $ledger, $shots, $packets, $work)) continue;
                    // Без изменения количества HP/щиты только убывают: окно,
                    // которое было слишком коротким, уже не вырастет.
                    $burstBlocked = $weights['revision'];
                }
                self::spendPackets($packets, 1);
                $index = self::pickTarget($weights, $weights['alive'] === 1
                    ? 1 : self::roll($roll, 1, $weights['total']), $work);
                $targetId = $weights['keys'][$index];
                $firing = min($remaining, $targets[$targetId]['count']);
                // RF определяется ВЫБРАННОЙ целью, не перехватившим огонь куполом.
                $rf = max(1.0, (float)($rapidfire[$source['type']][$targets[$targetId]['type']] ?? 1));
                if ($weights['alive'] === 1) {
                    // Объединяем только пакеты до первого изменения количества цели.
                    // Нельзя засчитать все оставшиеся корабли, если цель гибнет раньше.
                    $firing = self::singleTargetFiring($remaining, $targets[$targetId], self::number($attack * $rf));
                }
                $remaining -= $firing;
                $actualShots = self::number($firing * $rf);
                $shots[$sourceId][$targetId] = self::number(($shots[$sourceId][$targetId] ?? 0.0) + $actualShots);
                $damage = self::number($actualShots * $attack);
                self::hit($targets, $sourceId, $targetId, $damage, $ledger, $weights, $work);
            }
        }
        return $shots;
    }

    /**
     * Точный участок без изменения количества целей. Каждый случайный выбор
     * выполняется, но одинаковые попадания одного источника применяются вместе.
     * При куполах отключено: перехват связывает повреждения разных выбранных целей.
     */
    private static function burst(array $source, int|string $sourceId, float $attack, int &$remaining,
        array &$targets, array &$weights, array $rapidfire, callable $roll, array &$ledger,
        array &$shots, int &$packets, int &$work): bool
    {
        if ($attack <= 0.0) return false;
        self::spendWork($work, count($targets));
        $draws = 4096;
        $largest = 1;
        $rf = [];
        foreach ($weights['keys'] as $index => $id) {
            $group = $targets[$id];
            if ($group['count'] === 0) continue;
            $largest = max($largest, $group['count']);
            $rf[$index] = max(1.0, (float)($rapidfire[$source['type']][$group['type']] ?? 1));
            $packetDamage = self::number($group['count'] * $attack * $rf[$index]);
            if ($packetDamage <= 0.0) return false;
            $frontHull = max(0.0, $group['hullRemaining'] - $group['hullCorrection'] - ($group['count'] - 1) * $group['hull']);
            // Даже если ВСЕ выборы придутся на одну цель, первая потеря ещё
            // не наступит. Запас в пакет исключает пограничное округление.
            $draws = min($draws, max(0.0, floor(($group['shieldRemaining'] - $group['shieldCorrection'] + $frontHull) / $packetDamage) - 1));
            if ($draws < 4) return false;
        }
        $draws = (int)min($draws, intdiv($remaining, $largest));
        if ($draws < 4) return false;
        self::spendPackets($packets, $draws);
        $fired = [];
        if (count($weights['keys']) === 2) {
            // Частый тяжёлый случай: большой источник и две малые прочные цели.
            // Выборы всё ещё отдельные; постоянные веса читаются один раз.
            self::spendWork($work, $draws);
            $first = $weights['counts'][1];
            $second = $weights['counts'][2];
            $total = $weights['total'];
            $one = $two = 0;
            for ($i = 0; $i < $draws; ++$i) {
                if (self::roll($roll, 1, $total) <= $first) $one += $first;
                else $two += $second;
            }
            if ($one > 0) $fired[1] = $one;
            if ($two > 0) $fired[2] = $two;
            $remaining -= $one + $two;
        } else {
            for ($i = 0; $i < $draws; ++$i) {
                $index = self::pickTarget($weights, self::roll($roll, 1, $weights['total']), $work);
                $n = $weights['counts'][$index];
                $remaining -= $n;
                $fired[$index] = ($fired[$index] ?? 0) + $n;
            }
        }
        foreach ($fired as $index => $n) {
            $id = $weights['keys'][$index];
            $before = $targets[$id]['count'];
            $actualShots = self::number($n * $rf[$index]);
            $shots[$sourceId][$id] = self::number(($shots[$sourceId][$id] ?? 0.0) + $actualShots);
            self::hit($targets, $sourceId, $id, self::number($actualShots * $attack), $ledger, $weights, $work);
            if ($targets[$id]['count'] !== $before) {
                throw new \LogicException('Оптимизированный участок вышел за границу неизменных весов целей.');
            }
        }
        return true;
    }

    /** Дерево весов: выбор и изменение веса O(log G), без скана G на каждый пакет. */
    private static function targetWeights(array $targets, int &$work): array
    {
        $n = count($targets);
        self::spendWork($work, 2 * $n);
        $weights = ['keys' => [], 'positions' => [], 'tree' => $n > 8 ? array_fill(0, $n + 1, 0) : [],
            'counts' => [], 'total' => 0, 'alive' => 0, 'domes' => [], 'domeSet' => [], 'domeCount' => 0,
            'bit' => 1, 'depth' => 1, 'revision' => 0];
        $i = 0;
        $domes = array_fill_keys(self::DOMES, []);
        foreach ($targets as $id => $group) {
            ++$i;
            $weights['keys'][$i] = $id;
            $weights['positions'][$id] = $i;
            $weights['counts'][$i] = $group['count'];
            if ($n > 8) {
                $weights['tree'][$i] += $group['count'];
                $parent = $i + ($i & -$i);
                if ($parent <= $n) $weights['tree'][$parent] += $weights['tree'][$i];
            }
            $weights['total'] += $group['count'];
            if ($group['count'] > 0) ++$weights['alive'];
            if ($group['kind'] === 'defense' && isset($domes[$group['type']])) {
                $domes[$group['type']][] = $id;
                $weights['domeSet'][$id] = true;
                if ($group['count'] > 0) ++$weights['domeCount'];
            }
        }
        $weights['domes'] = array_merge(...array_values($domes));
        while ($weights['bit'] <= intdiv($n, 2)) { $weights['bit'] <<= 1; ++$weights['depth']; }
        return $weights;
    }

    private static function pickTarget(array &$weights, int $ticket, int &$work): int
    {
        $n = count($weights['keys']);
        if ($n === 1) {
            self::spendWork($work, 1);
            return 1;
        }
        if ($n === 2) {
            self::spendWork($work, 1);
            return $ticket <= $weights['counts'][1] ? 1 : 2;
        }
        if ($n <= 8) {
            // На малом числе групп кэш весов дешевле обслуживания дерева.
            self::spendWork($work, $n);
            foreach ($weights['counts'] as $index => $count) {
                if ($ticket <= $count) return $index;
                $ticket -= $count;
            }
            throw new \LogicException('Некорректная сумма весов боевых групп.');
        }
        self::spendWork($work, $weights['depth']);
        $index = 0;
        for ($bit = $weights['bit']; $bit > 0; $bit >>= 1) {
            $next = $index + $bit;
            if ($next <= $n && $weights['tree'][$next] < $ticket) {
                $ticket -= $weights['tree'][$next];
                $index = $next;
            }
        }
        return $index + 1;
    }

    private static function updateWeight(array &$weights, int|string $id, int $count, int &$work): void
    {
        $index = $weights['positions'][$id];
        $delta = $count - $weights['counts'][$index];
        if ($delta === 0) return;
        $n = count($weights['keys']);
        self::spendWork($work, $n <= 8 ? 1 : $weights['depth']);
        $weights['counts'][$index] = $count;
        ++$weights['revision'];
        $weights['total'] += $delta;
        if ($count === 0) {
            --$weights['alive'];
            if (isset($weights['domeSet'][$id])) --$weights['domeCount'];
        }
        if ($n <= 8) return;
        for (; $index <= $n; $index += $index & -$index) {
            $weights['tree'][$index] += $delta;
        }
    }

    private static function spendPackets(int &$packets, int $amount): void
    {
        if ($amount > self::MAX_PACKETS - $packets) {
            throw new \RuntimeException('Превышен безопасный бюджет пакетов боевого расчёта.');
        }
        $packets += $amount;
    }

    /** Объединение повторных выборов, пока они не меняют количество живых целей. */
    private static function singleTargetFiring(int $remaining, array $target, float $damagePerShip): int
    {
        $size = $target['count'];
        if ($remaining <= $size) return $remaining;
        if ($damagePerShip <= 0.0) return $remaining;
        $packetDamage = self::number($size * $damagePerShip);
        $frontHull = max(0.0, $target['hullRemaining'] - $target['hullCorrection'] - ($size - 1) * $target['hull']);
        $untilLoss = self::number($target['shieldRemaining'] - $target['shieldCorrection'] + $frontHull);
        $packetsUntilLoss = max(1.0, ceil(self::roundBoundary($untilLoss / $packetDamage)));
        $fullPackets = intdiv($remaining, $size);
        if ($packetsUntilLoss > $fullPackets) return $remaining;
        return (int)$packetsUntilLoss * $size;
    }

    /** Ограничиваем фактический перебор групп и шаги дерева, не только пакеты. */
    private static function spendWork(int &$work, int $visits): void
    {
        if ($visits > self::MAX_TARGET_VISITS - $work) {
            throw new \RuntimeException('Превышен безопасный бюджет перебора боевых групп.');
        }
        $work += $visits;
    }

    private static function hit(array &$targets, int|string $source, int|string $selected, float $damage,
        array &$ledger, array &$weights, int &$work): void
    {
        if ($targets[$selected]['kind'] === 'defense') {
            foreach ($weights['domes'] as $id) {
                self::spendWork($work, 1);
                if ($targets[$id]['count'] === 0) continue;
                $damage = self::absorb($targets[$id], $source, $id, $damage, $ledger, $weights, $work);
                // Перелёт остатка сквозь уничтоженную выбранную цель не создаёт новую цель.
                if ($id === $selected || $damage <= 0.0) return;
            }
        }
        self::absorb($targets[$selected], $source, $selected, $damage, $ledger, $weights, $work);
    }

    /** Возвращает только урон, не поглощённый щитом и корпусом этой группы. */
    private static function absorb(array &$group, int|string $source, int|string $target, float $damage,
        array &$ledger, array &$weights, int &$work): float
    {
        $shieldDamage = min($group['shieldRemaining'], $damage);
        if ($shieldDamage > 0.0) {
            $group['shieldRemaining'] = self::subtract($group['shieldRemaining'], $shieldDamage, $group['shield'], $group['shieldCorrection']);
        }
        $damage -= $shieldDamage;
        $hullDamage = min($group['hullRemaining'], $damage);
        if ($hullDamage > 0.0) {
            $group['hullRemaining'] = self::subtract($group['hullRemaining'], $hullDamage, $group['hull'], $group['hullCorrection']);
            $ledger[$source][$target] = self::number(($ledger[$source][$target] ?? 0.0) + $hullDamage);
            $ratio = ($group['hullRemaining'] - $group['hullCorrection']) / $group['hull'];
            $group['count'] = min($group['count'], (int)ceil(self::roundBoundary($ratio)));
            if ($group['count'] === 0) {
                $group['hullRemaining'] = $group['shieldRemaining'] = 0.0;
                $group['hullCorrection'] = $group['shieldCorrection'] = 0.0;
            }
            self::updateWeight($weights, $target, $group['count'], $work);
        }
        return max(0.0, $damage - $hullDamage);
    }

    private static function validateRapidfire(array $rapidfire): void
    {
        foreach ($rapidfire as $targets) {
            if (!is_array($targets)) throw new \InvalidArgumentException('Некорректная таблица скорострельности.');
            foreach ($targets as $shots) {
                $value = self::number($shots);
                if ($value !== floor($value)) throw new \InvalidArgumentException('Скорострельность должна быть целым числом.');
            }
        }
    }

    private static function roll(callable $roll, int $min, int $max): int
    {
        $value = $roll($min, $max);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException('Генератор случайных чисел вышел за диапазон.');
        }
        return $value;
    }

    private static function amount(array $groups): int
    {
        $total = 0;
        foreach ($groups as $group) {
            if ($group['count'] > self::MAX_EXACT_COUNT - $total) {
                throw new \InvalidArgumentException('Количество участников превышает точность расчёта.');
            }
            $total += $group['count'];
        }
        return $total;
    }

    private static function canFire(array $groups): bool
    {
        foreach ($groups as $group) if ($group['count'] > 0 && $group['attack'] > 0.0) return true;
        return false;
    }

    private static function power(array $groups): float
    {
        $a = $h = $s = 0.0;
        foreach ($groups as $group) {
            $a = self::number($a + $group['attack'] * $group['count']);
            $h = self::number($h + $group['hullRemaining']);
            $s = self::number($s + $group['shieldRemaining']);
        }
        return FleetExperienceService::sidePower($a, $h, $s);
    }

    private static function snapshot(array $groups): array
    {
        return array_map(static fn(array $g): array => ['count' => $g['count'],
            'hull' => $g['hullRemaining'], 'shield' => $g['shieldRemaining']], $groups);
    }

    private static function resultSide(array $groups, float $power, array $ledger): array
    {
        foreach ($groups as &$group) {
            $group['after'] = $group['count'];
            unset($group['count'], $group['hullCorrection'], $group['shieldCorrection']);
        }
        unset($group);
        return ['groups' => $groups, 'power' => $power, 'hullDamage' => $ledger];
    }

    private static function number(mixed $value): float
    {
        if (!(is_int($value) || is_float($value)) || !is_finite((float)$value) || $value < 0) {
            throw new \InvalidArgumentException('Боевая характеристика должна быть конечным неотрицательным числом.');
        }
        return (float)$value;
    }

    /** Убирает только машинную погрешность около целого (например 0.1 + 0.2). */
    private static function roundBoundary(float $value): float
    {
        $nearest = round($value);
        return abs($value - $nearest) <= min(1e-7, 8 * PHP_FLOAT_EPSILON * max(1.0, $value))
            ? $nearest : $value;
    }

    /** Компенсируем округление, но отклоняем ошибку, значимую для одной единицы. */
    private static function subtract(float $pool, float $damage, float $unitValue, float &$correction = 0.0): float
    {
        if ($correction === 0.0) {
            $exact = max(0.0, $pool - $damage);
            // Целые/полуцелые характеристики обычного боя часто вычитаются
            // точно: не считаем компенсацию и допуск без необходимости.
            if ($pool - $exact === $damage) return $exact;
        }
        // Компенсация Kahan не даёт ошибке копиться при тысячах малых попаданий.
        $adjusted = -$damage - $correction;
        $sum = $pool + $adjusted;
        $left = max(0.0, $sum);
        // Допуск зависит от ULP большого пула, но ограничен 0.001% характеристики
        // ОДНОЙ единицы. Малый урон хранится в correction, а не пропадает.
        $roundoff = 4 * PHP_FLOAT_EPSILON * max($pool, $damage);
        $tolerance = max(1e-9, min($roundoff, $unitValue * 1e-5));
        if (abs(($pool - $left) - $damage) > $tolerance) {
            throw new \InvalidArgumentException('Недостаточная численная точность для данного боевого повреждения.');
        }
        $correction = $left > 0.0 ? ($sum - $pool) - $adjusted : 0.0;
        return $left;
    }
}
