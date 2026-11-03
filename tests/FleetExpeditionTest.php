<?php

declare(strict_types=1);

// Только отдельные in-memory repositories; игровые данные/MySQL не используются.
require __DIR__ . '/FleetSpecialMissionsTest.php';

use SPGame\Game\Enums\{FleetCommandType as Command,FleetMissionType as Mission};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{Config,FleetCommands,FleetExpeditions as States,Fleets,FleetsShips,GalaxyOrbits,Messages,UserResources,Vars};
use SPGame\Game\Services\{FleetCommandsService as Commands,FleetExpeditionService as Expedition};
use SPGame\Game\Space\{Coordinate,ObjectType};

function expeditionFixture(string $event='nothing'): array
{
    $f=specialFixture();
    (new ReflectionProperty(\SPGame\Game\Services\AccountData::class,'FleetsData'))->setValue($f['account'],\SPGame\Game\Services\FleetsData::getInstance());
    $fleetIndexes=(new ReflectionProperty(Fleets::class,'indexTables'))->getValue();
    installTransportRepository(States::class,transportTable(),transportSyncTable(),[]);
    installTransportRepository(UserResources::class,transportTable([['id'=>100,'credit'=>0.0,'doubloon'=>0.0]]),transportSyncTable(),[]);
    (new ReflectionProperty(Fleets::class,'indexTables'))->setValue(null,$fleetIndexes);
    Vars::$resource[921]='credit'; Vars::$reslist['resstype'][3]=[921];
    appendTransportSchemaColumns(Fleets::class,['resource_credit'=>['swoole'=>[\Swoole\Table::TYPE_FLOAT],'default'=>0.0]]);
    Fleets::update(Fleets::castRowToSchema(Fleets::getById(1),true));
    $f['fleet']->reloadFromRuntime();
    Vars::$resource[124]='expedition_tech'; $f['account']['Techs']['expedition_tech']=3;
    Vars::$attributes[204]['missions'][]=Mission::EXPEDITION->value;
    Vars::$attributes[204]['capacity']=100000; Vars::$attributes[204]['consumption']=10;
    $f['fleet']->setShipCount(204,100); $f['fleet']->save();
    GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>50,'galaxy'=>1,'system'=>10,'orbit'=>10,'distance'=>3000,'type'=>GalaxyOrbits::EXPEDITION],true));
    $f['coordinate']=Coordinate::object(1,10,10);
    $weights=array_fill_keys(['resources','credits','ships','combat','lost','time','nothing'],0); $weights[$event]=1;
    foreach($weights as $key=>$value) Config::add(['name'=>'ExpeditionWeight'.ucfirst($key),'value'=>(string)$value]);
    Expedition::initializeWorkers(static function(array $task): bool { Expedition::executeTask($task); return true; },static fn():float=>0.0);
    return $f;
}

function expeditionStart(array $f,int $duration=3600): array
{
    $target=FleetTarget::fromCoordinates($f['coordinate']->toArray());
    $preview=$f['fleet']->getTravelInfo($target,$f['account'],10,Mission::EXPEDITION);
    transportAssert(in_array(40,$f['fleet']->getAvailableMissions($target,$f['account'])['missions'],true),'Экспедиция доступна.');
    Commands::startMission($f['fleet'],$f['account'],40,$f['coordinate']->toArray(),10,ceil($preview['fuel']['max']),[],100,$duration);
    $chain=FleetCommands::getFleetCommandChain(1);
    transportAssert(array_column($chain,'command_type')===[31,10,40,10,32,52],'Шесть команд, без WAIT/переходов.');
    $move=FleetCommands::getActiveCommand(1); Commands::completeCommand($move,$f['fleet'],$f['account']);
    $hold=FleetCommands::getActiveCommand(1);
    transportAssert((int)$hold['command_type']===Command::EXPEDITION->value,'Прибытие активирует EXPEDITION.');
    transportAssert(abs((float)$hold['execute_time']-(float)$move['execute_time']-3600)<.000001,'Первое событие через час от прибытия.');
    $state=States::state(1); $state['seed']=str_repeat('01',32); States::store(1,$state);
    return $hold;
}

