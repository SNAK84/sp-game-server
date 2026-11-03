<?php

declare(strict_types=1);
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\Message;
use SPGame\Game\Enums\FleetMissionType as PointMission;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{FleetCommands, Fleets, Galaxy, GalaxyOrbits, PlanetResources};
use SPGame\Game\Services\{FleetCommandsService as PointCommands, FleetControlService as PointControl,
    FleetMissionService as PointService};
use SPGame\Game\Space\Coordinate;

function pointRequest(float $distance): array
{
    return ['type'=>2, 'galaxy'=>1, 'system'=>1, 'distance'=>$distance, 'angle'=>45];
}
function pointRuntime(): array
{
    return [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
}
function pointRejected(callable $call): void
{
    try { $call(); } catch (InvalidArgumentException|LogicException) { return; }
    throw new RuntimeException('Недопустимая команда принята.');
}

researchCase('POINT: минимум системы и максимум границы одинаковы в navigation и preview', static function (): void {
    [$owner,$fleet]=nativePlanFixture();
    $page=new FleetsPage(new Message(['mode'=>'fleets','action'=>'GetSystemNavigationInfo',
        'data'=>['fleetId'=>1,'galaxy'=>1,'system'=>1]]));
    $info=(new ReflectionMethod($page,'buildNavigationSystemInfo'))->invoke($page,$owner);
    $bounds=PointService::pointDistanceBounds(1,1);
    researchAssert($info['valid'] && $info['minDistance']===$bounds['min'] && $info['maxDistance']===$bounds['max'], 'API даёт другие границы.');
    researchAssert($bounds['min']===100.0 && $bounds['max']>3500, 'Взята не внутренняя граница или не служебная орбита.');
    foreach([$bounds['min'],$bounds['max']]as$distance){
        $result=(new PointService())->getAvailableMissions($fleet,FleetTarget::fromCoordinates(pointRequest($distance)),$owner);
        researchAssert(!$result['disabled'] && in_array(30,$result['missions'],true),'Граница должна включаться в допустимый диапазон.');
    }
    $before=pointRuntime();
    foreach([$bounds['min']-.001,$bounds['max']+.001,-1,INF,NAN]as$distance){
        $result=(new PointService())->getAvailableMissions($fleet,FleetTarget::fromCoordinates(pointRequest($distance)),$owner);
        researchAssert($result['disabled'] && $result['missions']===[],'Preview разрешил недопустимое расстояние.');
        pointRejected(static fn()=>PointCommands::prepareMissionRequest($fleet,$owner,30,pointRequest($distance),10,1000,[]));
    }
    researchAssert(pointRuntime()===$before,'Отклонение изменило runtime.');
});

researchCase('POINT: разные системы, повреждённая граница и повторная серверная проверка', static function (): void {
    [$owner,$fleet]=nativePlanFixture();
    Galaxy::add(Galaxy::castRowToSchema(['id'=>2,'galaxy'=>1,'system'=>2,'min_distance'=>700,'max_distance'=>5000,'max_orbits'=>3],true));
    GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>20,'galaxy'=>1,'system'=>2,'orbit'=>4,'type'=>GalaxyOrbits::SYSTEM_EDGE,'distance'=>5600],true));
    researchAssert(PointService::pointDistanceBounds(1,2)===['min'=>700.0,'max'=>5600.0],'Границы взяты от предыдущей системы.');
    GalaxyOrbits::update(['id'=>20,'type'=>GalaxyOrbits::EXPEDITION]);
    researchAssert(PointService::pointDistanceBounds(1,2)===null && PointService::pointDistanceBounds(1,99)===null,'Нет настоящей границы — нельзя разрешать точку.');
    $target=pointRequest(500); PointCommands::prepareMissionRequest($fleet,$owner,30,$target,10,1000,[]);
    Galaxy::update(['id'=>1,'min_distance'=>600]);
    $before=pointRuntime();
    pointRejected(static fn()=>PointCommands::startMission($fleet,$owner,30,$target,10,1000,[],1000));
    researchAssert(pointRuntime()===$before,'Commit доверился устаревшему preview.');
    researchAssert(Coordinate::point(1,1,0,0)->getDistance()===0.0,'Нельзя ограничивать внутренние физические точки этим игровым диапазоном.');
});

