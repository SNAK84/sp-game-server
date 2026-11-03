<?php

declare(strict_types=1);

// Только чистый расчёт отчёта/RNG: без Swoole Tables, БД, EventLoop и аккаунтов.
require __DIR__ . '/fixtures/FleetPlanSpyRandom.php';
require __DIR__ . '/bootstrap.php';

$method = new ReflectionMethod(SPGame\Game\Services\FleetSpyService::class, 'report');
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$report = static fn() => $method->invoke(null, ['resources' => []], 1.0, 1, 1, null);

$destroyed = 0;
foreach (range(0, 100) as $spyRoll) {
    $result = withFleetPlanSpyRolls([0, $spyRoll], $report);
    $check($result['counterChance'] === 0 && $result['destroyed'] === ($spyRoll === 0), 'Изменён граничный исход0/' . $spyRoll);
    $check(!isset($GLOBALS['fleetPlanSpyRolls']), 'Броски остались после расчёта.');
    $destroyed += (int)$result['destroyed'];
}
$check($destroyed === 1, 'При нулевом counterLimit сохранилось не ровно одно опасное равенство.');

for ($i = 0; $i < 500; ++$i) {
    $check(withFleetPlanSpyRolls([0, 100], $report)['destroyed'] === false, 'Недетерминированный исход теста маршрута.');
}

withFleetPlanSpyRolls([0, 100], static function () use ($report, $check): void {
    $check(SPGame\Game\Services\random_int(7, 7) === 7, 'Изменён RNG вне FleetSpyService.');
    $check($GLOBALS['fleetPlanSpyRolls'] === [0, 100], 'Посторонний вызов израсходовал тестовую пару.');
    $check($report()['destroyed'] === false, 'Посторонний вызов изменил исход отчёта.');
});

foreach ([[0], [0, 101], [0, 100, 1]] as $bad) {
    $caught = false;
    try { withFleetPlanSpyRolls($bad, $report); }
    catch (RuntimeException) { $caught = true; }
    $check($caught && !isset($GLOBALS['fleetPlanSpyRolls']), 'Неверные/лишние броски не отклонены или остались после исключения.');
}
$caught = false;
try { withFleetPlanSpyRolls([0, 100], static function (): never { throw new RuntimeException('Контрольный отказ операции.'); }); }
catch (RuntimeException) { $caught = true; }
$check($caught && !isset($GLOBALS['fleetPlanSpyRolls']), 'Отказ операции оставил подмену RNG.');
$check(SPGame\Game\Services\random_int(9, 9) === 9, 'RNG не восстановлен после операции.');

echo 'RESULT spy random contract: ' . $checks . " passed; SQL=0, Tables=0\n";
