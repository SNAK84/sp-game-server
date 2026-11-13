<?php

declare(strict_types=1);

$exportSpecial = in_array('--export-special', $argv ?? [], true);
if ($exportSpecial) ob_start();
require __DIR__ . '/FleetAttackTest.php';

use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories\{Accounts, Builds, Defenses, FleetAttackGroups as Groups, FleetCommands, Fleets,
    FleetsShips, Friends, GalaxyOrbits, Messages, Planets, PlanetResources, Queues, Ships, Techs, Users, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService as Attack, FleetCommandsService as Commands,
    FleetDestroyService as Destroy, FleetGroupAttackService as GroupAttack, FleetEventScheduler, FleetsData};
use SPGame\Game\Space\{Coordinate, ObjectType};

function specialFixture(bool $moon = false): array
{
    $f = attackFixture();
    (new ReflectionProperty(\SPGame\Game\Repositories\Config::class, 'syncTable'))->setValue(null, transportSyncTable(3));
    foreach ([Groups::class, Friends::class, Queues::class] as $class) {
        $indexes = [];
        foreach ((new ReflectionProperty($class, 'indexes'))->getValue() as $key => $_) $indexes[$key] = transportTable();
        installTransportRepository($class, transportTable(), transportSyncTable(), $indexes);
    }
    Vars::$attributes[204]['missions'][] = Mission::GROUP_ATTACK->value;
    Vars::$attributes[214] = Vars::$attributes[204];
    Vars::$resource[214] = 'dearth_star';
    Vars::$pricelist[214] = [901 => 5000000, 902 => 4000000];
    Vars::$CombatCaps[214] = ['attack' => 200000, 'shield' => 10000];
    Vars::$reslist['fleet'][] = 214;
    $columns = [];
    foreach (['count' => 0, 'damage' => 0.0, 'morale' => 1.0, 'status' => 0, 'experience' => 0.0] as $suffix => $default) {
        $columns['dearth_star_' . $suffix] = ['swoole' => [is_int($default) ? \Swoole\Table::TYPE_INT : \Swoole\Table::TYPE_FLOAT], 'default' => $default];
    }
    appendTransportSchemaColumns(FleetsShips::class, $columns);
    FleetsShips::update(FleetsShips::castRowToSchema(FleetsShips::findExistingById(1), true));
    $f['fleet']->reloadFromRuntime();
    if ($moon) {
        $target = Planets::castRowToSchema(array_replace($f['target'], ['id' => 12, 'planet_type' => 3, 'size' => 6000]), true);
        Planets::add($target);
        foreach ([Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $class) {
            $row = $class::findExistingById(11);
            $row['id'] = 12;
            $class::add($row);
        }
        $f['target'] = $target;
        $f['coordinates']['objectType'] = 3;
        $f['fleet']->setShipCount(214, 25);
        $f['fleet']->save();
    }
    $GLOBALS['specialAccounts'] = [100 => $f['account']];
    return $f;
}

function specialStart(array $f, Mission $mission, ?array $group = null, ?\SPGame\Game\Fleet\Fleet $fleet = null,
    ?AccountData $owner = null, float $time = 100): void
{
    $fleet ??= $f['fleet']; $owner ??= $f['account'];
    $travel = $fleet->getTravelInfo(\SPGame\Game\Fleet\FleetTarget::fromCoordinates($f['coordinates']), $owner, 10, $mission);
    Commands::startMission($fleet, $owner, $mission->value, $f['coordinates'], 10, ceil($travel['fuel']['max']), [], $time, null, $group);
}

function specialDue(float $now): void
{
    (new FleetEventScheduler())->process($now, static function(float $now): ?array {
        foreach (FleetCommands::getPendingTimeCommands($now) as $row) if (!Attack::mustWait($row)) return $row;
        return null;
    }, static function(array $row): bool {
        $fleet = FleetsData::getInstance()->get((int)$row['fleet_id']);
        $user = Users::findExistingById($fleet->getOwnerId());
        return Commands::completeCommand($row, $fleet, $GLOBALS['specialAccounts'][$fleet->getOwnerId()] ?? new AccountData((int)$user['account_id']));
    });
}

function specialSeed(array $state, bool $fleetDestroyed): string
{
    for ($i = 0; $i < 1000; ++$i) {
        $seed = 'special-' . $i;
        $outcome = Destroy::outcome(6000, 25, true, hash_hmac('sha256', 'destroy:1', $seed));
        if ($outcome['moonDestroyed'] && $outcome['fleetDestroyed'] === $fleetDestroyed) return $seed;
    }
    throw new RuntimeException('Не найден проверочный seed.');
}

$cases = [];
$cases['DESTROY Nova formula, independent risks, determinism and victory requirement'] = static function(): void {
    $r = Destroy::outcome(9000, 1, true, 'a');
    transportAssert($r['moonChance'] === 5.1 && $r['fleetChance'] === 47.0, 'Формулы Nova.');
    transportAssert($r === Destroy::outcome(9000, 1, true, 'a'), 'Повтор не перебрасывает риски.');
    transportAssert(!Destroy::outcome(6000, 25, false, 'a')['attempted'], 'Ничья/поражение не дают попытки.');
    transportAssert(!Destroy::outcome(6000, 0, true, 'a')['attempted'], 'Нужны уцелевшие ЗС.');
    transportAssert(Destroy::outcome(10001, 100000, true, 'a')['moonChance'] === 0.0, 'Слишком крупная луна не уничтожается.');
};
$cases['DESTROY rejects planet, own moon, absent death stars and absent target without mutations'] = static function(): void {
    $f = specialFixture(true);
    foreach (['planet', 'own', 'ships', 'missing'] as $mode) {
        $f = specialFixture(true);
        if ($mode === 'planet') $f['coordinates']['objectType'] = 1;
        if ($mode === 'own') Planets::update(['id' => 12, 'owner_id' => 100]);
        if ($mode === 'ships') { $f['fleet']->setShipCount(214, 0); $f['fleet']->save(); }
        if ($mode === 'missing') Planets::delete(['id' => 12]);
        $before = [Fleets::getAll(), PlanetResources::getAll(), FleetCommands::getAll()];
        try { specialStart($f, Mission::DESTROY); throw new RuntimeException('Принята запрещённая миссия: ' . $mode); }
        catch (InvalidArgumentException|LogicException) {}
        transportAssert($before === [Fleets::getAll(), PlanetResources::getAll(), FleetCommands::getAll()], 'Отказ без мутаций: ' . $mode);
    }
};
$cases['DESTROY full battle, moon deletion, current planet fallback, base/queue retarget and return'] = static function(): void {
    $f = specialFixture(true);
    Users::update(['id' => 200, 'current_planet' => 12]);
    $other = attackClone($f, 3, 200);
    $other->setHomePlanetId(12);
    $other->setCurrentCoordinate(Coordinate::object(1, 10, 8, ObjectType::PLANET), 100);
    $other->save();
    Queues::add(Queues::castRowToSchema(['id' => 1, 'user_id' => 200, 'planet_id' => 12, 'type' => 'building'], true));
    Queues::add(Queues::castRowToSchema(['id' => 2, 'user_id' => 200, 'planet_id' => 12, 'type' => \SPGame\Game\Services\QueuesServices::TECHS], true));
    specialStart($f, Mission::DESTROY); attackArrive($f);
    $now = attackCalculate(12);
    $state = \SPGame\Game\Repositories\PlanetBattles::state(12);
    $state['seed'] = specialSeed($state, false);
    \SPGame\Game\Repositories\PlanetBattles::store(12, $state);
    Attack::process($now);
    $state = \SPGame\Game\Repositories\PlanetBattles::state(12);
    transportAssert($state['phase'] === 'applied' && Planets::findExistingById(12) === null, 'Луна удалена после боя.');
    transportAssert(Users::findExistingById(200)['current_planet'] === 11, 'Клиент переведён на родительскую планету.');
    transportAssert(Fleets::findExistingById(3)['home_planet_id'] === 11, 'База флота перенесена.');
    transportAssert(Queues::findExistingById(1) === null && Queues::findExistingById(2)['planet_id'] === 11, 'Локальные очереди удалены; исследование сохранено.');
    transportAssert($f['fleet']->isReturn(), 'Выживший флот возвращается.');
    $report = BattleReportsMemory::$rows[$state['id']]['report'];
    transportAssert($report['destruction'][0]['moonDestroyed'] && !$report['destruction'][0]['fleetDestroyed'], 'Доклад содержит оба исхода.');
    foreach ([Planets::class, Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $class) {
        transportAssert(in_array(12, $class::getPendingSync()['delete'], true), 'DELETE marker: ' . $class);
    }
    $before = [Planets::getAll(), Fleets::getAll(), Messages::getAll()];
    Attack::process($now + .1);
    transportAssert($before === [Planets::getAll(), Fleets::getAll(), Messages::getAll()], 'Повтор идемпотентен.');
    specialDue($now + 100000);
    transportAssert($f['fleet']->getStatus() === 0, 'Вернулся на базу.');
};
$cases['DESTROY may destroy the moon and the attacking fleet independently'] = static function(): void {
    $f = specialFixture(true); specialStart($f, Mission::DESTROY); attackArrive($f);
    $now = attackCalculate(12); $state = \SPGame\Game\Repositories\PlanetBattles::state(12);
    $state['seed'] = specialSeed($state, true); \SPGame\Game\Repositories\PlanetBattles::store(12, $state);
    Attack::process($now);
    transportAssert(Planets::findExistingById(12) === null && Fleets::findExistingById(1) === null, 'Оба независимых исхода применены.');
    transportAssert(FleetCommands::getFleetQueue(1) === [], 'Погибший флот не возвращается.');
};
$cases['DESTROY failure after deletion restores moon and DELETE markers; retry is stable'] = static function(): void {
    $f = specialFixture(true); specialStart($f, Mission::DESTROY); attackArrive($f);
    $now = attackCalculate(12); $state = \SPGame\Game\Repositories\PlanetBattles::state(12);
    $state['seed'] = specialSeed($state, false); \SPGame\Game\Repositories\PlanetBattles::store(12, $state);
    $before = [Planets::getAll(), Builds::getAll(), Ships::getAll(), PlanetResources::getAll(), Fleets::getAll()];
    $table = (new ReflectionProperty(Messages::class, 'table'))->getValue();
    $table->failedWrites = 1;
    Attack::process($now);
    transportAssert($before === [Planets::getAll(), Builds::getAll(), Ships::getAll(), PlanetResources::getAll(), Fleets::getAll()], 'Полный rollback удаления.');
    foreach ([Planets::class, Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $class) {
        transportAssert(!in_array(12, $class::getPendingSync()['delete'], true), 'После rollback не остался DELETE marker: ' . $class);
    }
    Attack::process($now + .1);
    transportAssert(Planets::findExistingById(12) === null && \SPGame\Game\Repositories\PlanetBattles::state(12)['phase'] === 'applied', 'Повтор завершился.');
};
$cases['GROUP_ATTACK two own fleets arrive simultaneously and enter round one including empty defense'] = static function(): void {
    foreach ([false, true] as $empty) {
        $f = specialFixture(); $second = attackClone($f, 2);
        if ($empty) { Ships::update(['id' => 11, 'small_cargo' => 0, 'spy_sonde' => 0]); Defenses::update(['id' => 11, 'missile_launcher' => 0]); }
        specialStart($f, Mission::GROUP_ATTACK, []);
        $row = array_values(Groups::rows())[0];
        specialStart($f, Mission::GROUP_ATTACK, ['id' => $row['id']], $second, null, 101);
        foreach ([1, 2] as $id) {
            $move = FleetCommands::getActiveCommand($id);
            transportAssert(abs($move['execute_time'] - $row['execute_time']) < .000002, 'Единое время прибытия.');
            $p = json_decode($move['params'], true);
            transportAssert($p['speedFactor'] >= 1 && $p['speedFactor'] <= 10, 'Допустимая дробная скорость.');
        }
        specialDue($row['execute_time']);
        $state = \SPGame\Game\Repositories\PlanetBattles::state(11);
        transportAssert(count(array_filter($state['mission']['participants'], static fn($p) => $p['side'] === 'attacker')) === 2, 'Оба флота включены до первого раунда.');
        $now = attackCalculate(); Attack::process($now);
        transportAssert(\SPGame\Game\Repositories\PlanetBattles::state(11)['phase'] === 'applied', 'Групповой бой завершён.');
        $loot = \SPGame\Game\Repositories\PlanetBattles::state(11)['resolution']['loot'];
        foreach ([901, 902, 903] as $id) transportAssert(abs($f['fleet']->getResources()[$id]->getCount()
            + $second->getResources()[$id]->getCount() - $loot[$id]) < .00001, 'Общая добыча не дублируется.');
        specialDue($now + 100000);
        transportAssert($f['fleet']->getStatus() === 0 && $second->getStatus() === 0, 'Оба вернулись.');
    }
};
$cases['GROUP_ATTACK invalid deadlines, target and outsider are rejected before mutations'] = static function(): void {
    $f = specialFixture(); specialStart($f, Mission::GROUP_ATTACK, []);
    $row = array_values(Groups::rows())[0]; $second = attackClone($f, 2);
    foreach ([['executeAt' => 101], ['executeAt' => 100000000], ['id' => 999], ['id' => '1']] as $options) {
        $before = [Fleets::getAll(), Groups::rows(), PlanetResources::getAll(), FleetCommands::getAll()];
        try { specialStart($f, Mission::GROUP_ATTACK, $options, $second, null, 101); throw new RuntimeException('Некорректный запрос принят.'); }
        catch (InvalidArgumentException|LogicException) {}
        transportAssert($before === [Fleets::getAll(), Groups::rows(), PlanetResources::getAll(), FleetCommands::getAll()], 'Отказ без side effects.');
    }
};

$cases['GROUP_ATTACK invitation by name allows a non-friend into round one; outsider cannot join'] = static function(): void {
    $f = specialFixture();
    $user = Users::findExistingById(100); $user['id'] = 300; $user['account_id'] = 3000; $user['name'] = 'Friend';
    Users::add($user);
    $account = Accounts::findExistingById(1000); $account['id'] = 3000; Accounts::add($account);
    $tech = Techs::findExistingById(100); $tech['id'] = 300; Techs::add($tech);
    $source = Planets::findExistingById(10); $source['id'] = 14; $source['owner_id'] = 300;
    $source['planet'] = 9; Planets::add($source);
    $orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [1, 10, 8]);
    $orbit['id'] = 99; $orbit['orbit'] = 9; GalaxyOrbits::add($orbit);
    Users::update(['id' => 300, 'main_planet' => 14, 'current_planet' => 14]);
    $friendOwner = new \TransportAccountData(300, 14, [14 => [901 => ['count' => 1000000], 902 => ['count' => 1000000], 903 => ['count' => 1000000]]], [14 => $source], 3000);
    $friendOwner['Techs']['computer_tech'] = 5;
    $GLOBALS['specialAccounts'][300] = $friendOwner;
    $friendFleet = attackClone($f, 3, 300);
    $friendFleet->setHomePlanetId(14); $friendFleet->setCurrentCoordinate(Coordinate::object(1,10,9,ObjectType::PLANET),100); $friendFleet->save();
    specialStart($f, Mission::GROUP_ATTACK, []);
    $group = array_values(Groups::rows())[0];
    try { specialStart($f, Mission::GROUP_ATTACK, ['id' => $group['id']], $friendFleet, $friendOwner, 101); throw new RuntimeException('Чужой игрок присоединился без приглашения.'); }
    catch (InvalidArgumentException) {}
    Commands::recall($f['fleet'], $f['account'], 101);
    specialDue(100000);
    transportAssert(!Friends::areFriends(100, 300), 'Игроки не обязаны быть друзьями.');
    $leader = attackClone($f, 2);
    specialStart($f, Mission::GROUP_ATTACK, ['usernames' => [' friend ']], $leader, null, 200);
    $row = Groups::findExistingById(2);
    specialStart($f, Mission::GROUP_ATTACK, ['id' => 2], $friendFleet, $friendOwner, 201);
    transportAssert(GroupAttack::listFor(400, 100) === [], 'Нет утечки чужих групп.');
    transportAssert(count(GroupAttack::listFor(300, 100)) === 1, 'Приглашённый видит свою группу.');
    specialDue($row['execute_time']);
    $state = \SPGame\Game\Repositories\PlanetBattles::state(11);
    transportAssert(isset($state['mission']['participants'][3])
        && $state['mission']['participants'][3]['side'] === 'attacker', 'Приглашённый игрок вошёл в атакующую сторону.');
};

$cases['GROUP_ATTACK runtime failure rolls back membership and resources; retry succeeds'] = static function(): void {
    $f = specialFixture();
    $before = [Fleets::getAll(), PlanetResources::getAll()];
    $table = (new ReflectionProperty(Groups::class, 'table'))->getValue(); $table->failedWrites = 1;
    try { specialStart($f, Mission::GROUP_ATTACK, []); throw new RuntimeException('Failure injection не сработал.'); }
    catch (RuntimeException $error) { transportAssert(str_contains($error->getMessage(), 'runtime'), 'Ожидаемая ошибка записи.'); }
    transportAssert($before === [Fleets::getAll(), PlanetResources::getAll()] && Groups::rows() === []
        && FleetCommands::getFleetQueue(1) === [], 'Не осталось ресурсов/членства/команд от failed start.');
    specialStart($f, Mission::GROUP_ATTACK, []);
    transportAssert(count(Groups::rows()) === 1, 'Повтор стартует.');
};

$cases['GROUP_ATTACK fleet limit and recall release only the recalled member'] = static function(): void {
    $f = specialFixture(); $second = attackClone($f, 2); $third = attackClone($f, 3);
    \SPGame\Game\Repositories\Config::add(['name' => 'GroupAttackMaxFleets', 'value' => '2']);
    specialStart($f, Mission::GROUP_ATTACK, []);
    $row = array_values(Groups::rows())[0];
    specialStart($f, Mission::GROUP_ATTACK, ['id' => $row['id']], $second, null, 101);
    $before = Groups::rows();
    try { specialStart($f, Mission::GROUP_ATTACK, ['id' => $row['id']], $third, null, 102); throw new RuntimeException('Превышен лимит.'); }
    catch (LogicException) {}
    transportAssert(Groups::rows() === $before && FleetCommands::getFleetQueue(3) === [], 'Лишний флот не добавлен.');
    Commands::recall($f['fleet'], $f['account'], 103);
    transportAssert(array_keys(GroupAttack::members(Groups::findExistingById($row['id']))) === [2], 'Отзыв не отменил чужой флот.');
    specialStart($f, Mission::GROUP_ATTACK, ['id' => $row['id']], $third, null, 104);
    transportAssert(count(GroupAttack::members(Groups::findExistingById($row['id']))) === 2, 'Место после отзыва освобождено.');
};

$cases['GROUP_ATTACK inter-system random entry keeps one exact arrival and persists per-leg factors'] = static function(): void {
    $f = specialFixture();
    \SPGame\Game\Repositories\Galaxy::add(\SPGame\Game\Repositories\Galaxy::castRowToSchema(
        ['id' => 1, 'galaxy' => 1, 'system' => 10, 'star_type' => 'G', 'max_distance' => 3000, 'max_orbits' => 8], true));
    \SPGame\Game\Repositories\Galaxy::add(\SPGame\Game\Repositories\Galaxy::castRowToSchema(
        ['id' => 2, 'galaxy' => 1, 'system' => 11, 'star_type' => 'G', 'max_distance' => 3000, 'max_orbits' => 8], true));
    foreach (GalaxyOrbits::getAll() as $row) {
        if ((int)$row['system'] !== 10) continue;
        $row['id'] += 1000; $row['system'] = 11; GalaxyOrbits::add($row);
    }
    Planets::update(['id' => 11, 'system' => 11]); $f['coordinates']['system'] = 11;
    specialStart($f, Mission::GROUP_ATTACK, []);
    $group = array_values(Groups::rows())[0]; $factors = [];
    for ($i = 0; $i < 3; ++$i) {
        $move = FleetCommands::getActiveCommand(1); $params = json_decode($move['params'], true);
        $factors[] = $params['speedFactor'];
        transportAssert($params['speedFactor'] >= 1 && $params['speedFactor'] <= 10 && $params['fuelCost'] >= 0, 'Валидные скорость и топливо сегмента.');
        if ($i === 1) transportAssert(isset($params['transitionObject']['angle']), 'Угол входа материализован при активации перехода.');
        if ($i === 2) transportAssert(abs($move['execute_time'] - $group['execute_time']) < .000002, 'Точное общее прибытие после случайного входа.');
        Commands::completeCommand($move, $f['fleet'], $f['account']);
    }
    transportAssert(count(array_unique($factors)) > 1, 'Коэффициент пересчитан по участкам.');
};

if ($exportSpecial) {
    $f = specialFixture(in_array('--destroy-target', $argv ?? [], true));
    PlanetResources::add(['id' => 10, 'metal' => 1000000.0, 'crystal' => 1000000.0, 'deuterium' => 1000000.0, 'energy' => 0.0]);
    foreach ([Ships::class, Defenses::class, Builds::class] as $class) {
        if ($class::findExistingById(10) === null) $class::add($class::castRowToSchema(['id' => 10], true));
    }
    $classes = [Accounts::class, Users::class, Planets::class, PlanetResources::class, \SPGame\Game\Repositories\UserResources::class,
        Techs::class, Builds::class, Ships::class, Defenses::class, Fleets::class, FleetsShips::class, FleetCommands::class,
        Messages::class, \SPGame\Game\Repositories\PlanetBattles::class, \SPGame\Game\Repositories\Config::class,
        GalaxyOrbits::class, \SPGame\Game\Repositories\Galaxy::class, \SPGame\Game\Repositories\PlayerQueue::class,
        \SPGame\Game\Repositories\DebrisFields::class, Groups::class, Friends::class, Queues::class];
    $fixture = ['vars' => [], 'repositories' => [], 'specialScenario' => in_array('--destroy-target', $argv ?? [], true) ? 'destroy' : 'group'];
    foreach ((new ReflectionClass(Vars::class))->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_STATIC) as $property) {
        if ($property->isStatic() && $property->isInitialized()) $fixture['vars'][$property->getName()] = $property->getValue();
    }
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class); $property = $reflection->getProperty('table');
        if (!$property->isInitialized()) continue;
        $rows = iterator_to_array($property->getValue());
        $schema = $reflection->getProperty('tableSchema')->getValue();
        foreach ($schema['columns'] as &$column) $column['nativeKind'] = match ($column['swoole'][0]) {
            \Swoole\Table::TYPE_INT => 'int', \Swoole\Table::TYPE_FLOAT => 'float', default => 'string' };
        unset($column);
        foreach ($rows as $row) foreach ($row as $name => $value) if (!isset($schema['columns'][$name])) {
            $kind = is_int($value) ? 'int' : (is_float($value) ? 'float' : 'string');
            $schema['columns'][$name] = ['nativeKind' => $kind, 'swoole' => [1, $kind === 'string' ? 8192 : 8], 'default' => $kind === 'string' ? '' : 0];
        }
        $indexes = [];
        foreach ($reflection->getProperty('indexTables')->getValue() as $name => $index) $indexes[$name] = iterator_to_array($index);
        $sync = $reflection->getProperty('syncTable');
        $fixture['repositories'][$class] = ['schema' => $schema, 'rows' => $rows, 'indexes' => $indexes,
            'sync' => $sync->isInitialized() ? iterator_to_array($sync->getValue()) : []];
    }
    ob_end_clean(); echo json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); return;
}

foreach ($cases as $name => $test) { $test(); echo "OK special {$name}\n"; }
echo 'RESULT ' . count($cases) . " special cases passed\n";
