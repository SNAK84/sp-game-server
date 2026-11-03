<?php

declare(strict_types=1);

define('SP_GAME_SENSOR_FIXTURE_ONLY', true);
require __DIR__ . '/FleetSensorSwooleTest.php';

use SPGame\Game\Enums\FleetCommandType as InterceptCommand;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Builds, Config, FleetCommands, Fleets, FleetsShips, PhalanxScans, Planets, PlanetResources, SensorContacts, Vars};
use SPGame\Game\Services\{AccountData, FleetControlService as InterceptControl, FleetFlightService, FleetInterceptionService as Intercept,
    FleetRendezvousService, FleetSensorService, FleetsData, PhalanxService};
use SPGame\Game\Space\{Coordinate, CoordinateType};

function interceptionFixture(bool $moving = false): array
{
    [$owner, $source] = sensorFixture();
    PhalanxScans::init();
    PhalanxScans::restore(1, false);
    PhalanxScans::restore(2, false);
    if ($moving) {
        $command = FleetCommands::getActiveCommand(2);
        InterceptControl::patchCommand((int)$command['id'], ['command_type' => 10, 'execute_time' => 4000.0,
            'params' => json_encode(['target' => ['type' => 2, 'galaxy' => 1, 'system' => 1, 'distance' => 3000, 'angle' => 90],
                'motionStart' => ['type' => 2, 'galaxy' => 1, 'system' => 1, 'distance' => 2000, 'angle' => 20],
                'motionEnd' => ['type' => 2, 'galaxy' => 1, 'system' => 1, 'distance' => 3000, 'angle' => 90]])]);
    }
    FleetSensorService::setMode($source, $owner, true, 1000);
    FleetSensorService::tick(1010);
    return [$owner, $source];
}

function interceptionPhalanxFixture(): AccountData
{
    [$owner] = nativePlanFixture();
    SensorContacts::put(1, []);
    PhalanxScans::init();
    PhalanxScans::restore(1, false);
    Vars::$resource[42] = 'phalanx';
    Vars::$reslist['build'][] = 42;
    $builds = Builds::getAll();
    foreach ($builds as &$row) $row['phalanx'] = $row['id'] === 101 ? 1 : 0;
    unset($row);
    researchRepository(Builds::class, $builds, array_map(static fn($id) => Vars::$resource[$id], Vars::$reslist['build']));
    Planets::update(['id' => 101, 'planet_type' => 3]);
    $enemy = new AccountData(2);
    $fleet = FleetsData::getInstance()->get(2);
    InterceptControl::savePlan($fleet, $enemy, ['version' => InterceptControl::state($fleet)['version'],
        'fuel' => 1000, 'resources' => [901 => 100], 'steps' => [nativeMission(10, 3), ['kind' => 'return']]], 1000);
    return new AccountData(1);
}

function interceptionUnchanged(array $before): void
{
    foreach ($before as $class => $rows) researchAssert($class::getAll() === $rows, 'Prepare изменил ' . $class);
    researchAssert(\SPGame\Core\Database::$calls === 0, 'Prepare обратился к SQL.');
}

researchCase('intercept: только свежий чужой контакт; ID не открывает обычную доставку', static function (): void {
    interceptionFixture();
    $observed = Intercept::observation(1, 2, 1010);
    researchAssert($observed['source'] === 'sensor' && $observed['id'] === 2 && $observed['observedAt'] === 1010.0, 'Не принят наблюдаемый флот.');
    foreach ([[0, 2], [2, 2], [3, 2], [1, 99999], [1, 1]] as [$viewer, $target]) {
        defendRejected(static fn() => Intercept::observation($viewer, $target, 1010));
    }
    defendRejected(static fn() => FleetRendezvousService::target(1, 2));
    $target = array_values(array_filter(FleetRendezvousService::targets(1, 1010), static fn($c) => $c['id'] === 2))[0];
    researchAssert($target['selectable'] === true && $target['interceptable'] === true && $target['delivery'] === false,
        'Контакт должен разрешать перехват, но не доставку.');
});

