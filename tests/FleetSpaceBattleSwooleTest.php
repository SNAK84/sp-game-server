<?php

declare(strict_types=1);

define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Game\Enums\FleetCommandType as SpaceCommand;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, Messages, PlanetBattles, PlanetResources,
    SpaceBattles, SpaceDebrisFields, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService, FleetBattleService as SpaceCoordinator,
    FleetCommandsService, FleetControlService as SpaceControl, FleetRendezvousService,
    FleetSpaceBattleService as SpaceBattle, FleetTransferService, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};

function spaceFixture(bool $strong = true, bool $moving = false): array
{
    [$owner] = nativePlanFixture();
    researchRepository(SpaceBattles::class, []);
    SpaceBattles::setLastId(1); // Первый checkpoint fixture имеет ID 2, независимо от ID цели.
    researchRepository(SpaceDebrisFields::class, []);
    Vars::$CombatCaps[202]['attack'] = $strong ? 500 : 5;
    FleetsShips::update(['id' => 2, 'small_cargo_count' => $strong ? 1 : 10]);
    foreach ([1, 2] as $id) Fleets::update(['id' => $id, 'status' => 1, 'mission' => 1,
        'current_coordinate_type' => 2, 'current_galaxy' => 1, 'current_system' => 1,
        'current_distance' => 2000.0, 'current_angle' => 20.0, 'current_position_time' => 1000.0,
        'fuel_total' => $id === 1 ? 10000.0 : 1000.0, 'fuel_current' => $id === 1 ? 10000.0 : 700.0]);
    Fleets::update(['id' => 2, 'target_coordinate_type' => 1, 'target_galaxy' => 1, 'target_system' => 1,
        'target_orbit' => 1, 'target_object_type' => 1, 'resource_metal' => 100.0, 'resource_crystal' => 60.0, 'resource_deuterium' => 40.0]);
    $point = Coordinate::point(1, 1, 2000, 20);
    $targetCommand = SpaceControl::add(2, $moving ? SpaceCommand::MOVE_IN_SYSTEM : SpaceCommand::OBSERVE,
        $moving ? ['target' => Coordinate::point(1, 1, 3000, 20)->toArray(), 'motionStart' => $point->toArray(),
            'motionEnd' => Coordinate::point(1, 1, 3000, 20)->toArray(), 'fuelCost' => 100.0, 'progressWeight' => 1.0, 'speedFactor' => 10]
            : ['target' => $point->toArray(), 'hold' => ['rate' => .1, 'startedAt' => 1000.0, 'lastAt' => 1000.0]]);
    SpaceControl::patchCommand((int)$targetCommand['id'], ['execute_time' => 5000.0]);
    $command = SpaceControl::add(1, SpaceCommand::ATTACK, ['target' => Coordinate::fleet(2, $point)->toArray()]);
    $home = Coordinate::object(1, 1, 1, ObjectType::PLANET)->toArray();
    SpaceControl::add(1, SpaceCommand::MOVE_IN_SYSTEM, ['target' => $home, 'speedFactor' => 10, 'returnLeg' => true]);
    SpaceControl::add(1, SpaceCommand::UNLOAD, ['target' => $home, 'returnLeg' => true]);
    SpaceControl::add(1, SpaceCommand::STAY, ['at' => $home, 'returnLeg' => true]);
    (new ReflectionProperty(FleetsData::class, 'instance'))->setValue(null, null);
    $attacker = FleetsData::getInstance()->get(1);
    $target = FleetsData::getInstance()->get(2);
    $GLOBALS['spaceJobs'] = $GLOBALS['spaceWorkerPids'] = [];
    SpaceCoordinator::initializeWorkers(static function (array $task): bool { $GLOBALS['spaceJobs'][] = $task; return true; }, 2);
    return [$owner, $attacker, $target, new AccountData(2), $command];
}

function spaceDrainWorkers(): void
{
    foreach ($GLOBALS['spaceJobs'] as $task) {
        $process = new Swoole\Process(static function (Swoole\Process $worker) use ($task): void {
            SpaceCoordinator::executeTask($task); $worker->exit(0);
        }, false, 0);
        $pid = $process->start();
        researchAssert($pid !== false && $pid !== getmypid(), 'Раунд не передан отдельному процессу.');
        $result = Swoole\Process::wait(true);
        researchAssert($result !== false && $result['code'] === 0, 'Ошибка боевого процесса.');
        $GLOBALS['spaceWorkerPids'][] = $pid;
    }
    $GLOBALS['spaceJobs'] = [];
}

function spaceFinish(int $key = 2): array
{
    for ($i = 0; $i < 30; ++$i) {
        $state = SpaceBattles::state($key);
        if ($state['phase'] === 'finished') return $state;
        researchAssert($state['phase'] === 'running', 'Бой не рассчитывается: ' . json_encode($state['error'] ?? $state['phase']));
        $time = $state['startedAt'] + count($state['combat']['rounds']) + 1;
        SpaceCoordinator::tick($time); spaceDrainWorkers(); SpaceCoordinator::tick($time + .01);
    }
    throw new RuntimeException('Бой не завершён за ограниченное число раундов.');
}

