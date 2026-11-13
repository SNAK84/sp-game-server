<?php

declare(strict_types=1);

// Настоящие services/repositories/Fleet с изолированными runtime Tables, без игровых данных.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{DebrisFields, FleetCommands, Fleets, FleetsShips, Messages, Planets, Users, Vars};
use SPGame\Game\Services\{AccountData, EventLoop, FleetCommandsService, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};
use Swoole\Table;

function recycleFixture(int $recyclers = 1, int $transports = 0, int $gigaRecyclers = 0): array
{
    $f = installTransportFixture();
    Vars::$resource += [209 => 'recycler', 219 => 'giga_recycler'];
    Vars::$reslist['fleet'] = [202, 209, 219];
    Vars::$attributes[209] = ['capacity' => 20000, 'speed' => 5000, 'consumption' => 20,
        'consumption2' => 20, 'tech' => 1, 'missions' => [FleetMissionType::RECYCLE->value]];
    Vars::$attributes[219] = Vars::$attributes[209];
    Vars::$attributes[219]['capacity'] = 50000;
    foreach (['recycler', 'giga_recycler'] as $name) {
        appendTransportSchemaColumns(FleetsShips::class, [
            $name . '_count' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
            $name . '_damage' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            $name . '_experience' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            $name . '_morale' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 1.0],
            $name . '_status' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
        ]);
    }
    FleetsShips::update(FleetsShips::castRowToSchema(array_replace(FleetsShips::findById(1),
        ['small_cargo_count' => $transports, 'recycler_count' => $recyclers, 'giga_recycler_count' => $gigaRecyclers]), true));
    FleetsData::getInstance()->remove(1, false);
    $f['fleet'] = FleetsData::getInstance()->get(1);
    (new ReflectionClass(AccountData::class))->getProperty('FleetsData')->setValue($f['account'], FleetsData::getInstance());
    $f['coordinates'] = ['type' => 1, 'galaxy' => 1, 'system' => 10, 'orbit' => 8, 'objectType' => 2];
    installTransportRepository(DebrisFields::class, transportTable(), transportSyncTable(), [
        'galaxy_system_orbit' => new Table(), 'galaxy_system' => new Table(),
    ]);
    return $f;
}

function recycleStart(array $f, array $cargo = []): float
{
    $travel = $f['fleet']->getTravelInfo(FleetTarget::fromCoordinates($f['coordinates']), $f['account'], 10, FleetMissionType::RECYCLE);
    $fuel = ceil($travel['fuel']['max']);
    FleetCommandsService::startMission($f['fleet'], $f['account'], FleetMissionType::RECYCLE->value,
        $f['coordinates'], 10, $fuel, $cargo, 100);
    return $fuel;
}

function recycleNear(float $left, float $right, string $message): void
{
    transportAssert(abs($left - $right) < 0.000001, $message . " ({$left} != {$right})");
}

function testRecycleFullCollectReturnAndMessages(): void
{
    $f = recycleFixture();
    $field = DebrisFields::addResources(1, 10, 8, 1200, 800, 90);
    $fuel = recycleStart($f);
    $page = new FleetsPage(new \SPGame\Core\Message());
    $flights = array_values($page->buildFlyingFleetList($f['account']));
    transportAssert((float)$flights[0]['mission_end_time'] > 100, 'RECYCLE должен иметь прогноз полного завершения.');
    $chain = FleetCommands::getFleetCommandChain(1);
    foreach ($chain as &$command) $command['params'] = json_decode($command['params'], true);
    unset($command);
    transportAssert(array_column($chain, 'command_type') === [31, 10, 33, 10, 32, 52], 'Существующая цепочка с возвращением.');
    transportAssert($chain[3]['params']['returnLeg'] && $chain[4]['params']['unloadFuel'], 'Возврат/выгрузка топлива должны быть помечены.');
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert($f['fleet']->isReturn(), 'После переработки активируется возврат.');
    transportAssert(DebrisFields::at(1, 10, 8)['metal'] === 0.0 && DebrisFields::at(1, 10, 8)['crystal'] === 0.0,
        'Полный сбор должен точно обнулить оба запаса.');
    recycleNear($f['fleet']->getResources()[901]->getCount(), 1200, 'Собран металл');
    recycleNear($f['fleet']->getResources()[902]->getCount(), 800, 'Собран кристалл');
    transportAssert(in_array($field['id'], DebrisFields::getPendingSync()['update'], true), 'Нулевой запас должен сохраняться в MySQL.');
    $report = Messages::findByIndex('to', [100])[0];
    transportAssert($report['sample'] === 'FleetRecycleCollected' && json_decode($report['data'], true)['Resources'][901] === 1200,
        'Отчёт содержит реальный сбор.');
    $remainingFuel = $f['fleet']->getFuelCurrent();
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    recycleNear($f['account']->resourceCount(10, 901), 11200, 'Груз выгружен на исходную планету');
    recycleNear($f['account']->resourceCount(10, 902), 10800, 'Кристалл выгружен');
    recycleNear($f['account']->resourceCount(10, 903), 10000 - $fuel + $remainingFuel, 'Остаток топлива возвращён');
    transportAssert($f['fleet']->getStatus() === Fleet::STATUS_IDLE && FleetCommands::getFleetQueue(1) === [], 'Миссия полностью закончена.');
    transportAssert(count(Messages::findByIndex('to', [100])) === 2, 'Отчёты о сборе и возвращении.');
}

