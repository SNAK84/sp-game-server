<?php

declare(strict_types=1);

// Изолированные native Tables; SQL запрещён fixture. Игровую БД не использует.
define('SP_GAME_RESUME_FIXTURE_ONLY', true);
require __DIR__ . '/FleetResumeCargoSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{FleetCommands, Fleets, PlanetResources, PlayerQueue, Techs, Vars};
use SPGame\Game\Services\{FleetControlService as Control, FleetCommandsService as Commands,
    FleetPlanService as Plan, FleetTransferService as Transfer, FleetsData, PageActionService};
use SPGame\Game\Space\{Coordinate, ObjectType};

function technologyFixture(int $computer = 6): array
{
    $owner = defendFixture();
    $owner['Techs']['computer_tech'] = $computer;
    Vars::$attributes[202]['missions'][] = Mission::TRANSPORT->value;
    return [$owner, FleetsData::getInstance()->get(1)];
}

function technologySteps(int $count, int $orbit = 2): array
{
    $steps = array_fill(0, $count - 1, ['kind' => 'mission', 'mission' => Mission::TRANSPORT->value,
        'coordinates' => Coordinate::object(1, 1, $orbit, ObjectType::PLANET)->toArray(),
        'percent' => 10, 'speedFactor' => 10]);
    $steps[] = ['kind' => 'return'];
    return $steps;
}

function technologyInput($fleet, array $steps): array
{
    return ['version' => Control::state($fleet)['version'], 'steps' => $steps, 'fuel' => 10000];
}

function technologySnapshot($fleet): array
{
    return [resumeCargoSnapshot($fleet), PlayerQueue::getAll(), Fleets::getPendingSync(),
        FleetCommands::getPendingSync(), PlanetResources::getPendingSync()];
}

researchCase('0Б: границы уровней и серверные capabilities', static function (): void {
    foreach ([0, 2, 3, 5, 6, 11, 12, 13] as $level) {
        $caps = Control::capabilitiesForLevels($level, $level);
        researchAssert($caps['plan'] === ($level >= 3) && $caps['edit'] === ($level >= 6)
            && $caps['maxStages'] === ($level < 3 ? 0 : min(12, $level))
            && $caps['cargoRefuel'] === ($level >= 3) && $caps['hold'] === ($level >= 1), 'Неверный порог: ' . $level);
    }
});

researchCase('0Б: ровно лимит этапов принимается, лишний этап/нет технологии — отказ без mutations', static function (): void {
    foreach ([0, 2, 3, 5, 6, 11, 12, 13] as $level) {
        [$owner, $fleet] = technologyFixture($level);
        $before = technologySnapshot($fleet);
        $limit = max(3, min(12, $level));
        if ($level < 3) {
            defendRejected(fn() => Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps(3)), 1000));
        } else {
            defendRejected(fn() => Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps($limit + 1)), 1000));
        }
        researchAssert(technologySnapshot($fleet) === $before, 'Отказ изменил runtime/markers: ' . $level);
        $response = new Message();
        PageActionService::handle(new Message(['mode'=>'fleets','action'=>'SetFleetCommands',
            'data'=>['fleetId'=>1,'plan'=>technologyInput($fleet, technologySteps($level < 3 ? 3 : $limit + 1))]]), $owner, 1, $response);
        researchAssert($response->source()['status'] === 'error' && technologySnapshot($fleet) === $before,
            'API пропустил превышение лимита или изменил runtime.');
        if ($level >= 3) {
            Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps($limit)), 1000);
            researchAssert(count(Plan::steps($fleet)) === $limit, 'Потерян этап, включая возврат: ' . $level);
        }
    }
});

researchCase('0Б: обычная одиночная миссия с уровнем 0, редактирование в полёте только с 6', static function (): void {
    [$owner, $fleet] = technologyFixture(0);
    $target = FleetTarget::fromCoordinates(Coordinate::object(1, 1, 2, ObjectType::PLANET)->toArray());
    $travel = $fleet->getTravelInfo($target, $owner, 10, Mission::TRANSPORT);
    Commands::startMission($fleet, $owner, Mission::TRANSPORT->value, Coordinate::object(1, 1, 2, ObjectType::PLANET)->toArray(), 10, ceil($travel['fuel']['max']), [901=>1], 1000);
    $active = FleetCommands::getActiveCommand(1);
    foreach ([0, 2, 3, 5] as $level) {
        $owner['Techs']['computer_tech'] = $level;
        $before = technologySnapshot($fleet);
        defendRejected(fn() => Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps(2)), 1001));
        researchAssert(technologySnapshot($fleet) === $before, 'Отказ в правке изменил текущий полёт.');
    }
    $owner['Techs']['computer_tech'] = 6;
    Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps(6)), 1001);
    researchAssert(FleetCommands::getActiveCommand(1) === $active, 'Изменён активный участок.');
});