function spaceGameplay(): array
{
    $rows = [];
    foreach ([Fleets::class, FleetsShips::class, FleetCommands::class, Messages::class,
        SpaceDebrisFields::class, PlanetResources::class] as $class) {
        $rows[$class] = $class::getAll();
        ksort($rows[$class]);
        $indexes = [];
        foreach ((new ReflectionProperty($class, 'indexTables'))->getValue() as $name => $table) {
            $indexes[$name] = [];
            foreach ($table as $key => $row) {
                $ids = empty($row['ids']) ? [(int)$row['id']] : unserialize($row['ids']);
                sort($ids); $indexes[$name][$key] = $ids;
            }
            ksort($indexes[$name]);
        }
        ksort($indexes);
        $rows['indexes:' . $class] = $indexes;
    }
    return $rows;
}

function spaceReinforcement(int $ownerId, int $targetId, float $time): array
{
    Fleets::update(['id' => 3, 'owner_id' => $ownerId, 'status' => 1, 'mission' => 1,
        'current_coordinate_type' => 2, 'current_galaxy' => 1, 'current_system' => 1,
        'current_distance' => 2000.0, 'current_angle' => 20.0, 'current_position_time' => $time,
        'fuel_total' => 10000.0, 'fuel_current' => 10000.0]);
    FleetsShips::update(['id' => 3, 'owner_id' => $ownerId]);
    FleetsData::getInstance()->refresh(3);
    $fleet = FleetsData::getInstance()->get(3);
    $point = Coordinate::point(1, 1, 2000, 20);
    $command = SpaceControl::add(3, SpaceCommand::ATTACK, ['target' => Coordinate::fleet($targetId, $point)->toArray()]);
    $home = Coordinate::object(1, 1, $ownerId, ObjectType::PLANET)->toArray();
    SpaceControl::add(3, SpaceCommand::MOVE_IN_SYSTEM, ['target' => $home, 'speedFactor' => 10, 'returnLeg' => true]);
    return [$fleet, new AccountData($ownerId), $command];
}

class SpaceFailureTable extends Swoole\Table
{
    public int $failOn = 0;
    public bool $afterWrite = false;
    public bool $permanent = false;
    public function set(string $key, array $value): bool
    {
        if ($this->permanent || ($this->failOn > 0 && --$this->failOn === 0)) {
            if ($this->afterWrite) { parent::set($key, $value); throw new RuntimeException('Injected failure after runtime write'); }
            return false;
        }
        return parent::set($key, $value);
    }
}

function spaceFailure(string $class, int $on = 1, bool $after = false): SpaceFailureTable
{
    $property = new ReflectionProperty($class, 'table');
    $old = $property->getValue();
    $table = new SpaceFailureTable(128);
    foreach ((new ReflectionProperty($class, 'tableSchema'))->getValue()['columns'] as $name => $column) {
        $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 8);
    }
    $table->create();
    foreach ($old as $key => $row) $table->set((string)$key, $row);
    $table->failOn = $on; $table->afterWrite = $after;
    $property->setValue(null, $table);
    return $table;
}

if (defined('SP_GAME_SPACE_BATTLE_FIXTURE_ONLY') && SP_GAME_SPACE_BATTLE_FIXTURE_ONLY) return;

researchCase('space battle: фактическая встреча, блокируются только участники; replay без повторной остановки', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    $resources = PlanetResources::getAll();
    $waiting = SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    $state = SpaceBattles::state(2);
    researchAssert($state['phase'] === 'running' && $state['fleets'] === [1, 2], 'Нет отдельного пространственного боя.');
    researchAssert(PlanetBattles::active() === [] && PlanetResources::getAll() === $resources, 'Бой затронул планеты.');
    foreach ([101, 102, 103] as $planetId) SpaceCoordinator::assertPlanetAvailable($planetId);
    foreach ([1, 2] as $id) defendRejected(static fn() => SpaceCoordinator::assertFleetAvailable($id));
    SpaceCoordinator::assertFleetAvailable(3);
    researchAssert(FleetAttackService::mustWait($waiting), 'Scheduler продолжает боевую команду.');
    $before = spaceGameplay();
    researchAssert(SpaceBattle::engage($command, $a, $d, $owner, $defender, 1001) === $waiting && spaceGameplay() === $before
        && SpaceBattles::state(2) === $state, 'Replay заново заморозил участника/топливо.');
});

