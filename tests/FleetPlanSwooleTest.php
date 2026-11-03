<?php

declare(strict_types=1);

// Свежие native Tables; Database fixture запрещает любые SQL-вызовы.
require_once __DIR__ . '/fixtures/FleetPlanSpyRandom.php';
define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Game\Enums\{FleetMissionType as PMission, FleetCommandType as PCommand};
use SPGame\Game\Repositories\{Config as PConfig, FleetCommands as PCommands, Fleets as PFleets,
    FleetsShips as PShips, FleetExpeditions as PExpeditions, PlanetResources as PResources, Vars as PVars};
use SPGame\Game\Services\{FleetControlService as PControl, FleetCommandsService as PService,
    FleetExpeditionService as PExpedition, FleetsData as PData};

function nativePlanFixture(): array
{
    $owner=defendFixture();
    PVars::$attributes[202]['missions']=[1,3,5,7,10,11,12,15,20,30,40];
    $fleet=PData::getInstance()->get(1);
    return [$owner,$fleet];
}
function nativeMission(int $mission,int $orbit,array $extra=[]): array
{
    return ['kind'=>'mission','mission'=>$mission,'coordinates'=>['type'=>1,'galaxy'=>1,'system'=>1,'orbit'=>$orbit,'objectType'=>1],'speedFactor'=>10]+$extra;
}
function nativePlanStart($owner,$fleet,array $steps): void
{
    PControl::savePlan($fleet,$owner,['version'=>PControl::state($fleet)['version'],'steps'=>$steps,
        'fuel'=>10000,'resources'=>[901=>1000,902=>400,903=>200]],1000);
}
function nativePlanFinish($owner,$fleet,int $limit=30): void
{
    while($limit-- > 0 && ($command=PCommands::getActiveCommand(1))!==null){
        if(\SPGame\Game\Services\FleetAttackService::isBattleWait($command))throw new RuntimeException('Незавершённый бой.');
        PService::completeCommand($command,$fleet,$owner);
    }
    researchAssert($limit>0 && $fleet->getStatus()===0,'Маршрут не вернулся: '.json_encode(PControl::state($fleet)));
    $owner->save();
}
function nativePlanResolveBattle(int $planetId): void
{
    $children=0;
    \SPGame\Game\Services\FleetBattleService::initializeWorkers(static function(array $task)use(&$children):bool{
        $p=new \Swoole\Process(static function(\Swoole\Process $worker)use($task):void{
            \SPGame\Game\Services\FleetBattleService::executeTask($task);$worker->exit(0);
        },false,0);
        if($p->start()===false)return false;$children++;
        $result=\Swoole\Process::wait(true);researchAssert($result!==false&&$result['code']===0,'Ошибка боевого процесса САБ.');return true;
    });
    $state=\SPGame\Game\Repositories\PlanetBattles::state($planetId);
    researchAssert($state!==null,'Бой САБ не начался: '.json_encode(PCommands::getFleetQueue(1)));
    for($i=0;$i<15&&$state['phase']!=='finished';$i++){
        $now=$state['startedAt']+count($state['combat']['rounds'])+1;
        \SPGame\Game\Services\FleetBattleService::tick($now);\SPGame\Game\Services\FleetBattleService::tick($now+.1);
        $state=\SPGame\Game\Repositories\PlanetBattles::state($planetId);
    }
    researchAssert($state['phase']==='finished'&&$children>0,'Не рассчитан бой САБ.');
    \SPGame\Game\Services\FleetAttackService::process($state['startedAt']+count($state['combat']['rounds'])+.5);
}

if (defined('SP_GAME_PLAN_FIXTURE_ONLY') && SP_GAME_PLAN_FIXTURE_ONLY) return;

