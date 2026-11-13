<?php

declare(strict_types=1);

// Реальные Swoole Tables и отдельный процесс на раунд; SQL запрещён fixture-guard.
define('SP_GAME_DEFEND_FIXTURE_ONLY',true);
require __DIR__.'/FleetDefendSwooleTest.php';

use SPGame\Game\Enums\{FleetCommandType as Cmd,FleetMissionType as M};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{Config,FleetCommands,FleetExpeditions as States,Fleets,Messages,UserResources,Vars};
use SPGame\Game\Services\{FleetCommandsService as Commands,FleetExpeditionService as Expedition,FleetsData};
use SPGame\Game\Space\Coordinate;

$childPids=[];
foreach(['nothing','resources','credits','ships','time','lost','combat'] as $event) {
    $owner=defendFixture();
    researchRepository(States::class,[]);
    Vars::$resource[124]='expedition_tech'; $owner['Techs']['expedition_tech']=3;
    Vars::$attributes[202]['missions'][]=M::EXPEDITION->value;
    foreach(['resources','credits','ships','combat','lost','time','nothing'] as $key) {
        Config::add(['name'=>'ExpeditionWeight'.ucfirst($key),'value'=>$key===$event?'1':'0']);
    }
    Expedition::initializeWorkers(static function(array $task)use(&$childPids):bool {
        $process=new Swoole\Process(static function(Swoole\Process $worker)use($task):void {
            Expedition::executeTask($task);
            $worker->exit(0);
        },false,0);
        $pid=$process->start();
        if($pid===false) return false;
        $childPids[]=$pid;
        // Только test: ждём child, чтобы виртуальное игровое время не обогнало CPU.
        $status=Swoole\Process::wait(true);
        researchAssert($status!==false && $status['code']===0,'Экспедиционный процесс завершился с ошибкой: '.json_encode($status));
        return true;
    },static fn():float=>0.0);
    $fleet=FleetsData::getInstance()->get(1);
    $target=Coordinate::object(1,1,5);
    $preview=$fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,1,M::EXPEDITION);
    $creditBefore=(float)UserResources::findExistingById(1)['credit'];
    Commands::startMission($fleet,$owner,M::EXPEDITION->value,$target->toArray(),1,ceil($preview['fuel']['max']),[],1000,3600);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$owner);
    $state=States::state(1); $state['seed']=str_repeat('01',32); States::store(1,$state);
    for($i=0;$i<32 && ($command=FleetCommands::getActiveCommand(1))!==null;++$i) {
        if((int)$command['command_type']!==Cmd::EXPEDITION->value) break;
        Commands::completeCommand($command,$fleet,$owner);
    }
    $messages=Messages::findByIndex('to',[1])??[];
    researchAssert(count($messages)===1 && str_starts_with($messages[0]['sample'],'FleetExpedition'),'Нет native результата экспедиции.');
    if(Fleets::getById(1)!==null) {
        $state=States::state(1);
        for($i=0;$i<4 && ($command=FleetCommands::getActiveCommand(1))!==null;++$i) Commands::completeCommand($command,$fleet,$owner);
        $owner->save();
        researchAssert($fleet->getStatus()===0 && $fleet->getFuelCurrent()===0.0,'Native экспедиция не вернулась.');
        researchAssert(States::state(1)===null,'Native состояние экспедиции не очищено.');
        researchAssert((float)UserResources::findExistingById(1)['credit']===$creditBefore+$state['plan']['credits'],
            'Native кредиты потеряны/начислены повторно после AccountData::save.');
        $returned=Messages::findByIndex('to',[1])??[];
        researchAssert(count($returned)===2 && count(array_filter($returned,static fn($row)=>$row['sample']==='FleetExpeditionReturned'))===1,
            'Native возврат создаёт отдельное уведомление без повторного итога.');
    }
    echo 'PASS native expedition '.$event."\n";
}
researchAssert($childPids!==[],'NPC бой не использовал отдельные процессы.');
foreach(['finish','recall'] as $mode) {
    $owner=defendFixture(); researchRepository(States::class,[]);
    Vars::$resource[124]='expedition_tech'; $owner['Techs']['expedition_tech']=3;
    Vars::$attributes[202]['missions'][]=M::EXPEDITION->value;
    foreach(['resources','credits','ships','combat','lost','time','nothing'] as $key) Config::add(['name'=>'ExpeditionWeight'.ucfirst($key),'value'=>$key==='credits'?'1':'0']);
    $fleet=FleetsData::getInstance()->get(1); $target=Coordinate::object(1,1,5);
    $preview=$fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,1,M::EXPEDITION);
    $creditBefore=(float)UserResources::findExistingById(1)['credit'];
    if($mode==='finish') {
        $used=$fleet->getUsedCapacity();
        $fleet->getResources()[921]->setCount($fleet->getCapacity()*10); $fleet->save(); $fleet->reloadFromRuntime();
        researchAssert($fleet->getResources()[921]->getCount()>$fleet->getCapacity()
            && $fleet->getUsedCapacity()===$used,'Native кредиты не занимают трюм даже сверх вместимости.');
    }
    Commands::startMission($fleet,$owner,40,$target->toArray(),1,ceil($preview['fuel']['max']),[],1000,10800);
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$owner);
    $hours=$mode==='finish'?3:1;
    for($hour=1;$hour<=$hours;$hour++) {
        Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$owner);
        researchAssert(count(States::state(1)['events'])===$hour,'Native потерян час экспедиции.');
        if($hour<3) researchAssert((Messages::getAll()??[])===[],'Native промежуточное сообщение не нужно.');
        $fleet->reloadFromRuntime();
    }
    $credits=$fleet->getResources()[921]->getCount();
    researchAssert($credits>0 && (float)UserResources::findExistingById(1)['credit']===$creditBefore,'Native кредиты должны быть на Fleet.');
    if($mode==='recall') Commands::recall($fleet,$owner,$fleet->getCurrentPositionTime()+1);
    researchAssert(count(Messages::getAll())===1,'Native нужен один сводный итог.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1),$fleet,$owner); $owner->save();
    researchAssert((float)UserResources::findExistingById(1)['credit']===$creditBefore+$credits
        && $fleet->getResources()[921]->getCount()===0.0 && count(Messages::getAll())===2,'Native выгрузка ровно один раз и отдельное письмо о прибытии.');
    echo 'PASS native hourly expedition '.$mode."\n";
}
researchAssert(\SPGame\Core\Database::$calls===0,'Native тест обратился к игровой SQL.');
echo 'RESULT native expeditions: 7 outcomes, 2 hourly scenarios, '.count($childPids).' child rounds, SQL calls=0'."\n";
