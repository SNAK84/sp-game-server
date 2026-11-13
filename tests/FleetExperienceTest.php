<?php

declare(strict_types=1);

// Чистые production-формулы; MySQL/Swoole и игровые команды не запускаются.
require __DIR__ . '/bootstrap.php';

use SPGame\Game\Fleet\FleetShip;
use SPGame\Game\Services\FleetExperienceService as Experience;

function experienceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function experienceNear(float $actual, float $expected, string $message): void
{
    experienceAssert(abs($actual - $expected) <= max(1e-7, abs($expected) * 1e-10), $message . ": {$actual} != {$expected}");
}

function experienceRejects(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException|TypeError $exception) {
        return;
    }
    throw new RuntimeException('Некорректный snapshot должен быть отклонён.');
}

function experienceGroup(int $before = 100, ?int $after = null, float $experience = 0.0,
    float $cost = 4000.0, float $contribution = 400000.0, bool $eligible = true): array
{
    return ['before' => $before, 'after' => $after ?? $before, 'experience' => $experience,
        'unitCost' => $cost, 'contribution' => $contribution, 'eligible' => $eligible];
}

$tests = [];
$tests['average and bounded bonuses'] = static function (): void {
    experienceNear(Experience::average(100, 7500), 75, 'Суммарный опыт нужно делить на количество.');
    experienceNear(Experience::average(0, 0), 0, 'Пустая группа.');
    foreach ([0 => 0.0, 25 => .022, 75 => .047142857142857, 150 => .066, 300 => .0825,
        600 => .094285714285714, 1000 => .1, 10000 => .1] as $xp => $attack) {
        $bonuses = Experience::bonuses((float)$xp);
        experienceNear($bonuses['attack'], $attack, 'Атака должна соответствовать кривой.');
        experienceNear($bonuses['hull'], $attack, 'Корпус должен соответствовать кривой.');
        experienceNear($bonuses['shield'], $attack / 2, 'Бонус щита вдвое меньше.');
    }
    $previous = 0.0;
    $previousStep = INF;
    for ($xp = 1; $xp <= 1000; ++$xp) {
        $bonus = Experience::bonuses((float)$xp)['attack'];
        experienceAssert($bonus > $previous && $bonus - $previous < $previousStep,
            'Бонус растёт с убывающей отдачей.');
        $previousStep = $bonus - $previous;
        $previous = $bonus;
    }
};

$tests['technology additive and no fleet multiplier'] = static function (): void {
    foreach ([0, 10, 20] as $level) {
        $factor = 1 + .1 * $level;
        $base = ['attack' => 100, 'hull' => 400, 'shield' => 10];
        $factors = array_fill_keys(array_keys($base), $factor);
        $rookie = Experience::combatStats($base, $factors, 0);
        $veteran = Experience::combatStats($base, $factors, 1000);
        experienceNear($veteran['attack'] - $rookie['attack'], 10, 'Не умножать технологический бонус.');
        experienceNear($veteran['hull'] - $rookie['hull'], 40, 'Один бонус корпуса.');
        experienceNear($veteran['shield'] - $rookie['shield'], .5, 'Один бонус щита.');
    }
    experienceNear(Experience::combatStats($base, array_fill_keys(array_keys($base), 2), 1000)['attack'],
        210, 'Не 220 и не повторный множитель опыта флота.');
};

$tests['fleet average is weighted by base price'] = static function (): void {
    $groups = [
        ['count' => 1, 'experience' => 0, 'unitCost' => 9000000],
        ['count' => 1000, 'experience' => 1000000, 'unitCost' => 1000],
    ];
    experienceNear(Experience::fleetAverage($groups), 100, 'Зонды не должны перевешивать звезду смерти по количеству.');
    experienceNear(Experience::fleetAverage([]), 0, 'Пустой флот.');
    experienceNear(Experience::fleetAverage([['count' => 0, 'experience' => 0]]), 0, 'Нет деления на ноль.');
};

$tests['threat and weak target penalty'] = static function (): void {
    experienceNear(Experience::sidePower(100, 300, 100), 200, 'Оценка мощности.');
    experienceNear(Experience::sidePower(0, 300, 100), 0, 'Безоружная сторона.');
    foreach ([1 => 20, 2 => 2.5, 3 => 20/27, 10 => .02] as $ratio => $expected) {
        $result = Experience::calculateRewards(['fleet:1:204' => experienceGroup(100*$ratio)], 1000*$ratio, 1000, 400000);
        experienceNear($result['groups']['fleet:1:204']['gainPerShip'], $expected, 'Награда за слабую цель.');
    }
};