researchCase('native: PageAction → PlayerQueue → сервис маршрута из 12 этапов',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    $owner['Techs']['computer_tech'] = 12;
    $beforeMetal=PResources::findExistingById(102)['metal'];
    $steps=array_fill(0,11,nativeMission(10,2,['percent'=>10]));$steps[]=['kind'=>'return'];
    $plan=['version'=>PControl::state($fleet)['version'],'steps'=>$steps,'fuel'=>10000,'resources'=>[901=>1000]];
    $message=new \SPGame\Core\Message(['mode'=>'fleets','action'=>'SetFleetCommands','requestId'=>'native-plan-ack',
        'data'=>['fleetId'=>1,'plan'=>$plan]]);
    $response=(new \SPGame\Core\Message())->setRequestId('native-plan-ack');
    researchAssert(\SPGame\Game\Services\PageActionService::handle($message,$owner,1,$response),'Handler не обработал план.');
    researchAssert($response->source()['status']==='ok' && $response->source()['requestId']==='native-plan-ack','План не получил ACK.');
    $events=\SPGame\Game\Repositories\PlayerQueue::getByAccount(1);
    researchAssert(count($events)===1 && $events[0]['data']['plan']===$plan,'PlayerQueue обрезал составной маршрут.');
    researchAssert($fleet->getStatus()===0 && $fleet->getResources()[901]->getCount()===0.0,'ACK применил действие синхронно.');
    PControl::savePlan($fleet,$owner,$events[0]['data']['plan'],1000);
    nativePlanFinish($owner,$fleet);
    researchAssert(abs(PResources::findExistingById(102)['metal']-$beforeMetal-1000*(1-pow(.9,11)))<1e-6,'Доставлены неверные доли остатка.');
    $before=\SPGame\Game\Repositories\PlayerQueue::getAll();
    try{\SPGame\Game\Repositories\PlayerQueue::addQueue(1,1,101,'test',['oversized'=>str_repeat('x',16384)]);
        throw new \RuntimeException('Переполнение не отклонено.');}catch(\InvalidArgumentException){}
    researchAssert($before===\SPGame\Game\Repositories\PlayerQueue::getAll(),'Переполнение оставило событие.');
});
researchCase('native: две доставки по 50% остатка, конечная выгрузка и SQL=0',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    $before2=PResources::findExistingById(102);$before3=PResources::findExistingById(103);
    nativePlanStart($owner,$fleet,[nativeMission(10,2,['percent'=>50]),nativeMission(10,3,['percent'=>50]),['kind'=>'return']]);
    nativePlanFinish($owner,$fleet);
    researchAssert(abs(PResources::findExistingById(102)['metal']-$before2['metal']-500)<1e-6,'Первая доставка неверна.');
    researchAssert(abs(PResources::findExistingById(103)['metal']-$before3['metal']-250)<1e-6,'Вторая доставка неверна.');
    researchAssert($fleet->getFuelCurrent()===0.0 && $fleet->getResources()[901]->getCount()===0.0,'Остаток не выгружен дома.');
});
researchCase('native: транспорт → оборона на той же планете → транспорт → возврат',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    nativePlanStart($owner,$fleet,[nativeMission(10,2,['percent'=>50]),nativeMission(5,2,['duration'=>3600]),
        nativeMission(10,3,['percent'=>50]),['kind'=>'return']]);
    PService::completeCommand(PCommands::getActiveCommand(1),$fleet,$owner);
    $defend=PCommands::getActiveCommand(1);
    researchAssert((int)$defend['command_type']===22 && $fleet->getMission()===5,'Действие обороны не началось.');
    PControl::savePlan($fleet,$owner,['version'=>PControl::state($fleet)['version'],
        'steps'=>[nativeMission(10,3,['percent'=>100]),['kind'=>'return']]],$fleet->getCurrentPositionTime()+1);
    researchAssert(PCommands::getActiveCommand(1)===$defend,'Редактор изменил текущее удержание.');
    $types=array_column(array_filter(PCommands::getFleetCommandChain(1),fn($r)=>empty(PControl::params($r)['cancelled'])),'command_type');
    researchAssert(in_array(22,$types,true) && count(array_filter($types,fn($v)=>$v===32))===3,'Потеряно действие составного маршрута: '.json_encode($types));
    $before=PResources::findExistingById(103)['metal'];
    nativePlanFinish($owner,$fleet);
    researchAssert(abs(PResources::findExistingById(103)['metal']-$before-500)<1e-6,'После обороны потеряна следующая доставка.');
});
researchCase('native: экспедиция → доставка → возврат с сохранённым результатом экспедиции',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    researchRepository(PExpeditions::class,[]);
    PVars::$resource[124]='expedition_tech';$owner['Techs']['expedition_tech']=3;
    foreach(['resources','credits','ships','combat','lost','time','nothing'] as $event){
        PConfig::add(['name'=>'ExpeditionWeight'.ucfirst($event),'value'=>$event==='nothing'?'1':'0']);
    }
    $children=0;
    PExpedition::initializeWorkers(static function(array $task)use(&$children):bool{
        $process=new \Swoole\Process(static function(\Swoole\Process $p)use($task):void{PExpedition::executeTask($task);$p->exit(0);},false,0);
        if($process->start()===false)return false;$children++;
        $result=\Swoole\Process::wait(true);researchAssert($result!==false&&$result['code']===0,'Ошибка процесса экспедиции.');return true;
    },static fn():float=>0);
    $expedition=nativeMission(40,5,['duration'=>3600]);$expedition['coordinates']['objectType']=null;
    nativePlanStart($owner,$fleet,[$expedition,nativeMission(10,3,['percent'=>50]),['kind'=>'return']]);
    PService::completeCommand(PCommands::getActiveCommand(1),$fleet,$owner);
    $hold=PCommands::getActiveCommand(1);
    PControl::savePlan($fleet,$owner,['version'=>PControl::state($fleet)['version'],
        'steps'=>[nativeMission(10,3,['percent'=>100]),['kind'=>'return']]],$fleet->getCurrentPositionTime()+1);
    researchAssert(PCommands::getActiveCommand(1)===$hold,'Редактор изменил активную экспедицию.');
    $before=PResources::findExistingById(103)['metal'];
    PService::completeCommand($hold,$fleet,$owner);
    researchAssert(count(PExpeditions::state(1)['events']??[])===1,'Потерян часовой результат экспедиции.');
    nativePlanFinish($owner,$fleet);
    researchAssert(PExpeditions::state(1)===null,'После составного маршрута осталась незавершённая запись экспедиции.');
    researchAssert(abs(PResources::findExistingById(103)['metal']-$before-1000)<1e-6,'Не применён новый план после экспедиции.');
    $messages=\SPGame\Game\Repositories\Messages::getAll();
    researchAssert(count(array_filter($messages,static fn($row)=>$row['sample']==='FleetExpeditionReturned'))===1
        && count(array_filter($messages,static fn($row)=>$row['sample']==='FleetReturnedWithFuel'))===0,
        'Составной маршрут отправляет ровно одно уведомление о возвращении после экспедиции.');
});