function testRecycleCapacityIsNotTransportCapacity(): void
{
    $f = recycleFixture(1, 2);
    DebrisFields::addResources(1, 10, 8, 60000, 40000, 90);
    recycleStart($f);
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    recycleNear($f['fleet']->getUsedCapacity(), 20000, 'Сопровождающие транспорты не увеличивают объём переработки');
    recycleNear($f['fleet']->getResources()[901]->getCount(), 12000, 'Пропорциональный сбор металла');
    recycleNear($f['fleet']->getResources()[902]->getCount(), 8000, 'Пропорциональный сбор кристалла');
    recycleNear(DebrisFields::at(1, 10, 8)['metal'], 48000, 'Остаток металла сохраняется');
}

function testRecycleCargoAndFuelOccupyCapacity(): void
{
    $f = recycleFixture();
    DebrisFields::addResources(1, 10, 8, 60000, 40000, 90);
    recycleStart($f, [901 => 1000]);
    $freeBeforeCollect = $f['fleet']->getFreeCapacity();
    $resourcesBefore = $f['fleet']->getUsedCapacity();
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    recycleNear($f['fleet']->getUsedCapacity() - $resourcesBefore, $freeBeforeCollect,
        'Топливо и существующий груз уменьшают свободный трюм на момент сбора');
    transportAssert($f['fleet']->getUsedCapacity() + $f['fleet']->getFuelCurrent() <= $f['fleet']->getCapacity() + 0.000001,
        'Трюм не должен переполняться.');
}

function testRecycleFieldChangedOrDisappearedDuringFlight(): void
{
    foreach ([false, true] as $deleted) {
        $f = recycleFixture();
        $field = DebrisFields::addResources(1, 10, 8, 1000, 500, 90);
        recycleStart($f);
        if ($deleted) DebrisFields::delete(['id' => $field['id']]);
        else DebrisFields::subtractResources(1, 10, 8, 1000, 500, 110);
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        transportAssert($f['fleet']->isReturn() && $f['fleet']->getUsedCapacity() === 0.0,
            'Опустевшее поле приводит к нулевому сбору и нормальному возврату.');
        transportAssert(DebrisFields::count() === ($deleted ? 0 : 1), 'Чтение не должно создавать запас.');
    }
}

function testRecycleRejectsInvalidRequestsWithoutMutations(): void
{
    foreach (['noShips', 'empty', 'planet', 'point', 'badFuel', 'wrongOwner'] as $case) {
        $f = recycleFixture($case === 'noShips' ? 0 : 1, $case === 'noShips' ? 1 : 0);
        if ($case !== 'empty') DebrisFields::addResources(1, 10, 8, 1000, 500, 90);
        $coordinates = $f['coordinates'];
        if ($case === 'planet') $coordinates['objectType'] = 1;
        if ($case === 'point') $coordinates = ['type' => 2, 'galaxy' => 1, 'system' => 10, 'distance' => 1600, 'angle' => 0];
        if ($case === 'wrongOwner') $f['account']['User']['id'] = 200;
        $rejected = false;
        try { FleetCommandsService::prepareMissionRequest($f['fleet'], $f['account'], 12,
            $coordinates, 10, $case === 'badFuel' ? 0 : 10, []); }
        catch (\InvalidArgumentException $e) { $rejected = true; }
        transportAssert($rejected && FleetCommands::getFleetQueue(1) === [] && $f['fleet']->getUsedCapacity() === 0.0,
            "{$case}: нельзя изменять очередь или груз.");
        recycleNear($f['account']->resourceCount(10, 903), 10000, 'Отклонение не списывает топливо');
    }
}