function expeditionResult(array $f): void
{
    for($i=0;$i<30;++$i) {
        $active=FleetCommands::getActiveCommand(1);
        if ($active===null || (int)$active['command_type']!==Command::EXPEDITION->value) return;
        Commands::completeCommand($active,$f['fleet'],$f['account']);
    }
    throw new RuntimeException('Экспедиция не завершилась за 30 событий.');
}

$cases=[];
$cases['validation: astrophysics, duration types, target orbit and same system']=static function():void {
    $f=expeditionFixture();
    foreach([0,-1,null,'3600',1.5,true,3599,7201,14400] as $duration) {
        if($duration===null) continue; // Отсутствующее значение означает один час.
        try { Expedition::duration($duration,$f['account']); throw new RuntimeException('Неверная выдержка принята.'); } catch(InvalidArgumentException) {}
    }
    $before=[Fleets::getAll(),FleetCommands::getAll()];
    foreach([Coordinate::point(1,10,3000,0),Coordinate::object(1,10,10,ObjectType::PLANET),Coordinate::object(1,11,10),Coordinate::object(1,10,7,ObjectType::PLANET)] as $target) {
        try { Commands::prepareMissionRequest($f['fleet'],$f['account'],40,$target->toArray(),10,100,[],3600); throw new RuntimeException('Неверная цель принята.'); } catch(InvalidArgumentException) {}
    }
    $f['account']['Techs']['expedition_tech']=0;
    transportAssert(Expedition::reason($f['fleet'],$f['account'],$f['coordinate'])!==null,'Астрофизика обязательна.');
    transportAssert($before===[Fleets::getAll(),FleetCommands::getAll()],'Отказ не меняет runtime.');
};
$cases['limit and duration: Nova floor sqrt, 1..level hours']=static function():void {
    $f=expeditionFixture();
    foreach([0=>0,1=>1,3=>1,4=>2,9=>3] as $level=>$limit) {
        $f['account']['Techs']['expedition_tech']=$level; transportAssert(Expedition::limits($f['account'])['max']===$limit,'Лимит sqrt.');
    }
    $f['account']['Techs']['expedition_tech']=3;
    expeditionStart($f,10800);
    $other=attackClone($f,2);
    transportAssert(Expedition::reason($other,$f['account'],$f['coordinate'])!==null,'Возврат/выдержка занимают слот до завершения: '.json_encode([Expedition::limits($f['account']),Fleets::getById(1),$other->getStatus()]));
};
foreach(['nothing','resources','credits','ships','time','lost','combat'] as $event) {
    $cases['outcome '.$event.'; completion, return and replay']=static function()use($event):void {
        $f=expeditionFixture($event); $hold=expeditionStart($f);
        $oldExperience=$f['fleet']->getShips()[204]->getExperience();
        $beforeCapacity=$f['fleet']->getCapacity(); $beforeFuel=$f['fleet']->getFuelCurrent();
        expeditionResult($f);
        $messages=Messages::findByIndex('to',[100])??[];
        transportAssert(count($messages)===1 && str_starts_with($messages[0]['sample'],'FleetExpedition'),'Один результат экспедиции.');
        $data=json_decode($messages[0]['data'],true,512,JSON_THROW_ON_ERROR);
        if ($event==='combat') {
            transportAssert(isset($data['BattleId']) && (\BattleReportsMemory::$rows[$data['BattleId']]['ready'] ?? false),
                'NPC бой публикует стандартный боевой доклад.');
        }
        if ($event==='lost' || ($event==='combat' && Fleets::getById(1)===null)) {
            transportAssert(Fleets::getById(1)===null && FleetsShips::findExistingById(1)===null && FleetCommands::getFleetCommandChain(1)===[],'Гибель удаляет флот/корабли/команды.');
            return;
        }
        $state=States::state(1);
        transportAssert($state['phase']==='returning','Результат применён до возврата.');
        transportAssert((int)FleetCommands::getCommand((int)$hold['id'])['executed']===1,'EXPEDITION выполнена.');
        $before=[Fleets::getAll(),FleetsShips::getAll(),Messages::getAll()];
        Expedition::complete($hold,$f['fleet'],$f['account']);
        transportAssert($before===[Fleets::getAll(),FleetsShips::getAll(),Messages::getAll()],'Replay не выдаёт награду/сообщение второй раз.');
        if ($event==='credits') transportAssert(UserResources::findExistingById(100)['credit']===0.0,'Кредиты не зачисляются до возвращения.');
        if ($event==='combat') {
            $return=FleetCommands::getActiveCommand(1); $returnParams=json_decode($return['params'],true,512,JSON_THROW_ON_ERROR);
            transportAssert(abs($f['fleet']->getFuelCurrent()+(float)$returnParams['fuelCost']-$beforeFuel*$f['fleet']->getCapacity()/$beforeCapacity)<.000001,
                'Топливо после боя пропорционально уцелевшей вместимости, затем оплачивается возврат.');
        }
        if ($event==='ships') transportAssert(array_sum($state['plan']['ships'])>0
            && $f['fleet']->getShips()[204]->getExperience()===$oldExperience,'Найденные корабли не добавляют опыта.');
        $cargo=[];
        foreach($f['fleet']->getResources() as $id=>$resource) $cargo[$id]=$resource->getCount();
        for($i=0;$i<4 && ($move=FleetCommands::getActiveCommand(1))!==null;++$i) Commands::completeCommand($move,$f['fleet'],$f['account']);
        transportAssert($f['fleet']->getStatus()===0 && $f['fleet']->getFuelCurrent()===0.0,'Возврат и выгрузка топлива.');
        transportAssert(States::state(1)===null,'Завершённая экспедиция освобождает runtime запись.');
        $returned=Messages::findByIndex('to',[100])??[];
        transportAssert(count($returned)===2 && count(array_filter($returned,static fn($row)=>$row['sample']==='FleetExpeditionSummary'))===1
            && count(array_filter($returned,static fn($row)=>$row['sample']==='FleetExpeditionReturned'))===1,
            'Один итог и отдельное уведомление о возвращении.');
        $delivery=json_decode(array_values(array_filter($returned,static fn($row)=>$row['sample']==='FleetExpeditionReturned'))[0]['data'],true,512,JSON_THROW_ON_ERROR);
        foreach($cargo as $id=>$amount) transportAssert((float)($delivery['Resources'][$id]??0)===$amount,'Возврат сообщает фактически доставленный груз.');
        if($event==='credits') transportAssert((float)UserResources::findExistingById(100)['credit']===(float)$state['plan']['credits'],'Кредиты зачислены ровно один раз.');
    };
}
$cases['rollback: failed fleet write preserves prepared outcome and retries once']=static function():void {
    $f=expeditionFixture('resources'); $hold=expeditionStart($f);
    $table=(new ReflectionProperty(Fleets::class,'table'))->getValue(); $table->failedWrites=1; $table->failedWriteKey='1';
    $before=[Fleets::getAll(),FleetsShips::getAll()];
    try { Commands::completeCommand($hold,$f['fleet'],$f['account']); throw new LogicException('Сбой записи не замечен.'); } catch(RuntimeException) {}
    transportAssert($before===[Fleets::getAll(),FleetsShips::getAll()] && count(Messages::findByIndex('to',[100])??[])===0,'Компенсация до отправки сообщения.');
    $plan=States::state(1)['plan'];
    expeditionResult($f);
    transportAssert(States::state(1)['plan']===$plan,'При retry не меняется случайная награда.');
};
$cases['recall during exploration: no reward, actual point and normal return']=static function():void {
    $f=expeditionFixture('credits'); $hold=expeditionStart($f);
    $position=$f['fleet']->getCurrentCoordinate()->toArray();
    Commands::recall($f['fleet'],$f['account'],$f['fleet']->getCurrentPositionTime()+5);
    transportAssert($f['fleet']->isReturn() && $f['fleet']->getCurrentCoordinate()->toArray()===$position,'Отзыв не телепортирует флот.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    transportAssert($f['fleet']->getStatus()===0 && (float)UserResources::findExistingById(100)['credit']===0.0,'Нет награды за прерванную выдержку.');
};
$cases['snapshot: active timer and estimated full return without exposing random seed']=static function():void {
    $f=expeditionFixture(); $hold=expeditionStart($f,7200);
    $page=new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
    $event=$page->buildFlyingFleetList($f['account'])[1]??null;
    transportAssert($event!==null && $event['route_leg']['direction']==='expedition'
        && $event['route_leg']['arrives_at']===(float)(States::state(1)['endsAt']),'Фаза и полный серверный таймер выдержки.');
    transportAssert($event['mission_end_time']>(float)$hold['execute_time'] && $event['mission_end_estimated'],'Время полного возврата есть, но зависит от исхода.');
    transportAssert(!str_contains(json_encode($event),'seed'),'Клиент не знает будущий исход.');
};
$cases['arrival failure restores MOVE origin and retry starts exploration']=static function():void {
    $f=expeditionFixture();
    $preview=$f['fleet']->getTravelInfo(FleetTarget::fromCoordinates($f['coordinate']->toArray()),$f['account'],10,Mission::EXPEDITION);
    Commands::startMission($f['fleet'],$f['account'],40,$f['coordinate']->toArray(),10,ceil($preview['fuel']['max']),[],100,3600);
    $before=$f['fleet']->getCurrentCoordinate()->toArray(); $fuel=$f['fleet']->getFuelCurrent();
    $table=(new ReflectionProperty(States::class,'table'))->getValue(); $table->failedWrites=1; $table->failedWriteKey='1';
    $move=FleetCommands::getActiveCommand(1);
    try { Commands::completeCommand($move,$f['fleet'],$f['account']); throw new LogicException('Не замечен отказ записи выдержки.'); } catch(RuntimeException) {}
    transportAssert($f['fleet']->getCurrentCoordinate()->toArray()===$before && $f['fleet']->getFuelCurrent()===$fuel,'Позиция для повторного MOVE не телепортируется, топливо не списывается повторно.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    transportAssert((int)FleetCommands::getActiveCommand(1)['command_type']===40,'Повтор активировал экспедицию.');
};
$cases['request ACK accepts duration but does not mutate gameplay']=static function():void {
    $f=expeditionFixture();
    $travel=$f['fleet']->getTravelInfo(FleetTarget::fromCoordinates($f['coordinate']->toArray()),$f['account'],10,Mission::EXPEDITION);
    $before=[Fleets::getAll(),FleetCommands::getAll(),States::getAll()];
    foreach(['3600',0,14400,3600] as $duration) {
        $request=new \SPGame\Core\Message(['mode'=>'fleets','action'=>'SendMission','requestId'=>'expedition-ack',
            'data'=>['fleetId'=>1,'missionType'=>40,'coordinates'=>$f['coordinate']->toArray(),'speedFactor'=>10,'fuel'=>ceil($travel['fuel']['max']),'resources'=>[],'duration'=>$duration]]);
        $response=(new \SPGame\Core\Message())->setRequestId('expedition-ack');
        \SPGame\Game\Services\PageActionService::handle($request,$f['account'],1000,$response);
        transportAssert($response->getStatus()===($duration===3600?'ok':'error') && $response->source()['requestId']==='expedition-ack','Существующий correlated ACK/error.');
        transportAssert($before===[Fleets::getAll(),FleetCommands::getAll(),States::getAll()],'ACK ещё не запускает миссию.');
    }
};
$cases['archive failure retries without reapplying NPC losses or duplicating message']=static function():void {
    $f=expeditionFixture('combat'); expeditionStart($f);
    \BattleReportsMemory::$failPublish=true;
    try { expeditionResult($f); throw new LogicException('Отказ публикации не замечен.'); } catch(RuntimeException) {}
    transportAssert(States::state(1)['phase']==='settled','Потери уже применены единственный раз.');
    $before=[Fleets::getAll(),FleetsShips::getAll()];
    transportAssert((Messages::findByIndex('to',[100])??[])===[],'До публикации нет ссылки на недоступный доклад.');
    \BattleReportsMemory::$failPublish=false;
    expeditionResult($f);
    transportAssert(count(Messages::findByIndex('to',[100])??[])===1,'После восстановления одно сообщение.');
};
$cases['found ships cannot overflow capacity with their return fuel']=static function():void {
    $f=expeditionFixture('ships'); expeditionStart($f);
    $before=FleetsShips::findExistingById(1);
    Vars::$attributes[204]['consumption']=1e12;
    expeditionResult($f);
    transportAssert(States::state(1)['plan']['ships']===[] && FleetsShips::findExistingById(1)===$before,
        'Неподъёмная находка отклонена без частичного добавления кораблей.');
    transportAssert($f['fleet']->getUsedCapacity()+$f['fleet']->getFuelCurrent()<=$f['fleet']->getCapacity(),
        'Нельзя переполнить трюм найденным топливом.');
};
$cases['worker failure preserves fleet and does not block other fleet events']=static function():void {
    $f=expeditionFixture('combat'); $hold=expeditionStart($f);
    Expedition::initializeWorkers(static fn(array $task):bool=>false,static fn():float=>0.0);
    $before=[Fleets::getAll(),FleetsShips::getAll()];
    Commands::completeCommand($hold,$f['fleet'],$f['account']);
    transportAssert(States::state(1)['phase']==='failed' && $before===[Fleets::getAll(),FleetsShips::getAll()],
        'Отказ worker не применяет вымышленные потери.');
    transportAssert(\SPGame\Game\Services\FleetAttackService::mustWait(FleetCommands::getActiveCommand(1)),
        'Повреждённый бой пропускается scheduler, а не выполняется бесконечно.');
    $rejected=false;
    try { Expedition::assertAvailable(1); } catch(LogicException) { $rejected=true; }
    transportAssert($rejected,'До диагностики нельзя менять участвующий в расчёте флот.');
};
$cases['NPC survivors lose fuel in capacity proportion and gain experience']=static function():void {
    $f=expeditionFixture('combat'); expeditionStart($f);
    // Слабый NPC успевает повредить Fleet, но не уничтожает его; round calculator настоящий.
    $state=States::state(1);
    $method=new ReflectionMethod(Expedition::class,'plan');
    $plan=$method->invoke(null,$f['fleet'],$f['account'],$state);
    $attackers=\SPGame\Game\Services\FleetAttackService::fleetGroups($f['fleet'],$f['account']);
    $enemy=reset($attackers);
    $enemy=array_replace($enemy,['count'=>1,'fleetId'=>0,'experience'=>0.0,'attack'=>5000.0,'shield'=>0.0,'hull'=>1.0]);
    $plan['battle']=\SPGame\Game\Services\FleetCombatService::startBattle($attackers,['npc'=>$enemy],[]);
    $state['plan']=$plan; $state['phase']='combat'; States::store(1,$state);
    $capacity=$f['fleet']->getCapacity(); $fuel=$f['fleet']->getFuelCurrent();
    expeditionResult($f);
    $return=FleetCommands::getActiveCommand(1);
    transportAssert(Fleets::getById(1)!==null && $f['fleet']->getCapacity()<$capacity,'Есть уцелевшие и реальные потери.');
    $paid=(float)json_decode($return['params'],true)['fuelCost'];
    transportAssert(abs($f['fleet']->getFuelCurrent()+$paid-$fuel*$f['fleet']->getCapacity()/$capacity)<.000001,
        'Доля топлива определяется вместимостью, не количеством кораблей.');
    transportAssert($f['fleet']->getExperience()>0 && count(Messages::findByIndex('to',[100])??[])===1,
        'Уцелевшим сохранён/начислен опыт, опубликован один результат.');
};
$cases['lost worker mailbox resumes saved round with the same seed']=static function():void {
    $f=expeditionFixture('combat'); $command=expeditionStart($f);
    $tasks=[];
    Expedition::initializeWorkers(static function(array $task)use(&$tasks):bool{$tasks[]=$task;return true;},static fn():float=>0.0);
    Commands::completeCommand($command,$f['fleet'],$f['account']);
    transportAssert(isset(States::state(1)['job']),'До рестарта есть сохранённый job.');
    // Новый mailbox, как после запуска процесса; сохранённое состояние repository остаётся.
    Expedition::initializeWorkers(static function(array $task)use(&$tasks):bool{$tasks[]=$task;Expedition::executeTask($task);return true;},static fn():float=>0.0);
    $active=FleetCommands::getActiveCommand(1);
    FleetCommands::update(['id'=>(int)$active['id'],'execute_time'=>(float)$active['execute_time']+61]);
    expeditionResult($f);
    transportAssert(count($tasks)>=2 && $tasks[0]['seed']===$tasks[1]['seed'],'Повтор раунда сохраняет случайность.');
    transportAssert(count(Messages::findByIndex('to',[100])??[])===1,'После восстановления один результат.');
};
$cases['three hours: hourly cargo, silent intermediate events and one final summary']=static function():void {
    $f=expeditionFixture('credits'); $hold=expeditionStart($f,10800);
    $used=$f['fleet']->getUsedCapacity(); $free=$f['fleet']->getFreeCapacity(); $credits=0.0;
    for($hour=1;$hour<=3;$hour++) {
        $command=FleetCommands::getActiveCommand(1);
        Commands::completeCommand($command,$f['fleet'],$f['account']);
        $state=States::state(1); $credits += $state['events'][$hour-1]['credits'];
        transportAssert(count($state['events'])===$hour && (float)$f['fleet']->getResources()[921]->getCount()===$credits,
            'Кредитная находка физически на борту после каждого часа.');
        transportAssert((float)UserResources::findExistingById(100)['credit']===0.0,'Счёт игрока до возвращения не меняется.');
        if($hour<3) {
            transportAssert($state['phase']==='holding' && (Messages::getAll()??[])===[],'До конца обследования сообщений нет.');
            transportAssert($f['fleet']->getUsedCapacity()===$used && $f['fleet']->getFreeCapacity()===$free,'Кредиты не занимают место.');
            Commands::completeCommand($command,$f['fleet'],$f['account']);
            transportAssert(count(States::state(1)['events'])===$hour,'Старый час нельзя применить повторно.');
            $f['fleet']->reloadFromRuntime();
        }
    }
    $messages=array_values(Messages::getAll()); $data=json_decode($messages[0]['data'],true);
    transportAssert(count($messages)===1 && $data['EventsCount']===3 && (float)$data['ResourcesFound'][921]===$credits,'Один сводный отчёт за три часа.');
    transportAssert($data['EventCounts']===['credits'=>3],'Структурированные события для рассказа без разбора локализованного текста.');
    $returnCommand=FleetCommands::getActiveCommand(1);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    transportAssert((float)UserResources::findExistingById(100)['credit']===$credits
        && $f['fleet']->getResources()[921]->getCount()===0.0 && count(Messages::getAll())===2,'На базе всё зачислено один раз, отправлено уведомление о прибытии.');
    $returned=array_values(array_filter(Messages::getAll(),static fn($row)=>$row['sample']==='FleetExpeditionReturned'));
    $delivery=json_decode($returned[0]['data'],true,512,JSON_THROW_ON_ERROR);
    transportAssert(count($returned)===1 && (float)$delivery['Resources'][921]===$credits
        && isset($delivery['Resources'][901],$delivery['Resources'][902],$delivery['Resources'][903],$delivery['ResourcesFuelReturned'][903])
        && $delivery['PlanetName']==='Source','Возврат содержит доставленный груз, кредиты, остаток топлива и базу.');
    Commands::completeCommand($returnCommand,$f['fleet'],$f['account']);
    transportAssert(count(Messages::getAll())===2,'Replay возврата не дублирует уведомление.');
};
$cases['recall after one hour: one summary, no future rewards, cargo delivered']=static function():void {
    $f=expeditionFixture('credits'); expeditionStart($f,10800);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    $credits=$f['fleet']->getResources()[921]->getCount();
    Commands::recall($f['fleet'],$f['account'],$f['fleet']->getCurrentPositionTime()+60);
    $data=json_decode(array_values(Messages::getAll())[0]['data'],true);
    transportAssert(count(Messages::getAll())===1 && $data['EventsCount']===1 && $data['lang']['FleetState']==='expedition.recalled','Отчёт только по состоявшемуся часу.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    transportAssert((float)UserResources::findExistingById(100)['credit']===$credits && States::state(1)===null,'Уже найденные кредиты возвращены.');
    transportAssert(count(Messages::getAll())===2,'Возврат отправляет отдельное уведомление, но не повторный итог.');
};
$cases['recall before first hour: no summary, but return is reported']=static function():void {
    $f=expeditionFixture(); expeditionStart($f,10800);
    Commands::recall($f['fleet'],$f['account'],$f['fleet']->getCurrentPositionTime()+60);
    transportAssert((Messages::getAll()??[])===[],'Без единого события нет отчёта экспедиции.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    $messages=array_values(Messages::getAll());
    transportAssert(count($messages)===1 && $messages[0]['sample']==='FleetExpeditionReturned','Даже без событий прибытие на базу подтверждено.');
};
$cases['loss on second hour destroys credits previously loaded aboard']=static function():void {
    $f=expeditionFixture('credits'); expeditionStart($f,10800);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    Config::add(['name'=>'ExpeditionWeightCredits','value'=>'0']); Config::add(['name'=>'ExpeditionWeightLost','value'=>'1']);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    $data=json_decode(array_values(Messages::getAll())[0]['data'],true);
    transportAssert(Fleets::getById(1)===null && (float)UserResources::findExistingById(100)['credit']===0.0,'Гибель не зачисляет бортовые кредиты.');
    transportAssert(count(Messages::getAll())===1 && $data['EventsCount']===2 && $data['ResourcesOnboard']===[],'Один итог о гибели за два события.');
};
$cases['credits can exceed fleet capacity and survive runtime reload']=static function():void {
    $f=expeditionFixture(); $used=$f['fleet']->getUsedCapacity(); $free=$f['fleet']->getFreeCapacity();
    $amount=$f['fleet']->getCapacity()*100;
    transportAssert($f['fleet']->getResources()[921]->setCount($amount)===0.0,'Кредиты не ограничены вместимостью.');
    $f['fleet']->save(); $f['fleet']->reloadFromRuntime();
    transportAssert($f['fleet']->getResources()[921]->getCount()===$amount
        && $f['fleet']->getUsedCapacity()===$used && $f['fleet']->getFreeCapacity()===$free,'Бортовой кошелёк сохраняется независимо от трюма.');
};
$cases['intermediate checkpoint failure retries without duplicate hourly credits']=static function():void {
    $f=expeditionFixture('credits'); $hold=expeditionStart($f,10800);
    $table=(new ReflectionProperty(FleetCommands::class,'table'))->getValue(); $table->failedWriteKey=(string)$hold['id']; $table->failedWrites=1;
    $failed=false;
    try { Commands::completeCommand($hold,$f['fleet'],$f['account']); } catch(RuntimeException) { $failed=true; }
    transportAssert($failed && States::state(1)['phase']==='settled','Сбой checkpoint замечен после единственного применения часа.');
    $credits=$f['fleet']->getResources()[921]->getCount();
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
    transportAssert(count(States::state(1)['events'])===1 && $credits===$f['fleet']->getResources()[921]->getCount()
        && (Messages::getAll()??[])===[],'Retry не дублирует кредиты и не создаёт промежуточное письмо.');
};
foreach($cases as $name=>$case) { $case(); echo "OK expedition {$name}\n"; }
echo 'RESULT '.count($cases)." expedition cases passed\n";
