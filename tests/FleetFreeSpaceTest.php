<?php

declare(strict_types=1);

// Отдельные in-memory Tables/аккаунты; не подключается к игровой MySQL.
require __DIR__ . '/FleetSpecialMissionsTest.php';

use SPGame\Game\Enums\{FleetMissionType as Mission, FleetCommandType as Command};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{FleetCommands, Fleets, Galaxy, GalaxyOrbits, PlanetResources, PlayerQueue};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetControlService as Control,
    FleetRendezvousService as Rendezvous, FleetTransferService as Transfer};
use SPGame\Game\Space\{Coordinate, ObjectType};

/** Только новый fixture: прежний TransportAccountData::save() намеренно пуст. */
final class ControlAccountData extends \SPGame\Game\Services\AccountData
{
    private int $selected = 10;
    public function __construct(private array $fixture) {}
    public function &offsetGet(mixed $key): mixed {
        if ($key==='WorkPlanet') return $this->selected;
        if ($key==='Planet') return $this->fixture['Planets'][$this->selected];
        if ($key==='Resources') return $this->fixture['Resources'][$this->selected];
        return $this->fixture[$key];
    }
    public function offsetSet(mixed $key,mixed $value): void {
        if ($key==='WorkPlanet') $this->selected=(int)$value; else $this->fixture[$key]=$value;
    }
    public function offsetExists(mixed $key): bool { return in_array($key,['Planet','Resources','WorkPlanet'],true)||isset($this->fixture[$key]); }
    public function offsetUnset(mixed $key): void { unset($this->fixture[$key]); }
    public function save(): void {
        $row=['id'=>$this->selected];
        foreach([901=>'metal',902=>'crystal',903=>'deuterium'] as $id=>$field) $row[$field]=(float)$this['Resources'][$id]['count'];
        PlanetResources::update($row);
    }
}

function controlFixture(): array
{
    $f = specialFixture();
    $f['account']=new ControlAccountData((new ReflectionProperty(TransportAccountData::class,'testData'))->getValue($f['account']));
    PlanetResources::add(['id'=>10,'metal'=>10000.0,'crystal'=>10000.0,'deuterium'=>10000.0,'energy'=>0.0]);
    \SPGame\Game\Repositories\Vars::$attributes[204]['missions'][] = Mission::TRANSPORT->value;
    $f['account']['Techs']['computer_tech'] = 6;
    $f['account']['Techs']['fleet_logistics_tech'] = 1;
    (new ReflectionProperty(\SPGame\Game\Services\AccountData::class, 'FleetsData'))
        ->setValue($f['account'], \SPGame\Game\Services\FleetsData::getInstance());
    Galaxy::add(Galaxy::castRowToSchema(['id' => 1,'galaxy' => 1,'system' => 10,'max_orbits' => 8,
        'min_distance' => 1000,'max_distance' => 1600], true));
    GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id' => 50,'galaxy' => 1,'system' => 10,
        'orbit' => 9,'distance' => 2100,'type' => GalaxyOrbits::SYSTEM_EDGE], true));
    $f['point'] = Coordinate::point(1,10,1300,45);
    return $f;
}

function controlSend(array $f, Mission $mission, array $coordinates, array $cargo = [], bool $refuel = false): void
{
    $travel = $f['fleet']->getTravelInfo(FleetTarget::fromCoordinates($coordinates), $f['account'], 10, $mission);
    transportAssert($travel !== null, 'Preview доступен: ' . json_encode($f['fleet']->getAvailableMissions(FleetTarget::fromCoordinates($coordinates), $f['account']), JSON_UNESCAPED_UNICODE));
    Commands::startMission($f['fleet'], $f['account'], $mission->value, $coordinates, 10,
        ceil($travel['fuel']['max']), $cargo, 100, null, null, $refuel);
}

