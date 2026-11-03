<?php

declare(strict_types=1);

// Диагностика возврата: отдельные настоящие Swoole Tables, SQL запрещён fixture-guard.
// Запуск: docker exec swoole_app php /var/www/html/tests/FleetRouteReturnLedgerSwooleTest.php
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Repositories\{Builds, Defenses, FleetCommands, Fleets, Messages, PlanetResources, Planets, Ships, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService, FleetCommandsService as Commands, FleetControlService as Control};

function returnLedgerCounts(int $planetId): array
{
    $row = PlanetResources::findExistingById($planetId);
    researchAssert($row !== null, 'Не найдена тестовая строка ресурсов.');
    $counts = [];
    foreach ([901, 902, 903] as $id) $counts[$id] = (float)$row[Vars::$resource[$id]];
    return $counts;
}

function returnLedgerEqual(array $expected, array $actual, string $stage): void
{
    foreach ($expected as $id => $count) {
        $epsilon = max(0.000001, abs($count) * PHP_FLOAT_EPSILON * 4);
        researchAssert(isset($actual[$id]) && abs($actual[$id] - $count) <= $epsilon,
            "$stage: ресурс $id, ожидалось $count, получено " . ($actual[$id] ?? 'нет'));
    }
}

$diagnostics = [];
foreach ([false, true] as $selectedMoon) foreach ([false, true] as $freshContext) {
    $case = ($selectedMoon ? 'selected-moon' : 'selected-home') . '/' . ($freshContext ? 'fresh-context' : 'cached-context');
    [$owner, $fleet] = nativePlanFixture();
    $fleet->setShipCount(202, 100); $fleet->save();
    Planets::add(array_replace(Planets::findExistingById(101), ['id' => 104, 'name' => 'Isolated moon', 'planet_type' => 3]));
    Builds::add(array_replace(Builds::findExistingById(101), ['id' => 104]));
    PlanetResources::add(['id' => 104, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
    Ships::add(['id' => 104]); Defenses::add(['id' => 104]);
    // Проверяется также доставка сверх ёмкости склада и точность больших DOUBLE.
    PlanetResources::update(['id' => 101, 'metal' => 2000000000, 'crystal' => 200000000000, 'deuterium' => 400000000000]);
    $owner = new AccountData(1);
    $owner->getData(101); $owner->getData(104);
    $originalContext = $owner;
    $moon = nativeMission(10, 1, ['percent' => 50]);
    $moon['coordinates']['objectType'] = 3;
    nativePlanStart($owner, $fleet, [nativeMission(1, 2), nativeMission(1, 3), $moon, ['kind' => 'return']]);
    // Вылет возможен только с выбранной базы; луну игрок выбирает уже в полёте.
    if ($selectedMoon) {
        $owner['User']['current_planet'] = 104;
        $owner['WorkPlanet'] = 104;
        $owner->save();
    }

    $beforePartial = $beforeReturn = $returnCommand = null;
    $battles = 0;
    for ($i = 0; $i < 30 && ($command = FleetCommands::getActiveCommand(1)) !== null; ++$i) {
        $params = Control::params($command);
        if (FleetAttackService::isBattleWait($command)) {
            nativePlanResolveBattle((int)$params['battleWait']['planetId']);
            ++$battles;
            continue;
        }
        if ($freshContext) {
            $owner = new AccountData(1);
            $owner['Resources']->toArray(); // Кэш текущей выбранной страницы, в том числе луны.
            $fleet->reloadFromRuntime();
        }
        if (($params['planStep']['mission'] ?? null) === 10) {
            $beforePartial = ['moon' => returnLedgerCounts(104), 'cargo' => []];
            foreach ([901, 902, 903] as $id) $beforePartial['cargo'][$id] = $fleet->getResources()[$id]->getCount();
        }
        if (($params['planStep']['kind'] ?? '') === 'return') {
            researchAssert(array_column(FleetCommands::getFleetQueue(1), 'command_type') === [10, 32, 52],
                'Ожидался последний перелёт, полная выгрузка и STAY.');
            $beforeReturn = ['home' => returnLedgerCounts(101), 'moon' => returnLedgerCounts(104),
                'cargo' => [], 'fuel' => $fleet->getFuelCurrent()];
            foreach ([901, 902, 903] as $id) $beforeReturn['cargo'][$id] = $fleet->getResources()[$id]->getCount();
            $returnCommand = $command;
        }
        Commands::completeCommand($command, $fleet, $owner);
    }
    researchAssert($battles === 2 && $beforePartial !== null && $beforeReturn !== null,
        'Не пройдены две атаки, выгрузка 50% и возврат.');
    researchAssert($fleet->getStatus() === 0 && FleetCommands::getFleetQueue(1) === [], 'Флот не завершил маршрут.');
    researchAssert($beforeReturn['fuel'] > 0 && min($beforeReturn['cargo']) > 0, 'Fixture не проверяет доставку груза и топлива.');

    $expectedHome = $beforeReturn['home']; $expectedMoon = $beforePartial['moon'];
    foreach ([901, 902, 903] as $id) {
        researchAssert(abs($beforeReturn['cargo'][$id] - $beforePartial['cargo'][$id] * .5) < .000001,
            'В трюме осталось не 50% груза.');
        $expectedMoon[$id] += $beforePartial['cargo'][$id] * .5;
        $expectedHome[$id] += $beforeReturn['cargo'][$id] + ($id === 903 ? $beforeReturn['fuel'] : 0);
    }
    returnLedgerEqual($expectedMoon, $beforeReturn['moon'], 'Лунная выгрузка 50%');
    returnLedgerEqual($expectedHome, returnLedgerCounts(101), 'Полная выгрузка на базе');
    returnLedgerEqual($expectedMoon, returnLedgerCounts(104), 'Возврат не изменяет лунный баланс');
    foreach ($fleet->getResources() as $resource) researchAssert($resource->getCount() === 0.0, 'Трюм не опустел.');
    researchAssert($fleet->getFuelCurrent() === 0.0, 'Бак не опустел.');

    $messages = array_values(array_filter(Messages::getAll(), static fn($row) => in_array($row['sample'],
        ['FleetReturnedWithFuel', 'FleetReturnedWithCargoAndFuel'], true)));
    researchAssert(count($messages) === 1, 'Ожидалось одно сообщение о возврате.');
    $report = json_decode($messages[0]['data'], true, 512, JSON_THROW_ON_ERROR);
    researchAssert((float)$report['ResourcesFuelReturned'][903] === $beforeReturn['fuel'], 'Неверное топливо в отчёте.');
    $reportedCargo = $report['Resources'] ?? null;

    // Поздний save кэшированного контекста и reload не должны вернуть старые балансы.
    $owner->save(); $originalContext->save(); $fleet->reloadFromRuntime();
    $reader = new AccountData(1);
    foreach ([101 => $expectedHome, 104 => $expectedMoon] as $planetId => $expected) {
        $reader['WorkPlanet'] = $planetId; $actual = [];
        foreach ([901, 902, 903] as $id) $actual[$id] = (float)$reader['Resources'][$id]['count'];
        returnLedgerEqual($expected, $actual, 'Чтение после save/reload');
    }
    $beforeReplay = [returnLedgerCounts(101), returnLedgerCounts(104), Fleets::getById(1), Messages::getAll()];
    Commands::completeCommand($returnCommand, $fleet, $owner);
    researchAssert($beforeReplay === [returnLedgerCounts(101), returnLedgerCounts(104), Fleets::getById(1), Messages::getAll()],
        'Повтор команды повторно зачислил груз или создал уведомление.');
    $diagnostics[] = ['case' => $case, 'balances' => 'PASS', 'cargoBeforeReturn' => $beforeReturn['cargo'],
        'homeBeforeReturn' => $beforeReturn['home'], 'homeAfterReturn' => returnLedgerCounts(101),
        'fuelReturned' => $beforeReturn['fuel'], 'reportedCargo' => $reportedCargo];
    echo "PASS balances, reload and replay: $case\n";
    if ($reportedCargo === null) echo "DEFECT return message has fuel but no cargo: $case\n";
    else returnLedgerEqual($beforeReturn['cargo'], $reportedCargo, 'Груз в сообщении');
}
researchAssert(Database::$calls === 0, 'Тест обратился к игровой SQL.');
echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
$missingReports = count(array_filter($diagnostics, static fn($row) => $row['reportedCargo'] === null));
echo 'RESULT balances: ' . count($diagnostics) . ' passed; reports missing cargo: ' . $missingReports . "; SQL calls=0\n";
restore_error_handler();
// Отличаем воспроизведённый дефект сообщения от исключения/сбоя проверки балансов.
exit($missingReports > 0 ? 2 : 0);