researchCase('space battle: движущаяся цель сохраняет оплаченный остаток топлива, позицию и прогресс', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture(false, true);
    $time = 2000.0;
    $point = FleetRendezvousService::position($d, $time);
    $physicalFuel = FleetTransferService::physicalFuel($d, $time);
    $a->setCurrentCoordinate($point, $time); $a->save();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, $time);
    $pending = FleetCommands::getNextPendingCommand(2);
    $params = SpaceControl::params($pending);
    researchAssert($d->getCurrentCoordinate()->toArray() === $point->toArray() && abs($d->getFuelCurrent() - $physicalFuel) < .000001,
        'Заморозка потеряла позицию или неоплаченный остаток участка.');
    researchAssert($params['pause'] === 'battle' && abs($params['progressDone'] - .25) < .000001
        && !isset($params['fuelCost'], $params['motionStart'], $params['motionEnd']), 'Повреждён оставшийся участок/прогресс.');
    researchAssert(SpaceBattles::state(2)['mission']['participants'][2]['fuel'] === $physicalFuel, 'В боевой snapshot попал не физический бак.');
});

researchCase('space battle: победа, XP, топливо по вместимости, половина груза, POINT-обломки, доклад, возврат', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    $resources = PlanetResources::getAll();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    $state = spaceFinish();
    researchAssert(count($GLOBALS['spaceWorkerPids']) > 0, 'Не было отдельных процессов раундов.');
    $accounts = SpaceBattle::process(1020);
    $state = SpaceBattles::state(2);
    researchAssert($state['phase'] === 'applied' && $state['resolution']['outcome'] === 'attacker' && $accounts === [1, 2],
        'Не применён результат победы: ' . json_encode([$state['phase'], $state['resolution']['outcome'] ?? null, $accounts]));
    researchAssert(Fleets::getById(2) === null && FleetsShips::findExistingById(2) === null && FleetCommands::getFleetCommandChain(2) === [], 'Уничтоженный флот оставил runtime строки.');
    $a = FleetsData::getInstance()->get(1);
    researchAssert($a->getShipCount(202) > 0 && $a->getShipCount(202) < 10 && $a->getShips()[202]->getExperience() > 0, 'Нет потерь/XP кораблей.');
    $move = FleetCommands::getActiveCommand(1);
    $fuel = FleetTransferService::physicalFuel($a, $a->getCurrentPositionTime());
    researchAssert(abs($fuel - 10000 * $a->getShipCount(202) / 10) < .000001, 'Бак уменьшен не по потерянной вместимости.');
    researchAssert((int)$move['command_type'] === SpaceCommand::MOVE_IN_SYSTEM->value && !empty(SpaceControl::params($move)['returnLeg']), 'Не активирован исходный маршрут домой.');
    researchAssert($a->getResources()[901]->getCount() === 50.0 && $a->getResources()[902]->getCount() === 30.0
        && $a->getResources()[903]->getCount() === 20.0, 'Захвачен не согласованный груз или топливо бака.');
    $fields = SpaceDebrisFields::atPoint(Coordinate::point(1, 1, 2000, 20));
    researchAssert(count($fields) === 1 && array_sum(array_column($fields, 'metal')) > 0 && PlanetResources::getAll() === $resources, 'Нет POINT-обломков или изменены ресурсы планеты.');
    researchAssert(count(Messages::getAll()) === 2 && BattleReportsMemory::$rows[$state['id']]['ready'], 'Нет двух сообщений/публичного доклада.');
    $report = BattleReportsMemory::$rows[$state['id']]['report'];
    researchAssert($report['target']['coordinate']['type'] === 2 && $report['moon'] === null && count($report['participants']) === 2,
        'Доклад содержит фиктивную планету/луну.');
    $after = spaceGameplay();
    SpaceBattle::process(1030); SpaceBattle::process(1040);
    researchAssert(spaceGameplay() === $after, 'Повтор результата изменил потери, XP, груз или обломки.');
    nativePlanFinish($owner, $a);
    researchAssert(FleetsData::getInstance()->get(1)->getStatus() === 0, 'Выживший флот не завершил возврат.');
});

researchCase('space battle: ничья — защитник остаётся на паузе, не возобновляется старый hold', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture(false);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    spaceFinish(); SpaceBattle::process(1020);
    $state = SpaceBattles::state(2);
    researchAssert($state['phase'] === 'applied' && $state['resolution']['outcome'] === 'draw', 'Ожидалась ничья.');
    $pending = FleetCommands::getNextPendingCommand(2);
    researchAssert(FleetCommands::getActiveCommand(2) === null && SpaceControl::params($pending)['pause'] === 'battle', 'Защитник сам возобновил действие.');
    SpaceCoordinator::assertFleetAvailable(2);
});

researchCase('space battle: неизвестный контакт, другая точка и скрытая экспедиция — без мутаций', static function (): void {
    foreach (['contact', 'point', 'expedition'] as $case) {
        [$owner, $a, $d, $defender, $command] = spaceFixture();
        if ($case === 'contact') Fleets::update(['id' => 2, 'target_coordinate_type' => 0]);
        elseif ($case === 'point') { $a->setCurrentCoordinate(Coordinate::point(1, 1, 3000, 40), 1000); $a->save(); }
        else SpaceControl::patchCommand((int)FleetCommands::getActiveCommand(2)['id'], ['command_type' => 40]);
        $before = spaceGameplay();
        defendRejected(static fn() => SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000));
        researchAssert(spaceGameplay() === $before && SpaceBattles::active() === [], 'Отказ оставил часть начала боя.');
    }
});