$controlCases = [];
$controlCases['POINT hold: tech gate, exact arrival, hourly fuel, exhaustion and explicit resume'] = static function (): void {
    $f = controlFixture(); $target = FleetTarget::fromCoordinates($f['point']->toArray());
    $f['account']['Techs']['fleet_logistics_tech'] = 0;
    transportAssert(!in_array(30, $f['fleet']->getAvailableMissions($target, $f['account'])['missions'], true), 'Нет удержания без технологии.');
    $f['account']['Techs']['fleet_logistics_tech'] = 1;
    controlSend($f, Mission::OBSERVE, $f['point']->toArray());
    $move = FleetCommands::getActiveCommand(1);
    Commands::completeCommand($move, $f['fleet'], $f['account']);
    $hold = FleetCommands::getActiveCommand(1);
    transportAssert((int)$hold['command_type'] === Command::OBSERVE->value, 'Прибытие включило удержание.');
    transportAssert($f['fleet']->getCurrentCoordinate()->toArray() === $f['point']->toArray(), 'Точная свободная точка.');
    $p = Control::params($hold); $rate = $p['hold']['rate'];
    transportAssert(abs($rate - $f['fleet']->getTotalFuelConsumption($f['account']) * .1 / 3600) < 1e-10, 'Расход 10% за час.');
    $fuel = $f['fleet']->getFuelCurrent();
    transportAssert(abs(Transfer::physicalFuel($f['fleet'], $p['hold']['lastAt'] + 10) - ($fuel - $rate * 10)) < 1e-6, 'Read-only интерполяция.');
    Commands::completeCommand($hold, $f['fleet'], $f['account']);
    transportAssert(Control::state($f['fleet'])['reason'] === 'fuel' && $f['fleet']->getFuelCurrent() < 1e-6, 'Исчерпание стало пассивной остановкой.');
    $f['fleet']->setFuelTotal(1000); $f['fleet']->setFuelCurrent(1000); $f['fleet']->save();
    transportAssert(FleetCommands::getActiveCommand(1) === null, 'Дозаправка не запустила флот.');
    Control::resume($f['fleet'], $f['account'], (float)$hold['execute_time'] + 1);
    transportAssert(FleetCommands::getActiveCommand(1) !== null && $f['fleet']->isReturn(), 'Явное продолжение включило оставшийся возврат.');
};

$controlCases['manual stop refunds unspent fuel, preserves plan and resumes from actual POINT'] = static function (): void {
    $f = controlFixture(); controlSend($f, Mission::TRANSPORT, $f['coordinates'], [901 => 10]);
    $active = FleetCommands::getActiveCommand(1); $params = Control::params($active);
    $time = (100 + (float)$active['execute_time']) / 2;
    $before = $f['fleet']->getFuelCurrent(); $planet = PlanetResources::getAll();
    Control::stop($f['fleet'], $f['account'], $time);
    transportAssert(abs($f['fleet']->getFuelCurrent() - $before - $params['fuelCost'] / 2) < 1e-6, 'Неиспользованная половина в баке.');
    transportAssert(Control::state($f['fleet'])['holding'], 'Добровольная остановка расходует топливо удержания.');
    transportAssert(PlanetResources::getAll() === $planet, 'Планета не получила возврат топлива.');
    $point = $f['fleet']->getCurrentCoordinate()->toArray();
    Control::resume($f['fleet'], $f['account'], $time + 10);
    transportAssert(FleetCommands::getActiveCommand(1) !== null && $f['fleet']->getCurrentCoordinate()->toArray() === $point, 'Продолжение от точки, без телепортации.');
};

$controlCases['insufficient next leg pauses without throwing or blocking other events'] = static function (): void {
    $f = controlFixture(); controlSend($f, Mission::TRANSPORT, $f['coordinates'], [901 => 10]);
    $f['fleet']->setFuelCurrent(0); $f['fleet']->save();
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert(Control::state($f['fleet'])['reason'] === 'fuel' && FleetCommands::getActiveCommand(1) === null, 'Нет exception в scheduler.');
    $before = [Fleets::getAll(), FleetCommands::getAll()];
    try { Control::resume($f['fleet'], $f['account'], 100000); throw new RuntimeException('Продолжение без топлива.'); }
    catch (LogicException) {}
    transportAssert($before === [Fleets::getAll(), FleetCommands::getAll()], 'Неудачное продолжение полностью откатилось.');
};