researchCase('POINT: редактор маршрута не обходит границы через move или mission', static function (): void {
    [$owner,$fleet]=nativePlanFixture();$bounds=PointService::pointDistanceBounds(1,1);
    $before=pointRuntime();
    foreach([$bounds['min']-1,$bounds['max']+1]as$distance)foreach(['move','mission']as$kind){
        $steps=[['kind'=>$kind,'mission'=>30,'coordinates'=>pointRequest($distance)],['kind'=>'hold']];
        pointRejected(static fn()=>PointControl::savePlan($fleet,$owner,
            ['version'=>PointControl::state($fleet)['version'],'fuel'=>1000,'steps'=>$steps],1000));
    }
    researchAssert(pointRuntime()===$before,'Недопустимый этап частично применился.');
});

researchCase('OBSERVE: большой запас загружается один раз и расходуется в удержании', static function (): void {
    [$owner,$fleet]=nativePlanFixture();$target=pointRequest(1500);
    $travel=$fleet->getTravelInfo(FleetTarget::fromCoordinates($target),$owner,10,PointMission::OBSERVE);
    $fuel=ceil($travel['fuel']['max'])+500;
    $prepared=PointCommands::prepareMissionRequest($fleet,$owner,30,$target,10,$fuel,[]);
    researchAssert($prepared['fuel']===$fuel,'Запас ограничен только перелётом.');
    $before=(float)$owner['Resources'][903]['count'];
    PointCommands::startMission($fleet,$owner,30,$target,10,$fuel,[],1000);
    researchAssert(abs($before-(float)$owner['Resources'][903]['count']-$fuel)<.000001,'Запас списан не один раз.');
    $move=FleetCommands::getActiveCommand(1);
    PointCommands::completeCommand($move,$fleet,$owner);
    $state=PointControl::state($fleet);$tank=$fleet->getFuelCurrent();
    researchAssert($state['holding'] && $tank>0,'Запас не дошёл до удержания.');
    PointControl::settleHold($fleet,$fleet->getCurrentPositionTime()+60);
    researchAssert(abs($fleet->getFuelCurrent()-($tank-$state['holdRate']*60))<.000001,'Неверный расход запаса удержания.');
});

researchCase('OBSERVE: вместимость/дейтерий ограничивают запас, конечные миссии сохраняют максимум', static function (): void {
    [$owner,$fleet]=nativePlanFixture();$target=pointRequest(1500);$before=pointRuntime();
    pointRejected(static fn()=>PointCommands::prepareMissionRequest($fleet,$owner,30,$target,10,$fleet->getCapacity()+1,[]));
    pointRejected(static fn()=>PointCommands::prepareMissionRequest($fleet,$owner,30,$target,10,(float)$owner['Resources'][903]['count']+1,[]));
    $finite=['type'=>1,'galaxy'=>1,'system'=>1,'orbit'=>2,'objectType'=>1];
    $travel=$fleet->getTravelInfo(FleetTarget::fromCoordinates($finite),$owner,10,PointMission::TRANSPORT);
    pointRejected(static fn()=>PointCommands::prepareMissionRequest($fleet,$owner,10,$finite,10,ceil($travel['fuel']['max'])+1,[901=>1]));
    researchAssert(pointRuntime()===$before,'Отклонённый запас изменил runtime.');
});

echo "RESULT POINT/hold: $passed passed / $failed failed; SQL calls=".\SPGame\Core\Database::$calls."\n";
restore_error_handler();
exit($failed>0||\SPGame\Core\Database::$calls>0?1:0);
