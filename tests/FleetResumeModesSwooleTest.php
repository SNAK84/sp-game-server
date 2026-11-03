<?php

declare(strict_types=1);

define('SP_GAME_RESUME_FIXTURE_ONLY', true);
require __DIR__ . '/FleetResumeCargoSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{FleetCommands, Galaxy, GalaxyOrbits, Planets, PlayerQueue, Vars};
use SPGame\Game\Services\{EventLoop, FleetCommandsService as Commands, FleetControlService as Control,
    FleetFlightService as Flight, FleetMissionBuilder, FleetMissionService, PageActionService};
use SPGame\Game\Space\{Coordinate, ObjectType};

function resumeFiniteFixture(float $cargoFactor = 100): array
{
    [$owner, $fleet, $cost] = resumeCargoFixture(.25, $cargoFactor);
    $home = Coordinate::object(1, 1, 1, ObjectType::PLANET);
    $next = FleetCommands::getFleetQueue(1)[1];
    Control::patchCommand((int)$next['id'], ['command_type' => Command::MOVE_IN_SYSTEM->value,
        'params' => json_encode(['target' => $home->toArray(), 'speedFactor' => 10, 'returnLeg' => true], JSON_THROW_ON_ERROR)]);
    Control::add(1, Command::UNLOAD, ['target' => $home->toArray(), 'unloadFuel' => true]);
    Control::add(1, Command::STAY, ['at' => $home->toArray()]);
    $fleet->setStartCoordinate($home);
    $fleet->save();
    return [$owner, $fleet, $cost];
}

function resumeProgressAt(\SPGame\Game\Fleet\Fleet $fleet, float $time): float
{
    $state = Control::routeProgress($fleet);
    $active = $state['active'];
    $fraction = $active !== null && $active['ends_at'] > $active['started_at']
        ? max(0, min(1, ($time - $active['started_at']) / ($active['ends_at'] - $active['started_at']))) : 0;
    return $state['total'] > 0 ? ($state['completed'] + ($active['span'] ?? 0) * $fraction) / $state['total'] : 0;
}

foreach (['available', 'segment', 'route'] as $mode) {
    researchCase('Ручная остановка → ' . $mode . ': продолжение и непрерывный прогресс всей миссии', static function () use ($mode): void {
        [$owner, $fleet, $cost] = resumeFiniteFixture();
        $fleet->setFuelTotal($cost * 2);
        $fleet->setFuelCurrent($cost * 2);
        $fleet->save();
        Control::resume($fleet, $owner, 1000);
        $active = FleetCommands::getActiveCommand(1);
        $time = 1000 + ((float)$active['execute_time'] - 1000) * .25;
        $progress = resumeProgressAt($fleet, $time);
        $total = Control::routeProgress($fleet)['total'];
        Control::stop($fleet, $owner, $time);
        researchAssert(Control::state($fleet)['holding'] && abs(resumeProgressAt($fleet, $time + 50) - $progress) < 1e-9,
            'Прогресс движется во время ручной остановки.');
        $fleet->setFuelCurrent($cost * .2);
        $fleet->save();
        Control::resume($fleet, $owner, $time + .1, fuelMode: $mode);
        researchAssert((int)FleetCommands::getActiveCommand(1)['command_type'] === Command::MOVE_IN_SYSTEM->value,
            'Осталось OBSERVE вместо продолжения перелёта.');
        researchAssert(abs(resumeProgressAt($fleet, $time + .1) - $progress) < 1e-9
            && abs(Control::routeProgress($fleet)['total'] - $total) < 1e-9,
            'Возобновление сбрасывает/перемасштабирует шкалу.');
        researchAssert(resumeProgressAt($fleet, (float)FleetCommands::getActiveCommand(1)['execute_time']) > $progress,
            'После продолжения прогресс не движется.');
    });
}

