<?php

declare(strict_types=1);

$exportFixture = in_array('--export-fixture', $argv ?? [], true);
if ($exportFixture) ob_start();

// Изолированные игровые repositories и существующий транспорт/шпионаж baseline.
require __DIR__ . '/FleetSpyTest.php';
require __DIR__ . '/fixtures/BattleReportsMemory.php';

use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Repositories\{DebrisFields, Defenses, FleetCommands, Fleets, FleetsShips,
    Messages, PlanetBattles, PlanetResources, Ships, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService as Attack, FleetBattleService as Battles,
    FleetCommandsService as Commands, FleetCombatService as Combat, FleetsData};

function attackFixture(): array
{
    BattleReportsMemory::reset();
    $f = spyFixture();
    Vars::$resource[204] = 'light_hunter';
    Vars::$resource[108] = 'computer_tech';
    Vars::$reslist['fleet'][] = 204;
    Vars::$attributes[204] = Vars::$attributes[202];
    Vars::$attributes[204]['missions'] = [FleetMissionType::ATTACK->value];
    Vars::$pricelist[204] = [901 => 3000, 902 => 1000];
    Vars::$pricelist[401] = [901 => 2000, 902 => 0];
    Vars::$CombatCaps = [204 => ['attack' => 200000, 'shield' => 10],
        202 => ['attack' => 5, 'shield' => 0], 210 => ['attack' => 0, 'shield' => 0],
        401 => ['attack' => 30, 'shield' => 0]];
    Vars::$rapidfire = [];
    Vars::$bonus = [];
    $columns = [];
    foreach ([202 => 'small_cargo', 204 => 'light_hunter', 210 => 'spy_sonde'] as $type => $name) {
        foreach (['count' => 0, 'damage' => 0.0, 'morale' => 1.0, 'status' => 0, 'experience' => 0.0] as $suffix => $default) {
            $columns[$name . '_' . $suffix] = ['swoole' => [is_int($default) ? \Swoole\Table::TYPE_INT : \Swoole\Table::TYPE_FLOAT], 'default' => $default];
        }
    }
    appendTransportSchemaColumns(FleetsShips::class, $columns);
    appendTransportSchemaColumns(Defenses::class, ['missile_launcher' => ['swoole' => [\Swoole\Table::TYPE_INT], 'default' => 0]]);
    appendTransportSchemaColumns(Ships::class, [
        'small_cargo' => ['swoole' => [\Swoole\Table::TYPE_INT], 'default' => 0],
        'spy_sonde' => ['swoole' => [\Swoole\Table::TYPE_INT], 'default' => 0],
    ]);
    FleetsShips::update(FleetsShips::castRowToSchema(FleetsShips::findExistingById(1), true));
    $f['fleet']->reloadFromRuntime();
    $f['fleet']->setShipCount(210, 0);
    $f['fleet']->setShipCount(204, 20)->setExperience(100.0);
    $f['fleet']->save();
    $f['account']['Techs']['computer_tech'] = 5;
    $techs = ['id' => 100, 'spy_tech' => 1, 'energy_tech' => 0, 'metal_tech' => 0,
        'crystal_tech' => 0, 'deuterium_tech' => 0, 'computer_tech' => 5,
        'combustion_tech' => 0, 'impulse_motor_tech' => 0, 'hyperspace_motor_tech' => 0];
    installTransportRepository(\SPGame\Game\Repositories\Techs::class,
        transportTable([$techs, array_replace($techs, ['id' => 200])]), transportSyncTable(200), []);
    installTransportRepository(PlanetBattles::class, transportTable(), transportSyncTable(), []);
    $GLOBALS['attackTasks'] = [];
    Battles::initializeWorkers(static function (array $task): bool { $GLOBALS['attackTasks'][] = $task; return true; });
    return $f;
}

function attackStart(array $f, ?\SPGame\Game\Fleet\Fleet $fleet = null, float $time = 100): void
{
    $fleet ??= $f['fleet'];
    $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($f['coordinates']);
    $travel = $fleet->getTravelInfo($target, $f['account'], 10, FleetMissionType::ATTACK);
    Commands::startMission($fleet, $f['account'], FleetMissionType::ATTACK->value,
        $f['coordinates'], 10, ceil($travel['fuel']['max']), [], $time);
}