$controlCases['FLEET resupply: server position, cargo, capacity and no automatic resume'] = static function (): void {
    $f = controlFixture(); $receiver = attackClone($f, 2);
    $receiver->setCurrentCoordinate($f['point'], 100); $receiver->setStatus(1); $receiver->setMission(30); $receiver->save();
    Control::add(2, Command::OBSERVE, ['target' => $f['point']->toArray(), 'pause' => 'fuel']);
    controlSend($f, Mission::TRANSPORT, ['type' => 3,'fleetId' => 2,'lastKnownPosition' => ['bogus' => true]], [901 => 10,903 => 20], true);
    $move = FleetCommands::getActiveCommand(1);
    Commands::completeCommand($move, $f['fleet'], $f['account']);
    transportAssert(abs($receiver->getFuelCurrent() - 20) < 1e-6 && abs($receiver->getResources()[901]->getCount() - 10) < 1e-6, 'Груз и топливо переданы.');
    transportAssert(Control::state($receiver)['paused'] && FleetCommands::getActiveCommand(2) === null, 'Получатель ждёт команды игрока.');
    transportAssert(FleetCommands::getActiveCommand(1) !== null && $f['fleet']->isReturn(), 'Доставляющий флот возвращается.');
    transportAssert($f['fleet']->getResources()[903]->getCount() < 1e-6, 'Топливо получателя не списано второй раз.');
};

$controlCases['meeting predicts moving target instead of flying to old snapshot'] = static function (): void {
    $f = controlFixture(); $receiver = attackClone($f, 2);
    $receiver->setFuelTotal(1000); $receiver->setFuelCurrent(1000); $receiver->setStatus(1); $receiver->setMission(30); $receiver->save();
    Commands::startFleetCommands($receiver, [['type' => Command::MOVE_IN_SYSTEM,
        'params' => ['target' => $f['point'],'speedFactor' => 1]]], 100, $f['account']);
    $initial = Rendezvous::position($receiver, 100);
    controlSend($f, Mission::TRANSPORT, ['type' => 3,'fleetId' => 2], [903 => 10], true);
    $move = FleetCommands::getActiveCommand(1); $p = Control::params($move);
    $aim = Coordinate::fromArray($p['target'])->getLastKnownPosition();
    $actual = Rendezvous::position($receiver, (float)$move['execute_time']);
    transportAssert(Rendezvous::distance($aim, $actual, (float)$move['execute_time']) < .05, 'Точка встречи совпадает с траекторией цели.');
    transportAssert(Rendezvous::distance($aim, $initial, 100) > .05, 'Не старый snapshot.');
    $receiverMove = FleetCommands::getActiveCommand(2);
    Commands::completeCommand($move, $f['fleet'], $f['account']);
    transportAssert(FleetCommands::getActiveCommand(2) === $receiverMove, 'Передача не останавливает движущуюся цель.');
};

$controlCases['plan gates, stale version, active immutability and explicit paused editing'] = static function (): void {
    $f = controlFixture(); $before = [Fleets::getAll(), FleetCommands::getAll()];
    $plan = ['version' => Control::state($f['fleet'])['version'], 'fuel' => 500,
        'steps' => [['kind' => 'move','coordinates' => $f['point']->toArray(),'speedFactor' => 10], ['kind' => 'hold']]];
    $f['account']['Techs']['computer_tech'] = 0;
    try { Control::preparePlan($f['fleet'], $f['account'], $plan); throw new RuntimeException('Редактор без технологии.'); } catch (LogicException) {}
    transportAssert($before === [Fleets::getAll(), FleetCommands::getAll()], 'Отказ без мутаций.');
    $f['account']['Techs']['computer_tech'] = 3;
    Control::savePlan($f['fleet'], $f['account'], $plan, 100);
    $active = FleetCommands::getActiveCommand(1);
    $plan = ['version' => Control::state($f['fleet'])['version'], 'steps' => [['kind' => 'return']]];
    try { Control::preparePlan($f['fleet'], $f['account'], $plan); throw new RuntimeException('Редактор в полёте без уровня 6.'); } catch (LogicException) {}
    $f['account']['Techs']['computer_tech'] = 6;
    Control::savePlan($f['fleet'], $f['account'], $plan, 101);
    transportAssert(FleetCommands::getActiveCommand(1) === $active, 'ACTIVE неизменна.');
    try { Control::savePlan($f['fleet'], $f['account'], $plan, 102); throw new RuntimeException('Принят устаревший план.'); } catch (LogicException) {}
};