researchCase('Старая остановка без metadata: прогресс восстанавливается read-only и сохраняется при продолжении', static function (): void {
    [$owner, $fleet, $cost] = resumeFiniteFixture();
    $fleet->setFuelTotal($cost * 2); $fleet->setFuelCurrent($cost * 2); $fleet->save();
    Control::resume($fleet, $owner, 1000);
    $active = FleetCommands::getActiveCommand(1);
    $time = 1000 + ((float)$active['execute_time'] - 1000) * .25;
    $expected = resumeProgressAt($fleet, $time);
    Control::stop($fleet, $owner, $time);
    foreach (FleetCommands::getFleetCommandChain(1) as $command) {
        $p = Control::params($command);
        foreach (['progressCompleted', 'progressWeight', 'progressDone', 'progressSpan'] as $key) unset($p[$key]);
        Control::patchCommand((int)$command['id'], ['params' => json_encode($p, JSON_THROW_ON_ERROR)]);
    }
    $before = resumeCargoSnapshot($fleet);
    researchAssert(abs(resumeProgressAt($fleet, $time + 50) - $expected) < 1e-9,
        'Старый остановленный флот получает другой прогресс.');
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Рендер прогресса мутирует runtime.');
    Control::resume($fleet, $owner, $time + .1, fuelMode: 'segment');
    researchAssert(abs(resumeProgressAt($fleet, $time + .1) - $expected) < 1e-9,
        'Нормализация старой остановки изменила прогресс.');
});

researchCase('Остановка по топливу и повторный старт сохраняют достигнутую долю маршрута', static function (): void {
    [$owner, $fleet] = resumeFiniteFixture();
    Control::resume($fleet, $owner, 1000, fuelMode: 'available');
    $active = FleetCommands::getActiveCommand(1);
    $time = (float)$active['execute_time'];
    $progress = resumeProgressAt($fleet, $time);
    Commands::completeCommand($active, $fleet, $owner);
    $fleet->reloadFromRuntime();
    researchAssert(abs(resumeProgressAt($fleet, $time + 1000) - $progress) < 1e-9,
        'Пассивная остановка/перезагрузка теряет прогресс.');
    Control::resume($fleet, $owner, $time + 1, fuelMode: 'segment');
    researchAssert(abs(resumeProgressAt($fleet, $time + 1) - $progress) < 1e-9,
        'Повторный старт изменил уже пройденную часть.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert(abs(resumeProgressAt($fleet, $time + 10000) - .5) < 1e-9,
        'Шкала не включает обратный перелёт.');
});

researchCase('available: доля времени/пути, весь бак, неизменный груз и исходная pending-цель', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture();
    $before = resumeCargoSnapshot($fleet);
    $fullTime = (new Flight())->calculateCommandDuration(Command::MOVE_IN_SYSTEM, $fleet->getCurrentCoordinate(),
        Coordinate::point(1, 1, 2100, 60), 1000, $fleet->getTotalSpeed($owner), 10);
    Control::resume($fleet, $owner, 1000, fuelMode: 'available');
    $active = FleetCommands::getActiveCommand(1);
    $params = Control::params($active);
    researchAssert(!empty($params['fuelStop']) && abs((float)$active['execute_time'] - 1000 - $fullTime * .25) < 1e-6,
        'Частичный MOVE меняет скорость или длительность.');
    researchAssert(abs($params['fuelCost'] - $cost * .25) < 1e-6 && $fleet->getFuelCurrent() === 0.0,
        'Неверное списание доступного бака.');
    researchAssert($fleet->getResources()[903]->getCount() === $before[4], 'available использует груз.');
    $expectedX = 1200 + .25 * (2100 * cos(deg2rad(60)) - 1200);
    $expectedY = .25 * 2100 * sin(deg2rad(60));
    $fleet->reloadFromRuntime();
    $position = (new Flight())->calculateCurrentCoordinate($fleet, $active, (float)$active['execute_time']);
    $physical = (new Flight())->resolvePhysicalPoint($position, (float)$active['execute_time']);
    researchAssert(hypot($physical->radius * cos(deg2rad($physical->angle)) - $expectedX,
        $physical->radius * sin(deg2rad($physical->angle)) - $expectedY) < 1e-6, 'Не та точка исчерпания топлива.');
    Commands::completeCommand($active, $fleet, $owner);
    $pending = FleetCommands::getNextPendingCommand(1);
    researchAssert(FleetCommands::getActiveCommand(1) === null && Control::state($fleet)['reason'] === 'fuel',
        'Исчерпание не остановило флот.');
    researchAssert((int)$pending['id'] === (int)$active['id'] && !(int)$pending['executed']
        && Control::params($pending)['target'] === Control::params($before[1][0])['target'],
        'Исходная цель пропущена или преждевременно выполнена.');
    researchAssert($fleet->getCurrentCoordinate()->toArray() === $position->toArray(), 'Остановка телепортирует флот.');
    Control::resume($fleet, $owner, (float)$active['execute_time'] + 1, fuelMode: 'segment');
    researchAssert(empty(Control::params(FleetCommands::getActiveCommand(1))['fuelStop']), 'Новая заправка наследует старую точку остановки.');
});

