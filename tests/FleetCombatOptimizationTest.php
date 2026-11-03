<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Game\Services\FleetCombatService as Combat;
use SPGame\Game\Services\FleetExperienceService as Experience;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function optAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function optNear(float $actual, float $expected, string $message): void
{
    optAssert(abs($actual - $expected) <= max(1e-7, abs($expected) * 1e-10), "{$message}: {$actual} != {$expected}");
}

function optGroup(int $type, int $count, int $attack, int $hull, int $shield, string $kind = 'fleet'): array
{
    return ['type' => $type, 'count' => $count, 'attack' => $attack, 'hull' => $hull,
        'shield' => $shield, 'kind' => $kind, 'unitCost' => $hull * 10, 'experience' => 0.0];
}

function optRandom(int $seed, array &$trace): Closure
{
    $random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
    $trace = ['calls' => 0, 'hash' => hash_init('sha256')];
    return static function (int $min, int $max) use ($random, &$trace): int {
        $value = $random->getInt($min, $max);
        ++$trace['calls'];
        hash_update($trace['hash'], "{$min}:{$max}:{$value};");
        return $value;
    };
}

/**
 * Независимая медленная спецификация: линейный выбор, каждый пакет отдельно.
 * HP/щиты в целых сотых: входные характеристики целые, разброс — целые проценты.
 * Нет production-дерева, окон, singleTargetFiring(), roundBoundary() и float-HP.
 */