$controlCases['visibility: own + incoming only, no self/hidden/missing targets or private cargo'] = static function (): void {
    $f = controlFixture(); $own = attackClone($f, 2); $hidden = attackClone($f, 3);
    $own->setCurrentCoordinate($f['point'],100); $own->save();
    Fleets::update(['id'=>3,'owner_id'=>200]); $hidden->reloadFromRuntime();
    foreach ([1,3,999] as $id) {
        try { Rendezvous::target(100,$id,1); throw new RuntimeException('Недоступная цель принята.'); } catch (InvalidArgumentException) {}
    }
    transportAssert(array_column(Rendezvous::targets(100,100),'id') === [2], 'Только свои флоты вне планеты.');
    $hidden->setMission(Mission::TRANSPORT); $hidden->setStatus(1);
    $hidden->setCurrentCoordinate($f['point'],100);
    $hidden->setTargetCoordinate($f['fleet']->getCurrentCoordinate()); $hidden->save();
    transportAssert(array_column(Rendezvous::targets(100,100),'id')===[2], 'Строка без событий не видна в обзоре и не раскрывается в выборе.');
    Control::add(3,Command::MOVE_IN_SYSTEM,['target'=>$f['fleet']->getCurrentCoordinate()->toArray(),'pause'=>'fuel']);
    $targets=Rendezvous::targets(100,100);
    transportAssert(array_column($targets,'id') === [2,3] && Rendezvous::target(100,3)->getId()===3, 'Входящий флот из обзора доступен.');
    transportAssert($targets[1]['name']==='', 'Приватное имя чужого флота не раскрывается сверх обзора.');
    transportAssert(!isset($targets[1]['resources'],$targets[1]['fuel']), 'В DTO нет чужого груза или топлива.');
    $hidden->setReturn(true); $hidden->save();
    try { Rendezvous::target(100,3); throw new RuntimeException('Ушедшая из видимости цель принята.'); } catch (InvalidArgumentException) {}
};

$controlCases['fleet targets exclude based fleets, retain orbital missions, flights and free-space stops'] = static function (): void {
    $f = controlFixture(); $receiver = attackClone($f,2);
    foreach ([ObjectType::PLANET,ObjectType::MOON] as $type) {
        $receiver->setCurrentCoordinate(Coordinate::object(1,10,7,$type),100); $receiver->setStatus(0); $receiver->save();
        $before = [Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll()];
        transportAssert(Rendezvous::targets(100,100)===[], 'Стоящий у планеты/луны флот отсутствует в списке.');
        try { Rendezvous::coordinate(100,2,100,1); throw new RuntimeException('Ручной ID обошёл запрет цели на планете/луне.'); }
        catch (InvalidArgumentException) {}
        try {
            Commands::prepareMissionRequest($f['fleet'],$f['account'],Mission::TRANSPORT->value,
                ['type'=>3,'fleetId'=>2],10,0,[901=>1]);
            throw new RuntimeException('Принята миссия к флоту на планете/луне.');
        } catch (InvalidArgumentException) {}
        transportAssert($before===[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll()], 'Отказ без мутаций.');
        // Оборона у объекта не является базированием и остаётся целью.
        $receiver->setStatus(1); $receiver->setMission(Mission::DEFEND); $receiver->save();
        $hold=Control::add(2,Command::DEFEND,['target'=>$receiver->getCurrentCoordinate()->toArray()]);
        FleetCommands::activateCommand((int)$hold['id'],200);
        transportAssert(Rendezvous::target(100,2,1)===$receiver, 'Обороняющий флот у объекта недоступен.');
        transportAssert(array_column(Rendezvous::targets(100,100),'id')===[2], 'Флот на задании отсутствует в списке целей.');
        FleetCommands::markExecuted((int)$hold['id']);
        foreach ([Command::MOVE_IN_SYSTEM,Command::MOVE_BETWEEN_SYSTEMS,Command::MOVE_BETWEEN_GALAXIES] as $moveType) {
            $move=Control::add(2,$moveType,['target'=>$f['point']->toArray()]);
            FleetCommands::activateCommand((int)$move['id'],200);
            transportAssert(Rendezvous::target(100,2,1)===$receiver, 'Летящий флот доступен, даже если current ещё указывает на старт.');
            FleetCommands::markExecuted((int)$move['id']);
        }
        transportAssert(Rendezvous::target(100,2,1)===$receiver, 'Завершение MOVE ошибочно приравнено к базированию.');
        $receiver->setStatus(0); $receiver->save();
        try { Rendezvous::target(100,2,1); throw new RuntimeException('Базирующийся флот остался доступной целью.'); }
        catch (InvalidArgumentException) {}
    }
    $receiver->setCurrentCoordinate($f['point'],100); $receiver->save();
    $pause=Control::add(2,Command::OBSERVE,['target'=>$f['point']->toArray(),'pause'=>'fuel']);
    transportAssert(array_column(Rendezvous::targets(100,100),'id')===[2], 'Аварийная остановка без активной команды доступна.');
    transportAssert(Rendezvous::target(100,2,1)===$receiver, 'Пассивная цель доступна по ID.');
    FleetCommands::markExecuted((int)$pause['id']);
    $receiver->setFuelTotal(1000); $receiver->setFuelCurrent(1000); $receiver->save();
    Control::beginHold(Control::add(2,Command::OBSERVE,['target'=>$f['point']->toArray()]),$receiver,$f['account'],100);
    transportAssert(array_column(Rendezvous::targets(100,100),'id')===[2], 'Добровольное удержание на свободных координатах доступно.');
};

