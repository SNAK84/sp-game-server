<?php

declare(strict_types=1);

// Изолированные реальные Swoole Tables; никакого доступа к игровым данным/MySQL.
define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\{Database,Message};
use SPGame\Game\Enums\{FleetCommandType as Command,FleetMissionType as Mission};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{FleetCommands,Fleets,Galaxy,GalaxyOrbits,Planets,PlanetResources,PlayerQueue,Vars};
use SPGame\Game\Services\{AccountData,FleetCommandsService as Commands,FleetDefendService as Defend,
    FleetFlightService,FleetMissionBuilder,FleetsData,PageActionService};
use SPGame\Game\Space\{Coordinate,ObjectType};

function recallProjection(AccountData $owner): array
{
    return (new FleetsPage(new Message()))->buildFlyingFleetList($owner)[1];
}

function recallFinish(Fleet $fleet, AccountData $owner): void
{
    for ($i=0;$i<12 && ($command=FleetCommands::getActiveCommand($fleet->getId()))!==null;++$i) {
        researchAssert((int)$command['command_type'] !== Command::DEFEND->value, 'После отзыва повторно началось удержание.');
        Commands::completeCommand($command,$fleet,$owner);
    }
    researchAssert($fleet->getStatus()===Fleet::STATUS_IDLE && !$fleet->isReturn(), 'Флот не вернулся на базу.');
    researchAssert($fleet->getCurrentCoordinate()->getOrbit()===1 && $fleet->getHomePlanetId()===101, 'Изменена база или неверная точка возврата.');
}

if (in_array('--export-ui', $argv ?? [], true)) {
    $owner=defendFixture(); $fleet=defendStart($owner); defendArrive($fleet,$owner);
    echo json_encode(['holding'=>recallProjection($owner), 'missions'=>Mission::list()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    exit;
}

researchCase('Удержание имеет отдельную фазу и точный таймер, видимый также принимающему игроку', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner); $hold=defendArrive($fleet,$owner);
    $event=recallProjection($owner);
    researchAssert($event['route_leg']['direction']==='holding', 'Удержание показано как перелёт.');
    researchAssert($event['route_leg']['arrives_at']===$hold['execute_time'] && !$event['route_leg']['estimated'], 'Показан таймер старого перелёта.');
    researchAssert($event['can_recall'] && !$event['is_return'], 'Нет отзыва с удержания.');
    $host=new AccountData(2);
    $incoming=(new FleetsPage(new Message()))->buildVisibleFlyingFleetList($host)[1];
    researchAssert($incoming['route_leg']['direction']==='holding' && $incoming['is_incoming'], 'Принимающий не видит фазу обороны.');
    researchAssert(!isset($incoming['can_recall'],$incoming['fuel'],$incoming['commands']), 'Получатель может управлять чужим флотом.');
});

researchCase('Отзыв с удержания: фактическое снабжение, штатный возврат и отсутствие кнопки повторного отзыва', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner); defendArrive($fleet,$owner);
    $time=$fleet->getCurrentPositionTime()+10;
    $stock=PlanetResources::findExistingById(102)['deuterium'];
    Commands::recall($fleet,$owner,$time);
    $event=recallProjection($owner);
    researchAssert(!$event['can_recall'] && $event['recall_pending'], 'Повторный отзыв разрешён.');
    Defend::rescheduleSupplies($time);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$owner);
    researchAssert($fleet->isReturn() && !recallProjection($owner)['can_recall'], 'Нет возврата после удержания.');
    researchBalances(researchLedger(),[102=>['deuterium'=>$stock-Defend::fuelRate($fleet,$owner)*10]]);
    recallFinish($fleet,$owner);
});

