<?php

declare(strict_types=1);

// Отдельные native Swoole Tables; fixture запрещает SQL и доступ к игровым данным.
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{FleetCommands, Fleets, Galaxy, GalaxyOrbits, PlanetResources, Planets};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetControlService as Control, FleetTransferService as Transfer, FleetFlightService as Flight, FleetsData};
use SPGame\Game\Services\FleetRendezvousService as Rendezvous;
use SPGame\Game\Space\{Coordinate, ObjectType};

function rendezvousFuelFixture(bool $outward = false, int $system = 1): array
{
    [$owner, $source] = nativePlanFixture();
    // На малом количестве кораблей разницу маскирует целочисленное округление.
    $source->setShipCount(202, 1000); $source->save();
    Fleets::update(['id' => 2, 'owner_id' => 1, 'home_planet_id' => 101]);
    $receiver = FleetsData::getInstance()->get(2);
    $receiver->setStatus(Fleet::STATUS_MISSION); $receiver->setMission(Mission::OBSERVE);
    if ($system !== 1) {
        Galaxy::add(array_replace(Galaxy::findExistingById(1), ['id' => 2, 'system' => $system]));
        foreach (GalaxyOrbits::findByIndex('galaxy_system', [1, 1]) as $orbit) {
            GalaxyOrbits::add(array_replace($orbit, ['id' => $orbit['id'] + 10, 'system' => $system]));
        }
    }
    $now = microtime(true);
    $from = Coordinate::point(1, $system, $outward ? 800 : 3000, 0);
    $to = Coordinate::point(1, $system, $outward ? 3000 : 800, 0);
    $receiver->setCurrentCoordinate($from, $now - 100); $receiver->save();
    $command = Control::add(2, Command::MOVE_IN_SYSTEM, ['target' => $to->toArray(),
        'motionStart' => $from->toArray(), 'motionEnd' => $to->toArray(), 'speedFactor' => 10]);
    Control::patchCommand((int)$command['id'], ['execute_time' => $now + 500]);
    return compact('owner', 'source', 'receiver', 'command', 'from', 'to', 'now')
        + ['coordinates' => ['type' => 3, 'fleetId' => 2]];
}

function rendezvousFuelPreview(array $fixture): array
{
    $travel = $fixture['source']->getTravelInfo(FleetTarget::fromCoordinates($fixture['coordinates']),
        $fixture['owner'], 10, Mission::TRANSPORT);
    researchAssert($travel !== null, 'Недоступен preview встречи.');
    return $travel;
}

function rendezvousFuelAdvance(array $fixture, float $elapsed = 500): void
{
    // Те же материализованные начало/конец; виртуально прошло 400 секунд без sleep.
    $fixture['receiver']->setCurrentCoordinate($fixture['from'], $fixture['now'] - $elapsed);
    $fixture['receiver']->save();
    Control::patchCommand((int)$fixture['command']['id'], ['execute_time' => $fixture['now'] + 600 - $elapsed]);
}