researchCase('native: две экспедиции на одной орбите → транспорт, кредиты обеих доставлены домой',static function():void{
    [$owner,$fleet]=nativePlanFixture();researchRepository(PExpeditions::class,[]);
    PVars::$resource[124]='expedition_tech';$owner['Techs']['expedition_tech']=3;
    foreach(['resources','credits','ships','combat','lost','time','nothing'] as $event){
        PConfig::add(['name'=>'ExpeditionWeight'.ucfirst($event),'value'=>$event==='credits'?'1':'0']);
    }
    $wallet=\SPGame\Game\Repositories\UserResources::findExistingById(1)['credit'];
    $expedition=nativeMission(40,5,['duration'=>3600]);$expedition['coordinates']['objectType']=null;
    nativePlanStart($owner,$fleet,[$expedition,$expedition,nativeMission(10,3,['percent'=>100]),['kind'=>'return']]);
    PService::completeCommand(PCommands::getActiveCommand(1),$fleet,$owner);
    $first=PCommands::getActiveCommand(1);PService::completeCommand($first,$fleet,$owner);
    $credits=$fleet->getResources()[921]->getCount();$second=PCommands::getActiveCommand(1);
    researchAssert($credits>0 && (int)$second['command_type']===40 && $first['id']!==$second['id'],'Вторая экспедиция не началась отдельно.');
    PService::completeCommand($second,$fleet,$owner);
    $allCredits=$fleet->getResources()[921]->getCount();
    researchAssert($allCredits>$credits,'Вторая находка не добавлена к грузу первой.');
    nativePlanFinish($owner,$fleet);
    researchAssert(\SPGame\Game\Repositories\UserResources::findExistingById(1)['credit']-$wallet===$allCredits,'Кредиты потеряны/удвоены после смены миссии.');
    researchAssert(PExpeditions::state(1)===null && $fleet->getResources()[921]->getCount()===0.0,'Не очищена завершённая экспедиция.');
});
researchCase('native: атака → частичная выгрузка на той же планете → возврат после боя',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    $fleet->setShipCount(202,100);$fleet->save();
    $children=0;
    \SPGame\Game\Services\FleetBattleService::initializeWorkers(static function(array $task)use(&$children):bool{
        $process=new \Swoole\Process(static function(\Swoole\Process $p)use($task):void{
            \SPGame\Game\Services\FleetBattleService::executeTask($task);$p->exit(0);
        },false,0);
        if($process->start()===false)return false;$children++;
        $result=\Swoole\Process::wait(true);researchAssert($result!==false&&$result['code']===0,'Ошибка боевого процесса.');return true;
    });
    nativePlanStart($owner,$fleet,[nativeMission(1,2),nativeMission(10,2,['percent'=>25]),['kind'=>'return']]);
    PService::completeCommand(PCommands::getActiveCommand(1),$fleet,$owner);
    $state=\SPGame\Game\Repositories\PlanetBattles::state(102);
    researchAssert($state!==null,'Бой не начался.');
    for($i=0;$i<15 && $state['phase']!=='finished';$i++){
        $now=$state['startedAt']+count($state['combat']['rounds'])+1;
        \SPGame\Game\Services\FleetBattleService::tick($now);
        \SPGame\Game\Services\FleetBattleService::tick($now+.1);
        $state=\SPGame\Game\Repositories\PlanetBattles::state(102);
    }
    researchAssert($state['phase']==='finished'&&$children>0,'Раунды боя не завершились.');
    $now=$state['startedAt']+count($state['combat']['rounds'])+.5;
    \SPGame\Game\Services\FleetAttackService::process($now);
    researchAssert($fleet->getMission()===10,'После боя не началось следующее действие.');
    $unloads=array_values(array_filter(PCommands::getFleetCommandChain(1),fn($q)=>(int)$q['command_type']===32&&(int)$q['executed']===1));
    researchAssert(count($unloads)===1 && (float)(json_decode($unloads[0]['params'],true)['percent']??-1)===25.0,'После боя потеряна частичная выгрузка.');
    nativePlanFinish($owner,$fleet);
});