$controlCases['transfer failure rolls back both fleets and marker; retry and replay are safe'] = static function (): void {
    $f = controlFixture(); $receiver = attackClone($f,2);
    $receiver->setCurrentCoordinate($f['point'],100); $receiver->save();
    $f['fleet']->setCurrentCoordinate($f['point'],100);
    $f['fleet']->getResources()[903]->setCount(25); $f['fleet']->save();
    $params=['target'=>['type'=>3,'fleetId'=>2],'refuel'=>true];
    $command=Control::add(1,Command::UNLOAD,$params);
    $before=[Fleets::getAll(),FleetCommands::getAll()];
    $table=(new ReflectionProperty(Fleets::class,'table'))->getValue();
    $table->failedWrites=1; $table->failedWriteKey='2';
    try { Transfer::deliver($command,$f['fleet'],$params); throw new LogicException('Сбой сохранения не обнаружен.'); } catch (RuntimeException) {}
    transportAssert($before === [Fleets::getAll(),FleetCommands::getAll()], 'Оба флота и marker восстановлены.');
    Transfer::deliver($command,$f['fleet'],$params);
    transportAssert(abs($receiver->getFuelCurrent()-25)<1e-6,'Повтор передал один раз.');
    Transfer::deliver($command,$f['fleet'],$params);
    transportAssert(abs($receiver->getFuelCurrent()-25)<1e-6,'Replay не начисляет повторно.');
};

$controlCases['capacity includes reserved fuel; proportional partial cargo and hold refuel deadline'] = static function (): void {
    $f=controlFixture(); $receiver=attackClone($f,2);
    $receiver->setCurrentCoordinate($f['point'],100); $receiver->setFuelTotal($receiver->getCapacity()-10);
    $receiver->setFuelCurrent($receiver->getCapacity()-10); $receiver->save();
    $f['fleet']->setCurrentCoordinate($f['point'],100);
    $f['fleet']->getResources()[901]->setCount(10); $f['fleet']->getResources()[903]->setCount(10); $f['fleet']->save();
    $hold=Control::add(2,Command::OBSERVE,['target'=>$f['point']->toArray()]);
    Control::beginHold($hold,$receiver,$f['account'],100);
    $end=FleetCommands::getActiveCommand(2)['execute_time'];
    $params=['target'=>['type'=>3,'fleetId'=>2],'refuel'=>true];
    Transfer::deliver(Control::add(1,Command::UNLOAD,$params),$f['fleet'],$params);
    transportAssert(abs($receiver->getResources()[901]->getCount()-5)<1e-6 && abs($f['fleet']->getResources()[901]->getCount()-5)<1e-6, 'Оставшийся груз у отправителя.');
    transportAssert(abs($receiver->getFuelCurrent()-($receiver->getCapacity()-5))<1e-6,'Только свободные 10 мест, 5 в бак.');
    transportAssert(FleetCommands::getActiveCommand(2)['execute_time']>$end,'Дозаправка продлила активное удержание.');
};

