<?php

declare(strict_types=1);

// Настоящие Swoole Tables в отдельном процессе; SQL запрещён fixture-guard.
define('SP_GAME_RESEARCH_FIXTURE_ONLY', true);
require __DIR__ . '/ResearchResourcesIsolationTest.php';
require __DIR__ . '/fixtures/BattleReportsMemory.php';

use SPGame\Core\Database;
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{Accounts, Builds, Config, DebrisFields, FleetCommands, Fleets, FleetsShips,
    Friends, Galaxy, GalaxyOrbits, Messages, PlanetBattles, Planets, PlanetResources, Queues, Ships, Techs, Users, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService as Attack, FleetBattleService as Battles,
    FleetCommandsService as Commands, FleetDefendService as Defend, FleetsData, FriendsService, Helpers};
use SPGame\Game\Space\{Coordinate, ObjectType};
use Swoole\Table;

function defendColumns(string $class, array $columns): void
{
    $schema = (new ReflectionProperty($class, 'tableSchema'))->getValue();
    foreach ($columns as $name => $type) $schema['columns'][$name] = ['swoole' => [$type, 8], 'default' => 0];
    (new ReflectionProperty($class, 'tableSchema'))->setValue(null, $schema);
}

function defendFixture(): AccountData
{
    researchFixture();
    BattleReportsMemory::reset();
    (new ReflectionProperty(FleetsData::class, 'instance'))->setValue(null, null);
    Vars::$resource += [34 => 'ally_deposit', 108 => 'computer_tech', 109 => 'military_tech',
        110 => 'defence_tech', 111 => 'shield_tech', 115 => 'combustion_tech', 117 => 'impulse_motor_tech',
        118 => 'hyperspace_motor_tech', 134 => 'fleet_logistics_tech', 202 => 'small_cargo'];
    Vars::$reslist['build'][] = 34;
    Vars::$reslist['tech'] = array_merge(Vars::$reslist['tech'], [108, 109, 110, 111, 115, 117, 118, 134]);
    Vars::$reslist['fleet'] = [202];
    Vars::$reslist['allow'][3] = [34];
    Vars::$attributes[202] = ['capacity' => 100000, 'speed' => 5000, 'consumption' => 20,
        'consumption2' => 20, 'tech' => 1, 'missions' => [Mission::DEFEND->value, Mission::ATTACK->value]];
    Vars::$pricelist[202] = [901 => 2000, 902 => 2000, 903 => 0];
    Vars::$CombatCaps = [202 => ['attack' => 5, 'shield' => 0]];
    Vars::$rapidfire = [];
    foreach (['FleetGameSpeed' => 10, 'FleetSpeedFactor' => 1, 'FleetDefendFuelPerHourFactor' => .1,
        'MoonChancePerUnit' => 0] as $name => $value) {
        Config::add(Config::castRowToSchema(['name' => $name, 'value' => (string)$value], true));
    }
    $accounts = $users = $builds = $techs = $fleets = $ships = [];
    foreach ([1 => 101, 2 => 102, 3 => 103] as $userId => $planetId) {
        $accounts[] = ['id' => $userId, 'login' => 'isolated-defense-' . $userId, 'lang' => 'ru'];
        $users[] = ['id' => $userId, 'account_id' => $userId, 'name' => 'Player' . $userId,
            'main_planet' => $planetId, 'current_planet' => $planetId];
        Planets::update(['id' => $planetId, 'owner_id' => $userId]);
        $builds[] = ['id' => $planetId, 'ally_deposit' => 2, 'metal_store' => 1];
        $techs[] = ['id' => $userId, 'computer_tech' => 10, 'fleet_logistics_tech' => 10];
        $fleets[] = ['id' => $userId, 'owner_id' => $userId, 'home_planet_id' => $planetId,
            'current_coordinate_type' => 1, 'current_galaxy' => 1, 'current_system' => 1,
            'current_orbit' => $userId, 'current_object_type' => 1, 'current_position_time' => 1000];
        $ships[] = ['id' => $userId, 'fleet_id' => $userId, 'owner_id' => $userId,
            'small_cargo_count' => 10, 'small_cargo_morale' => 1.0];
    }
    researchRepository(Accounts::class, $accounts);
    researchRepository(Users::class, $users);
    researchRepository(Builds::class, $builds, array_map(static fn($id) => Vars::$resource[$id], Vars::$reslist['build']));
    researchRepository(Techs::class, $techs, array_map(static fn($id) => Vars::$resource[$id], Vars::$reslist['tech']));
    foreach ([901, 902, 903, 921, 922] as $id) $columns['resource_' . Vars::$resource[$id]] = Table::TYPE_FLOAT;
    defendColumns(Fleets::class, $columns);
    defendColumns(FleetsShips::class, ['small_cargo_count' => Table::TYPE_INT, 'small_cargo_status' => Table::TYPE_INT,
        'small_cargo_damage' => Table::TYPE_FLOAT, 'small_cargo_experience' => Table::TYPE_FLOAT, 'small_cargo_morale' => Table::TYPE_FLOAT]);
    researchRepository(Fleets::class, $fleets);
    $fleetIndexes = (new ReflectionProperty(Fleets::class, 'indexTables'))->getValue();
    researchRepository(FleetsShips::class, $ships);
    researchRepository(Ships::class, [['id' => 101], ['id' => 102], ['id' => 103]], ['small_cargo']);
    researchRepository(Friends::class, [['id' => 1, 'user_low' => 1, 'user_high' => 2,
        'requester_id' => 1, 'status' => Friends::ACCEPTED, 'created_at' => 1000]]);
    researchRepository(FleetCommands::class, []);
    researchRepository(Messages::class, []);
    researchRepository(Queues::class, []);
    researchRepository(DebrisFields::class, []);
    researchRepository(Galaxy::class, [['id' => 1, 'galaxy' => 1, 'system' => 1, 'star_type' => 'G',
        'min_distance' => 100, 'max_distance' => 3500, 'max_orbits' => 3]]);
    researchRepository(GalaxyOrbits::class, [
        ['id' => 1, 'galaxy' => 1, 'system' => 1, 'orbit' => 1, 'type' => GalaxyOrbits::PLANET, 'distance' => 1000],
        ['id' => 2, 'galaxy' => 1, 'system' => 1, 'orbit' => 2, 'type' => GalaxyOrbits::PLANET, 'distance' => 2000],
        ['id' => 3, 'galaxy' => 1, 'system' => 1, 'orbit' => 3, 'type' => GalaxyOrbits::PLANET, 'distance' => 3000],
        ['id' => 4, 'galaxy' => 1, 'system' => 1, 'orbit' => 4, 'type' => GalaxyOrbits::SYSTEM_EDGE, 'distance' => 3500],
        ['id' => 5, 'galaxy' => 1, 'system' => 1, 'orbit' => 5, 'type' => GalaxyOrbits::EXPEDITION, 'distance' => 4000],
    ]);
    // В production initIndex() дополняет унаследованную карту, а fixture-helper
    // заменяет её целиком. Сохраняем также индексы Fleets при установке Commands.
    $indexes = new ReflectionProperty(Fleets::class, 'indexTables');
    $indexes->setValue(null, array_replace($indexes->getValue(), $fleetIndexes));
    return new AccountData(1);
}