researchCase('native: транспорт → САБ создаёт группу при начале соответствующего этапа',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    researchRepository(\SPGame\Game\Repositories\FleetAttackGroups::class,[]);
    $fleet->setShipCount(202,100);$fleet->save();
    nativePlanStart($owner,$fleet,[nativeMission(10,2,['percent'=>10]),nativeMission(3,3,['group'=>[]]),['kind'=>'return']]);
    researchAssert(\SPGame\Game\Repositories\FleetAttackGroups::getAll()===[],'Группа создана до своего этапа.');
    PService::completeCommand(PCommands::getActiveCommand(1),$fleet,$owner);
    $move=PCommands::getActiveCommand(1);$p=$move===null?[]:PControl::params($move);
    researchAssert($fleet->getMission()===3 && (int)($p['groupId']??0)>0,'САБ не активировалась после транспорта: '.json_encode(PControl::state($fleet)));
    researchAssert((float)$p['groupExecuteAt']>$fleet->getCurrentPositionTime() && isset($p['groupSpeedCap']),'Нет общего срока/скорости.');
    PService::completeCommand($move,$fleet,$owner);
    $rendezvous=PCommands::getActiveCommand(1);
    researchAssert((int)$rendezvous['command_type']===21,'САБ не дошла до точки сбора.');
    PService::completeCommand($rendezvous,$fleet,$owner);
    nativePlanResolveBattle(103);
    nativePlanFinish($owner,$fleet);
});