researchCase('Отзыв на половине внутрисистемного полёта: реальная позиция, топливо и ни одного действия у цели', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    $active=FleetCommands::getActiveCommand(1); $params=json_decode($active['params'],true);
    $time=($fleet->getCurrentPositionTime()+$active['execute_time'])/2;
    $position=(new FleetFlightService())->calculateCurrentCoordinate($fleet,$active,$time);
    $fuel=$fleet->getFuelCurrent(); $ledger=researchLedger();
    Commands::recall($fleet,$owner,$time);
    $back=FleetCommands::getActiveCommand(1); $backParams=json_decode($back['params'],true);
    researchAssert($fleet->getCurrentCoordinate()->toArray()===$position->toArray(), 'Флот телепортирован к старту/цели.');
    researchAssert($fleet->isReturn() && $backParams['returnLeg'], 'Не создано обратное плечо.');
    researchAssert(abs($fleet->getFuelCurrent()-($fuel+$params['fuelCost']/2-$backParams['fuelCost']))<1e-8, 'Топливо списано дважды или полностью возвращено.');
    researchAssert(researchLedger()===$ledger && Defend::holds()===[], 'Отзыв затронул ресурсы планеты или активировал оборону.');
    researchAssert(count(FleetCommands::getActiveCommands(1))===1, 'Одновременно активны две команды.');
    foreach (recallProjection($owner)['commands'] as $projected) {
        researchAssert($projected['key']!=='DEFEND', 'Отменённая оборона осталась в плане.');
    }
    defendRejected(static fn()=>Commands::recall($fleet,$owner,$time+1));
    recallFinish($fleet,$owner);
});

researchCase('Отзыв не зависит от выбранной страницы/планеты и не разрешён другому владельцу', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    $before=FleetCommands::getFleetCommandChain(1);
    defendRejected(static fn()=>Commands::recall($fleet,new AccountData(3),1001));
    researchAssert(FleetCommands::getFleetCommandChain(1)===$before, 'Чужой отзыв изменил команды.');
    $owner['WorkPlanet']=103;
    Commands::recall($fleet,$owner,1001);
    $target=Commands::getCommandTarget(FleetCommands::getActiveCommand(1));
    researchAssert($target->getOrbit()===1 && $fleet->getHomePlanetId()===101, 'Возврат направлен на выбранную, а не базируемую планету.');
});

researchCase('ACK отзыва содержит requestId и создаёт только PlayerQueue event', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    $before=FleetCommands::getFleetCommandChain(1);
    $message=(new Message())->setMode('fleets')->setAction('RecallFleet')->setRequestId('recall-correlation')->setData('fleetId',1);
    $response=(new Message())->setRequestId($message->getRequestId());
    PageActionService::handle($message,$owner,1,$response);
    researchAssert($response->source()['status']==='ok' && $response->source()['requestId']==='recall-correlation', 'ACK потерял correlation.');
    $events=PlayerQueue::getByAccount(1);
    researchAssert(count($events)===1 && array_values($events)[0]['action']===PlayerQueue::ActionFleetRecall, 'Нет события отзыва.');
    researchAssert(FleetCommands::getFleetCommandChain(1)===$before && !$fleet->isReturn(), 'ACK уже изменил полёт.');
    foreach ([null,0,-1,'1',3] as $id) {
        $invalid=(new Message())->setMode('fleets')->setAction('RecallFleet')->setData('fleetId',$id);
        $error=new Message(); PageActionService::handle($invalid,$owner,1,$error);
        researchAssert($error->source()['status']==='error', 'Принят некорректный/чужой ID.');
    }
    researchAssert(count(PlayerQueue::getByAccount(1))===1, 'Невалидный отзыв попал в очередь.');
});

researchCase('Отзыв невозможен во время боя и у завершённого флота', static function (): void {
    $owner=defendFixture();
    defendRejected(static fn()=>Commands::recall(FleetsData::getInstance()->get(1),$owner,1001));
    $fleet=defendStart($owner); $hold=defendArrive($fleet,$owner);
    $attacker=FleetsData::getInstance()->get(3);
    $attacker->setCurrentCoordinate($fleet->getCurrentCoordinate(),$fleet->getCurrentPositionTime()+1); $attacker->save();
    $attack=FleetCommands::addCommand(3,Command::ATTACK,['target'=>$fleet->getCurrentCoordinate()->toArray()]);
    \SPGame\Game\Services\FleetAttackService::arrive($attack,$attacker,new AccountData(3));
    defendRejected(static fn()=>Commands::recall($fleet,$owner,$fleet->getCurrentPositionTime()+1));
    researchAssert(!recallProjection($owner)['can_recall'], 'Во время боя показана рабочая кнопка.');
});

