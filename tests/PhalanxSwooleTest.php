<?php

declare(strict_types=1);

define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\Message;
use SPGame\Game\Repositories\{Builds, FleetCommands, Fleets, PhalanxScans, Planets, PlanetResources, PlayerQueue, Vars};
use SPGame\Game\Services\{AccountData, FleetControlService as Control, PhalanxService as Phalanx, PageActionService};
use SPGame\Game\Pages\FleetsPage;

function phalanxFixture(): array
{
    [$owner] = nativePlanFixture();
    Vars::$resource[42] = 'phalanx';
    Vars::$reslist['build'][] = 42;
    $builds = Builds::getAll();
    foreach ($builds as &$row) $row['phalanx'] = $row['id'] === 101 ? 1 : 0;
    unset($row);
    researchRepository(Builds::class, $builds, array_map(static fn($id) => Vars::$resource[$id], Vars::$reslist['build']));
    Planets::update(['id' => 101, 'planet_type' => 3]);
    $table = new Swoole\Table(16, 1.0);
    $table->column('expires_at', Swoole\Table::TYPE_FLOAT);
    $table->column('payload', Swoole\Table::TYPE_STRING, PhalanxScans::MAX_BYTES);
    $table->create();
    (new ReflectionProperty(PhalanxScans::class, 'table'))->setValue(null, $table);
    $enemy = new AccountData(2);
    $fleet = $enemy->getFleetsData()->get(2);
    Control::savePlan($fleet, $enemy, ['version' => Control::state($fleet)['version'],
        'fuel' => 1000, 'resources' => [901 => 100], 'steps' => [nativeMission(10, 3), ['kind' => 'return']]], 1000);
    return [new AccountData(1), $enemy, $fleet];
}

class PhalanxFailureTable extends Swoole\Table
{
    public bool $fail = false;
    public function set(string $key, array $value): bool
    {
        if ($this->fail) { $this->fail = false; return false; }
        return parent::set($key, $value);
    }
}

researchCase('phalanx: дальность Nova, строгая цель/луна/ресурсы; prepare без мутаций', static function (): void {
    [$owner] = phalanxFixture();
    foreach ([0 => 0, 1 => 1, 2 => 3, 3 => 8, 4 => 15] as $level => $range) researchAssert(Phalanx::range($level) === $range, 'Дальность не Nova.');
    $before = PlanetResources::getAll();
    researchAssert(Phalanx::validate($owner, 102)['id'] === 102 && PlanetResources::getAll() === $before, 'Prepare мутирует ресурсы.');
    foreach ([null, '102', 101, -1, 99999] as $id) defendRejected(static fn() => Phalanx::validate($owner, $id));
    Planets::update(['id' => 102, 'system' => 3]);
    defendRejected(static fn() => Phalanx::validate($owner, 102));
    Planets::update(['id' => 102, 'system' => 2]);
    researchAssert(Phalanx::validate($owner, 102)['id'] === 102, 'Граница range=1 не разрешена.');
    Planets::update(['id' => 102, 'galaxy' => 2]);
    defendRejected(static fn() => Phalanx::validate($owner, 102));
});

