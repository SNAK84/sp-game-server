<?php

declare(strict_types=1);

// Table double и текущие transport fixtures; ни MySQL, ни живые аккаунты не используются.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Message;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Repositories\{PlanetBattles, PlayerQueue, Queues};
use SPGame\Game\Services\{FleetBattleService as Battles, FleetCombatService as Combat,
    FleetCommandsService, FleetsServices, PageActionService, QueuesServices};

function battleGroup(): array
{
    return ['type' => 204, 'kind' => 'fleet', 'count' => 10, 'attack' => 1.0,
        'hull' => 10000.0, 'shield' => 0.0, 'unitCost' => 4000.0, 'experience' => 0.0];
}

function battleFixture(): array
{
    $fixture = installTransportFixture();
    $fixture['coordinates'] = \SPGame\Game\Space\Coordinate::object(
        galaxy: 1, system: 10, orbit: 8, objectType: \SPGame\Game\Space\ObjectType::PLANET
    )->toArray();
    installTransportRepository(PlanetBattles::class, transportTable(), transportSyncTable(), []);
    installTransportRepository(Queues::class, transportTable(), transportSyncTable(), []);
    $GLOBALS['roundJobs'] = [];
    Battles::initializeWorkers(static function (array $task): bool {
        $GLOBALS['roundJobs'][] = $task;
        return true;
    });
    return $fixture;
}

function battleRejects(callable $fn): void
{
    $rejected = false;
    try { $fn(); } catch (LogicException) { $rejected = true; }
    transportAssert($rejected, 'Ожидалась блокировка боем.');
}

