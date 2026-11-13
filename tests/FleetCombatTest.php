<?php

declare(strict_types=1);

// Чистый production-калькулятор, без запуска Swoole/MySQL и игровых команд.
require __DIR__ . '/bootstrap.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use SPGame\Game\Services\FleetCombatService as Combat;
use SPGame\Game\Services\FleetExperienceService as Experience;

function combatAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function combatNear(float $actual, float $expected, string $message): void
{
    combatAssert(abs($actual - $expected) <= max(1e-7, abs($expected) * 1e-10), "{$message}: {$actual} != {$expected}");
}

function combatGroup(int $type = 204, int $count = 1, float $attack = 0, float $hull = 1000,
    float $shield = 0, string $kind = 'fleet', float $experience = 0, float $cost = 10000): array
{
    return ['type' => $type, 'count' => $count, 'attack' => $attack, 'hull' => $hull,
        'shield' => $shield, 'kind' => $kind, 'experience' => $experience, 'unitCost' => $cost];
}

function combatFixed(int $min, int $max): int
{
    return $min === 80 && $max === 120 ? 100 : $min;
}

function combatSeed(int $seed): Closure
{
    $random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
    return static fn(int $min, int $max): int => $random->getInt($min, $max);
}

function combatRejects(callable $callback, string $class = InvalidArgumentException::class): void
{
    try { $callback(); }
    catch (Throwable $e) {
        if ($e instanceof $class) return;
        throw $e;
    }
    throw new RuntimeException('Ожидалось отклонение некорректного расчёта.');
}

$tests = [];
$tests['hull damage accumulates and shield regenerates'] = static function (): void {
    $a = ['a' => combatGroup(attack: 220)];
    $d = ['d' => combatGroup(type: 202)];
    $battle = Combat::calculate($a, $d, roll: 'combatFixed');
    combatAssert(count($battle['rounds']) === 5 && $battle['outcome'] === 'attacker', 'Корпус должен разрушиться за 5 раундов.');
    combatNear($battle['rounds'][1]['defender']['d']['hull'], 560, 'Повреждение переносится.');
    combatNear($battle['attacker']['hullDamage']['a']['d'], 1000, 'Избыточный урон не учитывается.');
    $a['a']['attack'] = 120;
    $d['d']['shield'] = 100;
    $battle = Combat::calculate($a, $d, maxRounds: 2, roll: 'combatFixed');
    combatNear($battle['defender']['groups']['d']['hullRemaining'], 960, 'Щиты восстанавливаются, корпус нет.');
    $a['a']['attack'] = 80;
    $battle = Combat::calculate($a, $d, roll: 'combatFixed');
    combatNear($battle['defender']['groups']['d']['hullRemaining'], 1000, 'Непробиваемый щит не даёт урона корпусу.');
    combatAssert($battle['attacker']['hullDamage'] === [], 'Щитовой урон не считается вкладом.');
};

$tests['100 ships fire two packets of 50 and may select same group'] = static function (): void {
    foreach ([[1, 1], [1, 100]] as $tickets) {
        $calls = 0;
        $roll = static function (int $min, int $max) use ($tickets, &$calls): int {
            return $min === 80 ? 100 : $tickets[$calls++];
        };
        $battle = Combat::calculate(['s' => combatGroup(count: 100, attack: 1)],
            ['a' => combatGroup(count: 50, hull: 1000000), 'b' => combatGroup(count: 50, hull: 1000000)],
            [204 => [204 => 3]], 1, $roll);
        $shots = $battle['rounds'][0]['shotsAttacker']['s'];
        combatAssert($calls === 2 && $battle['packets'] === 2, 'Выбор должен повторяться после 50 стреляющих кораблей.');
        combatNear(array_sum($shots), 300, 'RF=3 даёт 300 выстрелов от 100 кораблей.');
        combatNear($shots['a'], $tickets[1] === 1 ? 300 : 150, 'Повторный выбор той же группы допустим.');
    }
};