researchCase('intercept: прогноз курса без знания тайного конца, полный участок только для фаланги', static function (): void {
    interceptionFixture(true);
    $observation = Intercept::observation(1, 2, 1010);
    $fleet = new Fleet(2);
    foreach ([1010, 1200, 2200, 3999] as $time) {
        $expected = FleetRendezvousService::position($fleet, $time);
        $actual = Intercept::positionAt($observation, $time);
        researchAssert(FleetRendezvousService::distance($expected, $actual, $time) < .000001, 'Прогноз не совпал с реальным MOVE.');
    }
    researchAssert($observation['motion']['kind'] === 'course' && !isset($observation['motion']['end'], $observation['motion']['until'])
        && $observation['validUntil'] === null, 'Сенсор узнал тайный конец участка.');
    $last = Intercept::positionAt($observation, 7000);
    researchAssert($last->getDistance() <= $observation['motion']['radiusLimit'] + .000001
        && FleetRendezvousService::distance($last, FleetRendezvousService::position($fleet, 4000), 4000) > 1,
        'Курс незаметно остановился у тайной цели или вышел за систему: ' . json_encode([$observation['motion'], $last->toArray(), FleetRendezvousService::position($fleet, 4000)->toArray()]));
    $bounded = Intercept::captureObserved($fleet, 1010, true);
    researchAssert(Intercept::positionAt($bounded, 7000)->toArray() === Intercept::positionAt($bounded, 4000)->toArray(),
        'Прогноз фаланги прошёл за известный ей участок.');
});

researchCase('intercept: одинаковая наблюдаемая скорость даёт один прогноз при разных скрытых целях', static function (): void {
    interceptionFixture(true);
    $first = Intercept::captureObserved(new Fleet(2), 1000);
    $command = FleetCommands::getActiveCommand(2);
    $params = InterceptControl::params($command);
    // Вдвое ближе и вдвое меньше оставшееся время: тот же вектор скорости.
    $x = (2000 * cos(deg2rad(20))) / 2;
    $y = (2000 * sin(deg2rad(20)) + 3000) / 2;
    $params['target'] = $params['motionEnd'] = Coordinate::point(1, 1, hypot($x, $y), rad2deg(atan2($y, $x)))->toArray();
    InterceptControl::patchCommand((int)$command['id'], ['params' => json_encode($params), 'execute_time' => 2500.0]);
    $second = Intercept::captureObserved(new Fleet(2), 1000);
    foreach ([1100, 2600, 5000, 100000] as $time) {
        researchAssert(FleetRendezvousService::distance(Intercept::positionAt($first, $time), Intercept::positionAt($second, $time), $time) < .000001,
            'Прогноз мобильного контакта раскрыл тайный конец команды.');
    }
    researchAssert($first['validUntil'] === null && $second['validUntil'] === null, 'В наблюдение попало скрытое время окончания.');
});

researchCase('intercept: встреча с летящей и остановленной целью — prepare без runtime мутаций', static function (): void {
    foreach ([false, true] as $moving) {
        interceptionFixture($moving);
        $observation = Intercept::observation(1, 2, 1010);
        $before = [];
        foreach ([Fleets::class, FleetsShips::class, FleetCommands::class, PlanetResources::class] as $class) $before[$class] = $class::getAll();
        $markers = [Fleets::getPendingSync(), FleetsShips::getPendingSync(), FleetCommands::getPendingSync(), PlanetResources::getPendingSync()];
        $start = Coordinate::point(1, 1, 1000, 10);
        $meeting = Intercept::meeting($observation, $start, 1010, 50000, 10);
        $point = $meeting['target']->getLastKnownPosition();
        researchAssert($meeting['target']->getType() === CoordinateType::FLEET && $meeting['target']->getFleetId() === 2, 'Неверная identity встречи.');
        researchAssert(FleetRendezvousService::distance($point, Intercept::positionAt($observation, $meeting['arrivesAt']), $meeting['arrivesAt']) < .000001,
            'Цель встречи не находится в прогнозной точке.');
        $required = (new FleetFlightService())->calculateCommandDuration(InterceptCommand::MOVE_IN_SYSTEM, $start, $point, 1010, 50000, 10);
        researchAssert($required <= $meeting['duration'] && $meeting['duration'] - $required <= 1, 'Встреча назначена раньше физически возможного.');
        interceptionUnchanged($before);
        researchAssert([Fleets::getPendingSync(), FleetsShips::getPendingSync(), FleetCommands::getPendingSync(), PlanetResources::getPendingSync()] === $markers,
            'Prepare изменил dirty/delete markers.');
    }
});

