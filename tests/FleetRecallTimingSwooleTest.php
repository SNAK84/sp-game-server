<?php

declare(strict_types=1);

define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__.'/FleetDefendSwooleTest.php';

use SPGame\Core\{Database,Message};
use SPGame\Game\Enums\{FleetCommandType as Command,FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet,FleetTarget};
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{FleetCommands,Fleets,Galaxy,GalaxyOrbits,Planets};
use SPGame\Game\Services\{AccountData,FleetCommandsService as Commands,FleetFlightService as Flight,FleetsData,SystemCoordinate};
use SPGame\Game\Space\{Coordinate,ObjectType};

function timingStart(AccountData $owner, float $factor): Fleet
{
    $fleet=FleetsData::getInstance()->get(1);
    $target=Coordinate::object(1,1,2,ObjectType::PLANET);
    $preview=$fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,$factor,Mission::DEFEND);
    Commands::startMission($fleet,$owner,Mission::DEFEND->value,$target->toArray(),$factor,ceil($preview['fuel']['max']),[],1000,3600);
    return $fleet;
}

researchCase('Неподвижная база: 5/30/60 секунд туда дают 5/30/60 назад при 10/50/100% скорости', static function (): void {
    foreach([1,5,10] as $factor) foreach([5,30,60] as $elapsed) {
        $owner=defendFixture();
        Planets::update(['id'=>101,'speed'=>0]); Planets::update(['id'=>102,'speed'=>0]);
        $fleet=timingStart($owner,$factor); $out=FleetCommands::getActiveCommand(1);
        $params=json_decode($out['params'],true); $full=$out['execute_time']-1000;
        $loaded=$fleet->getFuelCurrent()+$params['fuelCost'];
        Commands::recall($fleet,$owner,1000+$elapsed);
        $back=FleetCommands::getActiveCommand(1); $bp=json_decode($back['params'],true);
        $seconds=$back['execute_time']-$fleet->getCurrentPositionTime();
        researchAssert(abs($seconds-$elapsed)<0.000002,'Повторная базовая длительность при '.$elapsed.' сек, factor='.$factor.': '.$seconds);
        researchAssert(abs($bp['fuelCost']-$params['fuelCost']*$elapsed/$full)<1e-7,'Повторная полная цена маленького участка.');
        researchAssert(abs($fleet->getFuelCurrent()-($loaded-2*$params['fuelCost']*$elapsed/$full))<1e-7,'Неверный баланс топлива туда/обратно.');
        echo 'TIMING '.json_encode(['speedPercent'=>$factor*10,'outboundElapsed'=>$elapsed,'returnSeconds'=>$seconds])."\n";
    }
});

researchCase('Обновление угла/времени планеты не сдвигает сохранённую траекторию MOVE', static function (): void {
    $owner=defendFixture(); $fleet=timingStart($owner,10); $move=FleetCommands::getActiveCommand(1);
    $flight=new Flight(); $before=$flight->calculateCurrentCoordinate($fleet,$move,1010)->toArray();
    $page=new FleetsPage(new Message()); $event=$page->buildFlyingFleetList($owner)[1];
    foreach([101,102] as $id) Planets::update(['id'=>$id,'deg'=>30/3600,'update_time'=>1030.0]);
    researchAssert($flight->calculateCurrentCoordinate($fleet,$move,1010)->toArray()===$before,'Старт потянулся за планетой.');
    $after=$page->buildFlyingFleetList($owner)[1];
    researchAssert($after['segment_start']===$event['segment_start'] && $after['segment_end']===$event['segment_end'],'Клиент получил другую геометрию уже начатого участка.');
    $params=json_decode($move['params'],true);
    researchAssert($params['motionStart']['type']===2 && $params['motionEnd']['type']===2,'Нет фиксированных физических endpoints.');
});