researchCase('space battle: отказ начала/команды после записи — rollback позиции, топлива, команд и checkpoint; retry', static function (): void {
    foreach ([[SpaceBattles::class, 1, true], [FleetCommands::class, 2, true], [FleetCommands::class, 2, false],
        [Fleets::class, 2, true], [Fleets::class, 2, false]] as [$class, $on, $after]) {
        [$owner, $a, $d, $defender, $command] = spaceFixture();
        $before = spaceGameplay();
        spaceFailure($class, $on, $after);
        $failed = false;
        try { SpaceBattle::engage($command, $a, $d, $owner, $defender, 1001); }
        catch (RuntimeException) { $failed = true; }
        researchAssert($failed && spaceGameplay() === $before && SpaceBattles::active() === [],
            'Начало боя не откатилось: ' . $class . ' after=' . (int)$after . ' threw=' . (int)$failed
            . ' equal=' . (int)(spaceGameplay() == $before));
        SpaceBattle::engage($command, $a, $d, $owner, $defender, 1001);
        $retry = SpaceBattles::forFleet(1);
        researchAssert($retry !== null && $retry['key'] > 2 && $retry['state']['phase'] === 'running', 'Retry начала не сработал.');
        researchAssert(!in_array($retry['key'], SpaceBattles::getPendingSync()['delete'], true), 'Retry оставил DELETE marker живого боя.');
    }
});

researchCase('space battle: SQL prepare/publish failure не повторяет gameplay commit', static function (): void {
    foreach (['prepare', 'publish'] as $phase) {
        [$owner, $a, $d, $defender, $command] = spaceFixture();
        SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish();
        $before = spaceGameplay();
        if ($phase === 'prepare') BattleReportsMemory::$failPrepare = true; else BattleReportsMemory::$failPublish = true;
        SpaceBattle::process(1020);
        $checkpoint = SpaceBattles::state(2);
        researchAssert($checkpoint['phase'] === ($phase === 'prepare' ? 'finished' : 'settled'), 'Отказ архива снял блокировку.');
        if ($phase === 'prepare') researchAssert(spaceGameplay() === $before, 'SQL prepare failure изменил gameplay.');
        else researchAssert(count(SpaceDebrisFields::getAll()) === 1 && count(Messages::getAll()) === 2, 'Не сохранён settled commit.');
        BattleReportsMemory::$failPrepare = BattleReportsMemory::$failPublish = false;
        SpaceBattle::process(1030);
        researchAssert(SpaceBattles::state(2)['phase'] === 'applied' && count(SpaceDebrisFields::getAll()) === 1
            && count(Messages::getAll()) === 2, 'Повтор публикации удвоил результат.');
    }
});

researchCase('space battle: runtime failure после частичной записи результата компенсируется; retry без двойной добычи', static function (): void {
    foreach ([[FleetsShips::class, 1, true], [Fleets::class, 1, true], [SpaceDebrisFields::class, 1, true],
        [Messages::class, 1, true], [FleetCommands::class, 1, true], [SpaceBattles::class, 2, true], [FleetCommands::class, 1, false]] as [$class, $on, $after]) {
        [$owner, $a, $d, $defender, $command] = spaceFixture();
        SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish();
        $before = spaceGameplay();
        spaceFailure($class, $on, $after);
        SpaceBattle::process(1020);
        researchAssert(spaceGameplay() === $before && SpaceBattles::state(2)['phase'] === 'finished', 'Gameplay не компенсирован: ' . $class);
        SpaceBattle::process(1030);
        researchAssert(SpaceBattles::state(2)['phase'] === 'applied' && count(Messages::getAll()) === 2
            && count(SpaceDebrisFields::getAll()) === 1, 'Повтор применения не удался: ' . $class);
    }
});