researchCase('available: пройден полный участок, затем частичный без автоматической дозаправки', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture(1.5);
    $next = FleetCommands::getFleetQueue(1)[1];
    Control::patchCommand((int)$next['id'], ['command_type' => Command::MOVE_IN_SYSTEM->value,
        'params' => json_encode(['target' => Coordinate::point(1, 1, 2600, 150)->toArray(), 'speedFactor' => 10], JSON_THROW_ON_ERROR)]);
    $cargo = $fleet->getResources()[903]->getCount();
    Control::resume($fleet, $owner, 1000, fuelMode: 'available');
    researchAssert(empty(Control::params(FleetCommands::getActiveCommand(1))['fuelStop']), 'Первый участок ошибочно ограничен.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $active = FleetCommands::getActiveCommand(1);
    researchAssert($active !== null && !empty(Control::params($active)['fuelStop']), 'Следующий участок не использует оставшийся бак.');
    researchAssert(abs(Control::params($active)['fuelCost'] - $cost * .5) < 1e-6
        && $fleet->getResources()[903]->getCount() === $cargo, 'Двойной расход или заправка из груза.');
});

researchCase('available: без топлива отказ и отсутствие runtime mutations', static function (): void {
    [$owner, $fleet] = resumeCargoFixture(0);
    $before = resumeCargoSnapshot($fleet);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000, fuelMode: 'available'));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Отказ изменил флот.');
});

researchCase('available: snapshot показывает остановку и не обещает дальнейшую выгрузку/конец миссии', static function (): void {
    [$owner, $fleet] = resumeFiniteFixture();
    Control::resume($fleet, $owner, 1000, fuelMode: 'available');
    $page = new \SPGame\Game\Pages\FleetsPage(new Message());
    $event = (new ReflectionMethod($page, 'buildFlyingFleetEvent'))->invoke($page, $fleet, $owner, new Flight(), true);
    researchAssert($event !== null && $event['fuel_stop'] && $event['mission_end_time'] === null
        && $event['route_leg']['direction'] === 'fuel_stop' && $event['segment_end']['type'] === 2,
        'Snapshot выдаёт время остановки за прибытие к цели.');
    foreach ($event['commands'] as $command) if ($command['status'] === 'pending') {
        researchAssert($command['estimated_time'] === null, 'Будущее действие имеет фиктивный срок выполнения.');
    }
    researchAssert($event['route_leg']['arrives_at'] === $event['end_time']
        && $event['route_leg']['segments'][0]['fuel_cost'] > 0, 'Таймер или интерполяция топлива не используют участок до остановки.');
});

