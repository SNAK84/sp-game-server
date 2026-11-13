<?php

declare(strict_types=1);

/**
 * php tests/FleetAttackTest.php --export-fixture |
 * docker exec -i swoole_app php /var/www/html/tests/FleetAttackSwooleTest.php
 * Реальные Tables и TaskWorker, loopback Server, без MySQL/живых аккаунтов.
 */
require __DIR__ . '/bootstrap.php';
$mysqlArchive = in_array('--mysql-archive', $argv ?? [], true);
$moonMode = in_array('--moon', $argv ?? [], true);
if (!$mysqlArchive) require __DIR__ . '/fixtures/BattleReportsMemory.php';

use SPGame\Game\Repositories\{FleetCommands, Fleets, Messages, PlanetBattles, PlanetResources, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService as Attack, FleetBattleService as Battles,
    FleetCommandsService as Commands, FleetsData};
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\FleetTarget;
use Swoole\{Server, Table, Timer};

/** Настоящий saver пишет только временные SQL-таблицы, игровые таблицы не изменяются. */
function verifyMoonMysql(array $moon): void
{
    $db = \SPGame\Core\Database::getInstance();
    $saver = new \SPGame\Game\Services\RepositorySaver();
    $tables = [];
    try {
        foreach ([\SPGame\Game\Repositories\Planets::class, \SPGame\Game\Repositories\Builds::class,
            \SPGame\Game\Repositories\Ships::class, \SPGame\Game\Repositories\Defenses::class,
            PlanetResources::class, \SPGame\Game\Repositories\DebrisFields::class] as $class) {
            $property = new ReflectionProperty($class, 'tableName');
            $source = $property->getValue();
            $name = 'qa_moon_' . bin2hex(random_bytes(6));
            $db->query("CREATE TEMPORARY TABLE `$name` LIKE `$source`");
            $tables[$class] = ['name' => $name, 'source' => $source];
            $property->setValue(null, $name);
            $saver->register($class);
        }
        $saver->saveAll();
        foreach ($tables as $class => $table) {
            $row = $class === \SPGame\Game\Repositories\DebrisFields::class
                ? $class::at(1, 10, 8) : $class::findExistingById($moon['id']);
            $saved = $db->fetchOne('SELECT * FROM `' . $table['name'] . '` WHERE id=?', [$row['id']]);
            if ($saved === null || in_array($row['id'], $class::getPendingSync()['update'], true)) {
                throw new RuntimeException('Saver не сохранил луну: ' . $class);
            }
            $restored = $class::castRowToSchema($saved, true);
            foreach ($row as $key => $value) {
                if (is_float($value) ? abs($restored[$key] - $value) > .000002 : $restored[$key] !== $value) {
                    throw new RuntimeException('MySQL reload отличается: ' . $class . '.' . $key);
                }
            }
        }
        echo "OK moon Swoole → RepositorySaver → isolated MySQL → decoded rows; debris remains zero\n";
    } finally {
        foreach ($tables as $class => $table) {
            (new ReflectionProperty($class, 'tableName'))->setValue(null, $table['source']);
            $db->query('DROP TEMPORARY TABLE IF EXISTS `' . $table['name'] . '`');
        }
    }
}