researchCase('space battle: подкрепления до/во время dispatch, обе стороны, идемпотентность и запрет третьего владельца', static function (): void {
    foreach ([[1, 2, 'attacker', false], [1, 2, 'attacker', true], [2, 1, 'defender', true]] as [$userId, $targetId, $side, $sealed]) {
        [$owner, $a, $d, $defender, $command] = spaceFixture(false);
        SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
        if ($sealed) SpaceCoordinator::tick(1001);
        [$extra, $extraOwner, $extraCommand] = spaceReinforcement($userId, $targetId, $sealed ? 1001.1 : 1000.5);
        $target = $targetId === 1 ? $a : $d;
        $targetOwner = $targetId === 1 ? $owner : $defender;
        $waiting = SpaceBattle::engage($extraCommand, $extra, $target, $extraOwner, $targetOwner, $sealed ? 1001.1 : 1000.5);
        $state = SpaceBattles::state(2);
        researchAssert($state['mission']['participants'][3]['side'] === $side
            && $state['mission']['participants'][3]['joinedRound'] === ($sealed ? 2 : 1), 'Подкрепление вступило не в тот раунд/сторону.');
        researchAssert(SpaceBattle::engage($extraCommand, $extra, $target, $extraOwner, $targetOwner, 1001.2) === $waiting
            && SpaceBattles::state(2) === $state, 'Повтор прибытия удвоил подкрепление.');
        if ($sealed) {
            spaceDrainWorkers(); SpaceCoordinator::tick(1001.3);
            researchAssert(!isset(SpaceBattles::state(2)['combat'][$side === 'attacker' ? 'a' : 'd']['fleet:3:202']), 'Запечатанный раунд получил позднее подкрепление.');
        }
        $state = spaceFinish(); SpaceBattle::process(1020);
        $report = BattleReportsMemory::$rows[$state['id']]['report'];
        researchAssert(isset($report['participants']['fleet:3']) && $report['participants']['fleet:3']['joinedRound'] === ($sealed ? 2 : 1), 'Доклад потерял подкрепление.');
        if ($side === 'defender') researchAssert(FleetCommands::getActiveCommand(3) === null
            && SpaceControl::params(FleetCommands::getNextPendingCommand(3))['pause'] === 'battle', 'Защитное подкрепление улетело без команды.');
    }
    [$owner, $a, $d, $defender, $command] = spaceFixture(false);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    [$extra, $extraOwner, $extraCommand] = spaceReinforcement(3, 2, 1000.5);
    $before = spaceGameplay(); $state = SpaceBattles::state(2);
    defendRejected(static fn() => SpaceBattle::engage($extraCommand, $extra, $d, $extraOwner, $defender, 1000.5));
    researchAssert(spaceGameplay() === $before && SpaceBattles::state(2) === $state, 'Третий владелец стал союзником.');
});

researchCase('space battle: победа защитника тоже захватывает груз, но не отправляет его домой', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    $d->setShipCount(202, 100); $d->save();
    foreach ([901 => 100, 902 => 60, 903 => 40] as $resourceId => $amount) $a->getResources()[$resourceId]->setCount($amount);
    $a->save();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish(); SpaceBattle::process(1020);
    $state = SpaceBattles::state(2);
    researchAssert($state['phase'] === 'applied' && $state['resolution']['outcome'] === 'defender' && Fleets::getById(1) === null, 'Не применена победа защитника.');
    $d = FleetsData::getInstance()->get(2);
    researchAssert($d->getResources()[901]->getCount() === 150.0 && $d->getResources()[902]->getCount() === 90.0
        && $d->getResources()[903]->getCount() === 60.0, 'Защитник не получил согласованную долю груза.');
    researchAssert(FleetCommands::getActiveCommand(2) === null && SpaceControl::params(FleetCommands::getNextPendingCommand(2))['pause'] === 'battle', 'Победивший защитник сам продолжил маршрут.');
});

researchCase('space battle: полный трюм не переполняется добычей после потери вместимости', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    $a->getResources()[901]->setCount($a->getCapacity() - $a->getFuelCurrent()); $a->save();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish(); SpaceBattle::process(1020);
    $state = SpaceBattles::state(2); $a = FleetsData::getInstance()->get(1);
    researchAssert($state['phase'] === 'applied' && array_sum($state['resolution']['loot']) === 0.0, 'Нет свободного места, но захвачен груз.');
    researchAssert($a->getUsedCapacity() + FleetTransferService::physicalFuel($a, $a->getCurrentPositionTime()) <= $a->getCapacity() + .000001,
        'Груз и физический бак превысили вместимость выживших.');
});

researchCase('space battle: смешанные вместимости теряют топливо по ёмкости, не по числу кораблей', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    Vars::$resource[204] = 'light_fighter'; Vars::$reslist['fleet'][] = 204;
    Vars::$attributes[204] = ['capacity' => 100, 'speed' => 5000, 'consumption' => 20, 'consumption2' => 20, 'tech' => 1, 'missions' => [1]];
    Vars::$pricelist[204] = [901 => 3000, 902 => 1000, 903 => 0];
    Vars::$CombatCaps[204] = ['attack' => 500, 'shield' => 0];
    defendColumns(FleetsShips::class, ['light_fighter_count' => Swoole\Table::TYPE_INT, 'light_fighter_status' => Swoole\Table::TYPE_INT,
        'light_fighter_experience' => Swoole\Table::TYPE_FLOAT, 'light_fighter_damage' => Swoole\Table::TYPE_FLOAT, 'light_fighter_morale' => Swoole\Table::TYPE_FLOAT]);
    $rows = FleetsShips::getAll();
    foreach ($rows as &$row) { $row['light_fighter_count'] = $row['fleet_id'] === 1 ? 10 : 0; $row['light_fighter_morale'] = 1.0; } unset($row);
    researchRepository(FleetsShips::class, $rows);
    $a->reloadFromRuntime(); $d->reloadFromRuntime();
    $capacity = $a->getCapacity(); $count = $a->getTotalShips();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish(); SpaceBattle::process(1020);
    $a = FleetsData::getInstance()->get(1);
    $fuel = FleetTransferService::physicalFuel($a, $a->getCurrentPositionTime());
    researchAssert(abs($fuel - 10000 * $a->getCapacity() / $capacity) < .000001, 'Бак рассчитан не по вместимости.');
    researchAssert(abs($fuel - 10000 * $a->getTotalShips() / $count) > .01, 'Сценарий не отличил долю кораблей от доли вместимости.');
});