researchCase('intercept: потеря источника/TTL не меняет рассчитанную встречу и не открывает новую', static function (): void {
    [$owner, $source] = interceptionFixture(true);
    $observation = Intercept::observation(1, 2, 1010);
    $start = Coordinate::point(1, 1, 1000, 10);
    $before = Intercept::meeting($observation, $start, 1010, 50000, 10);
    FleetSensorService::setMode($source, $owner, false, 1011);
    defendRejected(static fn() => Intercept::observation(1, 2, 1011));
    FleetSensorService::setMode($source, $owner, true, 1012);
    defendRejected(static fn() => Intercept::observation(1, 2, 1012));
    researchAssert(Intercept::meeting($observation, $start, 1010, 50000, 10)['target']->toArray() === $before['target']->toArray(),
        'Потеря контакта подменила уже рассчитанную точку.');
    FleetSensorService::tick(1020);
    researchAssert(Intercept::observation(1, 2, 1020)['observedAt'] === 1020.0, 'Повторный скан не открыл контакт.');
    defendRejected(static fn() => Intercept::observation(1, 2, 1050));
    researchAssert(!FleetSensorService::contacts(1, 1050)[2]['fresh'], 'Последняя отметка выдана как свежая.');
});

researchCase('intercept: смена курса внутри той же команды не превращается в скрытое сопровождение', static function (): void {
    interceptionFixture(true);
    $old = Intercept::observation(1, 2, 1010);
    $oldPoint = Intercept::positionAt($old, 1500)->toArray();
    $command = FleetCommands::getActiveCommand(2);
    $params = InterceptControl::params($command);
    $params['motionEnd']['angle'] = 270;
    InterceptControl::patchCommand((int)$command['id'], ['params' => json_encode($params)]);
    researchAssert(!Intercept::matchesCurrent($old), 'Смена motionEnd не обнаружена.');
    defendRejected(static fn() => Intercept::observation(1, 2, 1011));
    researchAssert(Intercept::positionAt($old, 1500)->toArray() === $oldPoint, 'Старый прогноз прочитал новый курс.');
    FleetSensorService::tick(1020);
    $new = Intercept::observation(1, 2, 1020);
    researchAssert($new['observedAt'] === 1020.0 && Intercept::positionAt($new, 1500)->toArray() !== $oldPoint, 'Реальный новый скан не уточнил курс.');
});

researchCase('intercept: переходы, EXPEDITION, базирование, исчезновение — запрет без удаления snapshot', static function (): void {
    foreach ([11, 12, 40, 'base', 'delete'] as $phase) {
        interceptionFixture(true);
        $old = Intercept::observation(1, 2, 1010);
        $point = Intercept::positionAt($old, 1500)->toArray();
        if (is_int($phase)) InterceptControl::patchCommand((int)FleetCommands::getActiveCommand(2)['id'], ['command_type' => $phase]);
        elseif ($phase === 'base') Fleets::update(['id' => 2, 'status' => 0]);
        else Fleets::delete(['id' => 2]);
        defendRejected(static fn() => Intercept::observation(1, 2, 1011));
        researchAssert(Intercept::positionAt($old, 1500)->toArray() === $point, 'Скрытая фаза прочитана через чистый прогноз.');
    }
});

