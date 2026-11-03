<?php

declare(strict_types=1);

define('SP_GAME_DEFEND_FIXTURE_ONLY',true);
require __DIR__.'/FleetDefendSwooleTest.php';

use SPGame\Core\{Database,Message};
use SPGame\Game\Pages\{HangarPage,InformationPage};
use SPGame\Game\Repositories\{Config,FleetCommands,Fleets,FleetsShips,PlanetResources,Queues,Vars};
use SPGame\Game\Services\{FleetsServices,QueuesServices};

function removedFixture(): \SPGame\Game\Services\AccountData
{
    $owner=defendFixture();
    Config::add(['name'=>'MaxQueueHangar','value'=>'10']);
    Config::add(['name'=>'MaxFleetPerBuild','value'=>'1000']);
    Vars::$reslist['nametype']=['fleet'=>[1=>'civil'],'defense'=>[]];
    Vars::$attributes[202]+=['factor'=>1,'max'=>0,'type'=>1];
    return $owner;
}

function removedRuntime(): array
{
    return [Queues::getAll(),Queues::getPendingSync(),PlanetResources::getAll(),PlanetResources::getPendingSync(),
        Fleets::getAll(),Fleets::getPendingSync(),FleetsShips::getAll(),FleetCommands::getAll()];
}

researchCase('удалённый ID: поддельный заказ не меняет resources/queue/markers',static function():void{
    $owner=removedFixture();
    $before=removedRuntime();$resources=$owner['Resources']->toArray();
    QueuesServices::AddToQueueHangar([220=>100],$owner,1000);
    researchAssert(removedRuntime()===$before && $owner['Resources']->toArray()===$resources,'Неизвестный тип изменил runtime.');
    QueuesServices::AddToQueueHangar([220=>100,202=>2],$owner,1000);
    $queue=Queues::getCurrentQueue('hangars',1,101);
    researchAssert(count($queue)===1 && (int)$queue[0]['object_id']===202 && (int)$queue[0]['count']===2,'Не работает обычный заказ.');
});

researchCase('удалённый ID: AddToOrbit и MoveShip не создают пустой флот',static function():void{
    $owner=removedFixture();$before=removedRuntime();
    FleetsServices::AddToOrbit([220=>100],$owner,1000);
    FleetsServices::MoveShip(1,0,220,100,$owner,1000);
    researchAssert(removedRuntime()===$before,'Неизвестный тип изменил состав/markers.');
});

researchCase('удалённый ID: справка отвергает ID, верфь сохраняет обычные корабли',static function():void{
    $owner=removedFixture();$before=removedRuntime();
    defendRejected(static function()use(&$owner):void{(new InformationPage(new Message(['data'=>['id'=>220]])))->render($owner);});
    $page=(new HangarPage(new Message()))->render($owner);
    researchAssert(!isset($page['FleetList'][220]) && isset($page['FleetList'][202]) && removedRuntime()===$before,'Изменена верфь или runtime.');
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=".Database::$calls."\n";
exit($failed>0 || Database::$calls>0 ? 1 : 0);