researchCase('phalanx: ACK только ставит событие, снимок оплачивается один раз, stale replay', static function (): void {
    [$owner] = phalanxFixture();
    $before = PlanetResources::findExistingById(101);
    $token = '12345678-1234-1234-1234-123456789abc';
    $message = new Message(['mode' => 'galaxy', 'action' => 'PhalanxScan', 'data' => ['targetId' => 102, 'scanToken' => $token]]);
    $response = new Message();
    researchAssert(PageActionService::handle($message, $owner, 1, $response) && $response->source()['status'] === 'ok', 'Нет ACK: ' . json_encode($response->source()));
    researchAssert(PlanetResources::findExistingById(101) === $before && PhalanxScans::get(1, 1000) === null, 'ACK уже оплатил скан.');
    $events = PlayerQueue::getByAccount(1);
    researchAssert(count($events) === 1 && $events[0]['planet_id'] === 101 && $events[0]['data']['targetId'] === 102, 'Потерян контекст команды.');
    $scan = Phalanx::scan($owner, 102, 100, $token, 1000);
    researchAssert(PlanetResources::findExistingById(101)['deuterium'] === $before['deuterium'] - 5000, 'Не одно списание.');
    researchAssert(count($scan['fleets']) === 1 && $scan['fleets'][0]['id'] === 2, 'Отлёт с выбранной планеты не обнаружен.');
    $paid = PlanetResources::getAll();
    researchAssert(Phalanx::scan($owner, 102, 100, $token, 1001) === $scan && PlanetResources::getAll() === $paid, 'Replay повторил оплату.');
    researchAssert(PhalanxScans::get(2, 1000) === null && PhalanxScans::get(1, 1301) === null, 'Чужой/просроченный снимок доступен.');
});

researchCase('phalanx: нет live-слежения, будущих команд, бака и новых permissions', static function (): void {
    [$owner] = phalanxFixture();
    $scan = Phalanx::scan($owner, 102, 1, 'snapshot', 1000);
    $event = $scan['fleets'][0];
    researchAssert($event['resources'][901] === 100.0 && !isset($event['resources'][921]), 'Груз снимка имеет неверный формат.');
    foreach (['control', 'commands', 'actions', 'fuel', 'mission_end_time', 'lastKnownPosition'] as $key) researchAssert(!array_key_exists($key, $event), 'Раскрыто лишнее: ' . $key);
    $before = PhalanxScans::get(1, 1001);
    Fleets::update(['id' => 2, 'name' => 'изменение после снимка']);
    researchAssert(PhalanxScans::get(1, 1001) === $before, 'Снимок изменился задним числом.');
    defendRejected(static fn() => \SPGame\Game\Services\FleetRendezvousService::target(1, 2));
});

researchCase('phalanx: EXPEDITION, отменённая передислокация и чужая планета не раскрываются', static function (): void {
    [$owner, $enemy, $fleet] = phalanxFixture();
    $page = new FleetsPage(new Message());
    $target = Planets::findExistingById(102);
    $command = FleetCommands::getActiveCommand(2);
    Control::patchCommand((int)$command['id'], ['command_type' => 40]);
    researchAssert($page->buildPhalanxFleetList($enemy, $target, 10) === [], 'Раскрыта EXPEDITION.');
    Control::patchCommand((int)$command['id'], ['command_type' => 10]);
    $fleet->setMission(11); $fleet->setReturn(true); $fleet->save();
    researchAssert($page->buildPhalanxFleetList($enemy, $target, 10) === [], 'Виден отзыв передислокации.');
    $fleet->setReturn(false); $fleet->save();
    researchAssert($page->buildPhalanxFleetList($enemy, $target, 10) === [], 'Виден отлёт передислокации.');
});

researchCase('phalanx: отказ снимка или оплаты восстанавливает луну и допускает retry', static function (): void {
    foreach ([PhalanxScans::class, PlanetResources::class] as $class) {
        [$owner] = phalanxFixture();
        $prop = new ReflectionProperty($class, 'table');
        $old = $prop->getValue();
        $table = new PhalanxFailureTable(128);
        if ($class === PhalanxScans::class) {
            $table->column('expires_at', Swoole\Table::TYPE_FLOAT);
            $table->column('payload', Swoole\Table::TYPE_STRING, PhalanxScans::MAX_BYTES);
        } else foreach ((new ReflectionProperty($class, 'tableSchema'))->getValue()['columns'] as $name => $column) $table->column($name, ...$column['swoole']);
        $table->create();
        foreach ($old as $key => $row) $table->set((string)$key, $row);
        $prop->setValue(null, $table);
        $before = PlanetResources::getAll();
        $table->fail = true;
        try { Phalanx::scan($owner, 102, 1, 'failure', 1000); throw new LogicException('Нет injected failure.'); }
        catch (RuntimeException) {}
        researchAssert(PlanetResources::getAll() === $before && PhalanxScans::get(1, 1000) === null, 'После отказа осталось частичное состояние.');
        Phalanx::scan($owner, 102, 2, 'retry', 1001);
        researchAssert(PlanetResources::findExistingById(101)['deuterium'] === $before[101]['deuterium'] - 5000, 'Retry не удался.');
    }
});