researchCase('segment: только ближайшая команда, не весь маршрут', static function (): void {
    [$owner, $fleet, $cost] = resumeFiniteFixture();
    $cargo = $fleet->getResources()[903]->getCount();
    Control::resume($fleet, $owner, 1000, fuelMode: 'segment');
    researchAssert(abs($fleet->getResources()[903]->getCount() - $cargo + .75 * $cost) < 1e-6, 'Загружен лишний резерв.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert(Control::state($fleet)['reason'] === 'fuel', 'Следующий участок заправлен автоматически.');
});

researchCase('route: резерв всего остатка, штатный возврат и выгрузка остатка топлива', static function (): void {
    [$owner, $fleet, $cost] = resumeFiniteFixture();
    $reserve = Control::remainingRouteFuel($fleet, $owner);
    researchAssert($reserve > $cost, 'Возврат не входит в резерв.');
    $cargo = $fleet->getResources()[903]->getCount();
    Control::resume($fleet, $owner, 1000, fuelMode: 'route');
    researchAssert(abs($fleet->getResources()[903]->getCount() - $cargo + $reserve - .25 * $cost) < 1e-6,
        'В бак перемещён не расчётный дефицит маршрута.');
    $spent = 0;
    for ($i = 0; $i < 10 && ($active = FleetCommands::getActiveCommand(1)) !== null; $i++) {
        $spent += (float)(Control::params($active)['fuelCost'] ?? 0);
        Commands::completeCommand($active, $fleet, $owner);
    }
    researchAssert($spent <= $reserve && $fleet->getStatus() === 0 && FleetCommands::getFleetQueue(1) === [],
        'Маршрут не обеспечен топливом или не завершён.');
    researchAssert($fleet->getFuelCurrent() === 0.0 && $fleet->getResources()[903]->getCount() === 0.0,
        'Дейтерий не выгружен на базе.');
});

researchCase('route: нехватка на весь маршрут не списывает частичный запас', static function (): void {
    [$owner, $fleet] = resumeFiniteFixture(.1);
    $before = resumeCargoSnapshot($fleet);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000, fuelMode: 'route'));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Не восстановлен исходный груз/бак/маршрут.');
});

researchCase('route: ставшее недоступным действие не оставляет дейтерий частично перемещённым в бак', static function (): void {
    [$owner, $fleet] = resumeFiniteFixture();
    $command = FleetCommands::getNextPendingCommand(1);
    $p = Control::params($command);
    $p['planStep'] = ['kind' => 'mission', 'mission' => \SPGame\Game\Enums\FleetMissionType::ATTACK->value,
        'coordinates' => Coordinate::object(1, 1, 1, ObjectType::PLANET)->toArray(), 'speedFactor' => 10];
    Control::patchCommand((int)$command['id'], ['params' => json_encode($p, JSON_THROW_ON_ERROR)]);
    $before = resumeCargoSnapshot($fleet);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000, fuelMode: 'route'));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Ошибка доступности сохранила частичную заправку.');
});

researchCase('route: бессрочное будущее удержание отклонено явно', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $before = resumeCargoSnapshot($fleet);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000, fuelMode: 'route'));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Бесконечный расход изменил state.');
});

researchCase('route: межсистемный транспорт, промежуточная выгрузка и возврат без повторного учёта MOVE', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    Vars::$attributes[202]['missions'][] = \SPGame\Game\Enums\FleetMissionType::TRANSPORT->value;
    Planets::update(['id' => 102, 'system' => 2]);
    Galaxy::add(Galaxy::castRowToSchema(['id' => 2, 'galaxy' => 1, 'system' => 2, 'max_orbits' => 3,
        'min_distance' => 1000, 'max_distance' => 4500], true));
    foreach ([[8, 2, GalaxyOrbits::PLANET, 2000], [9, 4, GalaxyOrbits::SYSTEM_EDGE, 4500], [10, 5, GalaxyOrbits::EXPEDITION, 5000]] as [$id, $orbit, $type, $radius]) {
        GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id' => $id, 'galaxy' => 1, 'system' => 2,
            'orbit' => $orbit, 'distance' => $radius, 'type' => $type], true));
    }
    $home = Coordinate::object(1, 1, 1, ObjectType::PLANET);
    $target = Coordinate::object(1, 2, 2, ObjectType::PLANET);
    $fleet->setStartCoordinate($home);
    $fleet->setTargetCoordinate($target);
    $fleet->getResources()[903]->setCount(100000);
    $fleet->save();
    FleetCommands::clearFleetQueue(1);
    $commands = FleetMissionBuilder::buildForwardPath($fleet->getCurrentCoordinate(), $target);
    $commands[] = ['type' => Command::UNLOAD, 'params' => ['target' => $target->toArray(), 'percent' => 50]];
    $commands = array_merge($commands, FleetMissionBuilder::buildReturn($target, $home));
    foreach ($commands as $index => $command) {
        $params = $command['params'];
        foreach (['target', 'at'] as $key) if (($params[$key] ?? null) instanceof Coordinate) $params[$key] = $params[$key]->toArray();
        $params['speedFactor'] = 10;
        if ($index === 0) $params['pause'] = 'fuel';
        Control::add(1, $command['type'], $params);
    }
    $mission = new FleetMissionService();
    $there = $mission->getTravelInfo($fleet, FleetTarget::fromCoordinates($target->toArray()), $owner, 10, origin: $fleet->getCurrentCoordinate());
    $back = $mission->getTravelInfo($fleet, FleetTarget::fromCoordinates($home->toArray()), $owner, 10, origin: $target);
    $reserve = Control::remainingRouteFuel($fleet, $owner);
    researchAssert($reserve === ceil($there['fuel']['max'] + $back['fuel']['max']),
        'Расчёт дважды учитывает переходы/входы в системы.');
    Control::resume($fleet, $owner, 1000, fuelMode: 'route');
    $cargo = $fleet->getResources()[903]->getCount();
    $spent = 0;
    $transitions = 0;
    for ($i = 0; $i < 16 && ($active = FleetCommands::getActiveCommand(1)) !== null; $i++) {
        $params = Control::params($active);
        if ((int)$active['command_type'] === Command::MOVE_BETWEEN_SYSTEMS->value) {
            $params['transitionObject']['angle'] = $transitions++ === 0 ? 180 : 0;
            Control::patchCommand((int)$active['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
            $active = FleetCommands::getCommand((int)$active['id']);
        }
        $spent += (float)($params['fuelCost'] ?? 0);
        Commands::completeCommand($active, $fleet, $owner);
        if ($i === 2) researchAssert(abs($fleet->getResources()[903]->getCount() - $cargo / 2) < 1e-6
            && $fleet->getFuelCurrent() > 0, 'Промежуточная выгрузка затронула резерв топлива.');
    }
    researchAssert($transitions === 2 && $fleet->getStatus() === 0 && $spent <= $reserve,
        'Максимальный резерв не обеспечил межсистемный возврат.');
});