$tests['no kill, unarmed enemy and own zero power'] = static function (): void {
    $noKill = Experience::calculateRewards([experienceGroup(contribution: 0)], 1000, 1000, 0);
    $unarmed = Experience::calculateRewards([experienceGroup()], 1000, 0, 400000);
    $ownZero = Experience::calculateRewards([experienceGroup()], 0, 1000, 400000);
    foreach ([$noKill, $unarmed, $ownZero] as $result) {
        experienceNear($result['groups'][0]['gained'], 0, 'Награда должна быть нулевой.');
    }
};

$tests['empty side and zero count group'] = static function (): void {
    foreach ([[], [experienceGroup(0, 0, contribution: 0)]] as $groups) {
        $result = Experience::calculateRewards($groups, 0, 0, 0);
        experienceNear($result['budget'], 0, 'У пустой стороны нет бюджета.');
        experienceNear($result['awardedValue'], 0, 'У пустой стороны нет награды.');
        foreach ($result['groups'] as $group) experienceNear($group['average'], 0, 'Нет деления на ноль.');
    }
};

$tests['experience losses and survivors do not inherit dead share'] = static function (): void {
    $input = experienceGroup(experience: 10000);
    $full = Experience::calculateRewards([$input], 1000, 1000, 400000)['groups'][0];
    $partial = Experience::calculateRewards([array_replace($input, ['after' => 40])], 1000, 1000, 400000)['groups'][0];
    experienceNear($partial['retained'], 4000, 'Сохраняется 40% исходного опыта.');
    experienceNear($partial['lost'], 6000, 'Опыт погибших теряется.');
    experienceNear($partial['gained'], 800, 'Опыт выдаётся только выжившим.');
    experienceNear($partial['average'], $full['average'], 'Потери не концентрируют опыт.');
    $dead = Experience::calculateRewards([array_replace($input, ['after' => 0])], 1000, 1000, 400000)['groups'][0];
    experienceAssert($dead['experience'] === 0.0 && $dead['gainPerShip'] === 0.0, 'Уничтоженная группа не получает XP.');
};

$tests['no redistribution of planetary or destroyed group reward'] = static function (): void {
    $groups = ['fleet' => experienceGroup(10, contribution: 20000),
        'planet' => experienceGroup(90, contribution: 180000, eligible: false)];
    $result = Experience::calculateRewards($groups, 1000, 1000, 200000);
    experienceNear($result['groups']['fleet']['gainPerShip'], 10, 'Доля планеты не передаётся флоту.');
    experienceNear($result['groups']['planet']['experience'], 0, 'На планете XP не копится.');
    $groups = ['alive' => experienceGroup(50, contribution: 100000),
        'dead' => experienceGroup(50, 0, contribution: 100000)];
    $result = Experience::calculateRewards($groups, 1000, 1000, 200000);
    experienceNear($result['groups']['alive']['gainPerShip'], 10, 'Доля погибшего флота не передаётся выжившему.');
    experienceNear($result['groups']['dead']['experience'], 0, 'Погибший флот теряет долю.');
};

$tests['cap and support participation'] = static function (): void {
    $max = Experience::calculateRewards([experienceGroup(experience: 100000)], 1000, 1000, 400000)['groups'][0];
    experienceNear($max['experience'], 100000, 'Предел 1000 на корабль.');
    experienceNear($max['gained'], 0, 'Нет награды сверх предела.');
    $groups = ['warship' => experienceGroup(contribution: 800000),
        'cargo' => experienceGroup(contribution: 0)];
    $result = Experience::calculateRewards($groups, 1000, 1000, 800000);
    experienceNear($result['groups']['warship']['gainPerShip'], 20, 'Лимит даже при большом вкладе.');
    experienceNear($result['groups']['cargo']['gainPerShip'], 4, 'Небольшой опыт участия транспортов.');
};

$tests['group split does not multiply XP'] = static function (): void {
    $whole = Experience::calculateRewards([experienceGroup(100, 75, 10000, contribution: 200000)], 1000, 1000, 200000);
    $parts = Experience::calculateRewards([experienceGroup(40,30,4000,contribution:80000),
        experienceGroup(60,45,6000,contribution:120000)], 1000, 1000, 200000);
    experienceNear(array_sum(array_column($parts['groups'], 'experience')), $whole['groups'][0]['experience'],
        'Разделение флотов не должно создавать опыт.');
};

$tests['snapshot is immutable and repeat calculation is deterministic'] = static function (): void {
    $input = ['fleet:2:204' => experienceGroup()];
    $before = $input;
    $first = Experience::calculateRewards($input, 1000, 1000, 400000);
    $second = Experience::calculateRewards($input, 1000, 1000, 400000);
    experienceAssert($input === $before && $first === $second, 'Чистый расчёт не применяет результат повторно.');
    // Это не exactly-once runtime commit: marker ATTACK проверит будущий handler.
};

