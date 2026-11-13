<?php

declare(strict_types=1);

// Повторяемая калибровка чистого калькулятора по read-only snapshot текущих Vars.
require __DIR__ . '/bootstrap.php';

use SPGame\Game\Services\FleetCombatService as Combat;
use SPGame\Game\Services\FleetExperienceService as Experience;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/combat-vars-20261016.json'), true, flags: JSON_THROW_ON_ERROR);
$units = array_column($fixture['units'], null, 'id');
$rapidfire = [];
foreach ($fixture['rapidfire'] as $row) $rapidfire[$row['elementID']][$row['rapidfireID']] = $row['shoots'];

function balanceGroups(array $composition, bool $planetary = false, float $experience = 0): array
{
    global $units;
    $groups = [];
    foreach ($composition as $type => $count) {
        $unit = $units[$type];
        $defense = $type >= 400;
        $stats = Experience::combatStats(['attack' => $unit['attack'],
            'hull' => $unit['unitCost'] / 10, 'shield' => $unit['shield']],
            ['attack' => 1, 'hull' => 1, 'shield' => 1], $defense || $planetary ? 0 : $experience);
        $groups['g' . $type] = $stats + ['type' => $type, 'count' => $count, 'unitCost' => $unit['unitCost'],
            'kind' => $defense ? 'defense' : ($planetary ? 'planet' : 'fleet'),
            'experience' => $defense || $planetary ? 0 : $count * $experience];
    }
    return $groups;
}

function balanceRemaining(array $side): int
{
    return array_sum(array_column($side['groups'], 'after'));
}

// Минимальная проверка длительности: у одинаковых ЛИ при 100% уроне корпус
// 400 / (атака 50 - щит 10) требует 10 залпов. На 6/8 бой ещё не завершён.
foreach ([6, 8, 10] as $limit) {
    $battle = Combat::calculate(balanceGroups([204 => 1]), balanceGroups([204 => 1]),
        $rapidfire, $limit, static fn(int $min, int $max): int => $min === 80 ? 100 : $min);
    $expected = $limit < 10 ? 1 : 0;
    if (balanceRemaining($battle['attacker']) !== $expected || balanceRemaining($battle['defender']) !== $expected) {
        throw new RuntimeException('Не совпала калибровка симметричного боя лёгких истребителей.');
    }
}
if (Combat::DEFAULT_ROUNDS !== 10) throw new RuntimeException('Нужно пересмотреть исходную калибровку числа раундов.');
echo "OK balance default 10: symmetric fighter duel reaches destruction, 6/8 only damage\n";

$cases = [
    'fighters_equal' => [[204 => 100], [204 => 100]],
    'cruisers_vs_fighters' => [[206 => 20], [204 => 100]],
    'battleships_vs_fighters' => [[207 => 66], [204 => 1000]],
    'bombers_vs_domed_defense' => [[211 => 10], [401 => 200, 407 => 1]],
    'mixed_fleets' => [[204 => 100, 206 => 20, 207 => 10], [205 => 40, 215 => 15]],
    'equal_battlecruisers' => [[215 => 10], [215 => 10]],
];
$runs = 0;
foreach ($cases as $name => [$attacker, $defender]) {
    $summary = [];
    foreach ([6, 8, 10, 12] as $limit) {
        $outcomes = ['attacker' => 0, 'defender' => 0, 'draw' => 0];
        $lost = 0.0;
        for ($seed = 1; $seed <= 100; ++$seed) {
            $rng = new Random\Randomizer(new Random\Engine\Mt19937($seed));
            $battle = Combat::calculate(balanceGroups($attacker), balanceGroups($defender, true),
                $rapidfire, $limit, static fn(int $min, int $max): int => $rng->getInt($min, $max));
            ++$outcomes[$battle['outcome']];
            $value = $destroyed = 0.0;
            foreach (['attacker', 'defender'] as $side) {
                foreach ($battle[$side]['groups'] as $group) {
                    $value += $group['before'] * $group['unitCost'];
                    $destroyed += ($group['before'] - $group['after']) * $group['unitCost'];
                }
            }
            $lost += $destroyed / $value;
            foreach (Combat::experienceInputs($battle) as $input) Experience::calculateRewards(...$input);
            ++$runs;
        }
        $summary[$limit] = $outcomes + ['lostPercent' => round($lost, 2)];
    }
    echo 'BALANCE ', $name, ' ', json_encode($summary, JSON_UNESCAPED_UNICODE), "\n";
}
echo "OK balance {$runs} seeded battles with actual Vars snapshot and rapidfire\n";