foreach (['available', 'route'] as $mode) {
    researchCase($mode . ': failure после начала commit откатывает бак, груз, режим и команды', static function () use ($mode): void {
        [$owner, $fleet] = resumeFiniteFixture();
        $before = resumeCargoSnapshot($fleet);
        $small = new \Swoole\Table(128);
        foreach ((new ReflectionProperty(FleetCommands::class, 'tableSchema'))->getValue()['columns'] as $name => $column) {
            $small->column($name, $column['swoole'][0], $name === 'params' ? 192 : ($column['swoole'][1] ?? 8));
        }
        $small->create();
        foreach (FleetCommands::getAll() as $row) $small->set((string)$row['id'], $row);
        $property = new ReflectionProperty(FleetCommands::class, 'table');
        $original = $property->getValue();
        $property->setValue(null, $small);
        try {
            try {
                Control::resume($fleet, $owner, 1000, fuelMode: $mode);
                throw new RuntimeException('Failure injection не сработала.');
            } catch (JsonException) {
                researchAssert(resumeCargoSnapshot($fleet) === $before, 'Rollback оставил частичный state.');
            }
        } finally {
            $property->setValue(null, $original);
        }
        Control::resume($fleet, $owner, 1000, fuelMode: $mode);
        researchAssert(FleetCommands::getActiveCommand(1) !== null, 'Retry после rollback невозможен.');
    });
}

researchCase('available: адресный переход не может завершиться в несуществующей промежуточной системе', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    Galaxy::add(Galaxy::castRowToSchema(['id' => 2, 'galaxy' => 1, 'system' => 2, 'max_orbits' => 3,
        'min_distance' => 1000, 'max_distance' => 4500], true));
    GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id' => 8, 'galaxy' => 1, 'system' => 2, 'orbit' => 4,
        'distance' => 4500, 'type' => GalaxyOrbits::SYSTEM_EDGE], true));
    $fleet->setCurrentCoordinate(Coordinate::object(1, 1, 4, angle: 0), 1000);
    $fleet->save();
    $first = FleetCommands::getNextPendingCommand(1);
    Control::patchCommand((int)$first['id'], ['command_type' => Command::MOVE_BETWEEN_SYSTEMS->value,
        'params' => json_encode(['target' => Coordinate::point(1, 2, 2000, 30)->toArray(),
            'speedFactor' => 10, 'pause' => 'fuel'], JSON_THROW_ON_ERROR)]);
    $position = $fleet->getCurrentCoordinate()->toArray();
    $cargo = $fleet->getResources()[903]->getCount();
    Control::resume($fleet, $owner, 1000, fuelMode: 'available');
    researchAssert(FleetCommands::getActiveCommand(1) === null && Control::state($fleet)['reason'] === 'fuel'
        && $fleet->getCurrentCoordinate()->toArray() === $position && $fleet->getResources()[903]->getCount() === $cargo,
        'Частичный прыжок изменил адрес/груз.');
});