researchCase('PageAction → PlayerQueue → настоящий EventLoop запускает возврат и завершает событие', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    \SPGame\Core\Connect::init();
    $message=(new Message())->setMode('fleets')->setAction('RecallFleet')->setData('fleetId',1);
    PageActionService::handle($message,$owner,1,new Message());
    $event=array_values(PlayerQueue::getByAccount(1))[0];
    PlayerQueue::update(['id'=>$event['id'],'added_at'=>1001.0]);
    $changed=(new ReflectionMethod(\SPGame\Game\Services\EventLoop::class,'processPlayerEvents'))
        ->invoke(new \SPGame\Game\Services\EventLoop(),1);
    researchAssert($changed && (PlayerQueue::getByAccount(1) ?? [])===[], 'EventLoop не завершил событие/не запросил ActualData.');
    researchAssert($fleet->isReturn() && count(FleetCommands::getActiveCommands(1))===1, 'EventLoop не запустил возврат.');
});

researchCase('Отзыв сохраняет груз до базы, возвращает его один раз и переживает восстановление Fleet из runtime', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    $fleet->getResources()[901]->setCount(123); $fleet->getResources()[902]->setCount(45); $fleet->save();
    Commands::recall($fleet,$owner,1001);
    $fuel=$fleet->getFuelCurrent(); $stock=PlanetResources::findExistingById(101);
    (new ReflectionProperty(FleetsData::class,'instance'))->setValue(null,null);
    $cold=new AccountData(1); $restored=FleetsData::getInstance()->get(1);
    researchAssert($restored->isReturn() && $restored->getFuelCurrent()===$fuel
        && $restored->getResources()[901]->getCount()===123.0, 'После восстановления потеряны возврат/груз/топливо.');
    recallFinish($restored,$cold);
    $after=PlanetResources::findExistingById(101);
    researchAssert($after['metal'] >= $stock['metal']+123 && $after['crystal'] >= $stock['crystal']+45, 'Груз не выгружен на базу.');
    researchAssert($restored->getResources()[901]->getCount()===0.0 && $restored->getFuelCurrent()===0.0, 'Груз/топливо остались для повторной выгрузки.');
    researchAssert(FleetCommands::getActiveCommand(1)===null, 'Осталась активная команда после возврата.');
});

researchCase('Ошибка активации возврата восстанавливает топливо/позицию/исходную очередь; повтор работает', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    $reserve=$fleet->getFuelCurrent(); $fleet->setFuelCurrent(0); $fleet->save();
    $before=Fleets::findExistingById(1); $commands=FleetCommands::getFleetCommandChain(1);
    $time=1000+($commands[1]['execute_time']-1000)*0.75;
    defendRejected(static fn()=>Commands::recall($fleet,$owner,$time));
    researchAssert(Fleets::findExistingById(1)===$before && FleetCommands::getFleetCommandChain(1)===$commands, 'Ошибка отзыва оставила частичный commit.');
    $fleet->setFuelCurrent($reserve); $fleet->save();
    Commands::recall($fleet,$owner,$time);
    researchAssert($fleet->isReturn(), 'Повтор после rollback не работает.');
});