researchCase('space battle: отказ release после запуска возврата не повторяет списание топлива', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish();
    spaceFailure(SpaceBattles::class, 3, true); SpaceBattle::process(1020);
    researchAssert(SpaceBattles::state(2)['phase'] === 'settled', 'Отказ release не удержал checkpoint.');
    $move = FleetCommands::getActiveCommand(1);
    researchAssert($move !== null && FleetAttackService::mustWait($move), 'Возврат исполняется до release боя.');
    $before = spaceGameplay(); SpaceBattle::process(1030);
    researchAssert(SpaceBattles::state(2)['phase'] === 'applied' && spaceGameplay() === $before && !FleetAttackService::mustWait($move),
        'Повтор release изменил уже подготовленный возврат.');
});

researchCase('space battle: долгий раунд допускает подкрепление владельца, не оживляя старый сенсор', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture(false);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); SpaceCoordinator::tick(1001);
    [$extra, $extraOwner, $extraCommand] = spaceReinforcement(2, 1, 1050);
    SpaceBattle::engage($extraCommand, $extra, $a, $extraOwner, $owner, 1050);
    researchAssert(SpaceBattles::state(2)['mission']['participants'][3]['joinedRound'] === 2, 'Placeholder ATTACK закрыл ещё идущий бой.');
});

researchCase('space battle: таймаут задания после restart повторяет только незакрытый раунд', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture(false);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); SpaceCoordinator::tick(1001);
    $oldTask = $GLOBALS['spaceJobs'][0]; $oldState = SpaceBattles::state(2);
    $GLOBALS['spaceJobs'] = [];
    SpaceCoordinator::initializeWorkers(static function (array $task): bool { $GLOBALS['spaceJobs'][] = $task; return true; }, 2);
    SpaceCoordinator::tick(1020);
    researchAssert(SpaceBattles::state(2) === $oldState, 'Перезапуск мгновенно переиграл активное задание.');
    SpaceCoordinator::tick(1062); SpaceCoordinator::tick(1062.1);
    researchAssert(SpaceBattles::state(2)['job']['round'] === 1 && SpaceBattles::state(2)['job']['token'] !== $oldTask['token'], 'Не перевыдан ровно первый раунд.');
    $GLOBALS['spaceJobs'][] = $oldTask; spaceDrainWorkers(); SpaceCoordinator::tick(1062.2);
    researchAssert(count(SpaceBattles::state(2)['combat']['rounds']) === 1, 'Поздний ответ старого процесса удвоил раунд.');
});

researchCase('space battle: защищённый состав изменён извне — fail closed без чужих списаний', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish();
    FleetsShips::update(['id' => 1, 'small_cargo_count' => 11]);
    $before = spaceGameplay(); SpaceBattle::process(1020);
    researchAssert(SpaceBattles::state(2)['phase'] === 'failed' && spaceGameplay() === $before, 'Нарушение checkpoint перезаписало чужие изменения.');
    defendRejected(static fn() => SpaceCoordinator::assertFleetAvailable(1));
});

researchCase('space battle: oversized command/checkpoint отклонены до частичного commit', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    $params = SpaceControl::params($command); $params['padding'] = '';
    $params['padding'] = str_repeat('x', 2040 - strlen(json_encode($params, JSON_THROW_ON_ERROR)));
    SpaceControl::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    $before = spaceGameplay(); $rejected = false;
    try { SpaceBattle::engage(FleetCommands::getCommand((int)$command['id']), $a, $d, $owner, $defender, 1000); }
    catch (OverflowException) { $rejected = true; }
    researchAssert($rejected && spaceGameplay() === $before && SpaceBattles::active() === [], 'Переполнение команды изменило состояние.');
    SpaceControl::patchCommand((int)$command['id'], ['params' => $command['params']]);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    $battle = SpaceBattles::forFleet(1); $key = $battle['key'];
    $old = $battle['state']; $oversized = $old; $oversized['padding'] = str_repeat('x', SpaceBattles::MAX_STATE_BYTES);
    $rejected = false; try { SpaceBattles::store($key, $oversized); } catch (OverflowException) { $rejected = true; }
    researchAssert($rejected && SpaceBattles::state($key) === $old, 'Переполнение боя повредило checkpoint.');
});