function defendStart(AccountData $owner, int $fleetId = 1, ?Coordinate $target = null, int $duration = 3600): Fleet
{
    $fleet = FleetsData::getInstance()->get($fleetId);
    $target ??= Coordinate::object(1, 1, 2, ObjectType::PLANET);
    $preview = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()), $owner, 1, Mission::DEFEND);
    Commands::startMission($fleet, $owner, Mission::DEFEND->value, $target->toArray(), 1,
        ceil($preview['fuel']['max']), [], 1000, $duration);
    $owner->save();
    return $fleet;
}

function defendArrive(Fleet $fleet, AccountData $owner): array
{
    $move = FleetCommands::getActiveCommand($fleet->getId());
    researchAssert($move['command_type'] === Command::MOVE_IN_SYSTEM->value, 'Нет перелёта к другу.');
    Commands::completeCommand($move, $fleet, $owner);
    return FleetCommands::getActiveCommand($fleet->getId());
}

function defendRejected(callable $callback): void
{
    try { $callback(); } catch (InvalidArgumentException|LogicException $e) { return; }
    throw new RuntimeException('Ожидался отказ.');
}

if (defined('SP_GAME_DEFEND_FIXTURE_ONLY') && SP_GAME_DEFEND_FIXTURE_ONLY) return;

