<?php

declare(strict_types=1);

// Только новые Tables отдельного процесса; fixture запрещает обращения к SQL.
define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Fleets, PlanetResources, PlayerQueue};
use SPGame\Game\Services\{EventLoop, FleetCommandsService as Commands, FleetControlService as Control, FleetsData, PageActionService};
use SPGame\Game\Space\Coordinate;
use Swoole\Table;

function resumeCargoFixture(float $fuelFactor = .25, float $cargoFactor = 4): array
{
    $owner = defendFixture();
    $fleet = FleetsData::getInstance()->get(1);
    $fleet->setStatus(Fleet::STATUS_MISSION);
    $fleet->setMission(Mission::TRANSPORT->value);
    $fleet->setCurrentCoordinate(Coordinate::point(1, 1, 1200, 0), 1000);
    $fleet->setFuelTotal(100000);
    $fleet->setFuelCurrent(100000);
    $fleet->save();
    $params = ['target' => Coordinate::point(1, 1, 2100, 60)->toArray(), 'speedFactor' => 10];
    $move = Control::add(1, Command::MOVE_IN_SYSTEM, $params);
    Control::add(1, Command::OBSERVE, ['target' => $params['target']]);
    $active = Commands::activateNextCommand($fleet, $owner);
    $cost = (float)Control::params($active)['fuelCost'];
    researchAssert($cost > .000001, 'Fixture не создаёт расход топлива.');
    $params['pause'] = 'fuel';
    Control::patchCommand((int)$move['id'], ['execute_time' => 0,
        'params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    $fleet->setFuelCurrent($cost * $fuelFactor);
    $fleet->setFuelTotal($fleet->getFuelCurrent());
    $fleet->getResources()[901]->setCount(100);
    $fleet->getResources()[902]->setCount(50);
    $fleet->getResources()[903]->setCount($cost * $cargoFactor);
    $fleet->save();
    return [$owner, $fleet, $cost];
}

function resumeCargoSnapshot(Fleet $fleet): array
{
    return [Fleets::getById($fleet->getId()), FleetCommands::getFleetCommandChain($fleet->getId()),
        PlanetResources::getAll(), $fleet->getFuelCurrent(), $fleet->getResources()[903]->getCount()];
}

if (defined('SP_GAME_RESUME_FIXTURE_ONLY') && SP_GAME_RESUME_FIXTURE_ONLY) return;

researchCase('Cargo resume: списан только дефицит следующего участка, не весь дейтерий', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture();
    $planet = PlanetResources::getAll();
    $capacity = $fleet->getUsedCapacity() + $fleet->getFuelCurrent();
    Control::resume($fleet, $owner, 1000, true);
    $active = FleetCommands::getActiveCommand(1);
    researchAssert($active !== null && (int)$active['command_type'] === Command::MOVE_IN_SYSTEM->value,
        'Перелёт не активирован.');
    researchAssert(abs($fleet->getResources()[903]->getCount() - $cost * 3.25) < 1e-6,
        'Переведён не точный дефицит .75 стоимости участка.');
    researchAssert(abs($fleet->getFuelCurrent()) < 1e-6, 'Топливо участка списано не ровно один раз.');
    researchAssert(abs($capacity - $fleet->getUsedCapacity() - $fleet->getFuelCurrent() - $cost) < 1e-6,
        'Нарушен баланс общей вместимости.');
    researchAssert($fleet->getResources()[901]->getCount() === 100.0
        && $fleet->getResources()[902]->getCount() === 50.0 && PlanetResources::getAll() === $planet,
        'Затронуты другие ресурсы или планета.');
    researchAssert(!Control::state($fleet)['paused'], 'Сохранилась пауза.');
    $fleet->reloadFromRuntime();
    researchAssert(abs($fleet->getResources()[903]->getCount() - $cost * 3.25) < 1e-6,
        'Заправка не сохранилась в runtime.');
});

researchCase('Обычное продолжение без флага не тратит дейтерий из трюма', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $before = resumeCargoSnapshot($fleet);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Обычное продолжение изменило остановленный флот.');
});