$tests['target selection is weighted by living ship count'] = static function (): void {
    $hits = 0;
    $roll = combatSeed(20261016);
    for ($i = 0; $i < 4000; ++$i) {
        $battle = Combat::calculate(['s' => combatGroup(attack: 1)],
            ['a' => combatGroup(count: 1), 'b' => combatGroup(count: 9)], maxRounds: 1, roll: $roll);
        $hits += isset($battle['rounds'][0]['shotsAttacker']['s']['b']) ? 1 : 0;
    }
    combatAssert($hits > 3480 && $hits < 3720, 'Группа 9 должна выбираться примерно в 90%, не 50% случаев.');
};

$tests['dead groups cannot be selected again'] = static function (): void {
    $battle = Combat::calculate(['s' => combatGroup(count: 3, attack: 1000)],
        ['a' => combatGroup(hull: 100), 'b' => combatGroup(hull: 10000)], maxRounds: 1, roll: 'combatFixed');
    $shots = $battle['rounds'][0]['shotsAttacker']['s'];
    combatNear($shots['a'], 1, 'Уничтоженная группа исключается из выбора.');
    combatNear($shots['b'], 2, 'Остаток залпа направляется в живую группу.');
};

$tests['dome intercepts defense with RF of selected target not dome'] = static function (): void {
    $a = ['s' => combatGroup(attack: 10)];
    $d = ['a-guns' => combatGroup(type: 401, count: 50, kind: 'defense'),
        'z-dome' => combatGroup(type: 407, hull: 2000, shield: 2000, kind: 'defense')];
    $rf = [204 => [401 => 10, 407 => 2]];
    $battle = Combat::calculate($a, $d, $rf, 1, 'combatFixed');
    combatNear($battle['defender']['groups']['z-dome']['shieldRemaining'], 1900, 'Огонь по орудию сохраняет RF=10 при перехвате.');
    combatNear($battle['defender']['groups']['a-guns']['hullRemaining'], 50000, 'Орудия защищены.');
    $direct = Combat::calculate($a, $d, $rf, 1,
        static fn(int $min, int $max): int => $min === 80 ? 100 : $max);
    combatNear($direct['defender']['groups']['z-dome']['shieldRemaining'], 1980, 'Сам купол выбирается целью с собственным RF=2.');
};

$tests['dome protects defense but not fleet or planetary ships'] = static function (): void {
    foreach (['fleet', 'planet'] as $kind) {
        $battle = Combat::calculate(['s' => combatGroup(attack: 300)],
            ['a-ship' => combatGroup(hull: 100, kind: $kind),
                'z-dome' => combatGroup(type: 407, kind: 'defense', shield: 2000)], maxRounds: 1, roll: 'combatFixed');
        combatAssert($battle['defender']['groups']['a-ship']['after'] === 0, 'Купол не прикрывает корабли.');
        combatNear($battle['defender']['groups']['z-dome']['shieldRemaining'], 2000, 'Огонь по кораблю не попадает в купол.');
    }
};

$tests['dome chain passes residual damage and has no attack'] = static function (): void {
    $battle = Combat::calculate(['s' => combatGroup(count: 10, attack: 140)],
        ['a-guns' => combatGroup(type: 401, count: 10, hull: 100, kind: 'defense'),
            'b-small' => combatGroup(type: 407, attack: 1, hull: 50, shield: 50, kind: 'defense'),
            'c-large' => combatGroup(type: 408, attack: 1, hull: 200, shield: 100, kind: 'defense')],
        maxRounds: 1, roll: 'combatFixed');
    combatAssert($battle['outcome'] === 'attacker', 'Остаток пробивает большой, малый купол, затем орудия.');
    $damage = $battle['attacker']['hullDamage']['s'];
    combatNear($damage['c-large'], 200, 'Корпус большого купола.');
    combatNear($damage['b-small'], 50, 'Корпус малого купола.');
    combatNear($damage['a-guns'], 1000, 'Корпус орудий после пробития.');
    combatAssert($battle['defender']['hullDamage'] === [], 'Купола не стреляют технической атакой 1.');
};

