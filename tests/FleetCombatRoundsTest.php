<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Game\Services\FleetCombatService as Combat;

function roundAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function roundGroup(int $count = 20, float $attack = 120, float $hull = 1000, float $shield = 100): array
{
    return ['type' => 204, 'kind' => 'fleet', 'count' => $count, 'attack' => $attack,
        'hull' => $hull, 'shield' => $shield, 'unitCost' => 4000, 'experience' => 0];
}

function roundRandom(int $seed): Closure
{
    $random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
    return static fn($min, $max) => $random->getInt($min, $max);
}

$tests = [];
$tests['500 seeded full and serialized round-by-round battles are identical'] = static function (): void {
    for ($seed = 0; $seed < 500; ++$seed) {
        $a = ['a' => roundGroup(100 + $seed % 17), 'b' => roundGroup(5, 700)];
        $d = ['d' => roundGroup(75), 'e' => roundGroup(20, 300, 2000)];
        $expected = Combat::calculate($a, $d, roll: roundRandom($seed));
        $state = Combat::startBattle($a, $d);
        $roll = roundRandom($seed);
        while (!Combat::isFinished($state)) {
            $before = $state;
            $next = Combat::calculateRound($state, $roll);
            roundAssert(count($next['rounds']) === count($state['rounds']) + 1, 'Один вызов — один раунд.');
            roundAssert($state === $before, 'Вход не мутируется.');
            $state = json_decode(json_encode($next, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
        }
        roundAssert(Combat::getResult($state) === $expected, 'Расчёт после сериализации отличается, seed=' . $seed);
    }
};
$tests['reinforcements preserve existing hull ledger and round limit'] = static function (): void {
    $roll = static fn($min, $max) => $min === 80 ? 100 : $min;
    $state = Combat::calculateRound(Combat::startBattle(['a' => roundGroup(1)], ['d' => roundGroup(1)]), $roll);
    $reinforced = Combat::addReinforcements($state, 'attacker', ['new' => roundGroup(3)]);
    roundAssert($reinforced['a']['a'] === $state['a']['a'], 'Корпус, щит и поправки старой группы не сбрасываются.');
    roundAssert($reinforced['damageA'] === $state['damageA'] && count($reinforced['rounds']) === 1, 'Ledger и номер раунда сохраняются.');
    roundAssert($reinforced['a']['new']['before'] === 3 && $reinforced['roundLimit'] === 10, 'Подкрепление не начинает новый бой.');
    $reinforced = Combat::calculateRound($reinforced, $roll);
    roundAssert(isset($reinforced['rounds'][1]['shotsAttacker']['new']), 'Подкрепление стреляет со следующего раунда.');
    roundAssert(!isset($reinforced['rounds'][0]['attacker']['new']), 'Исторический раунд не переписывается.');
};
$tests['repeated groups invalid roll and completed battle do not mutate inputs'] = static function (): void {
    $state = Combat::startBattle(['a' => roundGroup()], ['d' => roundGroup()]);
    $before = $state;
    foreach ([static fn() => Combat::addReinforcements($state, 'attacker', ['a' => roundGroup()]),
        static fn() => Combat::calculateRound($state, static fn() => -1),
        static fn() => Combat::getResult($state)] as $operation) {
        $rejected = false;
        try { $operation(); } catch (Throwable) { $rejected = true; }
        roundAssert($rejected && $state === $before, 'Некорректный вызов отклоняется без мутации.');
    }
    $finished = Combat::startBattle([], ['d' => roundGroup()]);
    roundAssert(Combat::calculateRound($finished) === $finished, 'Завершённый бой не делает лишних выстрелов.');
};

foreach ($tests as $name => $test) {
    $test();
    echo "OK rounds {$name}\n";
}
