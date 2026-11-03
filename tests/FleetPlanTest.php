<?php

declare(strict_types=1);

define('SP_GAME_CONTROL_FIXTURE_ONLY', true);
ob_start();
require __DIR__ . '/FleetFreeSpaceTest.php';
ob_end_clean();

use SPGame\Core\{Logger, LogLevel};
use SPGame\Game\Enums\{FleetMissionType as Mission, FleetCommandType as Command};
use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, GalaxyOrbits, Planets, PlanetResources, Vars};
use SPGame\Game\Services\{FleetControlService as Control, FleetCommandsService as Commands, FleetPlanService as Plan};
use SPGame\Game\Space\{Coordinate, ObjectType};

(new ReflectionProperty(Logger::getInstance(), 'minLevel'))->setValue(Logger::getInstance(), LogLevel::ERROR);

function planFixture(): array
{
    $f = controlFixture(); $owner = $f['account'];
    Vars::$attributes[204]['missions']=[1,3,5,7,10,11,12,15,20,30,40];
    $planets = $owner['Planets'];
    $resources = (new ReflectionProperty(ControlAccountData::class,'fixture'))->getValue($owner)['Resources'];
    foreach ([11 => [8,1600], 12 => [6,1400]] as $id => [$orbit,$distance]) {
        $row = Planets::castRowToSchema(array_replace($f['target'], ['id'=>$id,'owner_id'=>100,'planet'=>$orbit,'distance'=>$distance]),true);
        if (Planets::findExistingById($id) === null) Planets::add($row); else Planets::update($row);
        $planets[$id] = $row;
        $resources[$id] = [901=>['count'=>10000.0],902=>['count'=>10000.0],903=>['count'=>10000.0]];
        $r=['id'=>$id,'metal'=>10000.0,'crystal'=>10000.0,'deuterium'=>10000.0,'energy'=>0.0];
        if (PlanetResources::findExistingById($id)===null) PlanetResources::add($r); else PlanetResources::update($r);
    }
    $owner['Planets']=$planets; $owner['Resources']=$resources;
    GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>51,'galaxy'=>1,'system'=>10,'orbit'=>6,'distance'=>1400,'type'=>5],true));
    $f['second']=Coordinate::object(1,10,6,ObjectType::PLANET)->toArray();
    return $f;
}

function planInput(array $f,array $steps,array $extra=[]): array
{
    return ['version'=>Control::state($f['fleet'])['version'],'steps'=>$steps]+$extra;
}

function planMission(int $id,array $coordinates,array $extra=[]): array
{
    return ['kind'=>'mission','mission'=>$id,'coordinates'=>$coordinates,'speedFactor'=>10]+$extra;
}

function planRun(array $f,int $limit=25): void
{
    while($limit-- > 0 && ($active=FleetCommands::getActiveCommand(1))!==null) {
        transportAssert(in_array((int)$active['command_type'],[10,11,12],true),'Ожидался обычный перелёт, не пауза/бой.');
        Commands::completeCommand($active,$f['fleet'],$f['account']);
    }
    transportAssert($limit>0 && $f['fleet']->getStatus()===0,'Маршрут не завершился: '.json_encode(Control::state($f['fleet'])));
}

function planShip(array $f,int $id,string $name,int $count): void
{
    Vars::$resource[$id]=$name; Vars::$reslist['fleet'][]=$id;
    Vars::$attributes[$id]=['capacity'=>5000,'speed'=>100000000,'consumption'=>1,'consumption2'=>1,'tech'=>1,'missions'=>[1,3,5,7,10,11,12,15,20,30,40]];
    Vars::$pricelist[$id]=[901=>2000,902=>1000];Vars::$CombatCaps[$id]=['attack'=>5,'shield'=>10];
    $columns=[];
    foreach(['count'=>0,'damage'=>0.0,'experience'=>0.0,'morale'=>1.0,'status'=>0] as $suffix=>$default){
        $columns[$name.'_'.$suffix]=['swoole'=>[is_int($default)?\Swoole\Table::TYPE_INT:\Swoole\Table::TYPE_FLOAT],'default'=>$default];
    }
    appendTransportSchemaColumns(FleetsShips::class,$columns);
    FleetsShips::update(FleetsShips::castRowToSchema(FleetsShips::findExistingById(1),true));
    $f['fleet']->reloadFromRuntime();
    $f['fleet']->setShipCount($id,$count);$f['fleet']->save();
}