function rendezvousFuelSnapshot(): array
{
    return [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
}

function rendezvousObjectFixture(ObjectType $type): array
{
    $f = rendezvousFuelFixture();
    $destination = Coordinate::object(1, 1, 2, $type);
    // Заметное вращение орбиты выявляет «замороженную» точку после прибытия.
    Planets::update(['id' => 102, 'update_time' => $f['now'], 'deg' => 90, 'speed' => 720]);
    $arrival = $f['now'] + 1;
    $params = (new Flight())->captureCommandMotion($f['from'], $destination,
        ['target' => $destination->toArray(), 'startedAt' => $f['now'] - 100, 'fuelCost' => 0],
        $f['now'] - 100, $arrival);
    Control::patchCommand((int)$f['command']['id'], ['execute_time' => $arrival,
        'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    Control::add(2, Command::MOVE_IN_SYSTEM, ['target' => $f['from']->toArray(), 'pause' => 'fuel']);
    return $f + compact('destination', 'arrival');
}

function rendezvousFuelReject(callable $operation, string $text): void
{
    $before = rendezvousFuelSnapshot(); $rejected = false;
    try { $operation(); }
    catch (InvalidArgumentException $error) { $rejected = str_contains($error->getMessage(), $text); }
    researchAssert($rejected, 'Не получен ожидаемый отказ: ' . $text);
    researchAssert($before === rendezvousFuelSnapshot(), 'Отклонённая отправка изменила runtime.');
}

$cases = [];
foreach ([false, true] as $outward) foreach ([1, 2] as $system) {
    $cases[($outward ? 'outward' : 'inward') . '/system-' . $system] = static function () use ($outward, $system): void {
        $f = rendezvousFuelFixture($outward, $system);
        $preview = rendezvousFuelPreview($f);
        rendezvousFuelAdvance($f);
        $later = rendezvousFuelPreview($f);
        $before = rendezvousFuelSnapshot();
        $prepared = Commands::prepareMissionRequest($f['source'], $f['owner'], 10, $f['coordinates'], 10,
            ceil($preview['fuel']['max']), [903 => 25], null, null, null, true);
        researchAssert($preview['fuel']['max'] === $later['fuel']['max'], 'Движение цели изменило верхний резерв.');
        researchAssert($prepared['fuel'] === (float)ceil($preview['fuel']['max']) && $prepared['refuel'],
            'Ранее выбранный максимум не принят для дозаправки.');
        researchAssert($before === rendezvousFuelSnapshot(), 'Prepare изменил runtime.');
        echo 'FUEL preview=' . $preview['fuel']['max'] . '; send=' . $prepared['maximumFuel'] . "\n";
        // Повторная проверка той же команды при извлечении из PlayerQueue.
        rendezvousFuelAdvance($f, 550);
        Commands::prepareMissionRequest($f['source'], $f['owner'], 10, $f['coordinates'], 10,
            $prepared['fuel'], [903 => 25], null, null, null, true);
    };
}

$cases['maximum, minimum, stock and capacity checks stay enforced'] = static function (): void {
    $f = rendezvousFuelFixture(); $travel = rendezvousFuelPreview($f);
    rendezvousFuelReject(static fn() => Commands::prepareMissionRequest($f['source'], $f['owner'], 10,
        $f['coordinates'], 10, ceil($travel['fuel']['max']) + 1, [903 => 25], null, null, null, true), 'максимума топлива');
    rendezvousFuelReject(static fn() => Commands::prepareMissionRequest($f['source'], $f['owner'], 10,
        $f['coordinates'], 10, 0, [903 => 25], null, null, null, true), 'минимальной дальности');
    rendezvousFuelReject(static fn() => Commands::prepareMissionRequest($f['source'], $f['owner'], 10,
        $f['coordinates'], 10, ceil($travel['fuel']['max']), [903 => 60000], null, null, null, true), 'недостаточно дейтерия');
    $f['source']->setShipCount(202, 1); $f['source']->save();
    $travel = rendezvousFuelPreview($f);
    rendezvousFuelReject(static fn() => Commands::prepareMissionRequest($f['source'], $f['owner'], 10,
        $f['coordinates'], 10, ceil($travel['fuel']['max']), [901 => 100000, 902 => 80000], null, null, null, true), 'грузоподъёмность');
};

$cases['ordinary planet maximum remains unchanged'] = static function (): void {
    $f = rendezvousFuelFixture();
    $coordinates = ['type' => 1, 'galaxy' => 1, 'system' => 1, 'orbit' => 2, 'objectType' => 1];
    $preview = $f['source']->getTravelInfo(FleetTarget::fromCoordinates($coordinates), $f['owner'], 10, Mission::TRANSPORT);
    researchAssert($preview['distance']['max'] === 3000, 'Изменён диапазон обычной планеты.');
    rendezvousFuelReject(static fn() => Commands::prepareMissionRequest($f['source'], $f['owner'], 10,
        $coordinates, 10, ceil($preview['fuel']['max']) + 1, [903 => 25]), 'максимума топлива');
};

$cases['moving preview then stopped receiver: refuel delivered once; no automatic resume'] = static function (): void {
    $f = rendezvousFuelFixture(); $preview = rendezvousFuelPreview($f);
    $receiver = $f['receiver']; $source = $f['source']; $owner = $f['owner'];
    $receiver->setCurrentCoordinate(Coordinate::point(1, 1, 800, 180), $f['now']);
    $receiver->setFuelCurrent(0); $receiver->setFuelTotal(0); $receiver->save();
    Control::pause($f['command'], 'fuel');
    researchAssert(rendezvousFuelPreview($f)['fuel']['max'] === $preview['fuel']['max'], 'Остановка цели изменила максимум.');
    $fuel = (float)ceil($preview['fuel']['max']); $before = PlanetResources::findExistingById(101)['deuterium'];
    Commands::startMission($source, $owner, 10, $f['coordinates'], 10, $fuel, [903 => 25], $f['now'], null, null, true);
    researchAssert(abs(PlanetResources::findExistingById(101)['deuterium'] - ($before - $fuel - 25)) < .000001,
        'Дейтерий груза смешался с топливом перевозчика.');
    $move = FleetCommands::getActiveCommand(1);
    Commands::completeCommand($move, $source, $owner);
    researchAssert($receiver->getFuelCurrent() === 25.0 && $source->getResources()[903]->getCount() === 0.0,
        'Не доставлено топливо получателю.');
    researchAssert(Control::state($receiver)['reason'] === 'fuel' && FleetCommands::getActiveCommand(2) === null,
        'Дозаправка самовольно продолжила полёт.');
    $delivery = array_values(array_filter(FleetCommands::getFleetCommandChain(1),
        static fn($row) => (int)$row['command_type'] === Command::UNLOAD->value && (int)$row['executed'] === 1))[0];
    $beforeReplay = rendezvousFuelSnapshot();
    Transfer::deliver($delivery, $source, Control::params($delivery));
    researchAssert($receiver->getFuelCurrent() === 25.0 && $beforeReplay === rendezvousFuelSnapshot(), 'Повторная дозаправка.');
    $fuelReturned = $source->getFuelCurrent();
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $source, $owner);
    researchAssert($source->getStatus() === 0 && abs(PlanetResources::findExistingById(101)['deuterium']
        - ($before - $fuel - 25 + $fuelReturned)) < .000001, 'Неверный баланс после возвращения перевозчика.');
    researchAssert($receiver->getFuelCurrent() === 25.0, 'Возврат перевозчика изменил заправку получателя.');
};

$cases['refuel at an actual intercept does not stop or replace the receiver MOVE'] = static function (): void {
    $f = rendezvousFuelFixture(); $source = $f['source']; $receiver = $f['receiver'];
    $owner = $f['owner'];
    // Получатель будет продолжать этот же участок после физической встречи.
    $params = Control::params($f['command']); $params['fuelCost'] = 10;
    Control::patchCommand((int)$f['command']['id'], ['execute_time' => $f['now'] + 7100,
        'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    $receiver->setFuelTotal(100); $receiver->setFuelCurrent(50); $receiver->save();
    $preview = rendezvousFuelPreview($f);
    Commands::startMission($source, $owner, 10, $f['coordinates'], 10, ceil($preview['fuel']['max']),
        [903 => 25], $f['now'], null, null, true);
    $move = FleetCommands::getActiveCommand(1); $receiverMove = FleetCommands::getActiveCommand(2);
    researchAssert((float)$move['execute_time'] < (float)$receiverMove['execute_time'], 'Встреча должна быть в полёте.');
    $aim = Coordinate::fromArray(Control::params($move)['target'])->getLastKnownPosition();
    researchAssert(Rendezvous::distance($aim, Rendezvous::position($receiver, (float)$move['execute_time']),
        (float)$move['execute_time']) < .05, 'Точка встречи не совпадает с траекторией получателя.');
    Commands::completeCommand($move, $source, $owner);
    researchAssert($receiver->getFuelCurrent() === 75.0 && $source->getResources()[903]->getCount() === 0.0,
        'Дозаправка в полёте не передала грузовой дейтерий в бак получателя.');
    researchAssert(FleetCommands::getActiveCommand(2) === $receiverMove && $receiver->getStatus() === Fleet::STATUS_MISSION,
        'Дозаправка остановила получателя или изменила его текущую команду.');
};

foreach ([ObjectType::PLANET, ObjectType::MOON] as $type) {
    $cases['only based fleets at ' . $type->name . ' are excluded'] = static function () use ($type): void {
        $f = rendezvousObjectFixture($type);
        Commands::completeCommand(FleetCommands::getActiveCommand(2), $f['receiver'], $f['owner']);
        $before = rendezvousFuelSnapshot();
        researchAssert(Rendezvous::target(1, 2, 1) === $f['receiver'], 'Флот на паузе у объекта недоступен.');
        researchAssert(in_array(2, array_column(Rendezvous::targets(1, $f['arrival']), 'id'), true),
            'Небазирующийся флот отсутствует в списке целей.');
        researchAssert(rendezvousFuelSnapshot() === $before, 'Проверка цели изменила runtime.');
        $f['receiver']->setStatus(Fleet::STATUS_IDLE); $f['receiver']->save();
        $rejected = false;
        try { Rendezvous::target(1, 2, 1); } catch (InvalidArgumentException) { $rejected = true; }
        researchAssert($rejected && !in_array(2, array_column(Rendezvous::targets(1, $f['arrival']), 'id'), true),
            'Базирующийся флот стал доступной целью.');
    };

    $cases['refuel after receiver arrival at ' . $type->name . ' follows orbit; no pause=target'] = static function () use ($type): void {
        $f = rendezvousObjectFixture($type);
        $source = $f['source']; $receiver = $f['receiver']; $owner = $f['owner'];
        $preview = rendezvousFuelPreview($f);
        Commands::startMission($source, $owner, 10, $f['coordinates'], 10, ceil($preview['fuel']['max']),
            [903 => 50], $f['now'], null, null, true);
        $move = FleetCommands::getActiveCommand(1);
        $meetingAt = (float)$move['execute_time'];
        researchAssert($meetingAt > $f['arrival'], 'Сценарий должен встречать флот уже у объекта.');
        $physical = (new Flight())->resolvePhysicalPoint($f['destination'], $meetingAt);
        $expected = Coordinate::point($physical->galaxy, $physical->system, $physical->radius, $physical->angle);
        $aim = Coordinate::fromArray(Control::params($move)['target'])->getLastKnownPosition();
        researchAssert(Rendezvous::distance($aim, $expected, $meetingAt) < .05,
            'Прогноз встречи заморозил точку вместо движения по орбите.');
        $beforeFuel = $receiver->getFuelCurrent();
        Commands::completeCommand(FleetCommands::getActiveCommand(2), $receiver, $owner);
        $chainCount = count(FleetCommands::getFleetCommandChain(1));
        Commands::completeCommand($move, $source, $owner);
        researchAssert($receiver->getFuelCurrent() === $beforeFuel + 50.0
            && $source->getResources()[903]->getCount() === 0.0, 'Дейтерий не доставлен в бак.');
        researchAssert(Control::state($receiver)['reason'] === 'fuel' && $source->isReturn(),
            'Заправка сняла паузу получателя либо остановила перевозчика.');
        researchAssert(count(FleetCommands::getFleetCommandChain(1)) === $chainCount,
            'Вместо точной встречи добавлена лишняя погоня.');
        Commands::completeCommand(FleetCommands::getActiveCommand(1), $source, $owner);
        researchAssert($source->getStatus() === Fleet::STATUS_IDLE, 'Перевозчик не вернулся домой.');
    };

    $cases['send to a receiver already paused at ' . $type->name] = static function () use ($type): void {
        $f = rendezvousObjectFixture($type);
        Commands::completeCommand(FleetCommands::getActiveCommand(2), $f['receiver'], $f['owner']);
        $preview = rendezvousFuelPreview($f);
        Commands::startMission($f['source'], $f['owner'], 10, $f['coordinates'], 10,
            ceil($preview['fuel']['max']), [903 => 50], $f['arrival'], null, null, true);
        Commands::completeCommand(FleetCommands::getActiveCommand(1), $f['source'], $f['owner']);
        researchAssert($f['receiver']->getFuelCurrent() === 50.0
            && $f['source']->getResources()[903]->getCount() === 0.0, 'Отправка к флоту у объекта не завершила заправку.');
        researchAssert(Control::state($f['receiver'])['reason'] === 'fuel', 'Получатель самовольно продолжил маршрут.');
    };

    $cases['legacy pause=target resumes a physical rendezvous at ' . $type->name] = static function () use ($type): void {
        $f = rendezvousObjectFixture($type);
        $source = $f['source']; $owner = $f['owner'];
        $preview = rendezvousFuelPreview($f);
        $fuel = ceil($preview['fuel']['max']);
        Commands::startMission($source, $owner, 10, $f['coordinates'], 10, $fuel,
            [903 => 50], $f['now'], null, null, true);
        $move = FleetCommands::getActiveCommand(1);
        $delivery = FleetCommands::getFleetQueue(1)[1];
        // Состояние старого renderer/solver: перевозчик прибыл в прежнюю точку,
        // затем получил pause=target. Повторно грузить планету нельзя.
        $point = (new Flight())->resolvePhysicalPoint($f['destination'], $f['arrival']);
        $oldPosition = Coordinate::point($point->galaxy, $point->system, $point->radius, $point->angle);
        Control::patchCommand((int)$move['id'], ['executed' => 1]);
        $source->setCurrentCoordinate($oldPosition, (float)$move['execute_time']);
        $source->setFuelTotal($fuel * 3); $source->setFuelCurrent($fuel * 2); $source->save();
        Control::pause($delivery, 'target');
        Commands::completeCommand(FleetCommands::getActiveCommand(2), $f['receiver'], $owner);
        $stock = PlanetResources::findExistingById(101)['deuterium'];
        Control::resume($source, $owner, (float)$move['execute_time'] + 100, fuelMode: 'available');
        researchAssert($source->getCurrentCoordinate()->toArray() === $oldPosition->toArray(),
            'Продолжение телепортировало перевозчика вместо нового перелёта.');
        researchAssert(PlanetResources::findExistingById(101)['deuterium'] === $stock
            && $source->getResources()[903]->getCount() === 50.0, 'Продолжение повторно загрузило/передало груз.');
        Commands::completeCommand(FleetCommands::getActiveCommand(1), $source, $owner);
        researchAssert($f['receiver']->getFuelCurrent() === 50.0
            && $source->getResources()[903]->getCount() === 0.0 && $source->isReturn(),
            'Продолжение не доставило топливо и не активировало возврат.');
    };
}

foreach ($cases as $name => $case) { $case(); echo "PASS rendezvous fuel: $name\n"; }
researchAssert(Database::$calls === 0, 'Тест обратился к игровой SQL.');
restore_error_handler();
echo 'RESULT rendezvous fuel: ' . count($cases) . " passed; SQL calls=0\n";