researchCase('Вращающаяся база: пересечение рассчитано с прежней физической скоростью; топливо пропорционально времени', static function (): void {
    foreach([0,1] as $rotation) {
        $owner=defendFixture(); Planets::update(['id'=>101,'speed'=>360,'rotation'=>$rotation]);
        Planets::update(['id'=>102,'speed'=>0,'deg'=>90]);
        $fleet=timingStart($owner,10); $out=FleetCommands::getActiveCommand(1); $op=json_decode($out['params'],true);
        $flight=new Flight(); $a=Coordinate::fromArray($op['motionStart']); $b=Coordinate::fromArray($op['motionEnd']);
        $length=hypot($b->getDistance()*cos(deg2rad($b->getAngle()))-$a->getDistance()*cos(deg2rad($a->getAngle())),
            $b->getDistance()*sin(deg2rad($b->getAngle()))-$a->getDistance()*sin(deg2rad($a->getAngle())));
        $velocity=$length/($out['execute_time']-1000);
        Commands::recall($fleet,$owner,1030);
        $back=FleetCommands::getActiveCommand(1); $bp=json_decode($back['params'],true);
        $seconds=$back['execute_time']-1030;
        $p=Coordinate::fromArray($bp['motionStart']);
        $target=$flight->resolvePhysicalPoint(Coordinate::object(1,1,1,ObjectType::PLANET),1030+$seconds);
        $distance=hypot($p->getDistance()*cos(deg2rad($p->getAngle()))-$target->radius*cos(deg2rad($target->angle)),
            $p->getDistance()*sin(deg2rad($p->getAngle()))-$target->radius*sin(deg2rad($target->angle)));
        researchAssert(abs($distance-$velocity*$seconds)<0.00001,'Прибытие не соответствует движению базы.');
        researchAssert(abs($bp['fuelCost']-$op['fuelCost']*$seconds/($out['execute_time']-1000))<1e-8,'Расход не соответствует сохранённой скорости.');
    }
});

researchCase('Перехват: раннее пересечение до разворота быстро вращающейся базы не пропускается', static function (): void {
    defendFixture();
    Planets::update(['id'=>101,'speed'=>rad2deg(0.01)*3600,'deg'=>360-rad2deg(0.09)]);
    $method=new ReflectionMethod(Flight::class,'constantSpeedArrival');
    $seconds=$method->invoke(new Flight(),new SystemCoordinate(1,1,1050,0),Coordinate::object(1,1,1,ObjectType::PLANET),1000.0,5.0);
    researchAssert($seconds>10 && $seconds<15,'Выбран поздний перехват вместо раннего: '.$seconds);
});

researchCase('200 воспроизводимых орбит: уравнение скорости и отсутствие более раннего пересечения', static function (): void {
    defendFixture(); mt_srand(1092026);
    $method=new ReflectionMethod(Flight::class,'constantSpeedArrival'); $flight=new Flight();
    for($case=0;$case<200;++$case) {
        $radius=mt_rand(100,2200); $angle=mt_rand(0,359999)/1000; $velocity=mt_rand(10,500)/10;
        $planetSpeed=mt_rand(0,9000); $rotation=mt_rand(0,1); $degrees=mt_rand(0,359999)/1000;
        Planets::update(['id'=>101,'speed'=>$planetSpeed,'rotation'=>$rotation,'deg'=>$degrees]);
        $seconds=$method->invoke($flight,new SystemCoordinate(1,1,$radius,$angle),Coordinate::object(1,1,1,ObjectType::PLANET),1000.0,$velocity);
        $x=$radius*cos(deg2rad($angle)); $y=$radius*sin(deg2rad($angle));
        $omega=deg2rad($planetSpeed/3600)*($rotation===0?-1:1); $phase=deg2rad($degrees);
        $f=static fn(float $t): float=>hypot($x-1000*cos($phase+$omega*$t),$y-1000*sin($phase+$omega*$t))-$velocity*$t;
        researchAssert(is_finite($seconds) && $seconds>=0 && abs($f($seconds))<0.0001,'Ошибка перехвата #'.$case.': '.json_encode(compact('seconds','radius','angle','velocity','planetSpeed','rotation','degrees')));
        for($i=1;$i<1024;++$i) researchAssert($f($seconds*$i/1024)>-0.0001,'Пропущен ранний перехват #'.$case);
    }
});

