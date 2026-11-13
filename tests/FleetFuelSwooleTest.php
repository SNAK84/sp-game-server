<?php

declare(strict_types=1);

// Полный runtime pipeline в отдельных Swoole Tables; SQL запрещён guard-ом fixture.
define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{FleetCommands, Galaxy, GalaxyOrbits, Planets, Vars};
use SPGame\Game\Services\{FleetCommandsService, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};

researchCase('DEFEND: полный межсистемный возврат на максимуме топлива при разных углах входа', static function (): void {
    foreach ([[0,0], [0,180], [180,0], [180,180], [120.1,191.189], [359.999,90]] as [$outAngle,$returnAngle]) {
        $owner = defendFixture();
        Planets::update(['id'=>101,'distance'=>10048]);
        Planets::update(['id'=>102,'system'=>2,'distance'=>2160]);
        researchRepository(Galaxy::class, [
            ['id'=>1,'galaxy'=>1,'system'=>1,'star_type'=>'G','min_distance'=>100,'max_distance'=>21892,'max_orbits'=>3],
            ['id'=>2,'galaxy'=>1,'system'=>2,'star_type'=>'G','min_distance'=>100,'max_distance'=>6849,'max_orbits'=>2],
        ]);
        researchRepository(GalaxyOrbits::class, [
            ['id'=>1,'galaxy'=>1,'system'=>1,'orbit'=>1,'type'=>GalaxyOrbits::PLANET,'distance'=>10048],
            ['id'=>2,'galaxy'=>1,'system'=>1,'orbit'=>2,'type'=>GalaxyOrbits::PLANET,'distance'=>11000],
            ['id'=>3,'galaxy'=>1,'system'=>1,'orbit'=>3,'type'=>GalaxyOrbits::PLANET,'distance'=>12000],
            ['id'=>4,'galaxy'=>1,'system'=>1,'orbit'=>4,'type'=>GalaxyOrbits::SYSTEM_EDGE,'distance'=>21892],
            ['id'=>5,'galaxy'=>1,'system'=>1,'orbit'=>5,'type'=>GalaxyOrbits::EXPEDITION,'distance'=>30000],
            ['id'=>6,'galaxy'=>1,'system'=>2,'orbit'=>2,'type'=>GalaxyOrbits::PLANET,'distance'=>2160],
            ['id'=>7,'galaxy'=>1,'system'=>2,'orbit'=>3,'type'=>GalaxyOrbits::SYSTEM_EDGE,'distance'=>6849],
            ['id'=>8,'galaxy'=>1,'system'=>2,'orbit'=>4,'type'=>GalaxyOrbits::EXPEDITION,'distance'=>9000],
        ]);
        Vars::$attributes[202] = array_replace(Vars::$attributes[202], [
            'speed'=>30000.0,'consumption'=>75.0,'consumption2'=>75.0,
        ]);
        $fleet = FleetsData::getInstance()->get(1);
        $fleet->setShipCount(202,199); $fleet->save();
        $target = Coordinate::object(1,2,2,ObjectType::PLANET);
        $preview = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()),$owner,10,FleetMissionType::DEFEND);
        $maximum = ceil($preview['fuel']['max']);
        FleetCommandsService::startMission($fleet,$owner,FleetMissionType::DEFEND->value,$target->toArray(),10,$maximum,[],1000,3600);
        $spent = 0.0;
        $transitions = 0;
        for ($step=0; $step<10 && ($active=FleetCommands::getActiveCommand(1))!==null; ++$step) {
            $params = json_decode($active['params'],true,512,JSON_THROW_ON_ERROR);
            if ((int)$active['command_type'] === FleetCommandType::MOVE_BETWEEN_SYSTEMS->value) {
                // Задаём результат случайного выбора, сохраняя фактический формат command snapshot.
                $params['transitionObject']['angle'] = $transitions++ === 0 ? $outAngle : $returnAngle;
                FleetCommands::update(['id'=>$active['id'],'params'=>json_encode($params,JSON_THROW_ON_ERROR)]);
                $active = FleetCommands::getCommand((int)$active['id']);
            }
            $spent += (float)($params['fuelCost'] ?? 0);
            researchAssert(FleetCommandsService::completeCommand($active,$fleet,$owner),'Не завершена активная команда.');
        }
        researchAssert($transitions===2 && $fleet->getStatus()===Fleet::STATUS_IDLE,'Не завершён полный межсистемный возврат.');
        researchAssert($spent<=$maximum,'Загрузка максимума не покрыла фактический маршрут.');
        researchAssert($fleet->getFuelCurrent()===0.0 && FleetCommands::getFleetQueue(1)===[],
            'Не выгружен остаток топлива или не очищена завершённая очередь.');
        researchAssert($fleet->getCurrentCoordinate()->toArray()===Coordinate::object(1,1,1,ObjectType::PLANET)->toArray(),
            'Флот не вернулся на собственную планету.');
    }
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