$controlCases['invalid plan does not mutate; paused editing does not resume'] = static function (): void {
    $f=controlFixture(); $before=[Fleets::getAll(),FleetCommands::getAll()];
    foreach ([['kind'=>'LOAD'],['kind'=>'move','coordinates'=>['type'=>2,'galaxy'=>999,'system'=>1,'distance'=>1000,'angle'=>0]]] as $step) {
        $plan=['version'=>Control::state($f['fleet'])['version'],'steps'=>[$step,['kind'=>'return']]];
        try { Control::preparePlan($f['fleet'],$f['account'],$plan); throw new RuntimeException('Некорректный план принят.'); } catch (InvalidArgumentException) {}
        transportAssert($before===[Fleets::getAll(),FleetCommands::getAll()], 'Валидация без мутации.');
    }
    controlSend($f,Mission::TRANSPORT,$f['coordinates'],[901=>1]);
    $f['fleet']->setFuelCurrent(0); $f['fleet']->save();
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    $plan=['version'=>Control::state($f['fleet'])['version'],'steps'=>[['kind'=>'return']]];
    Control::savePlan($f['fleet'],$f['account'],$plan,100000);
    transportAssert(Control::state($f['fleet'])['paused'] && FleetCommands::getActiveCommand(1)===null,'Сохранение не запустило paused флот.');
};

$controlCases['redirect preserves custom hold rather than replacing it with return'] = static function (): void {
    $f=controlFixture(); $receiver=attackClone($f,2); $receiver->setCurrentCoordinate($f['point'],100); $receiver->save();
    $params=['target'=>['type'=>3,'fleetId'=>2],'refuel'=>true,'speedFactor'=>5];
    $delivery=Control::add(1,Command::UNLOAD,$params);
    Control::add(1,Command::OBSERVE,['target'=>$f['point']->toArray()]);
    Transfer::redirect($delivery,$f['fleet'],$receiver);
    $queue=FleetCommands::getFleetQueue(1);
    transportAssert((int)end($queue)['command_type']===Command::OBSERVE->value,'Сохранено пользовательское удержание.');
    transportAssert(count(array_filter($queue,static fn($r)=>(int)$r['command_type']===Command::LOAD->value))===0,'Нет повторного LOAD.');
};

