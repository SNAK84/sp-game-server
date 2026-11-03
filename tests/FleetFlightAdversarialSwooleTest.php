<?php

declare(strict_types=1);

// Аудит негативных сценариев. Свежие native Tables, любая SQL запрещена fixture.
// Регрессии replay и изоляции ошибочных событий, без игровых данных.
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

\SPGame\Core\Connect::init(8);

use SPGame\Core\Database;
use SPGame\Game\Repositories\{FleetCommands, Fleets, Messages, PlanetResources};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetControlService as Control,
    EventLoop, FleetsData, AccountData};

function flightAuditSnapshot($fleet): array
{
    return [Fleets::getById($fleet->getId()), FleetCommands::getFleetCommandChain($fleet->getId()),
        PlanetResources::getAll(), Messages::getAll(), $fleet->getCurrentCoordinate()->toArray()];
}

researchCase('replay: старый outbound MOVE не перемещает уже вернувшийся флот', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    nativePlanStart($owner, $fleet, [nativeMission(10, 2), ['kind' => 'return']]);
    $stale = FleetCommands::getActiveCommand(1);
    nativePlanFinish($owner, $fleet);
    $before = flightAuditSnapshot($fleet);
    researchAssert(Commands::completeCommand($stale, $fleet, $owner), 'Replay не обработан как no-op.');
    $after = flightAuditSnapshot($fleet);
    researchAssert($after === $before, 'Старая MOVE изменила завершённый маршрут: ' . json_encode([
        'before' => $before[4], 'after' => $after[4], 'status' => $after[0]['status'],
        'commands' => count($after[1]),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
});

researchCase('replay: MOVE из старого snapshot не обходит ручную остановку', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    nativePlanStart($owner, $fleet, [nativeMission(10, 2), ['kind' => 'return']]);
    $stale = FleetCommands::getActiveCommand(1);
    Control::stop($fleet, $owner, 1000 + ((float)$stale['execute_time'] - 1000) * .25);
    $before = flightAuditSnapshot($fleet);
    researchAssert(Commands::completeCommand($stale, $fleet, $owner), 'Replay остановленного MOVE не обработан как no-op.');
    $after = flightAuditSnapshot($fleet);
    researchAssert($after === $before, 'MOVE из старой цепочки изменила остановленный флот: ' . json_encode([
        'before' => $before[4], 'after' => $after[4], 'commandsBefore' => count($before[1]),
        'commandsAfter' => count($after[1]),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
});

researchCase('replay: изменённый срок активной команды требует свежего snapshot', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    nativePlanStart($owner, $fleet, [nativeMission(10, 2), ['kind' => 'return']]);
    $stale = FleetCommands::getActiveCommand(1);
    Control::patchCommand((int)$stale['id'], ['execute_time' => (float)$stale['execute_time'] + 100]);
    $before = flightAuditSnapshot($fleet);
    researchAssert(Commands::completeCommand($stale, $fleet, $owner) && flightAuditSnapshot($fleet) === $before,
        'Старый deadline применился к изменённой команде.');
    nativePlanFinish($owner, $fleet);
    researchAssert($fleet->getStatus() === 0, 'Свежая команда не завершила маршрут.');
});

researchCase('scheduler: повреждённая due-команда не блокирует следующий флот', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    nativePlanStart($owner, $fleet, [nativeMission(10, 2), ['kind' => 'return']]);
    $owner2 = new AccountData(2);
    $fleet2 = FleetsData::getInstance()->get(2);
    nativePlanStart($owner2, $fleet2, [nativeMission(10, 3), ['kind' => 'return']]);
    $second = FleetCommands::getActiveCommand(2);
    $broken = FleetCommands::getActiveCommand(1);
    $original = $broken;
    Control::patchCommand((int)$broken['id'], ['params' => '{}', 'execute_time' => 1001.0]);
    $broken = FleetCommands::getCommand((int)$broken['id']);
    $beforeBroken = flightAuditSnapshot($fleet);
    $loop = new EventLoop();
    $process = new ReflectionMethod($loop, 'processFleetEvents');
    $now = max((float)$original['execute_time'], (float)$second['execute_time']) + 1;
    researchAssert($process->invoke($loop, $now), 'Ошибка одного флота остановила мировой tick.');
    researchAssert(FleetCommands::getCommand((int)$second['id'])['executed'] === 1,
        'Второй due MOVE не выполнен.');
    researchAssert(FleetCommands::getCommand((int)$broken['id']) === $broken,
        'Ошибочная команда удалена или отмечена выполненной.');
    researchAssert(Fleets::getById(1) === $beforeBroken[0]
        && FleetCommands::getFleetCommandChain(1) === $beforeBroken[1], 'Изменён повреждённый флот.');
    researchAssert($process->invoke($loop, $now), 'Повторная попытка снова заблокировала мир.');
    researchAssert(FleetCommands::getCommand((int)$broken['id']) === $broken, 'Потерян retry marker.');
    // Положительный контроль: после удаления причины первый и второй MOVE исполняются.
    Control::patchCommand((int)$broken['id'], ['params' => $original['params'], 'execute_time' => $original['execute_time']]);
    researchAssert($process->invoke($loop, $now), 'Retry после восстановления params не завершился.');
    researchAssert(FleetCommands::getCommand((int)$original['id'])['executed'] === 1,
        'Исправленная команда не исполнена на следующем tick.');
});

researchAssert(Database::$calls === 0, 'Аудит обратился к игровой SQL.');
echo "RESULT adversarial flights: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 ? 1 : 0);
