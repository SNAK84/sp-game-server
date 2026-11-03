<?php

declare(strict_types=1);

// DTO из настоящего сервиса для renderer-проверок SPA, без игровых SQL/команд.
ob_start();
require dirname(__DIR__).'/FleetExpeditionTest.php';
$f=expeditionFixture('nothing');
expeditionStart($f);
$page=new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
$holding=$page->buildFlyingFleetList($f['account'])[1];
expeditionResult($f);
$returning=$page->buildFlyingFleetList($f['account'])[1];
$messages=[];
foreach(['nothing','resources','credits','ships','lost','time','combat'] as $event) {
    $f=expeditionFixture($event); expeditionStart($f);
    expeditionResult($f);
    $messages[$event]=(\SPGame\Game\Repositories\Messages::findByIndex('to',[100])??[])[0];
}
$f=expeditionFixture('combat'); $command=expeditionStart($f);
\SPGame\Game\Services\FleetCommandsService::completeCommand($command,$f['fleet'],$f['account']);
$combat=$page->buildFlyingFleetList($f['account'])[1];
$f=expeditionFixture('credits'); expeditionStart($f,10800);
\SPGame\Game\Services\FleetCommandsService::completeCommand(\SPGame\Game\Repositories\FleetCommands::getActiveCommand(1),$f['fleet'],$f['account']);
$hourly=$page->buildFlyingFleetList($f['account'])[1];
$hourlyCredits=$f['fleet']->getResources()[921]->getCount();
ob_end_clean();
echo json_encode(compact('holding','returning','combat','messages','hourly','hourlyCredits'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