researchCase('available: локальный PLANET → MOON не подменяется бесплатным прибытием в общий anchor', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $home = Coordinate::object(1, 1, 1, ObjectType::PLANET);
    $moon = Coordinate::object(1, 1, 1, ObjectType::MOON);
    Planets::add(Planets::castRowToSchema(['id' => 104, 'owner_id' => 1, 'galaxy' => 1,
        'system' => 1, 'planet' => 1, 'planet_type' => ObjectType::MOON->value, 'distance' => 1000], true));
    $fleet->setCurrentCoordinate($home, 1000);
    $cost = (new Flight())->calculateFuelConsumption($fleet, $owner, [5], 10);
    $fleet->setFuelCurrent($cost * .25); $fleet->save();
    $pending = FleetCommands::getNextPendingCommand(1);
    Control::patchCommand((int)$pending['id'], ['params' => json_encode(['target' => $moon->toArray(),
        'speedFactor' => 10, 'pause' => 'fuel'], JSON_THROW_ON_ERROR)]);
    Control::resume($fleet, $owner, 1000, fuelMode: 'available');
    researchAssert(FleetCommands::getActiveCommand(1) === null && Control::state($fleet)['reason'] === 'fuel'
        && $fleet->getCurrentCoordinate()->toArray() === $home->toArray(), 'Недоплаченный локальный переход изменил объект.');
});

foreach (['available', 'segment', 'route'] as $mode) {
    researchCase('ACK → PlayerQueue → EventLoop: fuelMode=' . $mode, static function () use ($mode): void {
        [$owner, $fleet] = resumeFiniteFixture();
        $before = resumeCargoSnapshot($fleet);
        $message = new Message(['mode' => 'fleets', 'action' => 'ResumeFleet', 'requestId' => 'resume-' . $mode,
            'data' => ['fleetId' => 1, 'fuelMode' => $mode]]);
        $response = (new Message())->setRequestId('resume-' . $mode);
        PageActionService::handle($message, $owner, 1, $response);
        researchAssert($response->source()['status'] === 'ok' && $response->source()['requestId'] === 'resume-' . $mode,
            'Не получен обычный correlated ACK.');
        researchAssert(resumeCargoSnapshot($fleet) === $before, 'ACK мутирует игровой state.');
        $event = PlayerQueue::getByAccount(1)[0];
        researchAssert($event['data']['fuelMode'] === $mode, 'Режим не передан в PlayerQueue.');
        (new ReflectionMethod(EventLoop::class, 'processPlayerEvent'))->invoke(new EventLoop(), $event, 1);
        researchAssert(FleetCommands::getActiveCommand(1) !== null && (PlayerQueue::getByAccount(1) ?? []) === [],
            'EventLoop не применил режим.');
        researchAssert(!empty(Control::params(FleetCommands::getActiveCommand(1))['fuelStop']) === ($mode === 'available'),
            'Активирован не выбранный режим.');
    });
}

researchCase('Некорректные режимы отклонены до ACK и PlayerQueue', static function (): void {
    [$owner, $fleet] = resumeFiniteFixture();
    $before = resumeCargoSnapshot($fleet);
    foreach ([null, '', false, 1, [], 'unknown'] as $mode) {
        $message = new Message(['mode' => 'fleets', 'action' => 'ResumeFleet',
            'data' => ['fleetId' => 1, 'fuelMode' => $mode]]);
        $response = new Message();
        PageActionService::handle($message, $owner, 1, $response);
        researchAssert($response->source()['status'] === 'error' && (PlayerQueue::getByAccount(1) ?? []) === [],
            'Некорректный режим попал в PlayerQueue.');
        researchAssert(resumeCargoSnapshot($fleet) === $before, 'Ошибка валидации изменила state.');
    }
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