function testRecycleSameOrbitAndGigaRecycler(): void
{
    $f = recycleFixture(0, 0, 1);
    $f['coordinates']['orbit'] = 7;
    DebrisFields::addResources(1, 10, 7, 30000, 10000, 90);
    recycleStart($f);
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    recycleNear($f['fleet']->getUsedCapacity(), 40000, 'Любой реальный корабль с capability RECYCLE участвует в сборе');
    transportAssert($f['fleet']->getCurrentCoordinate()->getObjectType() === ObjectType::DEBRIS, 'Обломки своей орбиты доступны без смены identity.');
}

function testRecycleSingleResourceAndFullCargo(): void
{
    foreach ([[0.0, 1000.0], [1000.0, 0.0]] as [$metal, $crystal]) {
        $f = recycleFixture();
        DebrisFields::addResources(1, 10, 8, $metal, $crystal, 90);
        recycleStart($f);
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        recycleNear($f['fleet']->getResources()[901]->getCount(), $metal, 'Сбор единственного ресурса: металл');
        recycleNear($f['fleet']->getResources()[902]->getCount(), $crystal, 'Сбор единственного ресурса: кристалл');
    }
    $f = recycleFixture();
    $field = DebrisFields::addResources(1, 10, 8, 1000, 500, 90);
    recycleStart($f);
    $f['fleet']->getResources()[901]->add($f['fleet']->getFreeCapacity());
    $f['fleet']->save();
    $cargo = $f['fleet']->getUsedCapacity();
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert($f['fleet']->isReturn() && DebrisFields::at(1, 10, 8) === $field, 'Полный трюм не изменяет поле и не блокирует возврат.');
    recycleNear($f['fleet']->getUsedCapacity(), $cargo, 'Нет груза сверх свободной вместимости');
}

function testRecycleRuntimeFailuresRollbackAndRetry(): void
{
    foreach ([DebrisFields::class, Fleets::class, FleetCommands::class] as $repository) {
        $f = recycleFixture();
        $field = DebrisFields::addResources(1, 10, 8, 1000, 500, 90);
        recycleStart($f);
        $recycle = array_values(array_filter(FleetCommands::getFleetQueue(1),
            static fn($c) => $c['command_type'] === 33))[0];
        $reflection = new ReflectionClass($repository);
        $table = $reflection->getProperty('table')->getValue();
        // Для Fleet ошибка нужна после MOVE save, непосредственно при сохранении груза.
        if ($repository === Fleets::class) {
            $move = FleetCommands::getActiveCommand(1);
            $f['fleet']->setCurrentCoordinate(Coordinate::object(1, 10, 8, ObjectType::DEBRIS), $move['execute_time']);
            $f['fleet']->save();FleetCommands::markExecuted($move['id']);
        }
        $table->failedWrites = 1;
        $table->failedWriteKey = (string)($repository === FleetCommands::class ? $recycle['id'] : 1);
        $failed = false;
        try {
            if ($repository === Fleets::class) FleetCommandsService::activateNextCommand($f['fleet'], $f['account']);
            else FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        } catch (\RuntimeException $e) { $failed = true; }
        transportAssert($failed && DebrisFields::at(1, 10, 8) === $field, "{$repository}: запас/время восстановлены.");
        transportAssert($f['fleet']->getUsedCapacity() === 0.0 && Fleets::getById(1)['resource_metal'] === 0.0,
            'Груз восстановлен в объекте и Swoole Table.');
        transportAssert(FleetCommands::getCommand($recycle['id'])['executed'] === 0 && (Messages::findByIndex('to', [100]) ?? []) === [],
            'Failed сбор не завершает команду и не создаёт успешный отчёт.');
        FleetCommandsService::activateNextCommand($f['fleet'], $f['account']);
        recycleNear($f['fleet']->getUsedCapacity(), 1500, 'Повторная обработка после rollback выполняется один раз');
        transportAssert($f['fleet']->isReturn(), 'Повторная обработка продолжает маршрут.');
    }
}

