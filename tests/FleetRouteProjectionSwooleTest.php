<?php

declare(strict_types=1);

// Проекции на изолированных native Tables; никакого доступа к игровому MySQL.
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{FleetCommands, Fleets, Planets, PlanetResources};
use SPGame\Game\Services\{AccountData, FleetCommandsService as Commands, FleetControlService as Control};

function routeProjectionFixture(): array
{
    [, $fleet] = nativePlanFixture();
    Planets::update(['id' => 102, 'owner_id' => 1]);
    Planets::update(['id' => 103, 'owner_id' => 1]);
    $owner = new AccountData(1);
    $steps = [];
    foreach ([2, 3] as $orbit) {
        $steps[] = ['kind' => 'load', 'coordinates' => ['type' => 1, 'galaxy' => 1,
            'system' => 1, 'orbit' => $orbit, 'objectType' => 1],
            'speedFactor' => 10, 'resources' => [901 => 10]];
    }
    Control::savePlan($fleet, $owner, ['version' => Control::state($fleet)['version'],
        'fuel' => 1000, 'steps' => [...$steps, ['kind' => 'return']]], 1000);
    return [$owner, $fleet];
}
function routeProjectionEvent($owner): array
{
    $event = (new FleetsPage(new Message()))->buildFlyingFleetList($owner)[1] ?? null;
    researchAssert($event !== null, 'Флот пропал из проекции.');
    return $event;
}
function routeProjectionSnapshot(): array
{
    return [Fleets::getById(1), FleetCommands::getFleetCommandChain(1), PlanetResources::getAll()];
}

if (in_array('--export-ui', $argv ?? [], true)) {
    [$owner, $fleet] = routeProjectionFixture();
    $events = ['first' => routeProjectionEvent($owner)];
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $events['loading'] = routeProjectionEvent($owner);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $events['second'] = routeProjectionEvent($owner);
    echo json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

researchCase('projection: каждое плечо оканчивается ближайшей погрузкой, а не последней прямой MOVE', static function (): void {
    [$owner, $fleet] = routeProjectionFixture();
    foreach ([[1, 2, 'outbound'], [2, 3, 'outbound'], [3, 1, 'return']] as [$start, $target, $direction]) {
        $before = routeProjectionSnapshot();
        $event = routeProjectionEvent($owner);
        $leg = $event['route_leg'];
        $active = FleetCommands::getActiveCommand(1);
        researchAssert($leg['start']['orbit'] === $start && $leg['target']['orbit'] === $target,
            'Неверные концы плеча: ' . json_encode($leg));
        researchAssert($leg['segments_total'] === 1 && $leg['active_segment'] === 1
            && $leg['arrives_at'] === $active['execute_time'] && $leg['direction'] === $direction,
            'В плечо включены MOVE после следующего действия.');
        researchAssert($before === routeProjectionSnapshot(), 'Проекция изменила runtime.');
        Commands::completeCommand($active, $fleet, $owner);
        if ($target !== 1) Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    }
    researchAssert($fleet->getStatus() === 0, 'Маршрут не завершился.');
});

researchCase('projection: активный LOAD неподвижен, имеет authoritative время и не обрывает прогноз', static function (): void {
    [$owner, $fleet] = routeProjectionFixture();
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $load = FleetCommands::getActiveCommand(1);
    $before = routeProjectionSnapshot();
    $event = routeProjectionEvent($owner);
    researchAssert($event['command']['type'] === 31 && $event['segment_start'] === $event['segment_end'],
        'LOAD отображается как перемещение.');
    researchAssert($event['mission_end_time'] > $load['execute_time'], 'LOAD оборвал прогноз оставшихся команд.');
    researchAssert($event['route_leg']['direction'] === 'loading'
        && $event['route_leg']['start'] === $event['route_leg']['target']
        && $event['route_leg']['arrives_at'] === $load['execute_time'], 'У LOAD таймер старого перелёта.');
    researchAssert($before === routeProjectionSnapshot(), 'Погрузка выполнена при чтении проекции.');
});

researchCase('projection: пауза LOAD не обещает будущего прибытия и не меняет склад', static function (): void {
    [$owner, $fleet] = routeProjectionFixture();
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    PlanetResources::update(['id' => 102, 'metal' => 0]);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $before = routeProjectionSnapshot();
    $event = routeProjectionEvent($owner);
    researchAssert($event['mission_end_time'] === null && $event['blocked_reason'] !== null,
        'Для паузы LOAD обещано время завершения.');
    researchAssert($event['route_leg']['direction'] === 'loading' && $event['route_leg']['arrives_at'] === null,
        'Для паузы показано старое время прибытия.');
    researchAssert($before === routeProjectionSnapshot(), 'Пауза проекции изменила runtime.');
});

researchCase('projection: межсистемный перелёт остаётся одним плечом; move-only этапы разделены', static function (): void {
    [$owner, $fleet] = routeProjectionFixture();
    $method = new ReflectionMethod(FleetsPage::class, 'buildRouteLegProjection');
    $page = new FleetsPage(new Message());
    $commands = [];
    foreach ([10, 11, 10, 10] as $index => $type) {
        $commands[] = ['id' => $index + 1, 'position' => $index + 1, 'type' => $type,
            'key' => 'MOVE', 'lang' => '', 'return_leg' => false,
            'status' => $index === 0 ? 'completed' : ($index === 1 ? 'active' : 'pending'),
            'started_time' => 1000 + $index * 100, 'estimated_time' => 1100 + $index * 100,
            'start_time_estimated' => $index > 1, 'time_estimated' => $index > 1,
            'target' => ['type' => 1, 'galaxy' => 1, 'system' => 2, 'orbit' => 2, 'objectType' => 1]];
    }
    // Два последовательных move-only этапа к одному объекту различаются ID этапа.
    $leg = $method->invoke($page, $fleet, $commands, [], [1 => 'a', 2 => 'a', 3 => 'a', 4 => 'b']);
    researchAssert($leg['segments_total'] === 3 && $leg['active_segment'] === 2 && $leg['arrives_at'] === 1300.0,
        'Три команды перелёта разделены либо захватили следующую остановку.');
    // Совместимость старой миссии без planStepId: смена цели тоже разделяет плечи.
    $commands[3]['target']['orbit'] = 3;
    $leg = $method->invoke($page, $fleet, $commands, []);
    researchAssert($leg['segments_total'] === 3, 'Старый формат склеил разные цели.');
    $commands[1]['status'] = $commands[2]['status'] = 'completed';
    $commands[3]['status'] = 'active';
    $leg = $method->invoke($page, $fleet, $commands, []);
    researchAssert($leg['segments_total'] === 1 && $leg['start']['orbit'] === 2 && $leg['target']['orbit'] === 3,
        'После остановки сохранились прежние концы плеча.');
});

restore_error_handler();
echo "RESULT route projection: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