researchCase('Планета → луна/обломки: условный локальный участок не получает повторные 1000 и минимум нового MOVE', static function (): void {
    foreach([ObjectType::MOON,ObjectType::DEBRIS] as $type) foreach([0,120] as $orbitSpeed) {
        $owner=defendFixture(); Planets::update(['id'=>101,'speed'=>$orbitSpeed]);
        $fleet=FleetsData::getInstance()->get(1);
        $fleet->setStatus(Fleet::STATUS_MISSION); $fleet->setMission(Mission::TRANSPORT);
        $fleet->setFuelTotal(100); $fleet->setFuelCurrent(100);
        Commands::startFleetCommands($fleet,[['type'=>Command::MOVE_IN_SYSTEM,'params'=>[
            'target'=>Coordinate::object(1,1,1,$type),'speedFactor'=>10,'returnLeg'=>false]]],1000,$owner);
        Commands::recall($fleet,$owner,1002.5);
        $back=FleetCommands::getActiveCommand(1);
        researchAssert(abs($back['execute_time']-1005)<1e-7,'Локальный возврат не равен пройденным 2.5 сек.');
        Commands::completeCommand($back,$fleet,$owner);
        researchAssert($fleet->getStatus()===Fleet::STATUS_IDLE && $fleet->getCurrentCoordinate()->getObjectType()===ObjectType::PLANET,'Локальный отзыв не завершился у базы.');
    }
});

researchCase('Отзыв из системы цели: первый обратный участок идёт к ближайшей границе с сохранённой скоростью', static function (): void {
    $owner=defendFixture();
    Galaxy::add(Galaxy::castRowToSchema(['id'=>2,'galaxy'=>1,'system'=>2,'star_type'=>'G','max_distance'=>3500,'max_orbits'=>3],true));
    foreach([1=>[GalaxyOrbits::PLANET,1000],2=>[GalaxyOrbits::PLANET,2000],3=>[GalaxyOrbits::PLANET,3000],4=>[GalaxyOrbits::SYSTEM_EDGE,3500]] as $orbit=>[$type,$distance]) {
        GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>10+$orbit,'galaxy'=>1,'system'=>2,'orbit'=>$orbit,'type'=>$type,'distance'=>$distance],true));
    }
    Planets::update(['id'=>102,'system'=>2]);
    $edge=\SPGame\Game\Services\GalaxyGenerator::getSpecialOrbit(1,2,GalaxyOrbits::SYSTEM_EDGE);
    $fleet=FleetsData::getInstance()->get(1); $fleet->setStatus(Fleet::STATUS_MISSION); $fleet->setMission(Mission::DEFEND);
    $fleet->setFuelTotal(100000); $fleet->setFuelCurrent(100000);
    $fleet->setCurrentCoordinate(Coordinate::object(1,2,(int)$edge['orbit'],null,angle:90),1000);
    Commands::startFleetCommands($fleet,[['type'=>Command::MOVE_IN_SYSTEM,'params'=>[
        'target'=>Coordinate::object(1,2,2,ObjectType::PLANET),'speedFactor'=>10,'returnLeg'=>false]]],1000,$owner);
    Commands::recall($fleet,$owner,1005);
    $back=FleetCommands::getActiveCommand(1); $params=json_decode($back['params'],true);
    researchAssert($back['execute_time']-1005<=5.00001,'За маленький обратный участок повторно начислена полная длительность.');
    researchAssert($params['resolvedTarget']['orbit']===4 && $params['resolvedTarget']['system']===2,'Возврат перескочил через фактическую границу.');
});

researchCase('Кинематика возврата сохраняется в runtime и не пересчитывается повторно после холодной загрузки', static function (): void {
    $owner=defendFixture(); $fleet=timingStart($owner,10); Commands::recall($fleet,$owner,1015);
    $before=FleetCommands::getActiveCommand(1); $fuel=$fleet->getFuelCurrent();
    (new ReflectionProperty(FleetsData::class,'instance'))->setValue(null,null);
    $cold=new AccountData(1); $restored=FleetsData::getInstance()->get(1);
    researchAssert(FleetCommands::getActiveCommand(1)===$before && $restored->getFuelCurrent()===$fuel,'После загрузки пересчитаны время/топливо.');
    Commands::completeCommand($before,$restored,$cold);
    researchAssert($restored->getStatus()===Fleet::STATUS_IDLE && $restored->getHomePlanetId()===101,'Возврат не завершился штатным pipeline.');
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=".Database::$calls."\n";
exit($failed>0 || Database::$calls>0 ? 1 : 0);
