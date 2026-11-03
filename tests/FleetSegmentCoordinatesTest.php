<?php

declare(strict_types=1);

// Изолированные runtime tables из transport fixture, без игровых SQL/команд.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Message;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Services\FleetCommandsService;
use SPGame\Game\Services\FleetControlService;
use SPGame\Game\Services\FleetFlightService;
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

function segmentCoordinatesFixture(Coordinate $from, Coordinate $to, array $extra = []): array
{
    $fixture = installTransportFixture();
    $fleet = $fixture['fleet'];
    // Исходная миссия намеренно отличается от текущего участка маршрута.
    $fleet->setStartCoordinate(Coordinate::object(1, 10, 7, ObjectType::PLANET));
    $fleet->setTargetCoordinate(Coordinate::object(1, 10, 8, ObjectType::PLANET));
    $fleet->setCurrentCoordinate($from, 100);
    $fleet->setStatus(Fleet::STATUS_MISSION);
    $fleet->setReturn(!empty($extra['returnLeg']));
    $fleet->save();
    $flight = new FleetFlightService();
    $params = $flight->captureCommandMotion($from, $to, [
        'target' => $to->toArray(), 'startedAt' => 100, 'fuelCost' => 1,
    ], 100, 200);
    $params = array_replace($params, $extra);
    $command = FleetControlService::add(1, FleetCommandType::MOVE_IN_SYSTEM, $params);
    if (!isset($params['pause'])) {
        FleetCommandsService::activateCommand((int)$command['id'], 200, $params);
    }
    return $fixture + compact('flight', 'params');
}

function segmentCoordinatesEvent(array $fixture): array
{
    $before = [Fleets::getAll(), FleetCommands::getAll()];
    $method = new ReflectionMethod(FleetsPage::class, 'buildFlyingFleetEvent');
    $event = $method->invoke(new FleetsPage(new Message()), $fixture['fleet'], $fixture['account'],
        $fixture['flight'], false);
    transportAssert(is_array($event), 'Проекция текущего участка должна существовать.');
    transportAssert($before === [Fleets::getAll(), FleetCommands::getAll()], 'Проекция должна оставаться read-only.');
    return $event;
}

$planet = Coordinate::object(1, 10, 7, ObjectType::PLANET);
$otherPlanet = Coordinate::object(1, 10, 8, ObjectType::PLANET);
$moon = Coordinate::object(1, 10, 7, ObjectType::MOON);
$debris = Coordinate::object(1, 10, 8, ObjectType::DEBRIS);
$point = Coordinate::point(1, 10, 1200, 45);
$cases = [
    'planet-to-planet' => [$planet, $otherPlanet, []],
    'return' => [$otherPlanet, $planet, ['returnLeg' => true]],
    'planet-to-moon' => [$planet, $moon, []],
    'planet-to-debris' => [$planet, $debris, []],
    'next-stop-not-mission-endpoints' => [$otherPlanet, $moon, []],
    'resume-from-real-point' => [$point, $otherPlanet, []],
    'real-point-target' => [$otherPlanet, $point, []],
    'fuel-stop-before-object' => [$planet, $otherPlanet, ['fuelStop' => true, 'motionEnd' => $point->toArray()]],
    'paused-at-real-point' => [$point, $otherPlanet, ['pause' => 'fuel']],
];

foreach ($cases as $name => [$from, $to, $extra]) {
    $fixture = segmentCoordinatesFixture($from, $to, $extra);
    $event = segmentCoordinatesEvent($fixture);
    $expectedEnd = isset($extra['pause']) ? $from : (!empty($extra['fuelStop']) ? $point : $to);
    transportAssert(($event['segment_origin'] ?? null) === $from->toArray(), "$name: подпись начала потеряла identity.");
    transportAssert(($event['segment_target'] ?? null) === $expectedEnd->toArray(), "$name: подпись конца не соответствует участку.");
    if (!isset($extra['pause'])) {
        transportAssert($event['segment_start'] === $fixture['params']['motionStart']
            && $event['segment_end'] === $fixture['params']['motionEnd'], "$name: физическая траектория изменилась.");
        transportAssert($event['segment_start']['type'] === CoordinateType::POINT->value
            && $event['segment_end']['type'] === CoordinateType::POINT->value, "$name: canvas должен получать POINT.");
    }
    echo "OK segment-coordinates/$name\n";
}