$battleTests = [];
$battleTests['arrivals before at and during a round enter only unsealed snapshots'] = static function (): void {
    battleFixture();
    $id = Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100, fleetIds: [1]);
    Battles::tick(100.9);
    transportAssert($GLOBALS['roundJobs'] === [], 'Раунд не начинается раньше дедлайна.');
    transportAssert(Battles::reinforce(10, $id, 'early', 'attacker', ['early' => battleGroup()], 100.5, [2]) === 1, 'Раннее подкрепление входит в первый раунд.');
    Battles::tick(101);
    $first = $GLOBALS['roundJobs'][0];
    transportAssert(isset($first['combat']['a']['early']), 'Подкрепление включено в snapshot задания.');
    transportAssert(Battles::reinforce(10, $id, 'late', 'defender', ['late' => battleGroup()], 101.2, [3]) === 2, 'Во время вычисления — следующий раунд.');
    transportAssert(Battles::reinforce(10, $id, 'late', 'defender', ['late' => battleGroup()], 101.2, [3]) === 2, 'Повтор arrival не удваивает состав.');
    battleRejects(static fn() => Battles::reinforce(10, $id, 'late', 'attacker', ['late' => battleGroup()], 101.2));
    Battles::executeTask($first);
    Battles::tick(101.5);
    $state = PlanetBattles::state(10);
    transportAssert(count($state['combat']['rounds']) === 1 && !isset($state['combat']['d']['late']), 'Текущий раунд не получает позднее подкрепление.');
    transportAssert(count($state['pending']) === 1, 'Ответ worker не стирает arrivals, принятые во время расчёта.');
    transportAssert(Battles::reinforce(10, $id, 'edge', 'attacker', ['edge' => battleGroup()], 102) === 2, 'На границе до dispatch — текущий не начатый раунд.');
    Battles::tick(102);
    $second = $GLOBALS['roundJobs'][1];
    transportAssert(isset($second['combat']['d']['late'], $second['combat']['a']['edge']), 'Обе стороны получают подкрепление.');
    Battles::executeTask($first); // Поздний дубликат результата первого раунда.
    Battles::tick(102.1);
    transportAssert(count(PlanetBattles::state(10)['combat']['rounds']) === 1, 'Старый ответ не закрывает второй раунд.');
    Battles::executeTask($second);
    Battles::tick(103);
    transportAssert(count(PlanetBattles::state(10)['combat']['rounds']) === 2, 'Каждый ответ применяется ровно один раз.');
};
$battleTests['delayed workers admit reinforcements by round sequence not wall clock'] = static function (): void {
    battleFixture();
    $id = Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    Battles::tick(101);
    transportAssert(Battles::reinforce(10, $id, 'delayed', 'defender', ['delayed' => battleGroup()], 112, [3]) === 2,
        'После десяти секунд ожидания worker остаются не начатые раунды.');
    Battles::executeTask($GLOBALS['roundJobs'][0]);
    Battles::tick(112);
    $second = $GLOBALS['roundJobs'][1];
    transportAssert(count($second['combat']['rounds']) === 1 && isset($second['combat']['d']['delayed']),
        'Подкрепление входит во второй раунд, не в несуществующий двенадцатый.');
    transportAssert($second['combat']['roundLimit'] === Combat::DEFAULT_ROUNDS,
        'Подкрепление не перезапускает лимит боя.');
};
$battleTests['ten rounds finish once and keep protection until explicit gameplay commit'] = static function (): void {
    battleFixture();
    $id = Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    for ($round = 1; $round <= 10; ++$round) {
        Battles::tick(100 + $round);
        $task = $GLOBALS['roundJobs'][$round - 1];
        transportAssert(count($task['combat']['rounds']) === $round - 1, 'Последовательное продолжение, не пересчёт с нуля.');
        Battles::executeTask($task);
    }
    Battles::tick(111);
    $state = PlanetBattles::state(10);
    transportAssert($state['phase'] === 'finished' && count($GLOBALS['roundJobs']) === 10, 'Не создаётся одиннадцатый раунд.');
    transportAssert(Combat::getResult($state['combat'])['reason'] === 'round_limit', 'Прежний лимит боя сохранён.');
    battleRejects(static fn() => Battles::assertPlanetAvailable(10));
    battleRejects(static fn() => Battles::reinforce(10, $id, 'too-late', 'attacker', ['new' => battleGroup()], 111));
    Battles::releaseAfterCommit(10, $id);
    Battles::releaseAfterCommit(10, $id);
    Battles::assertPlanetAvailable(10);
    transportAssert(PlanetBattles::getPendingSync()['update'] === [10], 'Состояние сохраняет обычный dirty marker.');
};
$battleTests['timeout restart and stale response retain seed snapshot and protection'] = static function (): void {
    battleFixture();
    Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    Battles::tick(101);
    $old = $GLOBALS['roundJobs'][0];
    Battles::tick(161);
    Battles::tick(162);
    $new = $GLOBALS['roundJobs'][1];
    transportAssert($old['token'] !== $new['token'] && $old['seed'] === $new['seed'] && $old['combat'] === $new['combat'], 'Retry получает новый token, но тот же случайный исход.');
    Battles::executeTask($old);
    Battles::tick(162.1);
    transportAssert(count(PlanetBattles::state(10)['combat']['rounds']) === 0, 'Старый worker не побеждает новую попытку.');
    Battles::executeTask($new);
    Battles::tick(162.2);
    transportAssert(count(PlanetBattles::state(10)['combat']['rounds']) === 1, 'Retry применён.');
    battleRejects(static fn() => Battles::assertPlanetAvailable(10));
    // Потеря mailbox при restart: сохранённый runtime checkpoint остаётся тем же.
    Battles::initializeWorkers(static function ($task): bool { $GLOBALS['roundJobs'][] = $task; return true; });
    Battles::tick(223);
    Battles::tick(224);
    transportAssert(PlanetBattles::state(10)['job'] !== null, 'После потери mailbox раунд можно повторить из checkpoint.');
};
$battleTests['worker failure cannot release planet or mutate gameplay'] = static function (): void {
    $f = battleFixture();
    Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100, fleetIds: [1]);
    Battles::tick(101);
    $task = $GLOBALS['roundJobs'][0];
    $task['combat']['version'] = 999;
    $resources = $f['account']['Resources'];
    Battles::executeTask($task);
    Battles::tick(102);
    transportAssert(PlanetBattles::state(10)['phase'] === 'failed', 'Ошибка зафиксирована, а не объявлена победой/ничьей.');
    battleRejects(static fn() => Battles::assertPlanetAvailable(10));
    battleRejects(static fn() => Battles::assertFleetAvailable(1));
    transportAssert($f['account']['Resources'] === $resources, 'Worker не меняет ресурсы.');
};
$battleTests['blocked previously accepted PlayerQueue event is removed before action execution'] = static function (): void {
    $f = battleFixture();
    PlayerQueue::addQueue(1000, 100, 10, PlayerQueue::ActionQueueUpgarde, ['Element' => 1]);
    transportAssert(count(PlayerQueue::getByAccount(1000) ?? []) === 1, 'Команда принята до начала боя.');
    Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    $resources = $f['account']['Resources'];
    $method = new ReflectionMethod(\SPGame\Game\Services\EventLoop::class, 'processPlayerEvents');
    transportAssert($method->invoke(new \SPGame\Game\Services\EventLoop(), 1000), 'Нужен актуальный snapshot.');
    transportAssert(PlayerQueue::getByAccount(1000) === null, 'Заблокированное событие не остаётся для исполнения после боя.');
    transportAssert((Queues::getAll() ?? []) === [] && $f['account']['Resources'] === $resources, 'Нет игровой очереди или списания.');
};
$battleTests['retry limit and independent battles cannot stop the whole scheduler'] = static function (): void {
    battleFixture();
    Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    foreach ([101, 162, 223] as $time) {
        Battles::tick($time);
        Battles::tick($time + Battles::JOB_TIMEOUT);
    }
    Battles::tick(284);
    transportAssert(count($GLOBALS['roundJobs']) === 3 && PlanetBattles::state(10)['phase'] === 'failed', 'Не создаётся бесконечная очередь retry.');
    battleRejects(static fn() => Battles::assertPlanetAvailable(10));
    Battles::begin(11, ['a' => battleGroup()], ['d' => battleGroup()], 284);
    Battles::tick(285);
    Battles::executeTask($GLOBALS['roundJobs'][3]);
    Battles::tick(286);
    transportAssert(count(PlanetBattles::state(11)['combat']['rounds']) === 1, 'Другой бой продолжает рассчитываться.');
};
$battleTests['queues cancel cross-planet and fleet loading cannot hide resources'] = static function (): void {
    $f = battleFixture();
    $account = $f['account'];
    Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100, fleetIds: [1]);
    $resources = $account['Resources'];
    foreach (['buildings', 'researchs', 'shipyard', 'defense'] as $mode) {
        foreach (['build', 'cancel'] as $action) {
            $response = new Message();
            $msg = new Message(['status' => 'ok', 'mode' => $mode, 'action' => $action, 'data' => ['id' => 1]]);
            transportAssert(PageActionService::handle($msg, $account, 1000, $response), 'Request обработан.');
            transportAssert($response->getStatus() === 'error', 'Request отклонён до PlayerQueue.');
        }
    }
    transportAssert((PlayerQueue::getAll() ?? []) === [], 'Отклонённые requests не создают событий.');
    foreach ([static fn() => QueuesServices::AddToQueue(1, $account, 101),
        static fn() => QueuesServices::AddToQueueHangar([], $account, 101),
        static fn() => QueuesServices::CancelToQueue(1, $account, 101),
        static fn() => QueuesServices::CancelToQueueHangar(1, $account, 101),
        static fn() => FleetsServices::AddToOrbit([], $account, 101),
        static fn() => FleetCommandsService::prepareMissionRequest($f['fleet'], $account,
            FleetMissionType::TRANSPORT->value, $f['coordinates'], 10, 1, [901 => 1])] as $operation) {
        battleRejects($operation);
    }
    installTransportRepository(Queues::class, transportTable([['id' => 7, 'planet_id' => 10]]), transportSyncTable(7), []);
    battleRejects(static fn() => Battles::assertQueueAction(11, 7));
    battleRejects(static fn() => Battles::assertPlayerMutation(PlayerQueue::ActionQueueUpgarde, 10, []));
    Battles::assertPlanetAvailable(11);
    transportAssert($account['Resources'] === $resources, 'Ни списаний, ни возвратов при блокировке.');
};
$battleTests['oversized snapshot and dirty marker failure roll back runtime state'] = static function (): void {
    battleFixture();
    $id = Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    $before = PlanetBattles::findById(10);
    $state = PlanetBattles::state(10);
    $state['padding'] = str_repeat('x', PlanetBattles::MAX_STATE_BYTES);
    $rejected = false;
    try { PlanetBattles::store(10, $state); } catch (OverflowException) { $rejected = true; }
    transportAssert($rejected && PlanetBattles::findById(10) === $before, 'Переполнение отклонено до записи.');
    $sync = transportSyncTable();
    $sync->failedWrites = 1;
    $sync->failedWriteKey = '11';
    $property = new ReflectionProperty(PlanetBattles::class, 'syncTable');
    $property->setValue(null, $sync);
    $rejected = false;
    try { Battles::begin(11, ['a' => battleGroup()], ['d' => battleGroup()], 100); }
    catch (RuntimeException) { $rejected = true; }
    transportAssert($rejected && PlanetBattles::findById(11) === null, 'Не остаётся боя без dirty marker.');
};
$battleTests['oversized result preserves last accepted checkpoint and fails closed'] = static function (): void {
    battleFixture();
    Battles::begin(10, ['a' => battleGroup()], ['d' => battleGroup()], 100);
    Battles::tick(101);
    $state = PlanetBattles::state(10);
    $state['padding'] = '';
    $size = strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $state['padding'] = str_repeat('x', PlanetBattles::MAX_STATE_BYTES - $size - 4);
    PlanetBattles::store(10, $state);
    Battles::executeTask($GLOBALS['roundJobs'][0]);
    Battles::tick(102);
    $after = PlanetBattles::state(10);
    transportAssert($after['phase'] === 'failed' && count($after['combat']['rounds']) === 0, 'Переполненный результат не заменяет checkpoint и не повторяется каждый tick.');
    battleRejects(static fn() => Battles::assertPlanetAvailable(10));
};

foreach ($battleTests as $name => $test) {
    $test();
    echo "OK battle {$name}\n";
}