researchCase('space battle: планетарные и пространственные раунды используют один лимит workers', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture(false);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    $groups = FleetAttackService::fleetGroups($a, $owner);
    SpaceCoordinator::begin(101, $groups, $groups, 1000);
    $GLOBALS['spaceJobs'] = [];
    SpaceCoordinator::initializeWorkers(static function (array $task): bool { $GLOBALS['spaceJobs'][] = $task; return true; }, 1);
    SpaceCoordinator::tick(1001);
    researchAssert(count($GLOBALS['spaceJobs']) === 1 && SpaceBattles::state(2)['job'] === null, 'Оба типа боя превысили worker limit.');
    spaceDrainWorkers(); SpaceCoordinator::tick(1001.1);
    researchAssert(count($GLOBALS['spaceJobs']) === 1 && SpaceBattles::state(2)['job'] !== null, 'Освободившийся worker не принял пространственный раунд.');
    spaceDrainWorkers(); SpaceCoordinator::tick(1001.2);
    researchAssert(count(PlanetBattles::state(101)['combat']['rounds']) === 1 && count(SpaceBattles::state(2)['combat']['rounds']) === 1,
        'Mailboxes разных областей перепутали результаты раундов.');
});

researchCase('space debris: 300 полей в одной системе не усеклись в общем списке ID', static function (): void {
    spaceFixture();
    $name = (new ReflectionProperty(SpaceDebrisFields::class, 'tableName'))->getValue();
    (new ReflectionProperty(SpaceDebrisFields::class, 'tableName'))->setValue(null, '');
    (new ReflectionProperty(SpaceDebrisFields::class, 'tableSize'))->setValue(null, 512);
    try { SpaceDebrisFields::init(); }
    finally {
        (new ReflectionProperty(SpaceDebrisFields::class, 'tableName'))->setValue(null, $name);
        (new ReflectionProperty(SpaceDebrisFields::class, 'tableSize'))->setValue(null, 32768);
    }
    $last = null;
    for ($i = 1; $i <= 300; ++$i) {
        $last = SpaceDebrisFields::castRowToSchema(['battle_id' => md5('space-field-' . $i), 'galaxy' => 1, 'system' => 1,
            'distance' => 1500.0 + $i, 'angle' => (float)($i % 360), 'metal' => 1.0, 'crystal' => 2.0, 'update_time' => 1000.0], true);
        SpaceDebrisFields::add($last);
    }
    researchAssert(count(SpaceDebrisFields::inSystem(1, 1)) === 300 && count(SpaceDebrisFields::atPoint(SpaceDebrisFields::point($last))) === 1,
        'Длинный список полей усечён/повреждён.');
    SpaceDebrisFields::update(['id' => $last['id'], 'metal' => 0.0, 'crystal' => 0.0]);
    researchAssert(count(SpaceDebrisFields::inSystem(1, 1)) === 299 && SpaceDebrisFields::inSystem(1, 2) === [], 'Пустое поле или чужая система попали в отображаемые ресурсы.');
});

researchCase('space battle: GC ждёт flush результата, возврата и SQL DELETE, не меняет gameplay/доклад', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish(); SpaceBattle::process(1020);
    $state = SpaceBattles::state(2);
    $report = BattleReportsMemory::$rows[$state['id']];
    researchAssert(isset($state['resolution']['syncRows']), 'Нет ссылок для подтверждения flush.');
    $classes = [Fleets::class, FleetsShips::class, FleetCommands::class, SpaceDebrisFields::class, Messages::class, SpaceBattles::class];
    // Только fixture: имитация ACK saver, без SQL. Настоящий DELETE проверяется отдельным MySQL-набором.
    foreach ($classes as $class) {
        $sync = (new ReflectionProperty($class, 'syncTable'))->getValue();
        foreach ($sync as $key => $row) if ($key !== 'meta') $sync->set((string)$key, ['need_update' => 0, 'need_delete' => 0]);
    }
    foreach ($classes as $class) {
        $sync = (new ReflectionProperty($class, 'syncTable'))->getValue();
        $refs = $state['resolution']['syncRows'];
        $names = [Fleets::class => 'fleets', FleetsShips::class => 'ships', FleetCommands::class => 'commands',
            SpaceDebrisFields::class => 'debris', Messages::class => 'messages'];
        $id = $class === SpaceBattles::class ? 2 : $refs[$names[$class]][0];
        $sync->set((string)$id, ['need_update' => 1]);
        SpaceBattle::cleanupApplied();
        researchAssert(empty(SpaceBattles::state(2)['cleanupRequested']), 'GC не дождался flush: ' . $class);
        $sync->set((string)$id, ['need_update' => 0]);
    }
    $future = SpaceControl::add(1, SpaceCommand::STAY, ['at' => Coordinate::object(1, 1, 1, ObjectType::PLANET)->toArray()]);
    SpaceBattle::cleanupApplied();
    researchAssert(empty(SpaceBattles::state(2)['cleanupRequested']), 'GC не проверил команду, созданную после результата.');
    $sync = (new ReflectionProperty(FleetCommands::class, 'syncTable'))->getValue();
    $sync->set((string)$future['id'], ['need_update' => 0]);
    $before = spaceGameplay();
    SpaceBattle::cleanupApplied();
    researchAssert(!empty(SpaceBattles::state(2)['cleanupRequested']) && SpaceBattles::getPendingSync()['delete'] === [2], 'Не запрошен SQL DELETE.');
    researchAssert(!SpaceBattles::finishCleanup(2, $state['id']), 'Слот освобождён до SQL ACK.');
    SpaceBattle::cleanupApplied();
    researchAssert(SpaceBattles::state(2) !== null, 'Повтор GC не дождался SQL ACK.');
    $sync = (new ReflectionProperty(SpaceBattles::class, 'syncTable'))->getValue();
    $sync->set('2', ['need_delete' => 0]);
    SpaceBattle::cleanupApplied();
    researchAssert(SpaceBattles::state(2) === null && spaceGameplay() === $before && count(Messages::getAll()) === 2
        && BattleReportsMemory::$rows[$state['id']] === $report && $report['ready'],
        'Освобождение checkpoint затронуло gameplay/уведомления.');
    researchAssert(SpaceBattles::finishCleanup(2, $state['id']), 'Повтор очистки не идемпотентен.');
});