if (!extension_loaded('swoole')) exit(77);
$archiveTables = ['qa_native_reports_' . bin2hex(random_bytes(6)), 'qa_native_saves_' . bin2hex(random_bytes(6))];
if ($mysqlArchive) {
    (new ReflectionProperty(\SPGame\Game\Repositories\BattleReports::class, 'reportsTable'))->setValue(null, $archiveTables[0]);
    (new ReflectionProperty(\SPGame\Game\Repositories\BattleReports::class, 'savesTable'))->setValue(null, $archiveTables[1]);
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
class_exists(\SPGame\Game\Repositories\Resources::class);
foreach ($fixture['vars'] as $key => $value) (new ReflectionProperty(Vars::class, $key))->setValue(null, $value);
(new ReflectionProperty(\SPGame\Game\Repositories\BaseRepository::class, 'logger'))->setValue(null, \SPGame\Core\Logger::getInstance());
foreach ($fixture['repositories'] as $class => $data) {
    $reflection = new ReflectionClass($class);
    $table = new Table(128);
    $schema = $data['schema'];
    foreach ($schema['columns'] as $name => &$column) {
        $column['swoole'][0] = match ($column['nativeKind']) { 'int' => Table::TYPE_INT, 'float' => Table::TYPE_FLOAT, default => Table::TYPE_STRING };
        unset($column['nativeKind']);
        $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 8);
    }
    unset($column);
    $table->create();
    foreach ($data['rows'] as $key => $row) {
        if (!$table->set((string)$key, $row)) throw new RuntimeException('Не установлена fixture ' . $class);
    }
    $reflection->getProperty('tableSchema')->setValue(null, $schema);
    $reflection->getProperty('table')->setValue(null, $table);
    $sync = new Table(256);
    foreach (['need_update', 'need_delete', 'last_id'] as $column) $sync->column($column, Table::TYPE_INT, 8);
    $sync->create();
    foreach ($data['sync'] as $key => $row) $sync->set((string)$key, $row);
    $reflection->getProperty('syncTable')->setValue(null, $sync);
    $indexes = [];
    foreach ($data['indexes'] as $name => $rows) {
        $index = new Table(128);
        $index->column('id', Table::TYPE_INT, 8);
        $index->column('ids', Table::TYPE_STRING, 16384);
        $index->create();
        foreach ($rows as $key => $row) $index->set((string)$key, $row);
        $indexes[$name] = $index;
    }
    $reflection->getProperty('indexTables')->setValue(null, $indexes);
}
$evidence = new Table(32);
$evidence->column('pid', Table::TYPE_INT, 8);
$evidence->create();
$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'task_worker_num' => 2, 'task_enable_coroutine' => false, 'log_level' => SWOOLE_LOG_WARNING]);
Battles::initializeWorkers(static fn($task): bool => $server->task($task) !== false);
$server->on('Receive', static function (): void {});
$server->on('Task', static function (Server $server, int $taskId, int $workerId, array $task) use ($evidence): void {
    $evidence->set($task['token'], ['pid' => getmypid()]);
    Battles::executeTask($task);
});
$server->on('Finish', static function (): void {});
$failed = false;
$server->on('Start', static function (Server $server) use ($evidence, &$failed, $mysqlArchive, $moonMode): void {
    try {
        if ($mysqlArchive) {
            \SPGame\Core\Environment::init();
            \SPGame\Game\Repositories\BattleReports::init();
        }
        if ($moonMode) {
            // setValue() пишет также в MySQL: fixture меняем только через runtime API.
            foreach (['MoonChance' => '100', 'MoonFactor' => '100000'] as $name => $value) {
                $row = \SPGame\Game\Repositories\Config::findByIndex('name', $name);
                if ($row === null) {
                    \SPGame\Game\Repositories\Config::add(['name' => $name, 'value' => $value]);
                } else {
                    \SPGame\Game\Repositories\Config::update(['id' => $row['id'], 'value' => $value]);
                }
            }
        }
        $fleet = FleetsData::getInstance()->get(1);
        $account = new AccountData(1000);
        $coordinate = \SPGame\Game\Space\Coordinate::object(1, 10, 8, \SPGame\Game\Space\ObjectType::PLANET)->toArray();
        $travel = $fleet->getTravelInfo(FleetTarget::fromCoordinates($coordinate), $account, 10, FleetMissionType::ATTACK);
        Commands::startMission($fleet, $account, FleetMissionType::ATTACK->value, $coordinate, 10, ceil($travel['fuel']['max']), [], 100);
        // Виртуальное время: не ждём реальных минут перелёта тестового флота.
        Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $account);
        $clock = PlanetBattles::state(11)['startedAt'];
        $pid = getmypid();
        Timer::tick(200, static function () use ($server, $fleet, $account, $evidence, $pid, &$clock, &$failed, $mysqlArchive, $moonMode): void {
            try {
                $clock += .5;
                Battles::tick($clock);
                Attack::process($clock);
                $state = PlanetBattles::state(11);
                if ($state['phase'] === 'failed') throw new RuntimeException('Бой оказался failed.');
                if ($state['phase'] !== 'applied') return;
                $resources = PlanetResources::findExistingById(11);
                if ($resources['metal'] !== 50.0 || $resources['crystal'] !== 100.0 || $resources['deuterium'] !== 150.0) {
                    throw new RuntimeException('Не согласованы ресурсы после боя.');
                }
                $reports = array_filter(Messages::getAll(), static fn($m) => $m['sample'] === 'FleetBattleReport');
                if (count($reports) !== 2 || $evidence->count() === 0) throw new RuntimeException('Нет докладов или задания TaskWorker.');
                if ($mysqlArchive) {
                    $archive = \SPGame\Game\Repositories\BattleReports::get($state['id'], 999999, $clock);
                    if ($archive === null || count($archive['report']['rounds']) !== count($state['combat']['rounds'])) {
                        throw new RuntimeException('Нет настоящего MySQL архива после runtime commit.');
                    }
                    echo "OK native ATTACK stores real MySQL report readable by nonparticipant\n";
                }
                foreach ($evidence as $entry) if ($entry['pid'] === $pid) throw new RuntimeException('Расчёт не вынесен в процесс.');
                for ($i = 0; $i < 10 && FleetCommands::getActiveCommand(1) !== null; ++$i) {
                    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $account);
                }
                if ($fleet->getStatus() !== 0 || FleetCommands::getFleetQueue(1) !== []) throw new RuntimeException('Не завершён возврат.');
                if ($moonMode) {
                    $moon = \SPGame\Game\Repositories\Planets::findByIndex('galaxy_system_planet_type', [1, 10, 8, 3]);
                    if ($moon === null || $moon['owner_id'] !== 200 || !$state['resolution']['moon']['created']) {
                        throw new RuntimeException('Native commit не создал луну.');
                    }
                    foreach ([\SPGame\Game\Repositories\Planets::class, \SPGame\Game\Repositories\Builds::class,
                        \SPGame\Game\Repositories\Ships::class, \SPGame\Game\Repositories\Defenses::class, PlanetResources::class] as $class) {
                        if ($class::findExistingById($moon['id']) === null || !in_array($moon['id'], $class::getPendingSync()['update'], true)) {
                            throw new RuntimeException('Не сохранены native данные луны: ' . $class);
                        }
                    }
                    echo "OK native moon: owner, planet/builds/ships/defense/resources, dirty markers and report\n";
                    if ($mysqlArchive) verifyMoonMysql($moon);
                }
                echo "OK native ATTACK: LOAD → MOVE → TaskWorker battle → loot/XP/debris/reports → return → UNLOAD → STAY\n";
                $evidence->set('status', ['pid' => 1]);
                Timer::clearAll();
                $server->shutdown();
            } catch (Throwable $error) {
                $failed = true;
                fwrite(STDERR, 'FAIL native ATTACK: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
                Timer::clearAll();
                $server->shutdown();
            }
        });
        Timer::after(15000, static function () use ($server, &$failed): void {
            $failed = true;
            fwrite(STDERR, "FAIL native ATTACK: timeout\n");
            Timer::clearAll();
            $server->shutdown();
        });
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, 'FAIL native ATTACK start: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
        $server->shutdown();
    }
});
try {
    $server->start();
} finally {
    if ($mysqlArchive) {
        \SPGame\Core\Environment::init();
        $db = \SPGame\Core\Database::getInstance();
        // Удаляются только две собственные таблицы этого isolated запуска.
        $db->query('DROP TABLE IF EXISTS `' . $archiveTables[1] . '`');
        $db->query('DROP TABLE IF EXISTS `' . $archiveTables[0] . '`');
    }
}
exit(($evidence->get('status')['pid'] ?? 0) === 1 ? 0 : 1);