foreach (['Нехватка' => .1, 'Пустой трюм' => 0] as $name => $factor) {
    researchCase($name . ': отказ без частичной заправки или потери команд', static function () use ($factor): void {
        [$owner, $fleet] = resumeCargoFixture(.25, $factor);
        $before = resumeCargoSnapshot($fleet);
        defendRejected(static fn() => Control::resume($fleet, $owner, 1000, true));
        researchAssert(resumeCargoSnapshot($fleet) === $before, 'Не восстановлены груз/топливо/пауза.');
    });
}

researchCase('Полный бак: дейтерий в трюме остаётся грузом', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture(2);
    $cargo = $fleet->getResources()[903]->getCount();
    Control::resume($fleet, $owner, 1000, true);
    researchAssert($fleet->getResources()[903]->getCount() === $cargo && abs($fleet->getFuelCurrent() - $cost) < 1e-6,
        'Избыточная заправка или двойное списание.');
});

researchCase('Следующий участок не заправляется автоматически из остатка груза', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $next = FleetCommands::getFleetQueue(1)[1];
    Control::patchCommand((int)$next['id'], ['command_type' => Command::MOVE_IN_SYSTEM->value,
        'params' => json_encode(['target' => Coordinate::point(1, 1, 2600, 150)->toArray(),
            'speedFactor' => 10], JSON_THROW_ON_ERROR)]);
    Control::resume($fleet, $owner, 1000, true);
    $cargo = $fleet->getResources()[903]->getCount();
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert(Control::state($fleet)['reason'] === 'fuel' && FleetCommands::getActiveCommand(1) === null,
        'Следующий участок заправлен без команды игрока.');
    researchAssert($fleet->getResources()[903]->getCount() === $cargo, 'Груз самовольно переведён в топливо.');
});

researchCase('Ровно достаточно дейтерия, затем повторное нажатие отклоняется', static function (): void {
    [$owner, $fleet] = resumeCargoFixture(0, 1);
    Control::resume($fleet, $owner, 1000, true);
    researchAssert(abs($fleet->getResources()[903]->getCount()) < 1e-6, 'Точный запас не использован.');
    $before = resumeCargoSnapshot($fleet);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000, true));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Повторное нажатие повторно списало груз.');
});

researchCase('Другой владелец не может заправить/продолжить чужой флот', static function (): void {
    [, $fleet] = resumeCargoFixture();
    $before = resumeCargoSnapshot($fleet);
    $other = new \SPGame\Game\Services\AccountData(2);
    defendRejected(static fn() => Control::resume($fleet, $other, 1000, true));
    researchAssert(resumeCargoSnapshot($fleet) === $before, 'Изменён чужой флот.');
});

researchCase('После отказа можно дозагрузить дейтерий и успешно повторить', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture(0, 0);
    defendRejected(static fn() => Control::resume($fleet, $owner, 1000, true));
    $fleet->getResources()[903]->setCount($cost * 2);
    $fleet->save();
    Control::resume($fleet, $owner, 1000, true);
    researchAssert(FleetCommands::getActiveCommand(1) !== null
        && abs($fleet->getResources()[903]->getCount() - $cost) < 1e-6, 'Повторная команда не исполнилась.');
});

researchCase('Ошибка записи команды ПОСЛЕ заправки: полный runtime rollback', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $before = resumeCargoSnapshot($fleet);
    // Исходные params помещаются, materialized MOVE — нет. Только Table этого теста.
    $small = new Table(128);
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
            Control::resume($fleet, $owner, 1000, true);
            throw new RuntimeException('Failure injection не сработала.');
        } catch (JsonException $error) {
            // Swoole сообщает об усечении своим warning, не PHP error handler.
            // До заправки активация невозможна; JSON ломается уже при чтении
            // записанной ACTIVE-команды после переноса дейтерия и списания топлива.
            researchAssert(resumeCargoSnapshot($fleet) === $before, 'Rollback не восстановил runtime state.');
        }
    } finally {
        $property->setValue(null, $original);
    }
    Control::resume($fleet, $owner, 1000, true);
    researchAssert(FleetCommands::getActiveCommand(1) !== null, 'Нельзя продолжить после rollback.');
});

