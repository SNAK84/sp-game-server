<?php

declare(strict_types=1);

define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{Defenses,FleetCommands,PlanetBattles,Ships,Techs,Vars};
use SPGame\Game\Services\{AccountData,FleetBattleService,FleetCommandsService,FleetsData};
use SPGame\Game\Space\{Coordinate,ObjectType};

researchCase('Боевые Factors: атакующий, флот защитника, корабли и оборона планеты используют свои бонусы', static function (): void {
    defendFixture();
    Vars::$reslist['bonus'] = [109,110,111];
    foreach ([109=>'Attack',110=>'Defensive',111=>'Shield'] as $id=>$key) {
        Vars::$bonus[$id] = array_fill_keys(Vars::$bonusList,[0,0]);
        Vars::$bonus[$id][$key] = [0.1,0];
    }
    Techs::update(['id'=>1,'military_tech'=>5,'defence_tech'=>7,'shield_tech'=>3]);
    Techs::update(['id'=>2,'military_tech'=>1,'defence_tech'=>2,'shield_tech'=>4]);
    Vars::$CombatCaps[202]['shield'] = 10;
    Vars::$resource[401] = 'launcher';
    Vars::$reslist['defense'] = [401];
    Vars::$CombatCaps[401] = ['attack'=>80,'shield'=>20];
    Vars::$pricelist[401] = [901=>2000,902=>0,903=>0];
    Ships::update(['id'=>102,'small_cargo'=>3]);
    researchRepository(Defenses::class,[['id'=>101],['id'=>102,'launcher'=>2],['id'=>103]],['launcher']);
    $owner = new AccountData(1);
    $fleet = FleetsData::getInstance()->get(1);
    $fleet->getShip(202)->setExperience(100.0); $fleet->save(); // 10 XP на корабль.
    $target = Coordinate::object(1,1,2,ObjectType::PLANET);
    $preview = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,1,FleetMissionType::ATTACK);
    FleetCommandsService::startMission($fleet,$owner,FleetMissionType::ATTACK->value,$target->toArray(),1,ceil($preview['fuel']['max']),[],1000);
    $move = FleetCommands::getActiveCommand(1);
    researchAssert(FleetCommandsService::completeCommand($move,$fleet,$owner),'Не выполнено прибытие атакующего.');
    $state = PlanetBattles::state(102);
    $expected = [
        ['a','fleet:1:202',7.55,13.05,684.0],
        ['d','fleet:2:202',5.5,14.0,480.0],
        ['d','planet:102:202',5.5,14.0,480.0],
        ['d','defense:102:401',88.0,28.0,240.0],
    ];
    foreach ($expected as [$side,$id,$attack,$shield,$hull]) {
        $group = $state['combat'][$side][$id] ?? [];
        foreach (['attack'=>$attack,'shield'=>$shield,'hull'=>$hull] as $stat=>$value) {
            researchAssert(abs(($group[$stat] ?? -1)-$value)<1e-8,'Неверный бонус '.$id.'.'.$stat);
        }
    }
    $tasks = [];
    FleetBattleService::initializeWorkers(static function (array $task) use (&$tasks): bool {$tasks[]=$task;return true;});
    FleetBattleService::tick($state['startedAt']+1);
    researchAssert(count($tasks)===1,'Раунд не отправлен существующему worker pipeline.');
    FleetBattleService::executeTask($tasks[0]);
    FleetBattleService::tick($state['startedAt']+1.1);
    $after = PlanetBattles::state(102);
    researchAssert(count($after['combat']['rounds'])===1,'Не принят результат раунда.');
    foreach ($expected as [$side,$id,$attack,$shield,$hull]) {
        $group = $after['combat'][$side][$id];
        researchAssert(abs($group['attack']-$attack)<1e-8 && abs($group['shield']-$shield)<1e-8 && abs($group['hull']-$hull)<1e-8,
            'Worker повторно применил технологии или опыт.');
    }
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