researchCase('intercept: временная копия Fleet при проверке контакта не удаляет живые Tables', static function (): void {
    interceptionFixture(true);
    $rows = Fleets::getAll(); $ships = FleetsShips::getAll(); $commands = FleetCommands::getAll();
    for ($i = 0; $i < 25; ++$i) {
        Intercept::observation(1, 2, 1011);
        gc_collect_cycles();
    }
    researchAssert(Fleets::getAll() === $rows && FleetsShips::getAll() === $ships && FleetCommands::getAll() === $commands,
        'Проверка контакта/GC удалила игровой Fleet.');
});

researchCase('intercept: внутренний снимок не выходит в sensor DTO ни на одном tier', static function (): void {
    interceptionFixture(true);
    researchAssert(isset(SensorContacts::get(1)[2]['_observation']['motion']['vx']), 'Не сохранена внутренняя скорость.');
    foreach ([1, 2, 3] as $tier) {
        $contacts = SensorContacts::get(1);
        $contacts[2]['tier'] = $tier;
        SensorContacts::put(1, $contacts);
        $public = FleetSensorService::contacts(1, 1010)[2];
        foreach (['_observation', 'signature', 'motion', 'end', 'until', 'target', 'lastKnownPosition', 'sourceId', 'viewerId'] as $key) {
            researchAssert(!array_key_exists($key, $public), 'Внутренний ключ в DTO: ' . $key);
        }
    }
});

researchCase('intercept: удержание у вращающегося объекта — immutable орбита, не статичная точка', static function (): void {
    foreach ([0, 1] as $rotation) {
        interceptionFixture();
        Fleets::update(['id' => 2, 'current_coordinate_type' => 1, 'current_orbit' => 2, 'current_object_type' => 1]);
        Planets::update(['id' => 102, 'deg' => 20, 'speed' => 360, 'rotation' => $rotation, 'update_time' => 1000]);
        Config::add(['name' => 'SpeedPlanets', 'value' => '24']);
        $snapshot = Intercept::captureObserved(new Fleet(2), 1010, true);
        $future = Intercept::positionAt($snapshot, 1120);
        researchAssert(FleetRendezvousService::distance($future, FleetRendezvousService::position(new Fleet(2), 1120), 1120) < .000001,
            'Орбитальный прогноз не совпал с игровым resolver.');
        Planets::update(['id' => 102, 'deg' => 200, 'speed' => 1]);
        researchAssert(Intercept::positionAt($snapshot, 1120)->toArray() === $future->toArray(), 'Прогноз перечитал планету.');
        researchAssert(Intercept::positionAt($snapshot, 100010)->toArray() === Intercept::positionAt($snapshot, 100000)->toArray(),
            'Прогноз продолжил орбитальное удержание после известного времени его завершения.');
    }
});

researchCase('intercept: future update_time anchor не вращает объект раньше времени', static function (): void {
    interceptionFixture();
    Fleets::update(['id' => 2, 'current_coordinate_type' => 1, 'current_orbit' => 2, 'current_object_type' => 1]);
    Planets::update(['id' => 102, 'deg' => 20, 'speed' => 360, 'rotation' => 1, 'update_time' => 1100]);
    Config::add(['name' => 'SpeedPlanets', 'value' => '24']);
    $snapshot = Intercept::captureObserved(new Fleet(2), 1010);
    foreach ([1050, 1100, 1200] as $time) researchAssert(FleetRendezvousService::distance(Intercept::positionAt($snapshot, $time),
        FleetRendezvousService::position(new Fleet(2), $time), $time) < .000001, 'Нарушен startsAt орбитального снимка.');
});

