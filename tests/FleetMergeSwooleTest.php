<?php

declare(strict_types=1);

define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{FleetAttackGroups, FleetCommands, Fleets, FleetsShips, PlanetResources, Vars};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetControlService as Control,
    FleetMergeService as Merge, FleetMissionService, FleetRendezvousService as Meeting,
    FleetTransferService as Transfer, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};

function mergeFixture(): array
{
    [$owner, $donor] = nativePlanFixture();
    researchRepository(FleetAttackGroups::class, []);
    Fleets::update(['id' => 2, 'owner_id' => 1, 'home_planet_id' => 101, 'name' => 'Принимающий']);
    FleetsShips::update(['id' => 2, 'owner_id' => 1]);
    $receiver = FleetsData::getInstance()->get(2);
    $receiver->setStatus(Fleet::STATUS_MISSION);
    $receiver->setMission(Mission::OBSERVE->value);
    $receiver->setCurrentCoordinate(Coordinate::point(1, 1, 1500, 0), 1000);
    $receiver->setFuelTotal(300); $receiver->setFuelCurrent(300);
    $receiver->getResources()[901]->setCount(15);
    $receiver->getResources()[921]->setCount(1e9);
    $receiver->save();
    Control::add(2, Command::OBSERVE, ['target' => $receiver->getCurrentCoordinate()->toArray(), 'pause' => 'manual']);
    return [$owner, $donor, $receiver];
}
function mergeArrive($owner, $donor): array
{
    $target = FleetTarget::fromCoordinates(['type' => 3, 'fleetId' => 2]);
    $preview = $donor->getTravelInfo($target, $owner, 10, Mission::MERGE);
    Commands::startMission($donor, $owner, 35, ['type' => 3, 'fleetId' => 2], 10,
        (int)ceil($preview['fuel']['max']), [901 => 40, 902 => 30, 903 => 20], 1000);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $donor, $owner);
    $merge = FleetCommands::getActiveCommand(1);
    researchAssert((int)($merge['command_type'] ?? 0) === 53, 'MERGE не является отдельным due событием.');
    return $merge;
}
function mergeSnapshot(): array
{
    return [Fleets::getAll(), FleetsShips::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
}
function mergeFastReceiver($receiver): void
{
    Vars::$reslist['fleet'][] = 203;
    Vars::$resource[203] = 'large_cargo';
    Vars::$attributes[203] = array_replace(Vars::$attributes[202], ['speed' => 20000]);
    Vars::$pricelist[203] = Vars::$pricelist[202];
    defendColumns(FleetsShips::class, ['large_cargo_count' => \Swoole\Table::TYPE_INT,
        'large_cargo_status' => \Swoole\Table::TYPE_INT, 'large_cargo_damage' => \Swoole\Table::TYPE_FLOAT,
        'large_cargo_experience' => \Swoole\Table::TYPE_FLOAT, 'large_cargo_morale' => \Swoole\Table::TYPE_FLOAT]);
    researchRepository(FleetsShips::class, array_values(FleetsShips::getAll()));
    FleetsShips::update(['id' => 2, 'small_cargo_count' => 0, 'large_cargo_count' => 10, 'large_cargo_morale' => 1]);
    $receiver->reloadFromRuntime();
}
function mergeSameSnapshot(array $before): bool
{
    $after = mergeSnapshot();
    foreach ([$before, $after] as &$snapshot) foreach ($snapshot as &$rows) ksort($rows);
    unset($snapshot, $rows);
    return $before === $after;
}
class MergeFailureTable extends \Swoole\Table
{
    public ?Closure $failSet = null;
    public ?Closure $failDelete = null;
    public function set(string $key, array $value): bool
    {
        if ($this->failSet !== null && ($this->failSet)($key, $value)) { $this->failSet = null; return false; }
        return parent::set($key, $value);
    }
    public function del(string $key): bool
    {
        if ($this->failDelete !== null && ($this->failDelete)($key)) { $this->failDelete = null; return false; }
        return parent::del($key);
    }
}
function mergeFailure(string $repo, bool $delete): void
{
    $property = new ReflectionProperty($repo, 'table');
    $table = new MergeFailureTable(128);
    foreach ((new ReflectionProperty($repo, 'tableSchema'))->getValue()['columns'] as $name => $column) $table->column($name, ...$column['swoole']);
    $table->create();
    foreach ($property->getValue() as $row) $table->set((string)$row['id'], $row);
    $property->setValue(null, $table);
    if ($delete) $table->failDelete = static fn($key) => $repo === FleetCommands::class || $key === '1';
    else $table->failSet = static fn($key) => $key === '2';
}

researchCase('merge: доступность только для своего небазирующегося флота; prepare read-only', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $before = mergeSnapshot();
    $available = (new FleetMissionService())->getAvailableMissions($donor, FleetTarget::fromCoordinates(['type' => 3, 'fleetId' => 2]), $owner);
    researchAssert(in_array(35, $available['missions'], true), 'Нет миссии объединения.');
    Commands::prepareMissionAction($donor, $owner, Mission::MERGE, ['type' => 3, 'fleetId' => 2], at: 1000);
    researchAssert(mergeSameSnapshot($before), 'Prepare изменил runtime.');
    researchAssert(Merge::reason($donor, 1) !== null && Merge::reason($donor, 3) !== null, 'Принят свой ID или чужой флот.');
    $receiver->setStatus(Fleet::STATUS_IDLE); $receiver->setCurrentCoordinate(Coordinate::object(1, 1, 2, ObjectType::PLANET), 1000); $receiver->save();
    researchAssert(Merge::reason($donor, 2) !== null, 'Базирующийся флот принят как космическая цель.');
});
researchCase('merge: стоянка — корабли/XP/повреждения/груз/валюта/топливо сохранены; replay no-op', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $donor->getShip(202)->setExperience(120); $donor->getShip(202)->setDamage(4); $donor->save();
    $receiver->getShip(202)->setExperience(60); $receiver->getShip(202)->setDamage(2); $receiver->save();
    $merge = mergeArrive($owner, $donor);
    $fuel = Transfer::physicalFuel($receiver, $donor->getCurrentPositionTime()) + $donor->getFuelCurrent();
    $queue = FleetCommands::getFleetCommandChain(2); $home = $receiver->getHomePlanetId();
    Commands::completeCommand($merge, $donor, $owner);
    researchAssert(Fleets::getById(1) === null && FleetsShips::findExistingById(1) === null
        && FleetCommands::getFleetCommandChain(1) === [] && FleetsData::getInstance()->get(1) === null, 'Донор остался в runtime/cache.');
    researchAssert($receiver->getShipCount(202) === 20 && $receiver->getShip(202)->getExperience() === 180.0
        && $receiver->getShip(202)->getDamage() === 3.0 && $receiver->getExperience() === 9.0, 'Потеряны корабли/XP/повреждения.');
    researchAssert($receiver->getName() === 'Принимающий' && $receiver->getHomePlanetId() === $home
        && FleetCommands::getFleetCommandChain(2) === $queue && Control::state($receiver)['reason'] === 'manual', 'Изменены имя, база или пауза/план.');
    researchAssert($receiver->getFuelCurrent() === $fuel && $receiver->getResources()[901]->getCount() === 55.0
        && $receiver->getResources()[902]->getCount() === 30.0 && $receiver->getResources()[903]->getCount() === 20.0
        && $receiver->getResources()[921]->getCount() === 1e9, 'Не сохранены груз/валюта/топливо.');
    $after = mergeSnapshot(); Commands::completeCommand($merge, $donor, $owner);
    researchAssert(mergeSameSnapshot($after), 'Stale replay повторил слияние.');
});
researchCase('merge: движущийся получатель — новая траектория из точки встречи, без двойного топлива', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    mergeFastReceiver($receiver);
    FleetCommands::clearFleetQueue(2);
    $receiver->setCurrentCoordinate(Coordinate::point(1, 1, 1200, 15), 1000);
    $receiver->setStartCoordinate($receiver->getCurrentCoordinate());
    $receiver->setTargetCoordinate(Coordinate::point(1, 1, 2400, 160)); $receiver->save();
    Control::add(2, Command::MOVE_IN_SYSTEM, ['target' => $receiver->getTargetCoordinate()->toArray(), 'speedFactor' => 1, 'progressWeight' => 1]);
    Control::add(2, Command::OBSERVE, ['target' => $receiver->getTargetCoordinate()->toArray(), 'pause' => 'manual']);
    Commands::activateNextCommand($receiver, $owner);
    $old = FleetCommands::getActiveCommand(2); $oldSpeed = $receiver->getTotalSpeed($owner);
    $merge = mergeArrive($owner, $donor);
    $time = $donor->getCurrentPositionTime(); $position = Meeting::position($receiver, $time);
    $physical = Transfer::physicalFuel($receiver, $time) + $donor->getFuelCurrent();
    $suffix = FleetCommands::getFleetQueue(2)[1];
    Commands::completeCommand($merge, $donor, $owner);
    $active = FleetCommands::getActiveCommand(2);
    researchAssert($receiver->getTotalSpeed($owner) < $oldSpeed && $receiver->getShipCount(202) === 10
        && $receiver->getShipCount(203) === 10, 'Разные типы/медленный донор не учтены.');
    researchAssert((int)$active['id'] === (int)$old['id'] && $receiver->getCurrentPositionTime() === $time
        && Meeting::distance($receiver->getCurrentCoordinate(), $position, $time) < .00001, 'Получатель телепортирован или потерял текущую команду.');
    researchAssert(abs(Transfer::physicalFuel($receiver, $time) - $physical) < .000001, 'Повторно списано уже израсходованное топливо.');
    researchAssert(FleetCommands::getCommand((int)$suffix['id']) === $suffix, 'Перезаписан будущий маршрут получателя.');
});
researchCase('merge: смена курса — встреча рассчитывается заново, слияния на расстоянии нет', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $merge = mergeArrive($owner, $donor);
    $donor->setFuelTotal(40); $donor->setFuelCurrent(40); $donor->save();
    $receiver->setCurrentCoordinate(Coordinate::point(1, 1, 2100, 80), 1000); $receiver->save();
    Commands::completeCommand($merge, $donor, $owner);
    researchAssert($receiver->getShipCount(202) === 10 && Fleets::getById(1) !== null
        && (int)FleetCommands::getActiveCommand(1)['command_type'] === 10, 'Слияние произошло без встречи либо потерян retry-перелёт.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $donor, $owner);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $donor, $owner);
    researchAssert(Fleets::getById(1) === null && $receiver->getShipCount(202) === 20, 'Повторная встреча не завершила слияние.');
});
researchCase('merge: ограничения повторяются при прибытии; САБ/оборона/переход/экспедиция запрещены', static function (): void {
    foreach ([11, 12, 22, 40, 20] as $type) {
        [$owner, $donor, $receiver] = mergeFixture();
        $merge = mergeArrive($owner, $donor);
        FleetCommands::clearFleetQueue(2);
        $blocked = Control::add(2, Command::from($type), ['target' => $receiver->getCurrentCoordinate()->toArray()]);
        Control::patchCommand((int)$blocked['id'], ['execute_time' => 2000.0]);
        researchAssert(Merge::reason($donor, 2) !== null, 'Не запрещён тип ' . $type);
        Commands::completeCommand($merge, $donor, $owner);
        researchAssert(Fleets::getById(1) !== null && $receiver->getShipCount(202) === 10
            && Control::state($donor)['reason'] === 'target', 'Ограничение прибытия не сохранило флоты.');
    }
    [$owner, $donor] = mergeFixture();
    FleetAttackGroups::store(['id' => 1, 'fleets' => '[2]', 'users' => '[1]']);
    $member = Control::add(2, Command::GROUP_ATTACK, ['groupId' => 1, 'target' => ['type' => 1, 'galaxy' => 1, 'system' => 1, 'orbit' => 3, 'objectType' => 1]]);
    researchAssert(Merge::reason($donor, 2) !== null, 'Участник САБ принят.');
    Control::patchCommand((int)$member['id'], ['executed' => 1]);
    researchAssert(Merge::reason($donor, 2) === null, 'Завершённая САБ продолжает блокировать слияние.');
});
researchCase('merge: отказ записи/удаления — rollback обоих флотов, маркеры, индексы и успешный retry', static function (): void {
    foreach ([[Fleets::class, false], [FleetsShips::class, false], [Fleets::class, true], [FleetsShips::class, true], [FleetCommands::class, true]] as [$repo, $delete]) {
        [$owner, $donor, $receiver] = mergeFixture();
        $merge = mergeArrive($owner, $donor); $before = mergeSnapshot();
        mergeFailure($repo, $delete);
        try { Commands::completeCommand($merge, $donor, $owner); throw new LogicException('Нет инъецированного отказа.'); }
        catch (RuntimeException $error) { /* ожидаемый отказ Table */ }
        researchAssert(mergeSameSnapshot($before), 'Rollback изменил runtime: ' . $repo . '/' . (int)$delete);
        researchAssert($receiver->getShipCount(202) === 10 && $donor->getShipCount(202) === 10, 'Кэш сохранил частичный состав.');
        foreach ([Fleets::class, FleetsShips::class] as $r) researchAssert(!in_array(1, $r::getPendingSync()['delete'], true), 'Сохранился DELETE после rollback.');
        researchAssert(count(Fleets::findByIndex('owner_id', '1')) === 2, 'Не восстановлен индекс флотов.');
        Commands::completeCommand($merge, $donor, $owner);
        researchAssert(Fleets::getById(1) === null && $receiver->getShipCount(202) === 20, 'Retry не прошёл: ' . $repo);
    }
});
researchCase('merge: маршрут заканчивается слиянием; последующие команды донора отклоняются', static function (): void {
    [$owner, $donor] = mergeFixture();
    $step = ['kind' => 'mission', 'mission' => 35, 'coordinates' => ['type' => 3, 'fleetId' => 2], 'speedFactor' => 10];
    defendRejected(static fn() => Control::preparePlan($donor, $owner,
        ['version' => Control::state($donor)['version'], 'steps' => [$step, ['kind' => 'return']]]));
    Control::savePlan($donor, $owner, ['version' => Control::state($donor)['version'], 'fuel' => 1000, 'steps' => [$step]], 1000);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $donor, $owner);
    $event = (new FleetsPage(new Message()))->buildFlyingFleetList($owner)[1];
    researchAssert($event['command']['type'] === 53 && $event['segment_start'] === $event['segment_end'], 'MERGE отображён как MOVE.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $donor, $owner);
    researchAssert(Fleets::getById(1) === null, 'Слияние из плана не выполнилось.');
});
researchCase('merge: аварийная пауза получателя не снимается даже после получения топлива', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $pending = FleetCommands::getNextPendingCommand(2); Control::pause($pending, 'fuel');
    $receiver->setFuelCurrent(0); $receiver->save();
    $merge = mergeArrive($owner, $donor);
    $donor->setFuelTotal(30); $donor->setFuelCurrent(30); $donor->save();
    Commands::completeCommand($merge, $donor, $owner);
    researchAssert($receiver->getFuelCurrent() === 30.0 && Control::state($receiver)['reason'] === 'fuel'
        && FleetCommands::getActiveCommand(2) === null, 'Заправка автоматически продолжила остановленный флот.');
});
researchCase('merge: активное удержание — оплаченный расход не возвращается, новая ставка по составу', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $hold = FleetCommands::getNextPendingCommand(2);
    $p = Control::params($hold); unset($p['pause']);
    Control::patchCommand((int)$hold['id'], ['params' => json_encode($p, JSON_THROW_ON_ERROR)]);
    Control::beginHold(FleetCommands::getCommand((int)$hold['id']), $receiver, $owner, 1000);
    $rate = Control::params(FleetCommands::getActiveCommand(2))['hold']['rate'];
    $merge = mergeArrive($owner, $donor); $time = $donor->getCurrentPositionTime();
    $fuel = Transfer::physicalFuel($receiver, $time) + $donor->getFuelCurrent();
    Commands::completeCommand($merge, $donor, $owner);
    $after = Control::params(FleetCommands::getActiveCommand(2))['hold'];
    researchAssert(abs($receiver->getFuelCurrent() - $fuel) < .000001 && abs($after['rate'] - 2 * $rate) < 1e-12
        && (float)$after['lastAt'] === $time, 'Неверный баланс/ставка удержания после слияния: '
            . json_encode([$receiver->getFuelCurrent(), $fuel, $after, $rate, $time]));
});
researchCase('merge: исчезнувшая цель — пауза донора без потери состава', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $merge = mergeArrive($owner, $donor);
    FleetsData::getInstance()->remove(2);
    Commands::completeCommand($merge, $donor, $owner);
    researchAssert(Fleets::getById(1) !== null && $donor->getShipCount(202) === 10
        && Control::state($donor)['reason'] === 'target', 'Исчезновение цели потеряло донора.');
});
researchCase('merge: миллион кораблей — сохранение количества и равного XP на корабль, без per-ship цикла', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    $merge = mergeArrive($owner, $donor);
    foreach ([$donor, $receiver] as $fleet) {
        $fleet->setShipCount(202, 1000000)->setExperience(9000000); $fleet->save();
    }
    Commands::completeCommand($merge, $donor, $owner);
    researchAssert($receiver->getShipCount(202) === 2000000 && $receiver->getShip(202)->getExperience() === 18000000.0
        && $receiver->getExperience() === 9.0, 'Масштабирование группы изменило опыт корабля.');
    $row = Fleets::getById(2); $ships = FleetsShips::findExistingById(2);
    $receiver->reloadFromRuntime();
    researchAssert(Fleets::getById(2) === $row && FleetsShips::findExistingById(2) === $ships
        && $receiver->getShipCount(202) === 2000000, 'Runtime reload потерял результат.');
});
researchCase('merge: существующий EventLoop завершает отдельное due-событие и не создаёт флот-призрак', static function (): void {
    [$owner, $donor, $receiver] = mergeFixture();
    \SPGame\Core\Connect::init(8);
    $merge = mergeArrive($owner, $donor);
    $loop = (new ReflectionClass(\SPGame\Game\Services\EventLoop::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($loop, 'logger'))->setValue($loop, \SPGame\Core\Logger::getInstance());
    $process = new ReflectionMethod($loop, 'processFleetEvents');
    researchAssert($process->invoke($loop, (float)$merge['execute_time']), 'Due scheduler остановился.');
    researchAssert(Fleets::getById(1) === null && FleetsData::getInstance()->get(1) === null
        && $receiver->getShipCount(202) === 20 && FleetCommands::getFleetCommandChain(1) === [], 'Scheduler потерял результат слияния.');
});

restore_error_handler();
echo "RESULT native merge: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