researchCase('space battle: отказ marker очистки компенсируется и допускает повтор', static function (): void {
    foreach ([false, true] as $after) {
        spaceFixture();
        $state = ['id' => bin2hex(random_bytes(16)), 'phase' => 'applied', 'fleets' => [1, 2]];
        SpaceBattles::store(2, $state);
        $sync = (new ReflectionProperty(SpaceBattles::class, 'syncTable'))->getValue();
        $sync->set('2', ['need_update' => 0]);
        spaceFailure(SpaceBattles::class, 1, $after);
        $rejected = false;
        try { SpaceBattles::requestCleanup(2, $state['id']); } catch (RuntimeException) { $rejected = true; }
        researchAssert($rejected && SpaceBattles::state(2) === $state && SpaceBattles::getPendingSync()['delete'] === [], 'Не откатился marker очистки.');
        SpaceBattles::requestCleanup(2, $state['id']);
        researchAssert(!SpaceBattles::finishCleanup(2, $state['id']), 'Retry преждевременно освободил слот.');
    }
});

researchCase('space battle: новый бой с прежней целью не перезаписывает checkpoint; stale space wait закрыт', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    $old = ['id' => bin2hex(random_bytes(16)), 'phase' => 'applied', 'fleets' => [1, 2]];
    SpaceBattles::store(2, $old); SpaceBattles::setLastId(2);
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000);
    researchAssert(SpaceBattles::state(2) === $old && SpaceBattles::forFleet(1)['key'] === 3, 'Новый бой перезаписал старую историю.');
    $rejected = false;
    try { SpaceCoordinator::beginSpace(2, [], [], 1000); } catch (LogicException) { $rejected = true; }
    researchAssert($rejected && SpaceBattles::state(2) === $old, 'Coordinator переиспользовал занятый key.');
    $wait = ['fleet_id' => 999, 'command_type' => SpaceCommand::ATTACK->value,
        'params' => json_encode(['battleWait' => ['scope' => 'space', 'key' => 500, 'id' => 'missing']])];
    researchAssert(FleetAttackService::mustWait($wait), 'Потерянный space wait стал планетарной атакой.');
});

researchCase('space battle: отказ самого rollback логируется CRITICAL с исходной ошибкой, флот не разблокируется', static function (): void {
    [$owner, $a, $d, $defender, $command] = spaceFixture();
    SpaceBattle::engage($command, $a, $d, $owner, $defender, 1000); spaceFinish();
    $table = spaceFailure(FleetsShips::class, 1, true); $table->permanent = true;
    $logger = \SPGame\Core\Logger::getInstance();
    $echo = new ReflectionProperty($logger, 'echoToConsole'); $beforeEcho = $echo->getValue($logger); $echo->setValue($logger, true);
    ob_start();
    try { SpaceBattle::process(1020); }
    finally { $output = ob_get_clean(); $table->permanent = false; $echo->setValue($logger, $beforeEcho); }
    researchAssert(str_contains($output, '[CRITICAL]') && str_contains($output, 'originalMessage')
        && str_contains($output, 'Injected failure after runtime write') && str_contains($output, 'Завершение боя в пространстве отложено'),
        'Исходная ошибка или CRITICAL rollback потеряны.');
    researchAssert(SpaceBattles::state(2)['phase'] === 'failed', 'Неуспешный rollback снял защиту.');
    defendRejected(static fn() => SpaceCoordinator::assertFleetAvailable(1));
});

echo "RESULT space battle: $passed passed / $failed failed; SQL calls=" . \SPGame\Core\Database::$calls . "\n";
restore_error_handler();
exit($failed > 0 || \SPGame\Core\Database::$calls > 0 ? 1 : 0);
