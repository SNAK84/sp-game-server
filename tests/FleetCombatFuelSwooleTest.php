<?php

declare(strict_types=1);

// Отдельные native Tables; это проверка топливного контракта после потерь, не эмуляция результата боя.
define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, Galaxy, GalaxyOrbits, Planets, Vars};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetMissionBuilder as Builder, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};

function combatFuelFixture(bool $interSystem, int $small = 10, int $fast = 0): array
{
    $owner = defendFixture();
    Planets::update(['id'=>101, 'distance'=>10048, 'deg'=>280.709576, 'speed'=>8.0]);
    Planets::update(['id'=>102, 'system'=>$interSystem ? 2 : 1, 'distance'=>2160, 'deg'=>127.7, 'speed'=>8.326096]);
    researchRepository(Galaxy::class, [
        ['id'=>1,'galaxy'=>1,'system'=>1,'star_type'=>'G','max_distance'=>21892,'max_orbits'=>2],
        ['id'=>2,'galaxy'=>1,'system'=>2,'star_type'=>'G','max_distance'=>6849,'max_orbits'=>2],
    ]);
    researchRepository(GalaxyOrbits::class, [
        ['id'=>1,'galaxy'=>1,'system'=>1,'orbit'=>1,'type'=>GalaxyOrbits::PLANET,'distance'=>10048],
        ['id'=>2,'galaxy'=>1,'system'=>1,'orbit'=>2,'type'=>GalaxyOrbits::PLANET,'distance'=>2160],
        ['id'=>3,'galaxy'=>1,'system'=>1,'orbit'=>3,'type'=>GalaxyOrbits::SYSTEM_EDGE,'distance'=>21892],
        ['id'=>4,'galaxy'=>1,'system'=>1,'orbit'=>4,'type'=>GalaxyOrbits::EXPEDITION,'distance'=>30000],
        ['id'=>5,'galaxy'=>1,'system'=>2,'orbit'=>2,'type'=>GalaxyOrbits::PLANET,'distance'=>2160],
        ['id'=>6,'galaxy'=>1,'system'=>2,'orbit'=>3,'type'=>GalaxyOrbits::SYSTEM_EDGE,'distance'=>6849],
        ['id'=>7,'galaxy'=>1,'system'=>2,'orbit'=>4,'type'=>GalaxyOrbits::EXPEDITION,'distance'=>9000],
    ]);
    Vars::$attributes[202] = array_replace(Vars::$attributes[202], ['capacity'=>50.0,'speed'=>12500.0,
        'consumption'=>20.0,'consumption2'=>20.0]);
    if ($fast > 0) {
        Vars::$resource[204] = 'test_fast';
        Vars::$reslist['fleet'][] = 204;
        Vars::$attributes[204] = array_replace(Vars::$attributes[202], ['capacity'=>250.0,'speed'=>40000.0,
            'consumption'=>60.0,'consumption2'=>60.0]);
        defendColumns(FleetsShips::class, ['test_fast_count'=>\Swoole\Table::TYPE_INT,
            'test_fast_status'=>\Swoole\Table::TYPE_INT, 'test_fast_damage'=>\Swoole\Table::TYPE_FLOAT,
            'test_fast_experience'=>\Swoole\Table::TYPE_FLOAT, 'test_fast_morale'=>\Swoole\Table::TYPE_FLOAT]);
        $rows = FleetsShips::getAll();
        foreach ($rows as &$row) {
            $row['test_fast_count'] = $row['test_fast_status'] = 0;
            $row['test_fast_damage'] = $row['test_fast_experience'] = 0.0;
            $row['test_fast_morale'] = 1.0;
        }
        unset($row);
        $indexes = (new ReflectionProperty(Fleets::class, 'indexTables'))->getValue();
        researchRepository(FleetsShips::class, $rows);
        $property = new ReflectionProperty(Fleets::class, 'indexTables');
        $property->setValue(null, array_replace($property->getValue(), $indexes));
    }
    $fleet = FleetsData::getInstance()->get(1);
    $fleet->setShipCount(202, $small);
    if ($fast > 0) $fleet->setShipCount(204, $fast);
    $fleet->save();
    return [$owner, $fleet, Coordinate::object(1,1,1,ObjectType::PLANET),
        Coordinate::object(1,$interSystem ? 2 : 1,2,ObjectType::PLANET)];
}