function optReference(array $a, array $d, array $rf, int $limit, callable $roll): array
{
    $prepare = static function (array $groups): array {
        ksort($groups, SORT_STRING);
        foreach ($groups as &$g) {
            if (in_array($g['type'], [407, 408, 409], true)) $g['attack'] = 0;
            $g['hpUnit'] = $g['hull'] * 100;
            $g['hp'] = $g['count'] * $g['hpUnit'];
            $g['sp'] = $g['count'] * $g['shield'] * 100;
        }
        unset($g);
        return $groups;
    };
    $a = $prepare($a);
    $d = $prepare($d);
    $ledgerA = $ledgerD = $rounds = [];
    $apply = static function (array &$g, int|string $source, int|string $id, int $damage, array &$ledger): int {
        $shield = min($g['sp'], $damage);
        $g['sp'] -= $shield;
        $damage -= $shield;
        $hull = min($g['hp'], $damage);
        if ($hull > 0) {
            $g['hp'] -= $hull;
            $ledger[$source][$id] = ($ledger[$source][$id] ?? 0) + $hull;
            $g['count'] = intdiv($g['hp'] + $g['hpUnit'] - 1, $g['hpUnit']);
            if ($g['count'] === 0) $g['sp'] = 0;
        }
        return $damage - $hull;
    };
    $hit = static function (array &$targets, int|string $source, int|string $selected, int $damage, array &$ledger) use ($apply): void {
        if ($targets[$selected]['kind'] === 'defense') {
            foreach ([409, 408, 407] as $type) {
                foreach (array_keys($targets) as $id) {
                    if ($targets[$id]['kind'] !== 'defense' || $targets[$id]['type'] !== $type || $targets[$id]['count'] === 0) continue;
                    $damage = $apply($targets[$id], $source, $id, $damage, $ledger);
                    if ($id === $selected || $damage === 0) return;
                }
            }
        }
        $apply($targets[$selected], $source, $selected, $damage, $ledger);
    };
    $fire = static function (array $sources, array &$targets, array &$ledger) use ($rf, $roll, $hit): array {
        $order = array_keys(array_filter($sources, static fn(array $g): bool => $g['count'] > 0 && $g['attack'] > 0));
        for ($i = count($order) - 1; $i > 0; --$i) {
            $j = $roll(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        $shots = [];
        foreach ($order as $source) {
            $g = $sources[$source];
            $attackCents = $g['attack'] * $roll(80, 120);
            $left = $g['count'];
            while ($left > 0) {
                $alive = array_filter($targets, static fn(array $target): bool => $target['count'] > 0);
                if ($alive === []) break;
                if (count($alive) === 1) $id = array_key_first($alive);
                else {
                    $ticket = $roll(1, array_sum(array_column($alive, 'count')));
                    foreach ($alive as $id => $target) {
                        $ticket -= $target['count'];
                        if ($ticket <= 0) break;
                    }
                }
                $firing = min($left, $targets[$id]['count']);
                $left -= $firing;
                $r = max(1, $rf[$g['type']][$targets[$id]['type']] ?? 1);
                $shots[$source][$id] = ($shots[$source][$id] ?? 0) + $firing * $r;
                $hit($targets, $source, $id, $firing * $r * $attackCents, $ledger);
            }
        }
        return $shots;
    };
    $snapshot = static fn(array $side): array => array_map(static fn(array $g): array => [
        'count' => $g['count'], 'hull' => $g['hp'] / 100, 'shield' => $g['sp'] / 100], $side);
    for ($r = 1; $r <= $limit; ++$r) {
        if (array_sum(array_column($a, 'count')) === 0 || array_sum(array_column($d, 'count')) === 0) break;
        $canFire = false;
        foreach ([&$a, &$d] as &$side) {
            foreach ($side as &$g) {
                $canFire = $canFire || ($g['count'] > 0 && $g['attack'] > 0);
                $g['sp'] = $g['count'] * $g['shield'] * 100;
            }
            unset($g);
        }
        unset($side);
        if (!$canFire) break;
        $startA = $a;
        $startD = $d;
        $shotsA = $fire($startA, $d, $ledgerA);
        $shotsD = $fire($startD, $a, $ledgerD);
        $rounds[] = ['number' => $r, 'attacker' => $snapshot($a), 'defender' => $snapshot($d),
            'shotsAttacker' => $shotsA, 'shotsDefender' => $shotsD];
    }
    foreach ([&$ledgerA, &$ledgerD] as &$ledger) {
        foreach ($ledger as &$targets) foreach ($targets as &$value) $value /= 100;
        unset($targets, $value);
    }
    unset($ledger);
    return ['rounds' => $rounds, 'attackerDamage' => $ledgerA, 'defenderDamage' => $ledgerD];
}

function optCompare(mixed $actual, mixed $expected, string $path): void
{
    if (is_array($expected)) {
        optAssert(is_array($actual) && count($actual) === count($expected), $path . ': количество полей отличается');
        foreach ($expected as $id => $value) {
            optAssert(array_key_exists($id, $actual), $path . ': отсутствует ключ ' . $id);
            optCompare($actual[$id], $value, $path . '/' . $id);
        }
    } elseif (is_int($expected) && str_ends_with($path, '/count')) {
        optAssert($actual === $expected, $path . ": {$actual} != {$expected}");
    } else optNear((float)$actual, (float)$expected, $path);
}

function optCheck(array $a, array $d, array $rf, int $rounds, int $seed): void
{
    $traceA = $traceB = [];
    $battle = Combat::calculate($a, $d, $rf, $rounds, optRandom($seed, $traceA));
    $reference = optReference($a, $d, $rf, $rounds, optRandom($seed, $traceB));
    optAssert($traceA['calls'] === $traceB['calls'] && hash_final($traceA['hash']) === hash_final($traceB['hash']),
        'RNG seed=' . $seed . ': ' . $traceA['calls'] . ' != ' . $traceB['calls']);
    optCompare($battle['rounds'], $reference['rounds'], 'rounds');
    optCompare($battle['attacker']['hullDamage'], $reference['attackerDamage'], 'attackerDamage');
    optCompare($battle['defender']['hullDamage'], $reference['defenderDamage'], 'defenderDamage');
    foreach (Combat::experienceInputs($battle) as $input) Experience::calculateRewards(...$input);
}

$rng = new Random\Randomizer(new Random\Engine\Mt19937(20261016));
for ($case = 0; $case < 1000; ++$case) {
    $sides = [];
    foreach (['a', 'd'] as $side) {
        $groups = [];
        // По обе стороны порога 8: проверяются и малый кэш, и дерево весов.
        for ($i = 0, $n = $rng->getInt(1, 16); $i < $n; ++$i) {
            $kind = $side === 'a' ? 'fleet' : ['planet', 'fleet', 'defense'][$rng->getInt(0, 2)];
            $type = $kind === 'defense' ? [401, 402, 407, 408, 409][$rng->getInt(0, 4)] : [202, 204, 206][$rng->getInt(0, 2)];
            $groups[$side . $i] = optGroup($type, in_array($type, [407, 408, 409], true) ? 1 : $rng->getInt(0, 80),
                $rng->getInt(0, 500), $rng->getInt(5, 2000), $rng->getInt(0, 500), $kind);
        }
        $sides[$side] = $groups;
    }
    $rf = [];
    foreach (array_merge($sides['a'], $sides['d']) as $source) {
        foreach (array_merge($sides['a'], $sides['d']) as $target) $rf[$source['type']][$target['type']] = $rng->getInt(0, 8);
    }
    try { optCheck($sides['a'], $sides['d'], $rf, 5, $case); }
    catch (Throwable $e) { throw new RuntimeException('Смешанный случай ' . $case . ': ' . $e->getMessage(), 0, $e); }
}
echo "OK optimization 1000 exact-cent battles: counts, HP, shields, domes, RF, provenance and RNG trace\n";

for ($case = 0; $case < 200; ++$case) {
    $a = ['source' => optGroup(204, $rng->getInt(5000, 10000), $rng->getInt(1, 40), 1000, 0)];
    $d = $rf = [];
    for ($i = 0, $n = $rng->getInt(2, 5); $i < $n; ++$i) {
        $d['target' . $i] = optGroup(202 + $i, $rng->getInt(1, 5), $rng->getInt(0, 100),
            $rng->getInt(5000, 20000), $rng->getInt(0, 500));
        $rf[204][202 + $i] = $rng->getInt(1, 8);
    }
    try { optCheck($a, $d, $rf, 1, $case); }
    catch (Throwable $e) { throw new RuntimeException('Неравные группы ' . $case . ' ' . json_encode([$a, $d, $rf]) . ': ' . $e->getMessage(), 0, $e); }
}
echo "OK optimization 200 unequal-group burst windows match every scalar random selection\n";

foreach ([1000000, 10000000, 100000000, 1000000000] as $n) {
    $g = ['group' => optGroup(204, $n, 50, 400, 10)];
    for ($seed = 1; $seed <= 200; ++$seed) optCheck($g, $g, [], 10, $seed);
}
echo "OK optimization 800 million-to-billion battles match exact-cent reference without precision failures\n";

// Отдельный воспроизводимый допуск из реального benchmark, не снятие защиты.
$subtract = new ReflectionMethod(Combat::class, 'subtract');
$left = $subtract->invoke(null, 29086418578.5, 3372720558.9999995, 400.0);
optNear($left, 25713698019.5, 'Обычная ULP-погрешность большого пула разрешена');
$pool = 1e18;
$correction = 0.0;
for ($i = 0; $i < 1000; ++$i) {
    $args = [$pool, 100.0, 1e18, &$correction];
    $pool = $subtract->invokeArgs(null, $args);
}
optNear((1e18 - $pool) + $correction, 100000, 'Компенсация сохраняет сумму малых попаданий');
foreach ([[9007199254740991.0, .5, 1.0]] as $args) {
    try { $subtract->invoke(null, ...$args); throw new RuntimeException('Потеря неразличимого урона должна отклоняться.'); }
    catch (InvalidArgumentException) { /* Защита сохранена. */ }
}
echo "OK optimization scale-aware tolerance accepts roundoff but rejects unresolvable damage\n";

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/combat-vars-20261016.json'), true, flags: JSON_THROW_ON_ERROR);
$units = array_column($fixture['units'], null, 'id');
$group = static function (int $id, int $count, float $xp) use ($units): array {
    $u = $units[$id];
    $defense = $id >= 400;
    return Experience::combatStats(['attack' => $u['attack'], 'hull' => $u['unitCost'] / 10, 'shield' => $u['shield']],
        ['attack' => 1, 'hull' => 1, 'shield' => 1], $defense ? 0 : $xp)
        + ['type' => $id, 'count' => $count, 'kind' => $defense ? 'defense' : 'fleet',
            'unitCost' => $u['unitCost'], 'experience' => $defense ? 0 : $count * $xp];
};
foreach ($units as $id => $_) foreach ([0, 75, 999.99] as $xp) {
    $battle = Combat::calculate(['a' => $group(209, 1, $xp)], ['d' => $group($id, 1000000000, $xp)], [], 1,
        static fn(int $lo, int $hi): int => $lo === 80 ? 100 : $lo);
    foreach (Combat::experienceInputs($battle) as $input) Experience::calculateRewards(...$input);
}
echo "OK optimization 90 tiny attacker vs billion-unit Vars groups including fractional XP\n";