researchCase('Друзья: заявка, только получатель принимает; посторонний/сам себе запрещены', static function (): void {
    defendFixture();
    FriendsService::change(1, 'remove', 1);
    FriendsService::change(2, 'request', 1);
    researchAssert(!Friends::areFriends(1, 2), 'Pending даёт право обороны.');
    defendRejected(static fn() => FriendsService::change(2, 'accept', 1));
    defendRejected(static fn() => FriendsService::change(3, 'accept', 1));
    FriendsService::change(1, 'accept', 1);
    researchAssert(Friends::areFriends(2, 1) && count(FriendsService::list(1)) === 1, 'Не принята симметричная дружба.');
    defendRejected(static fn() => FriendsService::change(1, 'request', 1));
    defendRejected(static fn() => FriendsService::change(1, 'request', 99));
    researchAssert(count(Friends::getAll()) === 1, 'Пара продублирована.');
});

researchCase('DEFEND: чужой без дружбы, свой объект, склад 0 и неверное время запрещены', static function (): void {
    $owner = defendFixture();
    $fleet = FleetsData::getInstance()->get(1);
    researchAssert(Defend::reason($fleet, Planets::findExistingById(103)) !== null, 'Разрешён посторонний.');
    researchAssert(Defend::reason($fleet, Planets::findExistingById(101)) !== null, 'Разрешён свой.');
    Builds::update(['id' => 102, 'ally_deposit' => 0]);
    researchAssert(Defend::reason($fleet, Planets::findExistingById(102)) !== null, 'Нет ограничения склада.');
    foreach ([0, -1, 3, '3600', 7201] as $value) defendRejected(static fn() => Defend::duration($value));
    researchAssert(Defend::duration(null) === 3600 && Defend::duration(115200) === 115200, 'Ошибка времени.');
    researchAssert(Defend::planet(Coordinate::object(1, 1, 2, ObjectType::DEBRIS)) === null, 'Обломки разрешены.');
    researchAssert(FleetCommands::getAll() === [], 'Валидация создала команды.');
});

researchCase('DEFEND полный путь: удержание за счёт хозяина, возврат, без повторного списания', static function (): void {
    $owner = defendFixture();
    $before = researchLedger();
    $fleet = defendStart($owner);
    $hold = defendArrive($fleet, $owner);
    researchAssert($hold['command_type'] === Command::DEFEND->value, 'Удержание не началось.');
    researchAssert(Defend::isHoldingAt($fleet, Planets::findExistingById(102)), 'Флот не считается защитником.');
    $onboard = $fleet->getFuelCurrent();
    $time = $fleet->getCurrentPositionTime();
    $host = new AccountData(2);
    Helpers::processPlanet($time + 60, $host); $host->save();
    $rate = Defend::fuelRate($fleet, $owner);
    researchBalances(researchLedger(), [102 => ['deuterium' => $before[102]['deuterium'] - $rate * 60],
        103 => $before[103]]);
    researchAssert($fleet->getFuelCurrent() === $onboard, 'Удержание списало бортовое топливо.');
    Commands::completeCommand($hold, $fleet, $owner);
    researchBalances(researchLedger(), [102 => ['deuterium' => $before[102]['deuterium'] - $rate * 3600]]);
    researchAssert(Defend::holds() === [], 'Удержание не завершилось.');
    $return = FleetCommands::getActiveCommand(1);
    researchAssert($return['command_type'] === Command::MOVE_IN_SYSTEM->value && $fleet->isReturn(), 'Нет возврата.');
    Commands::completeCommand($return, $fleet, $owner); $owner->save();
    researchAssert($fleet->getStatus() === Fleet::STATUS_IDLE && FleetCommands::getFleetQueue(1) === [], 'Не вернулся домой.');
    $cold = new AccountData(2);
    Helpers::processPlanet($time + 3600, $cold); $cold->save();
    researchBalances(researchLedger(), [102 => ['deuterium' => $before[102]['deuterium'] - $rate * 3600]]);
});

