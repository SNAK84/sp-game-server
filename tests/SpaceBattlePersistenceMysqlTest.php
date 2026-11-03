<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Core\{Database, Environment, Logger, LogLevel};
use SPGame\Game\Repositories\{SpaceBattles, SpaceDebrisFields};
use SPGame\Game\Services\{FleetCombatService, RepositorySaver};
use SPGame\Game\Space\Coordinate;

if (!extension_loaded('swoole')) { fwrite(STDERR, "SKIP: нужен native Swoole.\n"); exit(77); }
Environment::init();
$logger = Logger::getInstance();
(new ReflectionProperty($logger, 'islogFile'))->setValue($logger, false);
(new ReflectionProperty($logger, 'minLevel'))->setValue($logger, LogLevel::ERROR);
$db = Database::getInstance();
$saver = new RepositorySaver();
$temporary = [];

function spacePersistenceInit(string $class, string $name, ?RepositorySaver $saver = null): void
{
    // init() без tableName создаёт ТОЛЬКО native Tables, не выполняя DDL.
    // Затем штатный loadAll читает только нашу TEMPORARY таблицу соединения.
    (new ReflectionProperty($class, 'tableName'))->setValue(null, '');
    (new ReflectionProperty($class, 'tableSize'))->setValue(null, 16);
    $class::init($saver);
    (new ReflectionProperty($class, 'tableName'))->setValue(null, $name);
    (new ReflectionMethod($class, 'loadAll'))->invoke(null, $name);
}

function spacePersistenceAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

try {
    $tables = [];
    foreach ([SpaceBattles::class, SpaceDebrisFields::class] as $class) {
        $name = 'qa_space_' . bin2hex(random_bytes(8));
        $schema = (new ReflectionProperty($class, 'tableSchema'))->getValue();
        $ddl = [];
        foreach ($schema['columns'] as $column => $definition) $ddl[] = '`' . $column . '` ' . $definition['sql'];
        foreach ($schema['indexes'] as $index) {
            $fields = '`' . implode('`,`', $index['fields']) . '`';
            $ddl[] = match ($index['type']) {
                'PRIMARY' => 'PRIMARY KEY (' . $fields . ')',
                'UNIQUE' => 'UNIQUE KEY `' . $index['name'] . '` (' . $fields . ')',
                default => 'KEY `' . $index['name'] . '` (' . $fields . ')',
            };
        }
        $db->query('CREATE TEMPORARY TABLE `' . $name . '` (' . implode(',', $ddl) . ') ENGINE=InnoDB');
        $temporary[] = $tables[$class] = $name;
        spacePersistenceInit($class, $name, $saver);
    }
    $group = ['type' => 202, 'count' => 10, 'kind' => 'fleet', 'attack' => 5.0, 'shield' => 1.0,
        'hull' => 400.0, 'unitCost' => 4000.0, 'experience' => 12.5, 'fleetId' => 77];
    $state = ['id' => bin2hex(random_bytes(16)), 'phase' => 'running', 'startedAt' => 1000.0,
        'seed' => bin2hex(random_bytes(32)), 'combat' => FleetCombatService::startBattle(['a' => $group], ['d' => $group]),
        'pending' => [], 'admissions' => [], 'fleets' => [77, 78],
        'job' => ['token' => bin2hex(random_bytes(16)), 'round' => 1, 'submittedAt' => 1001.0], 'attempts' => 1,
        'mission' => ['spaceTarget' => ['name' => 'Перехват', 'coordinate' => Coordinate::point(1, 9, 2345.678901, 32.5)->toArray()]]];
    SpaceBattles::store(78, $state);
    // Имитация rollback нового боя и восстановления того же checkpoint.
    SpaceBattles::discardUncommittedRow(SpaceBattles::findById(78));
    SpaceBattles::store(78, $state);
    spacePersistenceAssert(!in_array(78, SpaceBattles::getPendingSync()['delete'], true), 'Живой повторный бой имеет DELETE marker.');
    $field = SpaceDebrisFields::castRowToSchema(['battle_id' => $state['id'], 'galaxy' => 1, 'system' => 9,
        'distance' => 2345.678901, 'angle' => 32.5, 'metal' => 123.5, 'crystal' => 456.75, 'update_time' => 1000.0], true);
    SpaceDebrisFields::add($field);
    $saver->saveAll();
    foreach ($tables as $class => $name) {
        spacePersistenceAssert($class::getPendingSync()['update'] === [], 'Dirty marker не очищен после SQL success.');
        spacePersistenceInit($class, $name);
    }
    spacePersistenceAssert(SpaceBattles::state(78) === $state, 'Checkpoint/pending job не восстановился из MySQL.');
    $loaded = SpaceDebrisFields::findById((int)$field['id']);
    spacePersistenceAssert($loaded === $field && count(SpaceDebrisFields::atPoint(Coordinate::point(1, 9, 2345.678901, 32.5))) === 1,
        'POINT/ресурсы/индекс обломков не восстановились из MySQL.');
    echo "PASS native → RepositorySaver → TEMPORARY MySQL → reload: battle checkpoint/job and POINT debris\n";
    SpaceDebrisFields::update(['id' => $field['id'], 'metal' => 0.0, 'crystal' => 0.0]);
    $state['phase'] = 'applied'; $state['job'] = null;
    SpaceBattles::store(78, $state);
    $saver->saveAll();
    foreach ($tables as $class => $name) spacePersistenceInit($class, $name);
    spacePersistenceAssert(SpaceBattles::active() === [] && SpaceDebrisFields::atPoint(Coordinate::point(1, 9, 2345.678901, 32.5)) === [],
        'После reload завершённый бой/собранные обломки появились снова.');
    echo "PASS applied battle and empty debris survive reload; no game tables modified\n";
    SpaceBattles::requestCleanup(78, $state['id']);
    spacePersistenceAssert(!SpaceBattles::finishCleanup(78, $state['id']), 'Освобождён слот до SQL DELETE.');
    $name = $tables[SpaceBattles::class];
    // Отказ DELETE только у нашей случайно именованной TEMPORARY таблицы.
    (new ReflectionProperty(SpaceBattles::class, 'tableName'))->setValue(null, $name . '_missing');
    try { $saver->saveAll(); }
    finally { (new ReflectionProperty(SpaceBattles::class, 'tableName'))->setValue(null, $name); }
    spacePersistenceAssert(in_array(78, SpaceBattles::getPendingSync()['delete'], true)
        && !SpaceBattles::finishCleanup(78, $state['id']), 'SQL failure потерял DELETE/освободил runtime слот.');
    spacePersistenceAssert($db->fetchOne('SELECT id FROM `' . $name . '` WHERE id=?', [78]) !== null, 'Отказ DELETE удалил checkpoint.');
    $saver->saveAll();
    spacePersistenceAssert(SpaceBattles::getPendingSync()['delete'] === [] && SpaceBattles::finishCleanup(78, $state['id']), 'SQL retry не освободил слот.');
    spacePersistenceInit(SpaceBattles::class, $name);
    spacePersistenceAssert(SpaceBattles::getAll() === [], 'Checkpoint воскрес после успешного DELETE и reload.');
    echo "PASS checkpoint GC: SQL DELETE failure retains runtime slot/marker; retry, ACK and reload empty\n";
} finally {
    // Только TEMPORARY таблицы этого соединения, с уникальными именами.
    foreach (array_reverse($temporary) as $name) $db->query('DROP TEMPORARY TABLE `' . $name . '`');
}
echo "RESULT space persistence: 3 passed\n";