$planCases=[];
$planCases['две планеты, 50% и 50% остатка, возврат оставшегося груза и топлива']=static function():void{
    $f=planFixture();$before=PlanetResources::getAll();
    $steps=[planMission(10,$f['coordinates'],['percent'=>50]),planMission(10,$f['second'],['percent'=>50]),['kind'=>'return']];
    Control::savePlan($f['fleet'],$f['account'],planInput($f,$steps,['fuel'=>500,'resources'=>[901=>1000,902=>400,903=>200]]),100);
    planRun($f);
    foreach([11=>[500,200,100],12=>[250,100,50]] as $id=>$v){
        foreach(['metal','crystal','deuterium'] as $i=>$field) transportAssert(abs(PlanetResources::findExistingById($id)[$field]-10000-$v[$i])<.000001,'Неверная частичная доставка.');
    }
    transportAssert($f['fleet']->getResources()[901]->getCount()===0.0 && $f['fleet']->getFuelCurrent()===0.0,'Остаток не возвращён на базу.');
    transportAssert(abs(PlanetResources::findExistingById(10)['metal']-9250)<.000001,'Домой вернулся неверный остаток.');
    transportAssert(Plan::steps($f['fleet'])===[],'После завершения остались будущие этапы.');
};
$planCases['несколько действий на одной планете не добавляют фиктивный перелёт']=static function():void{
    $f=planFixture();$steps=[planMission(10,$f['coordinates'],['percent'=>50]),planMission(10,$f['coordinates'],['percent'=>25]),['kind'=>'return']];
    $rows=Control::preparePlan($f['fleet'],$f['account'],planInput($f,$steps,['fuel'=>500,'resources'=>[901=>100]]));
    transportAssert(count(array_filter($rows,fn($r)=>$r['type']===Command::MOVE_IN_SYSTEM))===2,'Появился лишний MOVE между действиями.');
    Control::savePlan($f['fleet'],$f['account'],planInput($f,$steps,['fuel'=>500,'resources'=>[901=>100]]),100);
    planRun($f);
    transportAssert(abs(PlanetResources::findExistingById(11)['metal']-10062.5)<.000001,'Не применён процент остатка.');
};
$planCases['редактирование обычного транспорта сохраняет активный MOVE и добавляет второй адрес']=static function():void{
    $f=planFixture();controlSend($f,Mission::TRANSPORT,$f['coordinates'],[901=>100]);
    $active=FleetCommands::getActiveCommand(1);$mission=$f['fleet']->getMission();
    $rest=Control::state($f['fleet'])['steps'];
    transportAssert($rest[0]['mission']===10 && end($rest)['kind']==='return','Не восстановлена исходная транспортная миссия.');
    $f['fleet']->setFuelTotal(500);$f['fleet']->setFuelCurrent(500);$f['fleet']->save();
    $steps=[planMission(10,$f['coordinates'],['percent'=>50]),planMission(10,$f['second'],['percent'=>100]),['kind'=>'return']];
    Control::savePlan($f['fleet'],$f['account'],planInput($f,$steps),101);
    transportAssert(FleetCommands::getActiveCommand(1)===$active && $f['fleet']->getMission()===$mission,'Переписан текущий участок.');
    transportAssert(count(Control::state($f['fleet'])['steps'])===3,'План потерял этапы при повторном открытии.');
    planRun($f);
    transportAssert(PlanetResources::findExistingById(11)['metal']===10050.0 && PlanetResources::findExistingById(12)['metal']===10050.0,'Редактированный маршрут не выполнился.');
};
$planCases['проценты строго валидируются до любых runtime мутаций']=static function():void{
    foreach([-1,101,37.5,.01,99.999,null,'50',INF,NAN,[]] as $bad){
        $f=planFixture();$before=[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll()];
        try{Control::preparePlan($f['fleet'],$f['account'],planInput($f,[planMission(10,$f['coordinates'],['percent'=>$bad]),['kind'=>'return']]));throw new RuntimeException('Принят неверный процент.');}
        catch(InvalidArgumentException){}
        transportAssert($before===[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll()],'Проверка изменила runtime.');
    }
};
$planCases['целые проценты: 0, 1, 50 и 100 принимаются без округления']=static function():void{
    foreach([0,1,50,100,50.0] as $value){
        transportAssert(Plan::percentage($value)===(float)$value,'Изменился целый процент.');
    }
};
$planCases['проверки миссий: нельзя атаковать своё, шпионить без зондов, обследовать заглушкой']=static function():void{
    foreach([1,20,25,35] as $mission){
        $f=planFixture();
        try{Control::preparePlan($f['fleet'],$f['account'],planInput($f,[planMission($mission,$f['coordinates']),['kind'=>'return']]));throw new RuntimeException('Недоступная миссия принята.');}
        catch(InvalidArgumentException){}
    }
};
$planCases['изменившаяся цель приостанавливает следующий этап, а не выполняет запрещённое действие']=static function():void{
    $f=planFixture();
    Control::savePlan($f['fleet'],$f['account'],planInput($f,[planMission(10,$f['coordinates']),['kind'=>'return']],['fuel'=>500,'resources'=>[901=>100]]),100);
    Planets::update(['id'=>11,'owner_id'=>0]);
    $move=FleetCommands::getActiveCommand(1);Commands::completeCommand($move,$f['fleet'],$f['account']);
    transportAssert(Control::state($f['fleet'])['reason']==='plan','Недоступная цель не остановила план.');
    transportAssert($f['fleet']->getResources()[901]->getCount()===100.0 && PlanetResources::findExistingById(11)['metal']===10000.0,'Груз выгружен недоступной цели.');
};
$planCases['передислокация доступна, но завершает план на новой базе']=static function():void{
    $f=planFixture();
    try{Control::preparePlan($f['fleet'],$f['account'],planInput($f,[planMission(11,$f['coordinates']),['kind'=>'return']]));throw new RuntimeException('Принято продолжение после передислокации.');}catch(InvalidArgumentException){}
    Control::savePlan($f['fleet'],$f['account'],planInput($f,[planMission(11,$f['coordinates'])],['fuel'=>500]),100);
    planRun($f);transportAssert($f['fleet']->getHomePlanetId()===11,'Не изменена база.');
};