researchCase('0Б: текущая цель считается один раз, новая цель и отдельные действия занимают места', static function (): void {
    [$owner, $fleet] = technologyFixture();
    Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps(3)), 1000);
    researchAssert(Plan::stageCount($fleet, technologySteps(6)) === 6, 'Дважды посчитан перелёт + действие.');
    researchAssert(Plan::stageCount($fleet, technologySteps(6, 3)) === 7, 'Не учтён текущий неизменяемый этап.');
    $before = technologySnapshot($fleet);
    defendRejected(fn() => Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps(6, 3)), 1001));
    researchAssert($before === technologySnapshot($fleet), 'Переполнение изменило маршрут.');
    $active = FleetCommands::getActiveCommand(1);
    Control::stop($fleet, $owner, 1000 + ((float)$active['execute_time'] - 1000) / 2);
    researchAssert(Plan::stageCount($fleet, technologySteps(5)) === 6, 'Удержание не учтено отдельным этапом.');
});

researchCase('0Б: служебные MOVE и выполненная история не занимают дополнительные места', static function (): void {
    [$owner, $fleet] = technologyFixture();
    foreach ([Command::MOVE_IN_SYSTEM, Command::MOVE_BETWEEN_SYSTEMS, Command::MOVE_BETWEEN_GALAXIES] as $type) {
        $command = Control::add(1, $type, ['target' => Coordinate::object(1, 1, 2, ObjectType::PLANET)->toArray()]);
        FleetCommands::activateCommand((int)$command['id'], 1100);
        researchAssert(Plan::stageCount($fleet, technologySteps(6)) === 6, 'Внутренний переход съел место.');
        FleetCommands::markExecuted((int)$command['id']);
    }
    researchAssert(Plan::stageCount($fleet, technologySteps(6)) === 6, 'Выполненные команды вошли в лимит.');
    $return = Control::add(1, Command::MOVE_IN_SYSTEM, ['target' => Coordinate::object(1, 1, 1, ObjectType::PLANET)->toArray(), 'returnLeg' => true]);
    FleetCommands::activateCommand((int)$return['id'], 1200);
    researchAssert(Plan::stageCount($fleet, [['kind'=>'return']]) === 1, 'Текущий возврат посчитан дважды.');
    FleetCommands::markExecuted((int)$return['id']);
    $target = Coordinate::object(1, 1, 5)->toArray();
    $move = Control::add(1, Command::MOVE_IN_SYSTEM, ['target'=>$target]);
    FleetCommands::activateCommand((int)$move['id'], 1300);
    $target['objectType'] = null;
    researchAssert(Plan::stageCount($fleet, [['kind'=>'mission','mission'=>40,'coordinates'=>$target]]) === 1,
        'Отсутствующий objectType и null описывают одну служебную орбиту.');
});

researchCase('0Б: старый маршрут не обрезается и завершается после снижения уровня', static function (): void {
    [$owner, $fleet] = technologyFixture(12);
    Control::savePlan($fleet, $owner, technologyInput($fleet, technologySteps(12)), 1000);
    $owner['Techs']['computer_tech'] = 0;
    $before = technologySnapshot($fleet);
    researchAssert(count(Control::state($fleet)['steps']) === 12, 'Чтение обрезало старый план.');
    researchAssert($before === technologySnapshot($fleet), 'Рендер изменил state.');
    for ($i = 0; $i < 10 && ($active = FleetCommands::getActiveCommand(1)) !== null; $i++) Commands::completeCommand($active, $fleet, $owner);
    researchAssert($fleet->getStatus() === 0 && FleetCommands::getFleetQueue(1) === [], 'Старый план не вернулся на базу.');
});

researchCase('0Б: отказ дозаправки во всех входах, включая legacy/API/direct activation, без изменений', static function (): void {
    foreach ([0, 2] as $level) foreach (['segment', 'route', 'legacy', 'activation'] as $mode) {
        [$owner, $fleet] = resumeCargoFixture();
        $owner['Techs']['fleet_logistics_tech'] = $level;
        $before = technologySnapshot($fleet);
        defendRejected(static function () use ($owner, $fleet, $mode): void {
            if ($mode === 'activation') Commands::activateNextCommand($fleet, $owner, true);
            else Control::resume($fleet, $owner, 1001, $mode === 'legacy', $mode === 'legacy' ? null : $mode);
        });
        researchAssert(technologySnapshot($fleet) === $before, 'Отказ изменил state: ' . $mode);
        if ($mode === 'activation') continue;
        $data = ['fleetId' => 1] + ($mode === 'legacy' ? ['useCargoFuel' => true] : ['fuelMode' => $mode]);
        $response = new Message();
        PageActionService::handle(new Message(['mode'=>'fleets','action'=>'ResumeFleet','data'=>$data]), $owner, 1, $response);
        researchAssert($response->source()['status'] === 'error' && technologySnapshot($fleet) === $before, 'API допустил обход.');
    }
});