researchCase('Нет дейтерия при прибытии: немедленный возврат без удержания', static function (): void {
    $owner = defendFixture();
    PlanetResources::update(['id' => 102, 'deuterium' => 0]);
    $fleet = defendStart($owner);
    $active = defendArrive($fleet, $owner);
    researchAssert($active['command_type'] === Command::MOVE_IN_SYSTEM->value && $fleet->isReturn(), 'Флот остался без снабжения.');
    researchAssert(Defend::holds() === [], 'Создано удержание.');
});

researchCase('Отмена дружбы / заполненный склад в полёте: повторная проверка при прибытии', static function (): void {
    foreach (['friend', 'capacity'] as $mode) {
        $owner = defendFixture();
        $fleet = defendStart($owner);
        if ($mode === 'friend') FriendsService::change(2, 'remove', 1);
        else Builds::update(['id' => 102, 'ally_deposit' => 0]);
        $active = defendArrive($fleet, $owner);
        researchAssert($active['command_type'] === Command::MOVE_IN_SYSTEM->value && Defend::holds() === [], 'Нет отказа по ' . $mode);
    }
});

researchCase('Два флота: общий момент исчерпания, свой дейтерий не списывается', static function (): void {
    $owner = defendFixture();
    $first = defendStart($owner); defendArrive($first, $owner);
    Friends::add(['user_low' => 2, 'user_high' => 3, 'requester_id' => 2, 'status' => Friends::ACCEPTED, 'created_at' => 1000]);
    $third = new AccountData(3);
    $second = defendStart($third, 3); defendArrive($second, $third);
    $start = max($first->getCurrentPositionTime(), $second->getCurrentPositionTime());
    $host = new AccountData(2); Helpers::processPlanet($start, $host); $host->save();
    PlanetResources::update(['id' => 102, 'deuterium' => 1]);
    Defend::rescheduleSupplies($start);
    $a = FleetCommands::getActiveCommand(1); $b = FleetCommands::getActiveCommand(3);
    $expected = $start + 1 / (Defend::fuelRate($first, $owner) + Defend::fuelRate($second, $third));
    researchAssert(abs($a['execute_time'] - $expected) < .000001 && $a['execute_time'] === $b['execute_time'], 'Разные сроки исчерпания.');
    $fuel = [$first->getFuelCurrent(), $second->getFuelCurrent()];
    Commands::completeCommand($a, $first, $owner);
    Commands::completeCommand($b, $second, $third);
    researchBalances(researchLedger(), [102 => ['deuterium' => 0]]);
    researchAssert(Defend::holds() === [] && $first->isReturn() && $second->isReturn(), 'Не все флоты ушли.');
    researchAssert($first->getFuelCurrent() < $fuel[0] && $second->getFuelCurrent() < $fuel[1], 'Не оплачен обратный перелёт.');
});

researchCase('Луна: собственный склад и дейтерий, запас планеты не используется', static function (): void {
    $owner = defendFixture();
    $moon = Planets::findExistingById(102);
    $moon['id'] = 104; $moon['planet_type'] = ObjectType::MOON->value;
    Planets::add($moon);
    Builds::add(['id' => 104, 'ally_deposit' => 1, 'metal_store' => 1]);
    PlanetResources::add(['id' => 104, 'deuterium' => 10]);
    $parent = PlanetResources::findExistingById(102);
    $fleet = defendStart($owner, 1, Coordinate::object(1, 1, 2, ObjectType::MOON));
    defendArrive($fleet, $owner);
    $time = $fleet->getCurrentPositionTime();
    $host = new AccountData(2); $host['WorkPlanet'] = 104;
    Helpers::processPlanet($time + 30, $host); $host->save();
    researchAssert(abs(PlanetResources::findExistingById(104)['deuterium'] - (10 - Defend::fuelRate($fleet, $owner) * 30)) < .000001, 'Неверное снабжение луны.');
    researchAssert(PlanetResources::findExistingById(102) === $parent, 'Изменены ресурсы родительской планеты.');
    Builds::update(['id' => 104, 'ally_deposit' => 0]);
    researchAssert(Defend::reason($fleet, $moon) !== null, 'Луна использует склад планеты.');
});