$planCases['частичная выгрузка: rollback при сбое marker и единственный результат после retry']=static function():void{
    $f=planFixture();Control::savePlan($f['fleet'],$f['account'],planInput($f,[planMission(10,$f['coordinates'],['percent'=>50]),['kind'=>'return']],['fuel'=>500,'resources'=>[901=>100]]),100);
    $move=FleetCommands::getActiveCommand(1);
    $unload=array_values(array_filter(FleetCommands::getFleetQueue(1),fn($q)=>(int)$q['command_type']===32))[0];
    $table=(new ReflectionProperty(FleetCommands::class,'table'))->getValue();$table->failedWrites=1;$table->failedWriteKey=(string)$unload['id'];
    $failed=false;try{Commands::completeCommand($move,$f['fleet'],$f['account']);}catch(RuntimeException){$failed=true;}
    transportAssert($failed && $f['fleet']->getResources()[901]->getCount()===100.0 && PlanetResources::findExistingById(11)['metal']===10000.0,'Rollback потерял груз или создал ресурсы.');
    transportAssert(FleetCommands::getActiveCommand(1)!==null,'Нет повторной попытки после rollback.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    transportAssert($f['fleet']->getResources()[901]->getCount()===50.0 && PlanetResources::findExistingById(11)['metal']===10050.0,'Retry применил не 50%.');
    $method=new ReflectionMethod(Commands::class,'executeUnload');
    $params=Control::params($unload);$method->invoke(null,$f['fleet'],$f['account'],$params,10,$unload);
    transportAssert($f['fleet']->getResources()[901]->getCount()===50.0 && PlanetResources::findExistingById(11)['metal']===10050.0,'Повтор выполненной команды выгрузил процент снова.');
};
$planCases['перехват сменившего курс флота сохраняет процент и следующие этапы']=static function():void{
    $f=planFixture();$receiver=attackClone($f,2);
    $receiver->setCurrentCoordinate($f['point'],100);$receiver->setStatus(1);$receiver->setMission(30);$receiver->save();
    Planets::update(['id'=>11,'owner_id'=>200]);
    $steps=[planMission(10,['type'=>3,'fleetId'=>2],['percent'=>50]),planMission(1,$f['coordinates']),['kind'=>'return']];
    Control::savePlan($f['fleet'],$f['account'],planInput($f,$steps,['fuel'=>500,'resources'=>[901=>100]]),100);
    $move=FleetCommands::getActiveCommand(1);
    $receiver->setCurrentCoordinate(Coordinate::point(1,10,1350,100),100);$receiver->save();
    Commands::completeCommand($move,$f['fleet'],$f['account']);
    $redirected=FleetCommands::getActiveCommand(1);
    transportAssert((int)$redirected['id']!==(int)$move['id'] && count(Plan::steps($f['fleet']))===3,'Потеряны этапы при перехвате.');
    foreach(FleetCommands::getFleetQueue(1) as $command)transportAssert(isset(Control::params($command)['planStep']),'MOVE потерял метаданные этапа.');
    Commands::completeCommand($redirected,$f['fleet'],$f['account']);
    transportAssert($f['fleet']->getResources()[901]->getCount()===50.0 && $receiver->getResources()[901]->getCount()===50.0,'Неверная доля передачи.');
    transportAssert($f['fleet']->getMission()===1 && Control::params(FleetCommands::getActiveCommand(1))['planMission']===1,'Следующая атака летит как транспорт.');
};
$planCases['rollback после создания первой САБ удаляет группу, маршрут и погрузку']=static function():void{
    $f=planFixture();Planets::update(['id'=>11,'owner_id'=>200]);
    $before=[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll(),\SPGame\Game\Repositories\FleetAttackGroups::rows()];
    try {
        Control::commit([$f['fleet']],static function()use($f):void{
            Control::savePlan($f['fleet'],$f['account'],planInput($f,
                [planMission(3,$f['coordinates'],['group'=>[]]),['kind'=>'return']],['fuel'=>500]),100);
            transportAssert(\SPGame\Game\Repositories\FleetAttackGroups::rows()!==[],'Не создана группа для failure injection.');
            throw new RuntimeException('Failure после активации первой САБ.');
        },$f['account'],[]);
        throw new LogicException('Не получен ожидаемый failure.');
    } catch(RuntimeException $error) {
        transportAssert($error->getMessage()==='Failure после активации первой САБ.','Потеряно исходное исключение.');
    }
    transportAssert($before===[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll(),\SPGame\Game\Repositories\FleetAttackGroups::rows()],
        'После rollback осталась группа, маршрут или изменённая погрузка.');
};
$planCases['все действующие действия компилируются с обычными проверками и не меняют runtime']=static function():void{
    foreach([1,3,5,7,10,11,12,15,20,30,40] as $mission){
        $f=planFixture();$target=$f['coordinates'];$extra=[];
        if(in_array($mission,[1,3,5,7,20],true)) Planets::update(['id'=>11,'owner_id'=>200]);
        if($mission===20) planShip($f,210,'probe',1);
        if($mission===12){planShip($f,209,'recycler',1);$target['objectType']=2;\SPGame\Game\Repositories\DebrisFields::addResources(1,10,8,1000,500,100);}
        if($mission===7){$moon=Planets::castRowToSchema(array_replace($f['target'],['id'=>13,'owner_id'=>200,'planet_type'=>3]),true);Planets::add($moon);$target['objectType']=3;planShip($f,214,'dearth_star',1);}
        if($mission===5){
            \SPGame\Game\Repositories\Friends::add(\SPGame\Game\Repositories\Friends::castRowToSchema([
                'id'=>1,'user_low'=>100,'user_high'=>200,'requester_id'=>100,'status'=>\SPGame\Game\Repositories\Friends::ACCEPTED],true));
            Vars::$resource[34]='ally_deposit';
            appendTransportSchemaColumns(\SPGame\Game\Repositories\Builds::class,['ally_deposit'=>['swoole'=>[\Swoole\Table::TYPE_INT],'default'=>0]]);
            \SPGame\Game\Repositories\Builds::update(['id'=>11,'ally_deposit'=>1]);
        }
        if(in_array($mission,[15,40],true)){Vars::$resource[124]='expedition_tech';$f['account']['Techs']['expedition_tech']=3;}
        if($mission===15){planShip($f,208,'colony_ship',1);Planets::delete(Planets::findExistingById(12));GalaxyOrbits::update(['id'=>51,'type'=>GalaxyOrbits::EMPTY]);$target=$f['second'];}
        if($mission===40){GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>52,'galaxy'=>1,'system'=>10,'orbit'=>10,'distance'=>3000,'type'=>GalaxyOrbits::EXPEDITION],true));$target=Coordinate::object(1,10,10)->toArray();$extra['duration']=3600;}
        if($mission===30)$target=$f['point']->toArray();
        if($mission===3)$extra['group']=[];
        $steps=[planMission($mission,$target,$extra)];if(!in_array($mission,[11,30],true))$steps[]=['kind'=>'return'];
        $before=[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll()];
        $compiled=Control::preparePlan($f['fleet'],$f['account'],planInput($f,$steps,['fuel'=>500]));
        transportAssert(count($compiled)>1,'Нет команд для действия '.$mission);
        transportAssert($before===[Fleets::getAll(),FleetCommands::getAll(),PlanetResources::getAll()],'Prepare изменил runtime для миссии '.$mission);
    }
};

foreach($planCases as $name=>$test){$test();echo "OK plan $name\n";}
echo 'RESULT '.count($planCases)." fleet plan cases passed\n";