researchCase('phalanx: полный EventLoop обрабатывает очередь и повторно проверяет владельца', static function (): void {
    [$owner] = phalanxFixture();
    $loop = new \SPGame\Game\Services\EventLoop();
    $process = new ReflectionMethod($loop, 'processPlayerEvent');
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionPhalanxScan, ['targetId' => 102, 'token' => 'event-loop']);
    $event = PlayerQueue::getByAccount(1)[0];
    $before = PlanetResources::findExistingById(101)['deuterium'];
    researchAssert($process->invoke($loop, $event, 1) === true && PlayerQueue::findExistingById($id) === null, 'EventLoop не завершил скан.');
    researchAssert(PlanetResources::findExistingById(101)['deuterium'] === $before - 5000 && count(PhalanxScans::get(1)['fleets']) === 1, 'EventLoop потерял оплату или результат.');
    Planets::update(['id' => 101, 'owner_id' => 2]);
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionPhalanxScan, ['targetId' => 102, 'token' => 'lost-source']);
    $process->invoke($loop, PlayerQueue::getByAccount(1)[0], 1);
    researchAssert(PlanetResources::findExistingById(101)['deuterium'] === $before - 5000 && isset(PhalanxScans::get(1)['error']), 'Изменение владения не проверено при исполнении.');
});

researchCase('phalanx: недостаток дейтерия и переполнение результата — без оплаты', static function (): void {
    [$owner] = phalanxFixture();
    PlanetResources::update(['id' => 101, 'deuterium' => 4999]);
    $owner = new AccountData(1);
    $before = PlanetResources::getAll();
    defendRejected(static fn() => Phalanx::scan($owner, 102, 1, 'poor', 1000));
    researchAssert(PlanetResources::getAll() === $before && PhalanxScans::get(1, 1000) === null, 'Нехватка изменила мир.');
    defendRejected(static fn() => PhalanxScans::pack(['large' => bin2hex(random_bytes(30000))], 1000));
    researchAssert(PlanetResources::getAll() === $before, 'Переполнение изменило ресурсы.');
});

researchCase('phalanx: входящий чужой состав зависит от шпионажа, возврат к своей планете виден', static function (): void {
    [$owner, $enemy, $fleet] = phalanxFixture();
    $third = new AccountData(3);
    $incoming = $third->getFleetsData()->get(3);
    Control::savePlan($incoming, $third, ['version' => Control::state($incoming)['version'], 'fuel' => 1000,
        'resources' => [901 => 100], 'steps' => [nativeMission(10, 2), ['kind' => 'return']]], 1000);
    $page = new FleetsPage(new Message());
    $planet = Planets::findExistingById(102);
    researchAssert($page->buildPhalanxFleetList($third, $planet, 0)[3]['ships'][0]['count'] === null, 'Шпионаж 0 раскрыл количество по типам.');
    researchAssert($page->buildPhalanxFleetList($third, $planet, 8)[3]['ships'][0]['count'] === 10, 'Шпионаж 8 не раскрыл состав.');
    \SPGame\Game\Services\FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(2), $fleet, $enemy);
    researchAssert($fleet->isReturn() && isset($page->buildPhalanxFleetList($enemy, $planet, 0)[2]), 'Возврат к сканируемой планете не виден.');
});

echo "RESULT phalanx: 8 native сценариев, SQL=0\n";