$tests['contribution rounding cannot increase reward budget'] = static function (): void {
    $input = [experienceGroup(100, cost: 4000, contribution: .5),
        experienceGroup(100, cost: 4000, contribution: .5000009)];
    $result = Experience::calculateRewards($input, 1000, 1000, 1);
    experienceNear($result['awardedValue'], 20, 'Допуск погрешности вклада не создаёт дополнительный XP.');
    experienceRejects(fn() => Experience::calculateRewards([experienceGroup(contribution: 0)], 1000, 1000, .0000001));
    experienceRejects(fn() => Experience::calculateRewards([experienceGroup(contribution: .0000001)], 1000, 1000, 0));
};

$tests['invalid snapshot and nonfinite values are rejected'] = static function (): void {
    foreach ([INF, NAN, -1] as $invalid) {
        experienceRejects(fn() => Experience::bonuses($invalid));
        experienceRejects(fn() => Experience::sidePower($invalid, 1, 1));
        experienceRejects(fn() => Experience::calculateRewards([experienceGroup()], $invalid, 1, 400000));
    }
    foreach ([['after' => 101], ['before' => -1], ['before' => '100'], ['before' => true],
        ['unitCost' => 0], ['unitCost' => INF], ['experience' => NAN], ['experience' => 100001],
        ['eligible' => 'yes'], ['eligible' => false, 'experience' => 1], ['contribution' => -1],
        ['contribution' => 399000], ['before' => 0, 'after' => 0, 'contribution' => 0, 'experience' => 1]] as $fields) {
        experienceRejects(fn() => Experience::calculateRewards([array_replace(experienceGroup(), $fields)], 1000, 1000, 400000));
    }
    experienceRejects(fn() => Experience::sidePower(PHP_FLOAT_MAX, PHP_FLOAT_MAX, 1));
    experienceRejects(fn() => Experience::combatStats(['attack' => INF, 'hull' => 1, 'shield' => 1],
        ['attack' => 1, 'hull' => 1, 'shield' => 1], 0));
};

$tests['FleetShip rejects nonfinite XP without callbacks or mutation'] = static function (): void {
    $changes = [];
    $ship = new FleetShip(204, 'light_hunter', 10, experience: 100,
        onChange: static function ($field, $value) use (&$changes): void { $changes[] = [$field, $value]; });
    foreach ([NAN, INF, -INF] as $invalid) {
        experienceRejects(fn() => $ship->setExperience($invalid));
    }
    experienceAssert($ship->getExperience() === 100.0 && $changes === [], 'Ошибка не должна менять runtime cache.');
    $ship->setExperience(200);
    $ship->setExperience(200);
    experienceAssert($changes === [['experience', 200.0]], 'Существующий callback вызывается только при изменении.');
};

$tests['5000 seeded combinations keep budget, caps and losses'] = static function (): void {
    mt_srand(20261016);
    for ($case = 0; $case < 5000; ++$case) {
        $groups = [];
        $damage = [];
        $destroyed = (float)mt_rand(1, 10000000);
        $size = mt_rand(1, 8);
        for ($i = 0; $i < $size; ++$i) $damage[] = mt_rand(1,1000);
        $damageTotal = array_sum($damage);
        for ($i = 0; $i < $size; ++$i) {
            $n = mt_rand(1, 1000);
            $eligible = (bool)mt_rand(0, 1);
            $groups[$i] = experienceGroup($n, mt_rand(0, $n), $eligible ? $n*mt_rand(0,100000)/100 : 0,
                (float)mt_rand(1000, 9000000), $destroyed*$damage[$i]/$damageTotal, $eligible);
        }
        $result = Experience::calculateRewards($groups, (float)mt_rand(1,1000000), (float)mt_rand(0,1000000), $destroyed);
        experienceAssert($result['awardedValue'] <= $result['budget'] + max(1e-5, $result['budget']*1e-10), 'Награда вышла за бюджет.');
        foreach ($result['groups'] as $i => $group) {
            $input = $groups[$i];
            experienceAssert(is_finite($group['experience']) && $group['experience'] >= 0
                && $group['gainPerShip'] <= 20 && $group['average'] <= 1000.0000001, 'Предел XP нарушен.');
            experienceNear($group['retained'], $input['experience']*$input['after']/$input['before'], 'Пропорциональные потери.');
        }
    }
};

foreach ($tests as $name => $test) {
    $test();
    echo "OK experience {$name}\n";
}