researchCase('Остановка после удержания: перенос из трюма только по явному продолжению', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture();
    $pending = FleetCommands::getNextPendingCommand(1);
    Control::holdBefore($pending, $fleet, $owner);
    $hold = FleetCommands::getActiveCommand(1);
    researchAssert($hold !== null, 'Удержание не активировано.');
    Commands::completeCommand($hold, $fleet, $owner);
    researchAssert(Control::state($fleet)['reason'] === 'fuel', 'Не установлена пассивная остановка.');
    $cargo = $fleet->getResources()[903]->getCount();
    researchAssert(abs($cargo - $cost * 4) < 1e-6, 'Удержание самовольно использовало груз.');
    Control::resume($fleet, $owner, (float)$hold['execute_time'], true);
    researchAssert((int)FleetCommands::getActiveCommand(1)['command_type'] === Command::MOVE_IN_SYSTEM->value
        && $fleet->getResources()[903]->getCount() < $cargo, 'Не продолжён маршрут после удержания.');
});

foreach ([true, false] as $useCargo) {
    researchCase('PageAction → PlayerQueue → EventLoop: cargo=' . json_encode($useCargo), static function () use ($useCargo): void {
        [$owner, $fleet] = resumeCargoFixture();
        $before = resumeCargoSnapshot($fleet);
        $message = new Message(['mode' => 'fleets', 'action' => 'ResumeFleet', 'requestId' => 'cargo-resume-ack',
            'data' => ['fleetId' => 1, 'useCargoFuel' => $useCargo]]);
        $response = (new Message())->setRequestId('cargo-resume-ack');
        PageActionService::handle($message, $owner, 1, $response);
        researchAssert($response->source()['status'] === 'ok' && $response->source()['requestId'] === 'cargo-resume-ack',
            'Изменён contract immediate ACK.');
        $events = PlayerQueue::getByAccount(1);
        researchAssert(count($events) === 1 && $events[0]['data']['useCargoFuel'] === $useCargo,
            'Режим заправки потерян в PlayerQueue.');
        researchAssert(resumeCargoSnapshot($fleet) === $before, 'ACK изменил игровой state.');
        $process = new ReflectionMethod(EventLoop::class, 'processPlayerEvent');
        researchAssert($process->invoke(new EventLoop(), $events[0], 1), 'Нет признака обновления ActualData.');
        researchAssert((PlayerQueue::getByAccount(1) ?? []) === [], 'Обработанное событие осталось в PlayerQueue.');
        if ($useCargo) {
            researchAssert(FleetCommands::getActiveCommand(1) !== null
                && $fleet->getResources()[903]->getCount() < $before[4], 'EventLoop не использовал режим заправки.');
        } else {
            researchAssert(resumeCargoSnapshot($fleet) === $before, 'EventLoop без флага изменил груз.');
        }
    });
}

researchCase('Некорректный режим заправки отклонён до PlayerQueue', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $before = resumeCargoSnapshot($fleet);
    foreach ([null, 1, 'true', [], new stdClass()] as $invalid) {
        $message = new Message(['mode' => 'fleets', 'action' => 'ResumeFleet', 'requestId' => 'invalid-cargo-mode',
            'data' => ['fleetId' => 1, 'useCargoFuel' => $invalid]]);
        $response = (new Message())->setRequestId('invalid-cargo-mode');
        PageActionService::handle($message, $owner, 1, $response);
        researchAssert($response->source()['status'] === 'error' && (PlayerQueue::getByAccount(1) ?? []) === [],
            'Некорректный флаг принят в очередь.');
        researchAssert(resumeCargoSnapshot($fleet) === $before, 'Валидация изменила флот.');
    }
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