researchCase('Прогноз: после завершения короткого удержания общий расход уменьшается', static function (): void {
    $items = [
        ['params' => ['hold' => ['until' => 1002, 'rate' => 10]]],
        ['params' => ['hold' => ['until' => 1100, 'rate' => 10]]],
    ];
    $time = (new ReflectionMethod(Defend::class, 'exhaustionTime'))->invoke(null, $items, 1000, 100, 0);
    researchAssert(abs($time - 1008) < .000001, 'Прогноз не учитывает освобождение слота.');
});

researchCase('Пополнение до deadline продлевает удержание; расход на несколько флотов суммируется', static function (): void {
    $owner = defendFixture();
    $fleet = defendStart($owner); $hold = defendArrive($fleet, $owner);
    $start = $fleet->getCurrentPositionTime();
    PlanetResources::update(['id' => 102, 'deuterium' => .5]);
    Defend::rescheduleSupplies($start);
    researchAssert(FleetCommands::getActiveCommand(1)['execute_time'] < $hold['execute_time'], 'Не сокращён срок.');
    PlanetResources::update(['id' => 102, 'deuterium' => 1000]);
    Defend::rescheduleSupplies($start);
    researchAssert(FleetCommands::getActiveCommand(1)['execute_time'] === $hold['execute_time'], 'Пополнение не продлило удержание.');
});

researchCase('Обороняющий флот вступает в бой со своими технологиями и продолжает удержание после боя', static function (): void {
    $owner = defendFixture();
    Vars::$bonus[109] = array_fill_keys(['Attack', 'Defensive', 'Shield', 'ResourceStorage', 'Resource',
        'Energy', 'BuildTime', 'ResearchTime', 'ShipTime', 'DefensiveTime', 'SpyPower'], [0]);
    Vars::$bonus[109]['Attack'] = [.1];
    Vars::$reslist['bonus'] = [109];
    Techs::update(['id' => 1, 'military_tech' => 5]);
    $owner = new AccountData(1);
    $support = defendStart($owner); $hold = defendArrive($support, $owner);
    $attacker = FleetsData::getInstance()->get(3);
    $attacker->setCurrentCoordinate($support->getCurrentCoordinate(), $support->getCurrentPositionTime() + 1);
    $attacker->save();
    $command = FleetCommands::addCommand(3, Command::ATTACK, ['target' => $support->getCurrentCoordinate()->toArray()]);
    $third = new AccountData(3);
    Attack::arrive($command, $attacker, $third);
    $state = PlanetBattles::state(102);
    researchAssert($state['mission']['participants'][1]['side'] === 'defender', 'Друг не стал защитником.');
    researchAssert(abs($state['combat']['d']['fleet:1:202']['attack'] - 7.5) < .000001, 'Использованы чужие технологии.');
    researchAssert(Attack::mustWait(FleetCommands::getActiveCommand(1)), 'Защитник может выйти из боя.');
    $tasks = [];
    Battles::initializeWorkers(static function (array $task) use (&$tasks): bool { $tasks[] = $task; return true; });
    for ($i = 0; $i < 12; ++$i) {
        $state = PlanetBattles::state(102);
        if ($state['phase'] === 'finished') break;
        $now = $state['startedAt'] + count($state['combat']['rounds']) + 1;
        Battles::tick($now);
        while ($tasks !== []) Battles::executeTask(array_shift($tasks));
        Battles::tick($now + .1);
    }
    $state = PlanetBattles::state(102);
    researchAssert($state['phase'] === 'finished', 'Бой не рассчитан.');
    $time = $state['startedAt'] + count($state['combat']['rounds']) + .5;
    $affected = Attack::process($time, static function ($id, $at): void {
        $host = new AccountData(2); $host['WorkPlanet'] = $id;
        Helpers::processPlanet($at, $host); $host->save();
    });
    researchAssert(PlanetBattles::state(102)['phase'] === 'applied', 'Результат боя не сохранён.');
    researchAssert(in_array(1, $affected, true) && in_array(2, $affected, true) && in_array(3, $affected, true), 'Не все участники обновлены.');
    $active = FleetCommands::getActiveCommand(1);
    researchAssert($active['command_type'] === Command::DEFEND->value && !Attack::mustWait($active), 'Удержание не продолжилось.');
    researchAssert($active['execute_time'] === $hold['execute_time'], 'Потерян срок удержания.');
    $reports = array_filter(Messages::getAll(), static fn($m) => $m['sample'] === 'FleetBattleReport');
    researchAssert(count($reports) === 3, 'Участник обороны не получил доклад.');
});