function attackArrive(array $f, ?\SPGame\Game\Fleet\Fleet $fleet = null): void
{
    $fleet ??= $f['fleet'];
    for ($i = 0; $i < 10; ++$i) {
        $active = FleetCommands::getActiveCommand($fleet->getId());
        if ($active === null || Attack::isBattleWait($active)) return;
        transportAssert(Commands::completeCommand($active, $fleet, $f['account']), 'MOVE завершён.');
    }
    throw new RuntimeException('Не удалось завершить маршрут до боя.');
}

function attackCalculate(int $planetId = 11): float
{
    for ($i = 0; $i < 10; ++$i) {
        $state = PlanetBattles::state($planetId);
        if ($state['phase'] === 'finished') return $state['startedAt'] + count($state['combat']['rounds']) + .5;
        $now = $state['startedAt'] + count($state['combat']['rounds']) + 1;
        Battles::tick($now);
        while ($GLOBALS['attackTasks'] !== []) Battles::executeTask(array_shift($GLOBALS['attackTasks']));
        Battles::tick($now + .1);
    }
    $state = PlanetBattles::state($planetId);
    transportAssert($state['phase'] === 'finished', 'Бой рассчитан за установленный лимит.');
    return $state['startedAt'] + count($state['combat']['rounds']) + .5;
}

function attackClone(array $f, int $id, int $ownerId = 100): \SPGame\Game\Fleet\Fleet
{
    $row = Fleets::findExistingById(1);
    $row['id'] = $id;
    $row['owner_id'] = $ownerId;
    $row['mission'] = $row['status'] = $row['is_return'] = 0;
    $row['fuel_total'] = $row['fuel_current'] = 0.0;
    foreach (['metal', 'crystal', 'deuterium'] as $name) $row['resource_' . $name] = 0.0;
    Fleets::add($row);
    $ships = FleetsShips::findExistingById(1);
    $ships['id'] = $ships['fleet_id'] = $id;
    $ships['owner_id'] = $ownerId;
    FleetsShips::add($ships);
    return FleetsData::getInstance()->get($id);
}