$tests['both sides fire from round start including killed groups'] = static function (): void {
    $battle = Combat::calculate(['s' => combatGroup(attack: 2000)],
        ['d' => combatGroup(attack: 2000)], roll: 'combatFixed');
    combatAssert($battle['outcome'] === 'draw' && count($battle['rounds']) === 1, 'Взаимное уничтожение — ничья.');
    combatAssert($battle['attacker']['groups']['s']['after'] === 0 && $battle['defender']['groups']['d']['after'] === 0,
        'Уничтоженный в раунде защитник успевает ответить.');
};

$tests['empty sides unarmed groups and round limits'] = static function (): void {
    combatAssert(Combat::calculate([], [])['outcome'] === 'draw', 'Обе стороны пустые.');
    combatAssert(Combat::calculate(['s' => combatGroup()], [])['outcome'] === 'attacker', 'Пустая оборона.');
    combatAssert(Combat::calculate([], ['d' => combatGroup()])['outcome'] === 'defender', 'Пустая атака.');
    $battle = Combat::calculate(['s' => combatGroup()], ['d' => combatGroup()]);
    combatAssert($battle['reason'] === 'no_attack' && $battle['rounds'] === [], 'Безоружные стороны не создают цикл.');
    $battle = Combat::calculate(['s' => combatGroup(attack: 1)], ['d' => combatGroup()], maxRounds: 3, roll: 'combatFixed');
    combatAssert(count($battle['rounds']) === 3 && $battle['reason'] === 'round_limit', 'Лимит раундов соблюдается.');
};

$tests['contribution follows actual hull damage not shields or last hit'] = static function (): void {
    $battle = Combat::calculate(['a' => combatGroup(attack: 200), 'b' => combatGroup(attack: 100)],
        ['d' => combatGroup(hull: 1000, shield: 100)], maxRounds: 5, roll: 'combatFixed');
    combatAssert($battle['outcome'] === 'attacker', 'Пример должен завершиться уничтожением цели.');
    $input = Combat::experienceInputs($battle)['attacker'];
    combatNear(array_sum(array_column($input['groups'], 'contribution')), 10000, 'Сумма вкладов равна уничтоженной цене.');
    $ledger = $battle['attacker']['hullDamage'];
    foreach ($input['groups'] as $id => $group) {
        combatNear($group['contribution'], 10000 * ($ledger[$id]['d'] ?? 0) / 1000, 'Вклад пропорционален ограниченному урону корпусу.');
    }
    $reward = Experience::calculateRewards(...$input);
    combatNear($reward['budget'], 0, 'Безоружная жертва не даёт опыта.');
};

$tests['defense recovery projection excludes restored units from XP'] = static function (): void {
    $battle = Combat::calculate(['s' => combatGroup(attack: 10000)],
        ['d' => combatGroup(type: 401, count: 10, attack: 1, hull: 100, kind: 'defense', cost: 2000)], roll: 'combatFixed');
    $raw = Combat::experienceInputs($battle);
    $repaired = Combat::experienceInputs($battle, ['defender' => ['d' => 7]]);
    combatNear($raw['attacker']['destroyedValue'], 20000, 'Потери до восстановления.');
    combatNear($repaired['attacker']['destroyedValue'], 6000, 'Только 3 невосстановленные единицы.');
    combatNear($repaired['attacker']['groups']['s']['contribution'], 6000, 'Восстановление корректирует вклад.');
    combatAssert($repaired['defender']['groups']['d']['after'] === 7 && !$repaired['defender']['groups']['d']['eligible'],
        'Оборона восстановлена в projection, но опыта не получает.');
    foreach ($repaired as $input) Experience::calculateRewards(...$input);
    foreach ([['defender' => ['d' => 11]], ['defender' => ['d' => -1]], ['defender' => ['d' => 1.0]],
        ['attacker' => ['s' => 1]], ['defender' => ['missing' => 1]], ['unknown' => []]] as $bad) {
        combatRejects(fn() => Combat::experienceInputs($battle, $bad));
    }
};

