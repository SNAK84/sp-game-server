<?php

declare(strict_types=1);

define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\Message;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{Fleets, FleetCommands, PlanetResources, Vars};
use SPGame\Game\Services\{FleetControlService, FleetMissionService};
use SPGame\Game\Space\{Coordinate, ObjectType};

researchCase('preview: свои и чужие цели используют обычные правила миссий без мутаций', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    $before = [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
    $service = new FleetMissionService();
    $own = $service->getAvailableMissions($fleet, FleetTarget::fromCoordinates(Coordinate::object(1, 1, 1, ObjectType::PLANET)->toArray()), $owner);
    $foreign = $service->getAvailableMissions($fleet, FleetTarget::fromCoordinates(Coordinate::object(1, 1, 2, ObjectType::PLANET)->toArray()), $owner);
    researchAssert(in_array(10, $own['missions'], true) && in_array(11, $own['missions'], true) && !in_array(1, $own['missions'], true), 'Неверные миссии на своей планете.');
    researchAssert(in_array(1, $foreign['missions'], true) && !in_array(11, $foreign['missions'], true), 'Неверные миссии на чужой планете.');
    researchAssert($before === [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()], 'Preview изменил runtime.');
});

researchCase('preview: первый этап начинается в конце активного MOVE', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    nativePlanStart($owner, $fleet, [nativeMission(10, 2), ['kind' => 'return']]);
    $origin = FleetControlService::planOrigin($fleet);
    researchAssert($origin->getOrbit() === 2 && $fleet->getCurrentCoordinate()->getOrbit() === 1, 'Preview использует начало текущего участка.');
});

researchCase('preview API: экспедиция учитывает предыдущую цель, а не исходную систему флота', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    Vars::$resource[124] = 'expedition_tech'; $owner['Techs']['expedition_tech'] = 3;
    Vars::$reslist['nametype']['fleet'] = [];
    Vars::$attributes[202]['cost'] = [901 => 2000, 902 => 2000, 903 => 0];
    $before = [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
    foreach ([null, Coordinate::object(1, 2, 1, ObjectType::PLANET)->toArray()] as $origin) {
        $page = new FleetsPage(new Message(['mode' => 'fleets', 'action' => 'GetAvailableMissions', 'data' => [
            'fleetId' => $fleet->getId(), 'forPlan' => true, 'origin' => $origin,
            'coordinates' => Coordinate::object(1, 1, 5)->toArray(),
        ]]));
        $data = $page->render($owner);
        researchAssert(in_array(40, $data['AvailableMissions']['missions'], true) === ($origin === null), 'Не учтена система предыдущей цели: ' . json_encode($data['AvailableMissions']));
        researchAssert(($data['TravelInfo'] !== null) === ($origin === null), 'Расчёт должен учитывать доступность и исходную систему этапа.');
    }
    researchAssert($before === [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()], 'Preview API изменил runtime.');
});

researchCase('preview топлива: один участок, отдельный возврат, скорость и нулевая длина', static function (): void {
    [$owner, $fleet] = nativePlanFixture();
    Vars::$reslist['nametype']['fleet'] = [];
    Vars::$attributes[202]['cost'] = [901 => 2000, 902 => 2000, 903 => 0];
    $before = [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
    $read = static function (array $data) use ($owner): array {
        $page = new FleetsPage(new Message(['mode' => 'fleets', 'action' => 'GetAvailableMissions',
            'data' => $data + ['fleetId' => 1, 'forPlan' => true]]));
        return $page->render($owner)['TravelInfo']['fuel'];
    };
    $home = Coordinate::object(1, 1, 1, ObjectType::PLANET)->toArray();
    $target = Coordinate::object(1, 1, 2, ObjectType::PLANET)->toArray();
    $zero = $read(['coordinates' => $home]);
    researchAssert($zero === ['min' => 0.0, 'max' => 0.0], 'Списание за действие без перелёта.');
    $out = $read(['coordinates' => $target, 'speedFactor' => 10]);
    $back = $read(['coordinates' => [], 'origin' => $target, 'planReturn' => true, 'speedFactor' => 10]);
    $round = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target), $owner, 10, \SPGame\Game\Enums\FleetMissionType::TRANSPORT);
    foreach (['min', 'max'] as $bound) {
        researchAssert($out[$bound] + $back[$bound] === $round['fuel'][$bound], 'Возврат пропущен или учтён дважды.');
    }
    $slow = $read(['coordinates' => $target, 'speedFactor' => 1]);
    researchAssert($slow['max'] < $out['max'], 'Скорость не влияет на прогноз расхода.');
    $point = $read(['coordinates' => ['type' => 2, 'galaxy' => 1, 'system' => 1, 'distance' => 1000, 'angle' => 0],
        'origin' => ['type' => 2, 'galaxy' => 1, 'system' => 1, 'distance' => 1000, 'angle' => 0]]);
    researchAssert($point['max'] === 0.0, 'Фиктивный перелёт из точки в ту же точку.');
    researchAssert($before === [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()], 'Расчёт изменил runtime.');
});

restore_error_handler();
echo "RESULT plan availability: $passed passed / $failed failed; SQL calls=" . \SPGame\Core\Database::$calls . "\n";
exit($failed === 0 ? 0 : 1);
