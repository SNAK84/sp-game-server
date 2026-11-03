<?php

declare(strict_types=1);

/**
 * php tests/FleetSpecialMissionsTest.php --export-special [--destroy-target] |
 * docker exec -i swoole_app php /var/www/html/tests/FleetSpecialMissionsSwooleTest.php [--mysql]
 * Только отдельные runtime Tables, loopback TaskWorkers и TEMPORARY SQL-таблицы.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/BattleReportsMemory.php';

use SPGame\Game\Repositories\{BaseRepository, FleetAttackGroups as Groups, FleetCommands, Fleets, FleetsShips,
    PlanetBattles, PlanetResources, Planets, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService as Attack, FleetBattleService as Battles,
    FleetCommandsService as Commands, FleetDestroyService as Destroy, FleetEventScheduler, FleetsData};
use SPGame\Game\Enums\FleetMissionType as Mission;
use SPGame\Game\Fleet\FleetTarget;
use Swoole\{Server, Table, Timer};

if (!extension_loaded('swoole')) exit(77);
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixture['vars'] as $key => $value) (new ReflectionProperty(Vars::class, $key))->setValue(null, $value);
class_exists(\SPGame\Game\Repositories\Resources::class);
(new ReflectionProperty(BaseRepository::class, 'logger'))->setValue(null, \SPGame\Core\Logger::getInstance());
foreach ($fixture['repositories'] as $class => $data) {
    $reflection = new ReflectionClass($class); $table = new Table(128); $schema = $data['schema'];
    foreach ($schema['columns'] as $name => &$column) {
        $column['swoole'][0] = match ($column['nativeKind']) { 'int' => Table::TYPE_INT, 'float' => Table::TYPE_FLOAT, default => Table::TYPE_STRING };
        unset($column['nativeKind']);
        $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 8);
    }
    unset($column); $table->create();
    foreach ($data['rows'] as $key => $row) if (!$table->set((string)$key, $row)) throw new RuntimeException('Не загружена fixture.');
    $reflection->getProperty('tableSchema')->setValue(null, $schema);
    $reflection->getProperty('table')->setValue(null, $table);
    $sync = new Table(256);
    foreach (['need_update', 'need_delete', 'last_id'] as $field) $sync->column($field, Table::TYPE_INT, 8);
    $sync->create(); foreach ($data['sync'] as $key => $row) $sync->set((string)$key, $row);
    $reflection->getProperty('syncTable')->setValue(null, $sync);
    $indexes = [];
    foreach ($data['indexes'] as $name => $rows) {
        $index = new Table(128); $index->column('id', Table::TYPE_INT, 8); $index->column('ids', Table::TYPE_STRING, 16384); $index->create();
        foreach ($rows as $key => $row) $index->set((string)$key, $row);
        $indexes[$name] = $index;
    }
    $reflection->getProperty('indexTables')->setValue(null, $indexes);
}
$evidence = new Table(32); $evidence->column('pid', Table::TYPE_INT, 8); $evidence->create();
$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'task_worker_num' => 2, 'task_enable_coroutine' => false, 'log_level' => SWOOLE_LOG_WARNING]);
Battles::initializeWorkers(static fn($task): bool => $server->task($task) !== false);
$server->on('Receive', static function(): void {});
$server->on('Task', static function(Server $server, int $taskId, int $workerId, array $task) use ($evidence): void {
    $evidence->set($task['token'], ['pid' => getmypid()]); Battles::executeTask($task);
});
$server->on('Finish', static function(): void {});

function nativeSpecialDue(float $time, AccountData $owner): void
{
    (new FleetEventScheduler())->process($time, static function(float $time): ?array {
        foreach (FleetCommands::getPendingTimeCommands($time) as $row) if (!Attack::mustWait($row)) return $row;
        return null;
    }, static fn($row): bool => Commands::completeCommand($row, FleetsData::getInstance()->get((int)$row['fleet_id']), $owner));
}

function nativeSpecialMysql(?array $moonBefore): void
{
    \SPGame\Core\Environment::init(); $db = \SPGame\Core\Database::getInstance();
    foreach ([Groups::class, Planets::class] as $class) {
        $reflection = new ReflectionClass($class); $property = $reflection->getProperty('tableName');
        $source = $property->getValue(); $name = 'qa_special_' . bin2hex(random_bytes(6));
        $columns = [];
        foreach ($reflection->getProperty('tableSchema')->getValue()['columns'] as $key => $column) $columns[] = '`' . $key . '` ' . $column['sql'];
        $db->query('CREATE TEMPORARY TABLE `' . $name . '` (' . implode(',', $columns) . ',PRIMARY KEY(id))');
        try {
            $property->setValue(null, $name);
            if ($class === Planets::class && $moonBefore !== null) {
                $db->query('INSERT INTO `' . $name . '` (`' . implode('`,`', array_keys($moonBefore)) . '`) VALUES ('
                    . implode(',', array_fill(0, count($moonBefore), '?')) . ')', array_values($moonBefore));
            }
            $class::syncToDatabase();
            if ($class === Groups::class && Groups::rows() !== []) {
                $row = array_values(Groups::rows())[0];
                $saved = $db->fetchOne('SELECT * FROM `' . $name . '` WHERE id=?', [$row['id']]);
                if ($saved === null || $saved['fleets'] !== $row['fleets'] || $saved['users'] !== $row['users']
                    || abs($saved['execute_time'] - $row['execute_time']) > .000001) throw new RuntimeException('Не сохранена группа в MySQL.');
            }
            if ($class === Planets::class && $moonBefore !== null && $db->fetchOne('SELECT id FROM `' . $name . '` WHERE id=?', [$moonBefore['id']]) !== null) {
                throw new RuntimeException('DELETE луны не сохранён в MySQL.');
            }
            if ($class::getPendingSync()['update'] !== [] || $class::getPendingSync()['delete'] !== []) throw new RuntimeException('Saver не очистил markers после успеха.');
        } finally {
            $property->setValue(null, $source); $db->query('DROP TEMPORARY TABLE IF EXISTS `' . $name . '`');
        }
    }
    echo "OK native runtime → saver → isolated MySQL; success markers cleared\n";
}

$server->on('Start', static function(Server $server) use ($fixture, $evidence): void {
    try {
        $destroy = $fixture['specialScenario'] === 'destroy'; $planetId = $destroy ? 12 : 11;
        $moonBefore = $destroy ? Planets::findExistingById(12) : null;
        $fleet = FleetsData::getInstance()->get(1); $owner = new AccountData(1000);
        $coordinate = \SPGame\Game\Space\Coordinate::object(1,10,8, $destroy
            ? \SPGame\Game\Space\ObjectType::MOON : \SPGame\Game\Space\ObjectType::PLANET)->toArray();
        $mission = $destroy ? Mission::DESTROY : Mission::GROUP_ATTACK;
        if (!$destroy) {
            $row = Fleets::findExistingById(1); $row['id'] = 2; Fleets::add($row);
            $row = FleetsShips::findExistingById(1); $row['id'] = $row['fleet_id'] = 2; FleetsShips::add($row);
        }
        $travel = $fleet->getTravelInfo(FleetTarget::fromCoordinates($coordinate), $owner, 10, $mission);
        Commands::startMission($fleet,$owner,$mission->value,$coordinate,10,ceil($travel['fuel']['max']),[],100,null,$destroy?null:[]);
        if ($destroy) {
            Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
            $state = PlanetBattles::state(12);
            for ($i = 0; ; ++$i) {
                $seed = 'native-special-' . $i;
                if (!Destroy::outcome(6000,25,true,hash_hmac('sha256','destroy:1',$seed))['fleetDestroyed']) break;
            }
            $state['seed'] = $seed; PlanetBattles::store(12,$state);
        } else {
            $group = array_values(Groups::rows())[0]; $second = FleetsData::getInstance()->get(2);
            $user = \SPGame\Game\Repositories\Users::findExistingById(100);
            $user['id'] = 300; $user['account_id'] = 3000; $user['name'] = 'Guest';
            \SPGame\Game\Repositories\Users::add($user);
            $invited = \SPGame\Game\Services\FleetGroupAttackService::invite(100, (int)$group['id'], 'guest', 100.5);
            if (json_decode($invited['users'], true) !== [100, 300]
                || count(\SPGame\Game\Repositories\Messages::getAll()) !== 1) {
                throw new RuntimeException('Приглашение без дружбы не сохранено в native Tables.');
            }
            echo "OK native invitation after departure without friendship; recipient message created\n";
            Commands::startMission($second,$owner,$mission->value,$coordinate,10,ceil($travel['fuel']['max']),[],101,null,['id'=>$group['id']]);
            nativeSpecialDue($group['execute_time'], $owner);
            $state = PlanetBattles::state(11);
            if (count($state['mission']['participants']) !== 2) throw new RuntimeException('Участники группы не вошли вместе в бой.');
        }
        $clock = $state['startedAt']; $pid = getmypid();
        Timer::tick(100, static function() use ($server,$fleet,$owner,$evidence,$pid,&$clock,$planetId,$destroy,$moonBefore): void {
            try {
                $clock += .5; Battles::tick($clock); Attack::process($clock); $state = PlanetBattles::state($planetId);
                if ($state['phase'] === 'failed') throw new RuntimeException('Бой остановлен.');
                if ($state['phase'] !== 'applied') return;
                if ($evidence->count() === 0) throw new RuntimeException('Нет расчёта в TaskWorker.');
                foreach ($evidence as $entry) if ($entry['pid'] === $pid) throw new RuntimeException('Расчёт выполнен в игровом процессе.');
                if ($destroy && Planets::findExistingById(12) !== null) throw new RuntimeException('Луна не уничтожена.');
                if (!$destroy) {
                    $loot = $state['resolution']['loot'];
                    foreach ([901,902,903] as $id) {
                        $sum=0; foreach([1,2] as $fid) $sum+=FleetsData::getInstance()->get($fid)->getResources()[$id]->getCount();
                        if (abs($sum-$loot[$id])>.00001) throw new RuntimeException('Добыча задвоена.');
                    }
                }
                nativeSpecialDue($clock+100000,$owner);
                foreach($destroy?[1]:[1,2] as $id) if(FleetsData::getInstance()->get($id)->getStatus()!==0) throw new RuntimeException('Флот не вернулся.');
                if (in_array('--mysql', $GLOBALS['argv']??[], true)) nativeSpecialMysql($moonBefore);
                echo 'OK native '.($destroy?'DESTROY':'GROUP_ATTACK').": LOAD → MOVE → separate TaskWorker → runtime commit/report → return\n";
                $evidence->set('status',['pid'=>1]); Timer::clearAll(); $server->shutdown();
            } catch(Throwable $error) {
                fwrite(STDERR,'FAIL special native: '.$error->getMessage()."\n".$error->getTraceAsString()."\n"); Timer::clearAll(); $server->shutdown();
            }
        });
        Timer::after(15000,static function()use($server):void{fwrite(STDERR,"FAIL special native timeout\n");Timer::clearAll();$server->shutdown();});
    } catch(Throwable $error) { fwrite(STDERR,'FAIL special native start: '.$error->getMessage()."\n".$error->getTraceAsString()."\n"); $server->shutdown(); }
});
$server->start();
exit(($evidence->get('status')['pid']??0)===1?0:1);