researchCase('Page friends получает числовой ID из реального Message без преобразования в строку', static function (): void {
    $owner = defendFixture();
    $message = (new \SPGame\Core\Message())->setMode('friends')->setAction('remove')->setData('id', 1);
    (new \SPGame\Game\Pages\FriendsPage($message))->render($owner);
    researchAssert(!Friends::areFriends(1, 2), 'WS-действие не изменило связь.');
    $message = (new \SPGame\Core\Message())->setMode('friends')->setAction('reconnect');
    $page = (new \SPGame\Game\Pages\FriendsPage($message))->render($owner);
    researchAssert($page['page'] === 'friends' && $page['userId'] === 1, 'Reconnect не загрузил список друзей.');
});

researchCase('PageAction DEFEND сохраняет duration и только ставит PlayerQueue; неверный тип отклонён', static function (): void {
    $owner = defendFixture();
    $fleet = FleetsData::getInstance()->get(1);
    $target = Coordinate::object(1, 1, 2, ObjectType::PLANET);
    $preview = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()), $owner, 1, Mission::DEFEND);
    $message = (new \SPGame\Core\Message())->setMode('fleets')->setAction('SendMission')
        ->setData('fleetId', 1)->setData('missionType', Mission::DEFEND->value)->setData('coordinates', $target->toArray())
        ->setData('fuel', ceil($preview['fuel']['max']))->setData('speedFactor', 1)->setData('resources', [])
        ->setData('duration', '3600');
    $response = new \SPGame\Core\Message();
    \SPGame\Game\Services\PageActionService::handle($message, $owner, 1, $response);
    researchAssert($response->source()['status'] === 'error', 'Строковое duration принято.');
    $message->setData('duration', 7200);
    $response = new \SPGame\Core\Message();
    \SPGame\Game\Services\PageActionService::handle($message, $owner, 1, $response);
    researchAssert($response->source()['status'] === 'ok', 'Числовое duration не принято.');
    $queued = \SPGame\Game\Repositories\PlayerQueue::getByAccount(1);
    researchAssert(count($queued) === 1 && array_values($queued)[0]['data']['duration'] === 7200, 'duration потерялось в PlayerQueue.');
    researchAssert(FleetCommands::getAll() === [], 'ACK уже запустил миссию.');
});

researchCase('Отзыв с удержания: только владелец, оплата фактического времени, возврат штатным due', static function (): void {
    $owner = defendFixture();
    $fleet = defendStart($owner); defendArrive($fleet, $owner);
    $start = $fleet->getCurrentPositionTime();
    $stock = PlanetResources::findExistingById(102)['deuterium'];
    defendRejected(static fn() => Defend::recall($fleet, new AccountData(3), $start + 10));
    Defend::recall($fleet, $owner, $start + 10);
    Defend::rescheduleSupplies($start + 10);
    $hold = FleetCommands::getActiveCommand(1);
    researchAssert($hold['execute_time'] === $start + 10, 'Отзыв был продлён прогнозом снабжения.');
    Commands::completeCommand($hold, $fleet, $owner);
    researchAssert($fleet->isReturn() && Defend::holds() === [], 'Отзыв не начал возврат.');
    researchBalances(researchLedger(), [102 => ['deuterium' => $stock - Defend::fuelRate($fleet, $owner) * 10]]);
});