researchCase('intercept: ручной скан фаланги хранит приватное движение, не раскрывая его в Page/replay', static function (): void {
    $owner = interceptionPhalanxFixture();
    $scan = PhalanxService::scan($owner, 102, 900, 'interception-phalanx', 1000);
    researchAssert(count($scan['fleets']) === 1 && !isset($scan['_observations']), 'scan выдал внутреннюю траекторию.');
    $saved = PhalanxScans::get(1, 1000);
    researchAssert($saved === $scan && !isset($saved['_observations']), 'get изменил публичный контракт.');
    $observation = Intercept::observation(1, 2, 1001);
    researchAssert($observation['source'] === 'phalanx' && $observation['observedAt'] === 1000.0, 'Не принято оплаченное наблюдение фаланги.');
    $before = PlanetResources::getAll();
    researchAssert(PhalanxService::scan($owner, 102, 900, 'interception-phalanx', 1001) === $scan && PlanetResources::getAll() === $before,
        'Replay изменил оплату или публичный ответ.');
    $oldMotion = PhalanxScans::observation(1, 2, 1001);
    researchAssert(Intercept::observation(3, 2, 1001)['source'] === 'incoming', 'Входящая видимость к своей планете потеряна.');
    $command = FleetCommands::getActiveCommand(2);
    InterceptControl::patchCommand((int)$command['id'], ['execute_time' => (float)$command['execute_time'] + 1]);
    researchAssert(PhalanxScans::observation(1, 2, 1001) === $oldMotion, 'Чтение приватного снимка оживило курс.');
    defendRejected(static fn() => Intercept::observation(1, 2, 1001));
    defendRejected(static fn() => Intercept::observation(99, 2, 1001));
    researchAssert(PhalanxScans::get(1, 1300) === null && PhalanxScans::observation(1, 2, 1300) === null, 'Истёкший скан остался разрешением.');
});

researchCase('intercept: legacy снимок без движения не превращается в live-доступ', static function (): void {
    interceptionFixture();
    $contacts = SensorContacts::get(1);
    unset($contacts[2]['_observation']);
    SensorContacts::put(1, $contacts);
    PhalanxScans::put(1, PhalanxScans::pack(['scannedAt' => 1010, 'fleets' => [['id' => 2]]], 1010));
    defendRejected(static fn() => Intercept::observation(1, 2, 1011));
    FleetSensorService::tick(1020);
    researchAssert(Intercept::observation(1, 2, 1020)['source'] === 'sensor', 'Повторное наблюдение не восстановило возможность.');
});

researchCase('intercept: неверное время, скорость, система и повреждённый снимок отклоняются', static function (): void {
    interceptionFixture(true);
    $observation = Intercept::observation(1, 2, 1010);
    $start = Coordinate::point(1, 1, 1000, 0);
    foreach ([0, NAN, INF, 1009] as $time) defendRejected(static fn() => Intercept::positionAt($observation, $time));
    foreach ([0, -1, NAN, INF] as $speed) defendRejected(static fn() => Intercept::meeting($observation, $start, 1010, $speed, 10));
    foreach ([0, -1, 11, NAN, INF] as $factor) defendRejected(static fn() => Intercept::meeting($observation, $start, 1010, 5000, $factor));
    defendRejected(static fn() => Intercept::meeting($observation, Coordinate::point(1, 2, 1000, 0), 1010, 5000, 10));
    $bad = $observation;
    $bad['motion'] = ['kind' => 'linear', 'until' => 1000, 'end' => $observation['position']];
    defendRejected(static fn() => Intercept::positionAt($bad, 1020));
    $bad['motion'] = ['kind' => 'unknown'];
    defendRejected(static fn() => Intercept::positionAt($bad, 1020));
});

researchCase('intercept: одна точка не добавляет фиктивный минимальный перелёт', static function (): void {
    interceptionFixture();
    $observation = Intercept::observation(1, 2, 1010);
    $point = Intercept::positionAt($observation, 1010);
    $meeting = Intercept::meeting($observation, $point, 1010, 5000, 10);
    researchAssert($meeting['duration'] === 0.0 && $meeting['arrivesAt'] === 1010.0, 'Добавлен фиктивный перелёт до той же точки.');
});