$tests['large counts and rapidfire do not iterate over ships or RF shots'] = static function (): void {
    $a = ['s' => combatGroup(count: 1000000000, attack: 1)];
    $d = ['d' => combatGroup(hull: 1e18)];
    $battle = Combat::calculate($a, $d, [204 => [204 => 1250]], 1, 'combatFixed');
    combatAssert($battle['packets'] === 1, 'Миллиард повторных выборов единственной цели объединяется.');
    combatNear($battle['rounds'][0]['shotsAttacker']['s']['d'], 1250000000000, 'RF применяется арифметически.');
    $battle = Combat::calculate($a, ['a' => combatGroup(hull: 1), 'b' => combatGroup(hull: 1)],
        maxRounds: 1, roll: 'combatFixed');
    combatAssert($battle['packets'] === 2 && $battle['outcome'] === 'attacker', 'Живые малые цели исключаются после гибели.');
};

$tests['single target batching stops firing on death and matches packet reference'] = static function (): void {
    $battle = Combat::calculate(['s' => combatGroup(count: 100, attack: 100)],
        ['d' => combatGroup(hull: 100)], maxRounds: 1, roll: 'combatFixed');
    combatNear($battle['rounds'][0]['shotsAttacker']['s']['d'], 1, 'После первого смертельного пакета остальные не стреляют.');
    $fractional = Combat::calculate(['s' => combatGroup(count: 2, attack: .3)],
        ['d' => combatGroup(hull: .1, shield: .2)], maxRounds: 1, roll: 'combatFixed');
    combatNear($fractional['rounds'][0]['shotsAttacker']['s']['d'], 1, '0.1+0.2 не должно создавать лишний смертельный пакет.');
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(125010));
    for ($i = 0; $i < 2000; ++$i) {
        $remaining = $rng->getInt(1, 1000);
        $n = $rng->getInt(1, 50);
        $unitHull = $rng->getInt(1, 1000);
        $hull = (float)($n * $unitHull);
        $unitShield = $rng->getInt(0, 1000);
        $shield = (float)($n * $unitShield);
        $attack = $rng->getInt(1, 100);
        $rf = $rng->getInt(1, 10);
        $battle = Combat::calculate(['s' => combatGroup(count: $remaining, attack: $attack)],
            ['d' => combatGroup(type: 202, count: $n, hull: $unitHull, shield: $unitShield)],
            [204 => [202 => $rf]], 1, 'combatFixed');
        $shots = 0;
        // Простая неускоренная спецификация только для малых тестовых чисел.
        while ($remaining > 0 && $n > 0) {
            $firing = min($remaining, $n);
            $remaining -= $firing;
            $shots += $firing * $rf;
            $damage = $firing * $rf * $attack;
            $absorbed = min($shield, $damage);
            $shield -= $absorbed;
            $hull = max(0.0, $hull - ($damage - $absorbed));
            $n = (int)ceil($hull / $unitHull);
        }
        $actual = $battle['defender']['groups']['d'];
        combatAssert($actual['after'] === $n, 'Объединение не меняет потери пакетов.');
        combatNear($actual['hullRemaining'], $hull, 'Объединение не меняет корпус.');
        combatNear($actual['shieldRemaining'], $shield, 'Объединение не меняет щиты.');
        combatNear($battle['rounds'][0]['shotsAttacker']['s']['d'], $shots, 'Объединение не создаёт выстрелов после гибели.');
    }
};