researchCase('Подкрепление друга после фиксации первого раунда входит во второй; отзыв в бою запрещён', static function (): void {
    $owner = defendFixture();
    $support = defendStart($owner);
    $arrival = (float)FleetCommands::getActiveCommand(1)['execute_time'];
    $target = Coordinate::object(1, 1, 2, ObjectType::PLANET);
    $attacker = FleetsData::getInstance()->get(3);
    $attacker->setCurrentCoordinate($target, $arrival - 2); $attacker->save();
    $command = FleetCommands::addCommand(3, Command::ATTACK, ['target' => $target->toArray()]);
    Attack::arrive($command, $attacker, new AccountData(3));
    $tasks = [];
    Battles::initializeWorkers(static function (array $task) use (&$tasks): bool { $tasks[] = $task; return true; });
    Battles::tick($arrival - 1);
    researchAssert(count($tasks) === 1, 'Первый раунд не зафиксирован.');
    defendArrive($support, $owner);
    $state = PlanetBattles::state(102);
    researchAssert(count($state['pending']) === 1 && $state['pending'][0]['round'] === 2
        && $state['pending'][0]['side'] === 'defender', 'Подкрепление попало не в следующий раунд защиты.');
    defendRejected(static fn() => Defend::recall($support, $owner, $arrival));
    Battles::executeTask(array_shift($tasks));
    Battles::tick($arrival);
    $state = PlanetBattles::state(102);
    researchAssert(isset($state['combat']['d']['fleet:1:202']), 'Второй раунд не получил подкрепление.');
    researchAssert(Attack::mustWait(FleetCommands::getActiveCommand(1)), 'Защитник не заблокирован боем.');
});

researchCase('Холодное восстановление Fleet/AccountData не повторяет оплату удержания', static function (): void {
    $owner = defendFixture();
    $fleet = defendStart($owner); defendArrive($fleet, $owner);
    $time = $fleet->getCurrentPositionTime() + 60;
    $host = new AccountData(2); Helpers::processPlanet($time, $host); $host->save();
    $stock = PlanetResources::findExistingById(102)['deuterium'];
    (new ReflectionProperty(FleetsData::class, 'instance'))->setValue(null, null);
    $cold = new AccountData(2); Helpers::processPlanet($time, $cold); $cold->save();
    researchAssert(PlanetResources::findExistingById(102)['deuterium'] === $stock, 'Дейтерий списан повторно.');
    researchAssert(Defend::isHoldingAt(FleetsData::getInstance()->get(1), Planets::findExistingById(102)), 'Утрачено удержание после загрузки runtime.');
});

researchCase('Производство принимающего объекта покрывает удержание без досрочного ухода', static function (): void {
    $owner = defendFixture();
    $income = Config::findByIndex('name', 'deuteriumBasicIncome');
    Config::update(['id' => $income['id'], 'value' => '100']);
    $fleet = defendStart($owner); $hold = defendArrive($fleet, $owner);
    $start = $fleet->getCurrentPositionTime();
    PlanetResources::update(['id' => 102, 'deuterium' => .5]);
    Defend::rescheduleSupplies($start);
    researchAssert(FleetCommands::getActiveCommand(1)['execute_time'] === $hold['execute_time'], 'Не учтена добыча дейтерия.');
    $host = new AccountData(2); Helpers::processPlanet($start + 60, $host); $host->save();
    researchBalances(researchLedger(), [102 => ['deuterium' => .5 + 100 / 60 - Defend::fuelRate($fleet, $owner) * 60]]);
});

researchCase('После потери кораблей будущий расход уменьшается, оплаченный интервал не пересчитывается', static function (): void {
    $owner = defendFixture();
    $fleet = defendStart($owner); defendArrive($fleet, $owner);
    $start = $fleet->getCurrentPositionTime();
    $stock = PlanetResources::findExistingById(102)['deuterium'];
    $rate = Defend::fuelRate($fleet, $owner);
    $host = new AccountData(2); Helpers::processPlanet($start + 30, $host); $host->save();
    $fleet->setShipCount(202, 5); $fleet->save();
    Defend::refreshRate($fleet, $owner);
    $host = new AccountData(2); Helpers::processPlanet($start + 60, $host); $host->save();
    researchBalances(researchLedger(), [102 => ['deuterium' => $stock - $rate * 30 - $rate / 2 * 30]]);
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