researchCase('intercept: скрыта фаза EXPEDITION, а не вся миссия; просроченный MOVE не выдаёт следующий участок', static function (): void {
    interceptionFixture(true);
    Fleets::update(['id' => 2, 'mission' => 40]);
    researchAssert(Intercept::observation(1, 2, 1011)['source'] === 'sensor', 'Перелёт к экспедиции ошибочно скрыт.');
    $command = FleetCommands::getActiveCommand(2);
    $end = (float)$command['execute_time'];
    researchAssert(Intercept::captureObserved(new Fleet(2), $end) === null, 'Истёкший MOVE превратился в следующий участок.');
    InterceptControl::patchCommand((int)$command['id'], ['command_type' => 40]);
    defendRejected(static fn() => Intercept::observation(1, 2, 1011));
});

researchCase('intercept: запись приватной фаланги остаётся в прежних 16 KiB; overflow до оплаты', static function (): void {
    $owner = interceptionPhalanxFixture();
    $scan = PhalanxService::scan($owner, 102, 901, 'private-buffer', 1000);
    $one = PhalanxScans::observation(1, 2, 1001);
    $events = $observations = [];
    for ($id = 1; $id <= 100; ++$id) {
        $events[] = array_replace($scan['fleets'][0], ['id' => $id, 'name' => 'Контрольный флот ' . $id]);
        $observations[$id] = array_replace($one, ['id' => $id, 'signature' => hash('sha256', 'phalanx-' . $id)]);
    }
    $packed = PhalanxScans::pack(array_replace($scan, ['fleets' => $events, '_observations' => $observations]), 1000);
    researchAssert(strlen($packed['payload']) <= PhalanxScans::MAX_BYTES, '100 простых контактов переполнили прежний буфер.');
    $before = PlanetResources::getAll();
    $saved = PhalanxScans::row(1);
    $observations[1]['signature'] = bin2hex(random_bytes(30000));
    defendRejected(static fn() => PhalanxScans::pack(array_replace($scan, ['fleets' => $events, '_observations' => $observations]), 1000));
    researchAssert(PlanetResources::getAll() === $before && PhalanxScans::row(1) === $saved, 'Переполнение изменило оплату/старый снимок.');
    echo 'INTERCEPT_BUFFER ' . json_encode(['phalanxContacts' => 100, 'packedBytes' => strlen($packed['payload']), 'maxBytes' => PhalanxScans::MAX_BYTES]) . "\n";
});

researchCase('intercept: разные направления, скорости и орбитальное движение согласованы с формулой полёта', static function (): void {
    interceptionFixture(true);
    $observed = Intercept::observation(1, 2, 1010);
    $cases = 0;
    foreach ([0, 90, 180, 270] as $angle) foreach ([5000, 50000] as $speed) foreach ([1, 10] as $factor) {
        foreach (['course', 'linear', 'orbit'] as $kind) {
            $model = $observed;
            if ($kind === 'linear') $model = Intercept::captureObserved(new Fleet(2), 1010, true);
            if ($kind === 'orbit') $model['motion'] = ['kind' => 'orbit', 'degreesPerSecond' => .02, 'startsAt' => 1010.0];
            $start = Coordinate::point(1, 1, 1000, $angle);
            $meeting = Intercept::meeting($model, $start, 1010, $speed, $factor);
            $point = $meeting['target']->getLastKnownPosition();
            $expected = Intercept::positionAt($model, $meeting['arrivesAt']);
            $required = (new FleetFlightService())->calculateCommandDuration(InterceptCommand::MOVE_IN_SYSTEM, $start, $point, 1010, $speed, $factor);
            researchAssert($required <= $meeting['duration'] && $meeting['duration'] - $required <= 1
                && FleetRendezvousService::distance($point, $expected, $meeting['arrivesAt']) < .000001, 'Не согласована встреча ' . $kind);
            ++$cases;
        }
    }
    echo 'INTERCEPT_GEOMETRY ' . $cases . " combinations\n";
});

echo "RESULT interception preparation: $passed passed / $failed failed; SQL calls=" . \SPGame\Core\Database::$calls . "\n";
restore_error_handler();
exit($failed > 0 || \SPGame\Core\Database::$calls > 0 ? 1 : 0);