researchCase('native: сбор обломков → частичная доставка → возврат',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    \SPGame\Game\Repositories\DebrisFields::addResources(1,1,2,1000,500,1000);
    $recycle=nativeMission(12,2);$recycle['coordinates']['objectType']=2;
    $before=PResources::findExistingById(103)['metal'];
    nativePlanStart($owner,$fleet,[$recycle,nativeMission(10,3,['percent'=>50]),['kind'=>'return']]);
    nativePlanFinish($owner,$fleet);
    researchAssert(abs(PResources::findExistingById(103)['metal']-$before-1000)<1e-6,'Собранные ресурсы не доставлены.');
    $field=\SPGame\Game\Repositories\DebrisFields::at(1,1,2);
    researchAssert((float)($field['metal']??0)===0.0 && (float)($field['crystal']??0)===0.0,'Обломки не списаны.');
});
researchCase('native: шпионаж → доставка → возврат',static function():void{
    [$owner,$fleet]=nativePlanFixture();
    PVars::$resource[210]='probe';PVars::$resource[106]='spy_tech';PVars::$reslist['fleet'][]=210;
    PVars::$attributes[210]=['capacity'=>100,'speed'=>5000,'consumption'=>1,'consumption2'=>1,'tech'=>1,'missions'=>[20]];
    PVars::$pricelist[210]=[901=>0,902=>1000];PVars::$CombatCaps[210]=['attack'=>0,'shield'=>0];
    $indexes=(new ReflectionProperty(PFleets::class,'indexTables'))->getValue();
    defendColumns(PShips::class,['probe_count'=>\Swoole\Table::TYPE_INT,'probe_damage'=>\Swoole\Table::TYPE_FLOAT,
        'probe_experience'=>\Swoole\Table::TYPE_FLOAT,'probe_morale'=>\Swoole\Table::TYPE_FLOAT,'probe_status'=>\Swoole\Table::TYPE_INT]);
    researchRepository(PShips::class,PShips::getAll());
    (new ReflectionProperty(PFleets::class,'indexTables'))->setValue(null,$indexes);
    PShips::update(['id'=>1,'probe_count'=>1,'probe_morale'=>1.0]);PShips::update(['id'=>2,'small_cargo_count'=>0]);
    $fleet->reloadFromRuntime();
    $before=PResources::findExistingById(103)['metal'];
    nativePlanStart($owner,$fleet,[nativeMission(20,2),nativeMission(10,3,['percent'=>100]),['kind'=>'return']]);
    // Здесь проверяется продолжение маршрута, а не случайная контрразведка.
    // При counterLimit=0 пара0/0 по правилам Nova тоже уничтожает зонды.
    withFleetPlanSpyRolls([0,100],static fn()=>nativePlanFinish($owner,$fleet));
    researchAssert(abs(PResources::findExistingById(103)['metal']-$before-1000)<1e-6,'После шпионажа потеряна доставка.');
});

restore_error_handler();
echo "RESULT native plans: $passed passed / $failed failed; SQL calls=" . \SPGame\Core\Database::$calls . "\n";
exit($failed>0||\SPGame\Core\Database::$calls>0?1:0);
