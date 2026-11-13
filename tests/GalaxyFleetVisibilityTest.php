<?php

declare(strict_types=1);

// Настоящие DTO FleetsPage, отдельные fake Tables, без игровых SQL/команд.
$export = in_array('--export', $argv ?? [], true);
if ($export) ob_start();
require __DIR__ . '/FleetSegmentCoordinatesTest.php';

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Pages\GalaxyPage;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Services\FleetControlService;

$filter = new ReflectionMethod(GalaxyPage::class, 'fleetIsVisibleInSystem');
$samples = [];
foreach ($cases as $name => [$from, $to, $extra]) {
    $event = segmentCoordinatesEvent(segmentCoordinatesFixture($from, $to, $extra));
    transportAssert($filter->invoke(null, $event, 1, 10), "$name: флот пропал в своей системе.");
    transportAssert(!$filter->invoke(null, $event, 1, 9) && !$filter->invoke(null, $event, 2, 10),
        "$name: флот показан в другой системе/галактике.");
    $samples[$name] = $event;
    echo "OK galaxy-fleet/$name\n";
}

$fixture = segmentCoordinatesFixture($point, $otherPlanet, ['pause' => 'target']);
FleetControlService::patchCommand((int)FleetCommands::getNextPendingCommand(1)['id'],
    ['command_type' => FleetCommandType::UNLOAD->value]);
$event = segmentCoordinatesEvent($fixture);
transportAssert($filter->invoke(null, $event, 1, 10), 'Дозаправщик на pause=target исчез из системы.');
$samples['paused-unload'] = $event;
echo "OK galaxy-fleet/paused-unload\n";

foreach (['paused-planet' => $planet, 'paused-moon' => $moon] as $name => $coordinate) {
    $event = segmentCoordinatesEvent(segmentCoordinatesFixture($coordinate, $otherPlanet, ['pause' => 'fuel']));
    transportAssert($filter->invoke(null, $event, 1, 10), "$name: пауза у объекта не видна.");
    transportAssert($event['segment_start'] === $event['segment_end'] && $event['segment_start']['type'] === 1,
        "$name: потерян орбитальный anchor паузы.");
    $event['mission_target']['system'] = 8;
    transportAssert($filter->invoke(null, $event, 1, 10), "$name: фильтр использует цель вместо текущей позиции.");
    $samples[$name] = $event;
    echo "OK galaxy-fleet/$name\n";
}

foreach ([FleetCommandType::OBSERVE, FleetCommandType::DEFEND, FleetCommandType::EXPEDITION] as $type) {
    $fixture = segmentCoordinatesFixture($point, $point);
    $command = FleetCommands::getActiveCommand(1);
    $params = ['target' => $point->toArray(), 'hold' => ['startedAt' => 100, 'lastAt' => 100, 'rate' => 1],
        'expedition' => ['startedAt' => 100, 'endsAt' => 200]];
    FleetControlService::patchCommand((int)$command['id'], ['command_type' => $type->value,
        'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    $event = segmentCoordinatesEvent($fixture);
    transportAssert($filter->invoke(null, $event, 1, 10) === ($type !== FleetCommandType::EXPEDITION),
        "$type->name: неверная видимость стоящего флота.");
    if ($type === FleetCommandType::EXPEDITION) {
        $paused = $event; $paused['blocked_reason'] = 'Экспедиционный бой остановлен';
        transportAssert(!$filter->invoke(null, $paused, 1, 10), 'Пауза внутри EXPEDITION раскрыла флот.');
    }
    $samples[strtolower($type->name)] = $event;
    echo "OK galaxy-fleet/$type->name\n";
}

$event = $samples['planet-to-planet'];
foreach ([FleetCommandType::MOVE_BETWEEN_SYSTEMS, FleetCommandType::MOVE_BETWEEN_GALAXIES] as $type) {
    $transition = $event;
    $transition['command'] = ['type' => $type->value, 'key' => $type->name];
    transportAssert(!$filter->invoke(null, $transition, 1, 10), "$type->name: переход остался на карте.");
    echo "OK galaxy-fleet/exclude-$type->name\n";
}
$based = $samples['paused-at-real-point']; $based['status'] = 0;
transportAssert(!$filter->invoke(null, $based, 1, 10), 'Базирующийся флот показан на карте.');
$missing = $event; unset($missing['segment_start']);
transportAssert(!$filter->invoke(null, $missing, 1, 10), 'Принят флот без позиции.');
$wrongSystem = $event; $wrongSystem['segment_end']['system'] = 11;
transportAssert(!$filter->invoke(null, $wrongSystem, 1, 10), 'Адресный переход ошибочно отображается как локальный.');
echo "OK galaxy-fleet/exclude-based-missing-and-wrong-system\n";

if ($export) {
    ob_end_clean();
    echo json_encode($samples, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} else echo 'RESULT galaxy fleet visibility: ' . count($samples) . " DTO scenarios + exclusion checks passed\n";