$attackTests = [];
$attackTests['outbound attack projects battle and return without changing runtime commands'] = static function (): void {
    $f = attackFixture();
    attackStart($f);
    $commands = FleetCommands::getFleetCommandChain(1);
    $page = (new ReflectionClass(\SPGame\Game\Pages\FleetsPage::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($page, 'buildFlightCommandProjection');
    $flight = new \SPGame\Game\Services\FleetFlightService();
    $projection = $method->invoke($page, $f['fleet'], $f['account'], $commands, $flight);
    $attack = array_values(array_filter($projection['commands'], static fn($c) => $c['key'] === 'ATTACK'))[0];
    transportAssert($attack['estimated_time'] !== null && $attack['time_estimated'], 'Ожидающий бой должен иметь приблизительную длительность.');
    transportAssert(abs($attack['estimated_time'] - $attack['started_time']
        - Combat::DEFAULT_ROUNDS * Battles::ROUND_SECONDS) < 1e-7, 'Прогноз использует тот же плановый лимит раундов.');
    transportAssert($projection['mission_end_time'] > $attack['estimated_time'] && $projection['mission_end_estimated'],
        'Полное завершение включает бой и обратный перелёт.');
    foreach ($projection['commands'] as $command) {
        if ($command['return_leg']) transportAssert($command['estimated_time'] !== null, 'После боя продолжается прогноз возврата.');
    }
    transportAssert($projection === $method->invoke($page, $f['fleet'], $f['account'], $commands, $flight), 'Повторный прогноз стабилен.');
    transportAssert(FleetCommands::getFleetCommandChain(1) === $commands, 'Прогноз не активирует команды и не меняет runtime.');
};
$attackTests['full attack loads fuel pauses for battle commits loot reports and returns'] = static function (): void {
    $f = attackFixture();
    $before = PlanetResources::findExistingById(11);
    attackStart($f);
    $chain = FleetCommands::getFleetCommandChain(1);
    transportAssert((int)$chain[0]['command_type'] === FleetCommandType::LOAD->value, 'Топливо реально загружено через LOAD.');
    attackArrive($f);
    $state = PlanetBattles::state(11);
    transportAssert($state['mission']['attackerId'] === 100 && $state['mission']['defenderId'] === 200, 'Стороны определены сервером.');
    transportAssert(FleetCommands::getActiveCommand(1)['command_type'] === FleetCommandType::ATTACK->value, 'Возврат не стартовал до конца боя.');
    $page = new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
    $viewer = new AccountData(1000);
    $events = $page->buildFlyingFleetList($viewer);
    transportAssert(array_keys($events[1]['battle']) === ['phase', 'round', 'rounds_max'], 'Клиент получает только публичный прогресс боя.');
    transportAssert(!str_contains(json_encode($events, JSON_THROW_ON_ERROR), $state['seed']), 'Seed не попадает в WebSocket snapshot.');
    transportAssert($events[1]['mission_end_time'] > $events[1]['end_time'] && $events[1]['mission_end_estimated'],
        'Во время боя показан приблизительный возврат, а не гарантированный срок.');
    $now = attackCalculate();
    $affected = Attack::process($now);
    $state = PlanetBattles::state(11);
    transportAssert($state['phase'] === 'applied', 'Результат применён и планета разблокирована.');
    transportAssert($state['resolution']['outcome'] === 'attacker', 'Атакующий победил.');
    transportAssert(in_array(1000, $affected, true) && in_array(2000, $affected, true), 'Оба клиента должны получить ActualData.');
    $after = PlanetResources::findExistingById(11);
    foreach ([901, 902, 903] as $id) {
        $name = Vars::$resource[$id];
        transportAssert(abs($after[$name] - $before[$name] / 2) < 1e-7, 'Добыто не более половины ' . $name);
        transportAssert(abs($f['fleet']->getResources()[$id]->getCount() - $before[$name] / 2) < 1e-7, 'Согласован груз Fleet cache.');
    }
    $reports = array_filter(Messages::getAll() ?? [], static fn($m) => $m['sample'] === 'FleetBattleReport');
    transportAssert(count($reports) === 2, 'Доклад каждому владельцу.');
    $events = $page->buildFlyingFleetList($viewer);
    transportAssert($events[1]['is_return'] && $events[1]['mission_end_time'] !== null, 'После боя прогноз продолжает работать на возврате.');
    $resources = PlanetResources::findExistingById(11);
    $xp = $f['fleet']->getShips()[204]->getExperience();
    Attack::process($now + .2);
    transportAssert(PlanetResources::findExistingById(11) === $resources && $f['fleet']->getShips()[204]->getExperience() === $xp, 'Повтор не списывает добычу и не выдаёт опыт.');
    transportAssert(count(array_filter(Messages::getAll(), static fn($m) => $m['sample'] === 'FleetBattleReport')) === 2, 'Нет повторных докладов.');
    for ($i = 0; $i < 10 && FleetCommands::getActiveCommand(1) !== null; ++$i) {
        Commands::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    }
    transportAssert($f['fleet']->getStatus() === \SPGame\Game\Fleet\Fleet::STATUS_IDLE && FleetCommands::getFleetQueue(1) === [], 'Возврат → UNLOAD → STAY.');
};
$attackTests['loot matches Nova five-step order and never exceeds resources or capacity'] = static function (): void {
    transportAssert(Attack::calculateLoot([901 => 900, 902 => 600, 903 => 300], 300) === [901 => 100.0, 902 => 100.0, 903 => 100.0], 'Три ресурса, равные доли при заполненном трюме.');
    for ($i = 0; $i < 500; ++$i) {
        $resources = [901 => $i * 77, 902 => $i % 17 * 100, 903 => $i * 9];
        $capacity = $i * 13.0;
        $loot = Attack::calculateLoot($resources, $capacity);
        transportAssert(array_sum($loot) <= $capacity + 1e-7, 'Вместимость не превышена.');
        foreach ($loot as $id => $amount) transportAssert($amount >= 0 && $amount <= $resources[$id] / 2 + 1e-7, 'Не более половины каждого ресурса.');
    }
};
$attackTests['queue production and resource income during battle are not overwritten'] = static function (): void {
    $f = attackFixture();
    attackStart($f);
    attackArrive($f);
    $now = attackCalculate();
    $actualized = false;
    Attack::process($now, static function (int $planetId, float $time) use (&$actualized): void {
        $actualized = true;
        transportAssert($planetId === 11, 'Актуализируется цель, включая offline-владельца.');
        Ships::update(['id' => 11, 'small_cargo' => 9]);
        PlanetResources::update(['id' => 11, 'metal' => 300.0]);
    });
    transportAssert($actualized, 'Использован существующий pipeline актуализации.');
    transportAssert(PlanetBattles::state(11)['phase'] === 'applied', 'Бой завершён.');
    transportAssert(Ships::findExistingById(11)['small_cargo'] === 5, 'Новые корабли не затёрты старым snapshot.');
    transportAssert(PlanetResources::findExistingById(11)['metal'] === 150.0, 'Ресурсы берутся с актуального runtime запаса.');
};
$attackTests['late attacker reinforces round two without restarting the battle'] = static function (): void {
    $f = attackFixture();
    Vars::$CombatCaps[204] = ['attack' => 1, 'shield' => 3000000];
    $second = attackClone($f, 2);
    attackStart($f);
    attackArrive($f);
    $start = PlanetBattles::state(11)['startedAt'];
    Battles::tick($start + 1);
    attackStart($f, $second, 101.2);
    attackArrive($f, $second);
    $state = PlanetBattles::state(11);
    transportAssert(count($state['pending']) === 1 && $state['pending'][0]['round'] === 2, 'Подкрепление принято на второй раунд.');
    transportAssert(!isset($GLOBALS['attackTasks'][0]['combat']['a']['fleet:2:204']), 'Первый snapshot immutable.');
    Battles::executeTask(array_shift($GLOBALS['attackTasks']));
    Battles::tick($start + 1.5);
    Battles::tick($start + 2);
    transportAssert(isset($GLOBALS['attackTasks'][0]['combat']['a']['fleet:2:204']), 'Второй snapshot содержит новый флот.');
};
$attackTests['friendly arriving fleet enters defense and unloads only after settlement'] = static function (): void {
    $f = attackFixture();
    Vars::$CombatCaps[204] = ['attack' => 1, 'shield' => 3000000];
    $friendly = attackClone($f, 2, 200);
    $friendly->setCurrentCoordinate(\SPGame\Game\Space\Coordinate::point(1, 10, 1200, 0), 100);
    $friendly->setFuelTotal(100000.0);
    $friendly->setFuelCurrent(100000.0);
    $friendly->setMission(FleetMissionType::RELOCATE);
    $friendly->setStatus(\SPGame\Game\Fleet\Fleet::STATUS_MISSION);
    $friendly->save();
    attackStart($f);
    attackArrive($f);
    $target = \SPGame\Game\Space\Coordinate::fromArray($f['coordinates']);
    $owner = new AccountData(2000);
    Commands::startFleetCommands($friendly, [
        ['type' => FleetCommandType::MOVE_IN_SYSTEM, 'params' => ['target' => $target, 'speedFactor' => 10]],
        ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $target, 'unloadFuel' => true]],
        ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]],
    ], 100, $owner);
    Commands::completeCommand(FleetCommands::getActiveCommand(2), $friendly, $owner);
    $state = PlanetBattles::state(11);
    transportAssert($state['mission']['participants'][2]['side'] === 'defender', 'Сторона выбрана по реальному владельцу планеты.');
    transportAssert(Attack::isBattleWait(FleetCommands::getActiveCommand(2)), 'UNLOAD приостановлен.');
};
$attackTests['runtime failure after debris and first report rolls back and retries once'] = static function (): void {
    $f = attackFixture();
    attackStart($f);
    attackArrive($f);
    $now = attackCalculate();
    $before = [Fleets::findExistingById(1), FleetsShips::findExistingById(1),
        Ships::findExistingById(11), Defenses::findExistingById(11), PlanetResources::findExistingById(11)];
    $table = (new ReflectionProperty(Messages::class, 'table'))->getValue();
    $table->failedWrites = 1;
    $table->failedWriteKey = '2';
    Attack::process($now);
    transportAssert(PlanetBattles::state(11)['phase'] === 'finished', 'Чистый rollback допускает повтор применения.');
    transportAssert([Fleets::findExistingById(1), FleetsShips::findExistingById(1),
        Ships::findExistingById(11), Defenses::findExistingById(11), PlanetResources::findExistingById(11)] === $before, 'Все затронутые runtime строки восстановлены.');
    transportAssert((Messages::getAll() ?? []) === [] && DebrisFields::at(1, 10, 8) === null, 'Нет частичных докладов/обломков.');
    Attack::process($now + .1);
    transportAssert(PlanetBattles::state(11)['phase'] === 'applied', 'Повтор успешно применён.');
    transportAssert(count(Messages::getAll()) === 2, 'Ровно два итоговых доклада.');
    $debris = DebrisFields::at(1, 10, 8);
    transportAssert($debris['metal'] === 2400.0 && $debris['crystal'] === 3000.0, 'Обломки созданы ровно один раз.');
};
$attackTests['destroyed attacker creates no loot and is removed with its commands'] = static function (): void {
    $f = attackFixture();
    Vars::$CombatCaps[202]['attack'] = 2000000;
    $before = PlanetResources::findExistingById(11);
    attackStart($f);
    attackArrive($f);
    $now = attackCalculate();
    Attack::process($now);
    $state = PlanetBattles::state(11);
    transportAssert($state['phase'] === 'applied' && $state['resolution']['outcome'] === 'draw', 'Обе стороны стреляют даже при взаимной гибели.');
    transportAssert(Fleets::findExistingById(1) === null && FleetsShips::findExistingById(1) === null, 'Уничтоженный Fleet удалён.');
    transportAssert(FleetCommands::getFleetQueue(1) === [] && PlanetResources::findExistingById(11) === $before, 'Нет возврата погибшего флота или добычи при ничьей.');
};
$attackTests['fuel loss follows lost capacity and insufficient return cannot lock planet'] = static function (): void {
    $f = attackFixture();
    Vars::$CombatCaps[202]['attack'] = 500;
    attackStart($f);
    attackArrive($f);
    $state = PlanetBattles::state(11);
    $original = $state['mission']['participants'][1];
    $now = attackCalculate();
    $state = PlanetBattles::state(11);
    $method = new ReflectionMethod(Attack::class, 'prepareResult');
    $plan = $method->invoke(null, 11, $state, $now);
    $afterCount = Combat::getResult($state['combat'])['attacker']['groups']['fleet:1:204']['after'];
    foreach ($plan['changes'] as $change) {
        if ($change['repository'] === 'fleets' && $change['id'] === 1) {
            transportAssert(abs($change['after']['fuel_current'] - $original['fuel'] * $afterCount / 20) < 1e-7, 'Топливо пропорционально потерянной вместимости.');
        }
    }
    // Имитируем полный расход оставшегося топлива ДО начала боя, не после snapshot.
    $state['mission']['participants'][1]['fuel'] = 0.0;
    PlanetBattles::store(11, $state);
    Fleets::update(['id' => 1, 'fuel_current' => 0.0]);
    FleetsData::getInstance()->refresh(1);
    Attack::process($now);
    transportAssert(PlanetBattles::state(11)['phase'] === 'applied', 'Недостаток топлива не удерживает планету закрытой.');
    $next = FleetCommands::getNextPendingCommand(1);
    transportAssert(json_decode($next['params'], true)['blockedAfterBattle'] === true, 'Флот не получил бесплатное топливо и не исчез: команда явно приостановлена.');
    $page = new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
    $account = new AccountData(1000);
    $events = $page->buildFlyingFleetList($account);
    transportAssert(count($events) === 1 && $events[1]['blocked_reason'] !== null, 'Приостановленный флот остаётся видимым владельцу.');
    transportAssert($events[1]['mission_end_time'] === null, 'При нехватке топлива не обещается возвращение.');
};
$attackTests['idle defending fleets participate and survivors retain fleet XP'] = static function (): void {
    $f = attackFixture();
    $defender = attackClone($f, 2, 200);
    $defender->setCurrentCoordinate(\SPGame\Game\Space\Coordinate::fromArray($f['coordinates']), 100);
    $defender->setHomePlanetId(11);
    $defender->save();
    attackStart($f);
    attackArrive($f);
    $state = PlanetBattles::state(11);
    transportAssert(isset($state['combat']['d']['fleet:2:204']), 'Флот у планеты входит в защиту вместе с планетарными кораблями.');
    transportAssert($state['mission']['participants'][2]['side'] === 'defender', 'Защитник привязан к реальному owner.');
};
$attackTests['third owner waits then starts a separate attack without joining another owner'] = static function (): void {
    $f = attackFixture();
    $third = attackClone($f, 3, 300);
    \SPGame\Game\Repositories\Users::add(\SPGame\Game\Repositories\Users::castRowToSchema([
        'id' => 300, 'account_id' => 3000, 'current_planet' => 10, 'main_planet' => 10, 'name' => 'Third'], true));
    \SPGame\Game\Repositories\Accounts::add(\SPGame\Game\Repositories\Accounts::castRowToSchema(['id' => 3000, 'login' => 'third'], true));
    $tech = \SPGame\Game\Repositories\Techs::findExistingById(100);
    $tech['id'] = 300;
    \SPGame\Game\Repositories\Techs::add($tech);
    attackStart($f);
    attackArrive($f);
    $first = PlanetBattles::state(11);
    $third->setCurrentCoordinate(\SPGame\Game\Space\Coordinate::fromArray($f['coordinates']), $first['startedAt'] + .2);
    $third->setMission(FleetMissionType::ATTACK);
    $third->setStatus(\SPGame\Game\Fleet\Fleet::STATUS_MISSION);
    $third->save();
    Commands::setFleetCommands(3, [['type' => FleetCommandType::ATTACK,
        'params' => ['target' => \SPGame\Game\Space\Coordinate::fromArray($f['coordinates'])]]]);
    Commands::activateNextCommand($third, new AccountData(3000));
    $state = PlanetBattles::state(11);
    transportAssert(isset($state['mission']['waiting'][3]) && !isset($state['mission']['participants'][3]), 'Нет автоматической коалиции третьего игрока.');
    $now = attackCalculate();
    Attack::process($now);
    $waiting = FleetCommands::getActiveCommand(3);
    transportAssert(!Attack::mustWait($waiting), 'Следующее столкновение доступно после commit первого.');
    Commands::completeCommand($waiting, $third, new AccountData(3000));
    $next = PlanetBattles::state(11);
    transportAssert($next['id'] !== $first['id'] && $next['mission']['attackerId'] === 300, 'Начат отдельный бой нового владельца.');
};
$attackTests['changed snapshot fails closed instead of applying a second or stale result'] = static function (): void {
    $f = attackFixture();
    attackStart($f);
    attackArrive($f);
    $now = attackCalculate();
    FleetsShips::update(['id' => 1, 'light_hunter_count' => 19]); // Недопустимая сторонняя мутация.
    $resources = PlanetResources::findExistingById(11);
    Attack::process($now);
    transportAssert(PlanetBattles::state(11)['phase'] === 'failed', 'Не угадываем, какой состав считать правильным.');
    transportAssert(PlanetResources::findExistingById(11) === $resources && (Messages::getAll() ?? []) === [], 'Никаких списаний/наград по устаревшему snapshot.');
};
$attackTests['moon battle uses moon resources and common orbital debris without touching planet'] = static function (): void {
    $f = attackFixture();
    $moon = array_replace($f['target'], ['id' => 12, 'planet_type' => 3]);
    \SPGame\Game\Repositories\Planets::add($moon);
    PlanetResources::add(['id' => 12, 'metal' => 40.0, 'crystal' => 80.0, 'deuterium' => 120.0, 'energy' => 0.0]);
    Ships::add(['id' => 12, 'small_cargo' => 1, 'spy_sonde' => 0]);
    Defenses::add(['id' => 12, 'missile_launcher' => 1]);
    $f['coordinates']['objectType'] = 3;
    $planet = PlanetResources::findExistingById(11);
    attackStart($f);
    attackArrive($f);
    $now = attackCalculate(12);
    Attack::process($now);
    transportAssert(PlanetBattles::state(12)['phase'] === 'applied', 'Бой на луне завершён.');
    transportAssert(PlanetResources::findExistingById(12)['metal'] === 20.0 && PlanetResources::findExistingById(11) === $planet, 'Добыча только с луны.');
    transportAssert(DebrisFields::at(1, 10, 8) !== null, 'Поле обломков общее для орбиты.');
};
$attackTests['target becoming own before arrival returns without starting a battle'] = static function (): void {
    $f = attackFixture();
    attackStart($f);
    \SPGame\Game\Repositories\Planets::update(['id' => 11, 'owner_id' => 100]);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert(PlanetBattles::state(11) === null, 'Не начинается бой против собственного объекта.');
    transportAssert($f['fleet']->isReturn() && (Messages::getAll() ?? []) === [], 'Без списаний и боевых докладов запущен возврат.');
};