function combatFuelMoves($fleet, $owner, $from, $to, float $factor, float $angle, bool $return): float
{
    $commands = array_values(array_filter(Builder::buildReturn($from, $to), static fn($c) =>
        in_array($c['type'], [Command::MOVE_IN_SYSTEM,Command::MOVE_BETWEEN_SYSTEMS,Command::MOVE_BETWEEN_GALAXIES], true)));
    foreach ($commands as &$command) $command['params'] += ['speedFactor'=>$factor,'returnLeg'=>$return];
    unset($command);
    $before = $fleet->getFuelCurrent();
    Commands::startFleetCommands($fleet, $commands, max(1000.0,$fleet->getCurrentPositionTime()), $owner);
    while (($active = FleetCommands::getActiveCommand(1)) !== null) {
        if ((int)$active['command_type'] === Command::MOVE_BETWEEN_SYSTEMS->value) {
            $params = json_decode($active['params'],true);
            $params['transitionObject']['angle'] = $angle;
            FleetCommands::update(['id'=>$active['id'],'params'=>json_encode($params,JSON_THROW_ON_ERROR)]);
            $active = FleetCommands::getCommand((int)$active['id']);
        }
        researchAssert(Commands::completeCommand($active,$fleet,$owner), 'Не завершён участок маршрута.');
    }
    // Нехватка теперь штатно ставит runtime на паузу, а не бросает exception из scheduler.
    // Для прежних проверок топливного баланса сохраняем явный сигнал незавершённого пути.
    if (\SPGame\Game\Services\FleetControlService::state($fleet)['reason'] === 'fuel') {
        throw new LogicException('Для продолжения недостаточно топлива.');
    }
    return $before - $fleet->getFuelCurrent();
}

researchCase('Старый максимум воспроизводит дефицит после потери 9 из 10 кораблей', static function(): void {
    [$owner,$fleet,$home,$target] = combatFuelFixture(true);
    $legacy = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,10,Mission::TRANSPORT);
    $fleet->setFuelTotal(ceil($legacy['fuel']['max'])); $fleet->setFuelCurrent(ceil($legacy['fuel']['max']));
    combatFuelMoves($fleet,$owner,$home,$target,10,3.579,false);
    $fleet->setShipCount(202,1); $fleet->setFuelCurrent($fleet->getFuelCurrent()/10); $fleet->save();
    try { combatFuelMoves($fleet,$owner,$target,$home,10,165.697,true); }
    catch (LogicException $error) {
        researchAssert(str_contains($error->getMessage(),'недостаточно топлива'), 'Другая ошибка воспроизведения.');
        return;
    }
    throw new RuntimeException('Старый расчёт неожиданно покрыл дефицит.');
});

researchCase('Новый максимум: одна/две системы, 10/50/100%, потери до одного корабля, разные углы', static function(): void {
    $count = 0;
    foreach ([false,true] as $inter) foreach ([1,5,10] as $factor) foreach ([1,2,5,10] as $survivors) foreach ([0,165.697,319.987] as $angle) {
        [$owner,$fleet,$home,$target] = combatFuelFixture($inter);
        $before = [Fleets::getAll(),FleetsShips::getAll(),FleetCommands::getAll()];
        $travel = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,$factor,Mission::ATTACK);
        researchAssert($before === [Fleets::getAll(),FleetsShips::getAll(),FleetCommands::getAll()], 'Preview мутирует runtime.');
        $fleet->setFuelTotal(ceil($travel['fuel']['max'])); $fleet->setFuelCurrent(ceil($travel['fuel']['max']));
        combatFuelMoves($fleet,$owner,$home,$target,$factor,$angle,false);
        $fleet->setShipCount(202,$survivors);
        $fleet->setFuelCurrent($fleet->getFuelCurrent()*$survivors/10); $fleet->save();
        combatFuelMoves($fleet,$owner,$target,$home,$factor,$angle,true);
        researchAssert($fleet->getFuelCurrent() >= 0 && $fleet->getCurrentCoordinate()->toArray() === $home->toArray(), 'Не завершён возврат.');
        ++$count;
    }
    echo "CHECK {$count} homogeneous return scenarios\n";
});