researchCase('0Б: уровень проверяется повторно после ACK, без перекачки', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $owner['Techs']['fleet_logistics_tech'] = 3;
    $response = new Message();
    PageActionService::handle(new Message(['mode'=>'fleets','action'=>'ResumeFleet','data'=>['fleetId'=>1,'fuelMode'=>'segment']]), $owner, 1, $response);
    researchAssert($response->source()['status'] === 'ok' && count(PlayerQueue::getByAccount(1)) === 1, 'Нет ACK.');
    $owner['Techs']['fleet_logistics_tech'] = 2;
    $before = technologySnapshot($fleet);
    defendRejected(fn() => Control::resume($fleet, $owner, 1001, fuelMode:'segment'));
    researchAssert($before === technologySnapshot($fleet), 'Отложенное действие обошло технологию.');
});

researchCase('0Б: логистика 3 — оба режима, conservation; без технологии доступно топливо бака', static function (): void {
    foreach (['segment','route','available'] as $mode) {
        [$owner, $fleet, $cost] = resumeCargoFixture(.25, 100);
        $owner['Techs']['computer_tech'] = 0;
        $owner['Techs']['fleet_logistics_tech'] = $mode === 'available' ? 0 : 3;
        $tail = FleetCommands::getFleetQueue(1)[1];
        FleetCommands::markExecuted((int)$tail['id']); // Конечный маршрут только до POINT.
        $before = $fleet->getFuelCurrent() + $fleet->getResources()[903]->getCount();
        $cargo = $fleet->getResources()[903]->getCount();
        Control::resume($fleet, $owner, 1001, fuelMode:$mode);
        $active = FleetCommands::getActiveCommand(1);
        researchAssert($active !== null, 'Не активирован режим ' . $mode);
        $burned = Control::params($active)['fuelCost'];
        researchAssert(abs($before - $burned - $fleet->getFuelCurrent() - $fleet->getResources()[903]->getCount()) < 1e-6, 'Нарушен баланс.');
        researchAssert($mode !== 'available' || $fleet->getResources()[903]->getCount() === $cargo, 'Режим бака тратит груз.');
    }
});

researchCase('0Б: внешняя дозаправка без технологии, явное продолжение на баке и replay без дубля', static function (): void {
    [$owner, $fleet, $cost] = resumeCargoFixture();
    $owner['Techs']['computer_tech'] = 0;
    $owner['Techs']['fleet_logistics_tech'] = 0;
    Fleets::update(['id'=>2,'owner_id'=>1]);
    $sender = FleetsData::getInstance()->get(2);
    $sender->reloadFromRuntime();
    $sender->setCurrentCoordinate($fleet->getCurrentCoordinate(), 1000);
    $sender->getResources()[903]->setCount(2 * $cost);
    $sender->save();
    $params = ['target'=>['type'=>3,'fleetId'=>1], 'refuel'=>true];
    $command = Control::add(2, Command::UNLOAD, $params);
    $cargo = $fleet->getResources()[903]->getCount();
    $before = $fleet->getFuelCurrent();
    Transfer::deliver($command, $sender, $params);
    Transfer::deliver($command, $sender, $params);
    researchAssert(abs($fleet->getFuelCurrent() - $before - 2 * $cost) < 1e-6
        && $sender->getResources()[903]->getCount() < 1e-6 && Control::state($fleet)['paused'],
        'Заправка требует технологию, дублируется или автоматически продолжает полёт.');
    Control::resume($fleet, $owner, 1001, fuelMode:'available');
    researchAssert(FleetCommands::getActiveCommand(1) !== null && $fleet->getResources()[903]->getCount() === $cargo,
        'После внешней заправки недоступно продолжение на баке без технологии.');
});

researchCase('0Б: уведомление учитывает технологию владельца', static function (): void {
    foreach ([0,2,3] as $level) {
        [$owner, $fleet] = resumeCargoFixture();
        Techs::update(['id'=>1,'fleet_logistics_tech'=>$level]);
        Control::notifyFuelStops(1001);
        $row = array_values(\SPGame\Game\Repositories\Messages::getAll())[0];
        $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        researchAssert($data['lang']['CargoRefuelAdvice'] === 'fuelAdvice.' . ($level >= 3 ? 'available' : 'locked'), 'Неверный совет.');
    }
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