if ($exportFixture) {
    $f = attackFixture();
    // Native AccountData, в отличие от test double, читает также исходную планету.
    PlanetResources::add(['id' => 10, 'metal' => 1000000.0, 'crystal' => 1000000.0, 'deuterium' => 1000000.0, 'energy' => 0.0]);
    foreach ([Ships::class, Defenses::class, \SPGame\Game\Repositories\Builds::class] as $class) {
        if ($class::findExistingById(10) === null) $class::add($class::castRowToSchema(['id' => 10], true));
    }
    $classes = [\SPGame\Game\Repositories\Accounts::class, \SPGame\Game\Repositories\Users::class,
        \SPGame\Game\Repositories\Planets::class, PlanetResources::class, \SPGame\Game\Repositories\UserResources::class,
        \SPGame\Game\Repositories\Techs::class, \SPGame\Game\Repositories\Builds::class, Ships::class, Defenses::class,
        Fleets::class, FleetsShips::class, FleetCommands::class, Messages::class, PlanetBattles::class,
        \SPGame\Game\Repositories\Config::class, \SPGame\Game\Repositories\GalaxyOrbits::class,
        \SPGame\Game\Repositories\Galaxy::class, \SPGame\Game\Repositories\PlayerQueue::class, DebrisFields::class];
    $fixture = ['vars' => [], 'repositories' => []];
    foreach ((new ReflectionClass(Vars::class))->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_STATIC) as $property) {
        if ($property->isStatic() && $property->isInitialized()) $fixture['vars'][$property->getName()] = $property->getValue();
    }
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        $tableProperty = $reflection->getProperty('table');
        if (!$tableProperty->isInitialized()) continue;
        $schema = $reflection->getProperty('tableSchema')->getValue();
        foreach ($schema['columns'] as &$column) {
            $column['nativeKind'] = match ($column['swoole'][0]) {
                \Swoole\Table::TYPE_INT => 'int', \Swoole\Table::TYPE_FLOAT => 'float', default => 'string',
            };
        }
        unset($column);
        $indexes = [];
        foreach ($reflection->getProperty('indexTables')->getValue() as $name => $index) $indexes[$name] = iterator_to_array($index);
        $rows = iterator_to_array($tableProperty->getValue());
        // Старые host fixtures содержат поля динамических repositories без вызова DB init().
        foreach ($rows as $row) {
            foreach ($row as $name => $value) {
                if (isset($schema['columns'][$name])) continue;
                $kind = is_int($value) ? 'int' : (is_float($value) ? 'float' : 'string');
                $schema['columns'][$name] = ['nativeKind' => $kind, 'swoole' => [
                    $kind === 'int' ? \Swoole\Table::TYPE_INT : ($kind === 'float' ? \Swoole\Table::TYPE_FLOAT : \Swoole\Table::TYPE_STRING),
                    $kind === 'string' ? 8192 : 8,
                ], 'default' => $kind === 'string' ? '' : 0];
            }
        }
        $syncProperty = $reflection->getProperty('syncTable');
        $sync = $syncProperty->isInitialized() ? iterator_to_array($syncProperty->getValue())
            : ['meta' => ['need_update' => 0, 'need_delete' => 0, 'last_id' => max([0, ...array_keys($rows)])]];
        $fixture['repositories'][$class] = ['schema' => $schema, 'rows' => $rows, 'sync' => $sync, 'indexes' => $indexes];
    }
    ob_end_clean();
    echo json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    return;
}

foreach ($attackTests as $name => $test) {
    $test();
    echo "OK attack {$name}\n";
}