researchCase('Смешанный состав: резерв по вместимости выдерживает потерю всех медленных кораблей', static function(): void {
    for ($small=0;$small<=3;++$small) for ($fast=0;$fast<=3;++$fast) {
        if ($small+$fast===0) continue;
        [$owner,$fleet,$home,$target] = combatFuelFixture(true,3,3);
        $travel = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,10,Mission::GROUP_ATTACK);
        $fleet->setFuelTotal(ceil($travel['fuel']['max'])); $fleet->setFuelCurrent(ceil($travel['fuel']['max']));
        combatFuelMoves($fleet,$owner,$home,$target,10,90,false);
        $before = $fleet->getCapacity();
        $fleet->setShipCount(202,$small); $fleet->setShipCount(204,$fast);
        $fleet->setFuelCurrent($fleet->getFuelCurrent()*$fleet->getCapacity()/$before); $fleet->save();
        combatFuelMoves($fleet,$owner,$target,$home,10,270,true);
        researchAssert($fleet->getFuelCurrent() >= 0, 'Не хватило топлива смешанному остатку.');
    }
    echo "CHECK 15 mixed survivor compositions\n";
});

researchCase('Резерв действует для четырёх боевых миссий; минимум и небоевые миссии не меняются', static function(): void {
    [$owner,$fleet,$home,$target] = combatFuelFixture(true);
    $destination = FleetTarget::fromCoordinates($target->toArray());
    $ordinary = $fleet->getTravelInfo($destination,$owner,10,Mission::TRANSPORT);
    $battle = $fleet->getTravelInfo($destination,$owner,10,Mission::ATTACK);
    foreach ([Mission::ATTACK,Mission::GROUP_ATTACK,Mission::DESTROY,Mission::DEFEND] as $mission) {
        $travel = $fleet->getTravelInfo($destination,$owner,10,$mission);
        researchAssert($travel === $battle && $travel['fuel']['min'] === $ordinary['fuel']['min']
            && $travel['fuel']['max'] > $ordinary['fuel']['max'], 'Неверная область действия резерва.');
    }
    foreach ([Mission::TRANSPORT,Mission::SPY,Mission::RECYCLE] as $mission) {
        researchAssert($fleet->getTravelInfo($destination,$owner,10,$mission) === $ordinary, 'Изменён небоевой расчёт.');
    }
});

researchCase('Дробный остаток после потерь: погрешность DOUBLE не блокирует MOVE, реальный дефицит отклоняется', static function(): void {
    foreach ([1e-10, 0.0001] as $shortfall) {
        [$owner,$fleet,$home,$target] = combatFuelFixture(false,1);
        $fleet->setFuelTotal(3.0); $fleet->setFuelCurrent(3.0-$shortfall); $fleet->save();
        try {
            combatFuelMoves($fleet,$owner,$home,$target,10,0,false);
            researchAssert($shortfall < 1e-6 && $fleet->getFuelCurrent() === 0.0,
                'Проигнорирована настоящая нехватка или получился отрицательный остаток.');
        } catch (LogicException $error) {
            researchAssert($shortfall > 1e-6 && str_contains($error->getMessage(),'недостаточно топлива'),
                'Малая погрешность DOUBLE заблокировала возврат.');
            researchAssert($fleet->getFuelCurrent() === 3.0-$shortfall, 'Отказ изменил топливо.');
        }
    }
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