$controlCases['stop during transition waits for arrival then charges voluntary hold'] = static function (): void {
    $f=controlFixture(); $fleet=$f['fleet']; $fleet->setStatus(1); $fleet->setMission(Mission::OBSERVE);
    $fleet->setFuelTotal(1000); $fleet->setFuelCurrent(1000);
    $fleet->setCurrentCoordinate(Coordinate::point(1,9,1000,0),100); $fleet->save();
    $jump=Control::add(1,Command::MOVE_BETWEEN_SYSTEMS,['target'=>$f['point']->toArray(),
        'transitionPoint'=>Coordinate::point(1,10,2100,12)->toArray(),'fuelCost'=>5]);
    Commands::activateCommand((int)$jump['id'],200,Control::params($jump));
    Control::add(1,Command::MOVE_IN_SYSTEM,['target'=>$f['point']->toArray(),'speedFactor'=>10]);
    Control::stop($fleet,$f['account'],150);
    transportAssert($fleet->getCurrentCoordinate()->getSystem()===9 && Control::state($fleet)['stop_pending'], 'Нет телепортации посреди перехода.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$f['account']);
    transportAssert($fleet->getCurrentCoordinate()->getSystem()===10 && Control::state($fleet)['holding'],'Удержание началось только после входа.');
};

$controlCases['silent planet write failure rolls back prelaunch load and new commands'] = static function (): void {
    $f=controlFixture();
    $plan=['version'=>Control::state($f['fleet'])['version'],'fuel'=>500,'resources'=>[901=>20],
        'steps'=>[['kind'=>'move','coordinates'=>$f['point']->toArray()],['kind'=>'hold']]];
    $before=[Fleets::getAll(),PlanetResources::getAll(),FleetCommands::getAll()];
    $table=(new ReflectionProperty(PlanetResources::class,'table'))->getValue(); $table->failedWrites=1; $table->failedWriteKey='10';
    try { Control::savePlan($f['fleet'],$f['account'],$plan,100); throw new LogicException('Незамеченный сбой ресурса.'); } catch (RuntimeException) {}
    transportAssert($before===[Fleets::getAll(),PlanetResources::getAll(),FleetCommands::getAll()], 'Нет бесплатной погрузки/частичного плана.');
    $plan['version']=Control::state($f['fleet'])['version'];
    Control::savePlan($f['fleet'],$f['account'],$plan,100);
    transportAssert(FleetCommands::getActiveCommand(1)!==null, 'Повтор после отказа ресурса выполнился.');
};

$controlCases['request ACK is correlated and only accepts a validated plan into PlayerQueue'] = static function (): void {
    $f=controlFixture(); $before=[Fleets::getAll(),PlanetResources::getAll(),FleetCommands::getAll()];
    $plan=['version'=>Control::state($f['fleet'])['version'],'fuel'=>500,
        'steps'=>[['kind'=>'move','coordinates'=>$f['point']->toArray()],['kind'=>'hold']]];
    foreach([0,-1,null,'1',1] as $id) {
        $message=new \SPGame\Core\Message(['mode'=>'fleets','action'=>'SetFleetCommands','requestId'=>'control-ack','data'=>['fleetId'=>$id,'plan'=>$plan]]);
        $response=(new \SPGame\Core\Message())->setRequestId('control-ack');
        transportAssert(\SPGame\Game\Services\PageActionService::handle($message,$f['account'],1000,$response),'Handler обработал запрос.');
        $raw=$response->source();
        transportAssert($raw['status']===($id===1?'ok':'error') && $raw['requestId']==='control-ack' && isset($raw['serverTime']), 'Обычный ACK/error contract.');
        transportAssert(count(PlayerQueue::getAll())===($id===1?1:0),'Невалидный ID не попал в очередь.');
        transportAssert($before===[Fleets::getAll(),PlanetResources::getAll(),FleetCommands::getAll()],'ACK не меняет gameplay.');
    }
    $event=array_values(PlayerQueue::getAll())[0];
    transportAssert($event['action']===PlayerQueue::ActionFleetControl,'Используется существующий PlayerQueue pipeline.');
};

$controlCases['queued resume cannot rewind a hold already settled by an earlier world event'] = static function (): void {
    $f=controlFixture(); controlSend($f,Mission::OBSERVE,$f['point']->toArray());
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    $start=$f['fleet']->getCurrentPositionTime();
    $f['fleet']->setFuelTotal(1000); $f['fleet']->setFuelCurrent(1000); $f['fleet']->save();
    Control::settleHold($f['fleet'],$start+5); $f['fleet']->save();
    Control::resume($f['fleet'],$f['account'],$start+1);
    transportAssert($f['fleet']->getCurrentPositionTime()===$start+5 && $f['fleet']->isReturn(), 'Время команды не переместилось в прошлое.');
};

$controlCases['indefinite hold accepts additional tank supply without changing finite mission limits'] = static function (): void {
    $f=controlFixture();
    $prepared=Commands::prepareMissionRequest($f['fleet'],$f['account'],Mission::OBSERVE->value,$f['point']->toArray(),10,1000,[]);
    transportAssert($prepared['fuel']===1000.0,'Запас удержания не ограничен расходом одного конечного перелёта.');
    $travel=$f['fleet']->getTravelInfo(FleetTarget::fromCoordinates($f['coordinates']),$f['account'],10,Mission::TRANSPORT);
    try {
        Commands::prepareMissionRequest($f['fleet'],$f['account'],Mission::TRANSPORT->value,$f['coordinates'],10,ceil($travel['fuel']['max'])+1,[901=>1]);
        throw new RuntimeException('Для конечной миссии пропал максимальный предел.');
    } catch(InvalidArgumentException) {}
};

if (defined('SP_GAME_CONTROL_FIXTURE_ONLY') && SP_GAME_CONTROL_FIXTURE_ONLY) return;
foreach ($controlCases as $name => $test) { $test(); echo "OK control {$name}\n"; }
echo 'RESULT ' . count($controlCases) . " fleet control cases passed\n";