$tests['work budget accounts for thousands of target groups'] = static function (): void {
    $groups = [];
    for ($i = 0; $i < 4000; ++$i) $groups[$i] = combatGroup(attack: 1);
    $targetSelections = 0;
    $roll = static function (int $min, int $max) use (&$targetSelections): int {
        if ($min === 1) ++$targetSelections;
        return combatFixed($min, $max);
    };
    $battle = Combat::calculate($groups, $groups, maxRounds: 1, roll: $roll);
    combatAssert($targetSelections === 8000 && $battle['targetVisits'] < Combat::MAX_TARGET_VISITS,
        'Дерево должно обслужить 4000 групп на сторону без полного скана на каждый выбор.');
    // Непробиваемые группы живут все раунды: длинный перебор всё ещё ограничен.
    foreach ($groups as &$group) $group['hull'] = 1e12;
    unset($group);
    try {
        Combat::calculate($groups, $groups, maxRounds: 20, roll: $roll);
        throw new LogicException('Неограниченная работа должна быть отклонена.');
    } catch (RuntimeException $e) {
        combatAssert(str_contains($e->getMessage(), 'перебора'), 'Ожидался лимит работы, не случайная ошибка.');
    }
    combatAssert($targetSelections < Combat::MAX_PACKETS + 8000, 'Защитный предел работы сохранён.');
};

$tests['pathological packet budget fails without mutating caller snapshot'] = static function (): void {
    $a = ['s' => combatGroup(count: Combat::MAX_PACKETS + 1, attack: 1)];
    $d = ['a' => combatGroup(hull: 1e12), 'b' => combatGroup(hull: 1e12)];
    $original = [$a, $d];
    combatRejects(fn() => Combat::calculate($a, $d, maxRounds: 1, roll: 'combatFixed'), RuntimeException::class);
    combatAssert([$a, $d] === $original, 'Отклонённый расчёт не меняет runtime входные данные.');
};

$tests['invalid snapshots and rolls fail before persistence'] = static function (): void {
    foreach ([['count' => -1], ['count' => '1'], ['count' => 1.0], ['count' => true], ['type' => 0],
        ['hull' => 0], ['attack' => INF], ['shield' => NAN], ['unitCost' => 0], ['kind' => 'unknown'],
        ['experience' => 1001], ['kind' => 'planet', 'experience' => 1], ['type' => 407],
        ['count' => 0, 'experience' => 1]] as $fields) {
        combatRejects(fn() => Combat::calculate([array_replace(combatGroup(), $fields)], []));
    }
    foreach ([0, 21] as $rounds) combatRejects(fn() => Combat::calculate([], [], maxRounds: $rounds));
    foreach ([NAN, -1, 1.5, '3'] as $shots) combatRejects(fn() => Combat::calculate([], [], [204 => [202 => $shots]]));
    combatRejects(fn() => Combat::calculate(['s' => combatGroup(attack: 1)], ['d' => combatGroup()], roll: fn() => 1000));
    combatRejects(fn() => Combat::calculate(['s' => combatGroup(attack: PHP_FLOAT_MAX, count: 100)], []));
    // Ошибка .5 для единичного корпуса способна создать ложную потерю корабля.
    // В отличие от малого поправочного хвоста большого корпуса это запрещено.
    combatRejects(fn() => Combat::calculate(['s' => combatGroup(attack: .5)],
        ['d' => combatGroup(count: 9007199254740991, hull: 1)], maxRounds: 1, roll: 'combatFixed'));
};

$tests['small damage in huge hull pool is retained rather than discarded'] = static function (): void {
    $battle = Combat::calculate(['s' => combatGroup(attack: 100)],
        ['d' => combatGroup(hull: 1e18)], maxRounds: 1, roll: 'combatFixed');
    combatAssert($battle['defender']['groups']['d']['after'] === 1, 'Один малый удар не уничтожает огромный корабль.');
    combatNear($battle['attacker']['hullDamage']['s']['d'], 100, 'Ledger сохраняет фактический урон, не квантование float.');
    combatNear(Combat::experienceInputs($battle)['attacker']['destroyedValue'], 0, 'Повреждение выжившего не создаёт опыт.');
};