researchCase('Переполненная Table команд: rollback сохраняет очередь и индексы; после освобождения места отзыв повторяется', static function (): void {
    $owner=defendFixture(); $fleet=defendStart($owner);
    $before=Fleets::findExistingById(1); $commands=FleetCommands::getFleetCommandChain(1);
    $table=(new ReflectionProperty(FleetCommands::class,'table'))->getValue();
    $indexes=(new ReflectionProperty(FleetCommands::class,'indexTables'))->getValue();
    $indexIds=static function (\Swoole\Table $index): array {
        $result=[];
        foreach($index as $key=>$row) {
            $ids=unserialize($row['ids']); sort($ids);
            $result[$key]=$ids;
        }
        ksort($result);
        return $result;
    };
    $indexBefore=$indexIds($indexes['fleet_id']);
    $keys=[]; $failure=null;
    // В этом сценарии намеренно проверяется set(false), а не ErrorException от warning.
    set_error_handler(static fn(): bool=>true);
    try {
        $row=FleetCommands::castRowToSchema(['id'=>100000,'fleet_id'=>9999],true);
        for($i=0;$i<10000;++$i) {
            $key='full-'.$i;
            if (!$table->set($key,$row)) continue;
            $keys[]=$key;
        }
        try { Commands::recall($fleet,$owner,1001); }
        catch (Throwable $e) { $failure=$e; }
    } finally {
        restore_error_handler();
        foreach($keys as $key) $table->del($key);
    }
    researchAssert($failure!==null,'Переполнение не было обнаружено.');
    researchAssert(Fleets::findExistingById(1)===$before && FleetCommands::getFleetCommandChain(1)===$commands,'Переполнение оставило частичный возврат.');
    researchAssert($indexIds($indexes['fleet_id'])===$indexBefore,'После failed add остался ghost ID в индексе команд.');
    Commands::recall($fleet,$owner,1001);
    researchAssert($fleet->isReturn(),'Повтор отзыва после освобождения места не работает.');
});

foreach ([Mission::ATTACK,Mission::TRANSPORT,Mission::RELOCATE,Mission::SPY,Mission::RECYCLE,Mission::COLONIZE] as $mission) {
    researchCase('Общий отзыв по дороге: '.$mission->name, static function () use ($mission): void {
        $owner=defendFixture(); $fleet=defendStart($owner);
        // Действия у цели ещё не исполнялись: проверяем общий MOVE/return pipeline.
        $fleet->setMission($mission); $fleet->save();
        Commands::recall($fleet,$owner,1001);
        researchAssert($fleet->getMission()===$mission->value && $fleet->isReturn(), 'Отзыв изменил тип миссии.');
        recallFinish($fleet,$owner);
    });
}

foreach ([Command::MOVE_BETWEEN_SYSTEMS,Command::MOVE_BETWEEN_GALAXIES] as $type) {
    researchCase('Отзыв при переходе '.$type->name.': без телепортации, затем возврат', static function () use ($type): void {
        $owner=defendFixture(); $fleet=defendStart($owner);
        $galaxy=$type===Command::MOVE_BETWEEN_GALAXIES?2:1;
        Galaxy::add(Galaxy::castRowToSchema(['id'=>2,'galaxy'=>$galaxy,'system'=>2,'star_type'=>'G','max_distance'=>3500,'max_orbits'=>1],true));
        GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>20,'galaxy'=>$galaxy,'system'=>2,'orbit'=>2,'type'=>GalaxyOrbits::SYSTEM_EDGE,'distance'=>3500],true));
        FleetCommands::clearFleetQueue(1);
        $fleet->setCurrentCoordinate(Coordinate::object(1,1,4,null,angle:20),1000);
        $fleet->setFuelTotal(1e8); $fleet->setFuelCurrent(1e8); $fleet->save();
        FleetCommands::addCommand(1,$type,['target'=>Coordinate::object($galaxy,2,2,null,angle:40)->toArray(),
            'speedFactor'=>1,'returnLeg'=>false,'destination'=>['galaxy'=>$galaxy,'system'=>2]]);
        Commands::activateNextCommand($fleet,$owner);
        $active=FleetCommands::getActiveCommand(1); $position=$fleet->getCurrentCoordinate()->toArray();
        Commands::recall($fleet,$owner,1001);
        researchAssert(FleetCommands::getActiveCommand(1)['id']===$active['id'] && $fleet->getCurrentCoordinate()->toArray()===$position, 'Прерван переход телепортацией.');
        researchAssert(recallProjection($owner)['recall_pending'], 'Отзыв перехода не отображается.');
        Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$owner);
        researchAssert($fleet->isReturn() && $fleet->getCurrentCoordinate()->getGalaxy()===$galaxy && $fleet->getCurrentCoordinate()->getSystem()===2, 'Возврат начался не у фактического выхода.');
        recallFinish($fleet,$owner);
    });
}

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=".Database::$calls."\n";
exit($failed>0 || Database::$calls>0 ? 1 : 0);
