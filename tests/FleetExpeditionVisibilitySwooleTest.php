<?php

declare(strict_types=1);

// Настоящий lifecycle экспедиции в отдельных Swoole Tables; SQL запрещён.
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Pages\{FleetsPage, GalaxyPage};
use SPGame\Game\Repositories\{Config, FleetCommands, FleetExpeditions, Fleets, PlanetResources, Vars};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetControlService as Control,
    FleetFlightService, FleetMissionService, FleetRendezvousService as Rendezvous, FleetTransferService, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};

function expeditionVisibilityFixture(): array
{
    [$owner, $fleet] = nativePlanFixture();
    researchRepository(FleetExpeditions::class, []);
    Vars::$resource[124] = 'expedition_tech'; $owner['Techs']['expedition_tech'] = 3;
    foreach (['resources', 'credits', 'ships', 'combat', 'lost', 'time', 'nothing'] as $event) {
        Config::add(['name' => 'ExpeditionWeight' . ucfirst($event), 'value' => $event === 'nothing' ? '1' : '0']);
    }
    Fleets::update(['id' => 2, 'owner_id' => 1, 'home_planet_id' => 101]);
    $sender = FleetsData::getInstance()->get(2);
    $home = Coordinate::object(1, 1, 1, ObjectType::PLANET);
    $sender->setCurrentCoordinate($home, 1000); $sender->save();
    $target = Coordinate::object(1, 1, 5);
    $preview = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()), $owner, 1, Mission::EXPEDITION);
    Commands::startMission($fleet, $owner, Mission::EXPEDITION->value, $target->toArray(), 1,
        ceil($preview['fuel']['max']), [], 1000, 3600);
    return compact('owner', 'fleet', 'sender', 'home');
}

function expeditionVisibilityEvent(array $f): array
{
    $page = new FleetsPage(new Message());
    $event = (new ReflectionMethod($page, 'buildFlyingFleetEvent'))->invoke($page,
        $f['fleet'], $f['owner'], new FleetFlightService(), true);
    researchAssert(is_array($event), 'Владелец должен сохранять управление экспедицией.');
    return $event;
}

function expeditionVisibleOnMap(array $event): bool
{
    return (new ReflectionMethod(GalaxyPage::class, 'fleetIsVisibleInSystem'))->invoke(null, $event, 1, 1);
}

function expeditionTargetRejected(callable $action): void
{
    $rejected = false;
    try { $action(); } catch (InvalidArgumentException|LogicException) { $rejected = true; }
    researchAssert($rejected, 'Экспедиция доступна по вручную указанному ID.');
}

researchCase('экспедиция: виден/доступен MOVE туда → скрыта EXPEDITION → виден/доступен MOVE обратно', static function (): void {
    $f = expeditionVisibilityFixture(); $fleet = $f['fleet']; $owner = $f['owner'];
    researchAssert(expeditionVisibleOnMap(expeditionVisibilityEvent($f)) && Rendezvous::target(1, 1, 2) === $fleet
        && in_array(1, array_column(Rendezvous::targets(1, $fleet->getCurrentPositionTime()), 'id'), true),
        'Полёт к экспедиции скрыт.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert((int)FleetCommands::getActiveCommand(1)['command_type'] === Command::EXPEDITION->value,
        'Исследование не активировалось.');
    researchAssert(!expeditionVisibleOnMap(expeditionVisibilityEvent($f)), 'EXPEDITION раскрыта в галактике.');
    researchAssert(!in_array(1, array_column(Rendezvous::targets(1, $fleet->getCurrentPositionTime()), 'id'), true),
        'EXPEDITION раскрыта в списке целей.');
    expeditionTargetRejected(static fn() => Rendezvous::coordinate(1, 1, $fleet->getCurrentPositionTime(), 2));
    $target = FleetTarget::fromCoordinates(['type' => 3, 'fleetId' => 1]);
    $available = (new FleetMissionService())->getAvailableMissions($f['sender'], $target, $owner);
    researchAssert($available['disabled'] && $available['missions'] === [], 'Ручной ID раскрывает доступные миссии.');
    $before = [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()];
    expeditionTargetRejected(static fn() => Commands::prepareMissionRequest($f['sender'], $owner,
        Mission::TRANSPORT->value, ['type' => 3, 'fleetId' => 1], 10, 1, [903 => 50], null, null, null, true));
    researchAssert($before === [Fleets::getAll(), FleetCommands::getAll(), PlanetResources::getAll()],
        'Отклонённая отправка изменила runtime.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert($fleet->getMission() === Mission::EXPEDITION->value && $fleet->isReturn()
        && expeditionVisibleOnMap(expeditionVisibilityEvent($f)) && Rendezvous::target(1, 1, 2) === $fleet
        && in_array(1, array_column(Rendezvous::targets(1, $fleet->getCurrentPositionTime()), 'id'), true),
        'Возврат скрыт из-за общего mission=EXPEDITION.');
});

researchCase('пауза внутри EXPEDITION не раскрывает карту и цель', static function (): void {
    $f = expeditionVisibilityFixture();
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['owner']);
    Control::pause(FleetCommands::getActiveCommand(1), 'fuel');
    researchAssert(!expeditionVisibleOnMap(expeditionVisibilityEvent($f)), 'Пауза раскрыла экспедицию на карте.');
    expeditionTargetRejected(static fn() => Rendezvous::target(1, 1, 2));
});

researchCase('ранее отправленный дозаправщик не передаёт груз после входа цели в EXPEDITION', static function (): void {
    $f = expeditionVisibilityFixture(); $sender = $f['sender'];
    $known = Rendezvous::coordinate(1, 1, $f['fleet']->getCurrentPositionTime(), 2);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['owner']);
    $sender->setCurrentCoordinate(Rendezvous::position($f['fleet'], $f['fleet']->getCurrentPositionTime()),
        $f['fleet']->getCurrentPositionTime());
    $sender->setStatus(1); $sender->setMission(Mission::TRANSPORT);
    $sender->setFuelTotal(100); $sender->setFuelCurrent(100);
    $sender->getResources()[903]->setCount(50); $sender->save();
    $delivery = Control::add(2, Command::UNLOAD, ['target' => $known->toArray(), 'refuel' => true]);
    $fuel = $f['fleet']->getFuelCurrent();
    Commands::activateNextCommand($sender, $f['owner']);
    researchAssert(Control::state($sender)['reason'] === 'target'
        && (int)FleetCommands::getCommand((int)$delivery['id'])['executed'] === 0,
        'Доставка к скрытой цели не приостановлена.');
    researchAssert($sender->getResources()[903]->getCount() === 50.0 && $sender->getFuelCurrent() === 100.0
        && $f['fleet']->getFuelCurrent() === $fuel, 'Груз/топливо переданы скрытой экспедиции.');
    expeditionTargetRejected(static fn() => FleetTransferService::receiver($sender, Control::params($delivery)));
});

researchAssert($failed === 0 && Database::$calls === 0, 'Ошибка сценария или обращение к игровой SQL.');
restore_error_handler();
echo "RESULT expedition visibility: $passed passed / $failed failed; SQL calls=0\n";