$tests['fractional experience stats remain usable with large practical pools'] = static function (): void {
    $groups = [];
    foreach ([204 => [1, 50, 400, 10, 4000], 214 => [100000, 200000, 900000, 50000, 9000000]]
        as $type => [$n, $attack, $hull, $shield, $cost]) {
        $groups[$type] = Experience::combatStats(['attack' => $attack, 'hull' => $hull, 'shield' => $shield],
            ['attack' => 1, 'hull' => 1, 'shield' => 1], 75)
            + ['type' => $type, 'count' => $n, 'kind' => 'fleet', 'experience' => $n * 75.0, 'unitCost' => $cost];
    }
    $battle = Combat::calculate(['a' => $groups[204]], ['d' => $groups[214]], maxRounds: 1, roll: 'combatFixed');
    combatAssert($battle['outcome'] === 'defender', 'Дробный XP-бонус не должен делать обычный большой пул непригодным.');
    foreach (Combat::experienceInputs($battle) as $input) Experience::calculateRewards(...$input);
};

$tests['seeded calculation is repeatable and does not mutate inputs'] = static function (): void {
    $a = ['fleet:1:204' => combatGroup(count: 20, attack: 50, hull: 400, shield: 10),
        'fleet:2:205' => combatGroup(type: 205, count: 10, attack: 150, shield: 25)];
    $d = ['planet:204' => combatGroup(count: 40, attack: 50, hull: 400, shield: 10, kind: 'planet')];
    $old = [$a, $d];
    $one = Combat::calculate($a, $d, roll: combatSeed(123));
    $two = Combat::calculate($a, $d, roll: combatSeed(123));
    combatAssert($one === $two && [$a, $d] === $old, 'Одинаковый RNG даёт тот же чистый результат.');
    $reverse = Combat::calculate(array_reverse($a, true), $d, roll: combatSeed(123));
    combatAssert($one === $reverse, 'Порядок входных ключей не задаёт очередь стрельбы.');
};

$tests['1000 mixed seeded battles conserve damage losses and XP budget'] = static function (): void {
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(16102026));
    for ($case = 0; $case < 1000; ++$case) {
        $sides = [];
        foreach (['attacker', 'defender'] as $side) {
            $groups = [];
            for ($i = 0, $n = $rng->getInt(1, 5); $i < $n; ++$i) {
                $count = $rng->getInt(1, 100);
                $kind = $side === 'attacker' ? 'fleet' : ['fleet', 'planet', 'defense'][$rng->getInt(0, 2)];
                $groups['g' . $i] = combatGroup(type: $kind === 'defense' ? 401 : 204,
                    count: $count, attack: $rng->getInt(0, 1000), hull: $rng->getInt(100, 10000),
                    shield: $rng->getInt(0, 1000), kind: $kind,
                    experience: $kind === 'fleet' ? $count * $rng->getInt(0, 1000) : 0);
            }
            $sides[$side] = $groups;
        }
        $battle = Combat::calculate($sides['attacker'], $sides['defender'], [204 => [401 => 5]], roll: combatSeed($case));
        foreach (['attacker' => 'defender', 'defender' => 'attacker'] as $side => $enemy) {
            foreach ($battle[$side]['groups'] as $id => $g) {
                combatAssert($g['after'] >= 0 && $g['after'] <= $g['before'], 'Потери должны быть целыми в допустимом диапазоне.');
                $damage = 0.0;
                foreach ($battle[$enemy]['hullDamage'] as $ledger) $damage += $ledger[$id] ?? 0;
                combatNear($damage, $g['before'] * $g['hull'] - $g['hullRemaining'], 'Учёт фактического урона корпусу.');
            }
        }
        foreach (Combat::experienceInputs($battle) as $input) {
            combatNear(array_sum(array_column($input['groups'], 'contribution')), $input['destroyedValue'], 'Сохранение уничтоженной стоимости.');
            $reward = Experience::calculateRewards(...$input);
            combatAssert($reward['awardedValue'] <= $reward['budget'] + max(1e-5, $reward['budget'] * 1e-10), 'Нельзя раздуть XP.');
        }
    }
};

foreach ($tests as $name => $test) {
    $test();
    echo "OK combat {$name}\n";
}