function testRecycleSequentialFleetsCannotOverdraw(): void
{
    $f = recycleFixture(1, 1);
    // Сценарий с двумя одновременными миссиями требует двух компьютерных слотов.
    $f['account']['Techs']['computer_tech'] = 1;
    DebrisFields::addResources(1, 10, 8, 15000, 15000, 90);
    $row = Fleets::getById(1);$row['id'] = 2;Fleets::add($row);
    $ships = FleetsShips::findById(1);$ships['id'] = $ships['fleet_id'] = 2;FleetsShips::add($ships);
    $second = $f;$second['fleet'] = new Fleet(2);
    recycleStart($f);recycleStart($second);
    foreach ([$f, $second] as $entry) {
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand($entry['fleet']->getId()), $entry['fleet'], $entry['account']);
    }
    recycleNear($f['fleet']->getUsedCapacity() + $second['fleet']->getUsedCapacity(), 30000, 'Два последовательных прилёта сохраняют общее количество ресурсов');
    transportAssert(DebrisFields::at(1, 10, 8)['metal'] === 0.0 && DebrisFields::at(1, 10, 8)['crystal'] === 0.0,
        'Запас не уходит в минус и не дублируется.');
}

function testRecycleFractionalRemainderAndRepeatedCommand(): void
{
    $f = recycleFixture();
    DebrisFields::addResources(1, 10, 8, 8.3, 1.7, 90);
    recycleStart($f);
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert(DebrisFields::at(1, 10, 8)['metal'] === 0.0 && DebrisFields::at(1, 10, 8)['crystal'] === 0.0, 'Дробные остатки точно обнуляются.');
    $count = count(Messages::findByIndex('to', [100]));
    $method = new ReflectionMethod(FleetCommandsService::class, 'executeImmediateCommand');
    $executed = array_values(array_filter(FleetCommands::getFleetCommandChain(1), static fn($c) => $c['command_type'] === 33))[0];
    $rejected = false;
    try { $method->invoke(null, $executed, $f['fleet'], $f['account']); }
    catch (\LogicException $e) { $rejected = true; }
    transportAssert($rejected && count(Messages::findByIndex('to', [100])) === $count, 'Выполненная команда не применяется повторно.');
    recycleNear($f['fleet']->getUsedCapacity(), 10, 'Нет дополнительного груза');
}

function testRecycleRefreshTargetsPlanetOwner(): void
{
    $f = recycleFixture();
    Planets::update(['id' => 11, 'owner_id' => 200]);
    installTransportRepository(Users::class, transportTable([
        Users::castRowToSchema(['id' => 200, 'account_id' => 2000], true),
    ]), transportSyncTable(200), []);
    $f['fleet']->setTargetCoordinate(Coordinate::object(1, 10, 8, ObjectType::DEBRIS));
    $method = new ReflectionMethod(EventLoop::class, 'getFleetTargetAccountId');
    transportAssert($method->invoke(new EventLoop(), $f['fleet']) === 2000, 'Обновление обломков использует существующий ActualData получателя.');
    $f['fleet']->setReturn(true);
    transportAssert($method->invoke(new EventLoop(), $f['fleet']) === null, 'При возврате бывший получатель больше не считается целью.');
}

foreach (['testRecycleFullCollectReturnAndMessages', 'testRecycleCapacityIsNotTransportCapacity',
    'testRecycleCargoAndFuelOccupyCapacity', 'testRecycleFieldChangedOrDisappearedDuringFlight',
    'testRecycleRejectsInvalidRequestsWithoutMutations', 'testRecycleSameOrbitAndGigaRecycler', 'testRecycleSingleResourceAndFullCargo',
    'testRecycleRuntimeFailuresRollbackAndRetry', 'testRecycleSequentialFleetsCannotOverdraw',
    'testRecycleFractionalRemainderAndRepeatedCommand', 'testRecycleRefreshTargetsPlanetOwner'] as $test) {
    $test();echo "OK {$test}\n";
}
