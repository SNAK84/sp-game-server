<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Core\{Database, Environment, Logger, LogLevel};
use SPGame\Game\Repositories\{Galaxy, Planets};
use SPGame\Game\Services\{CelestialNameGenerator as Names, RepositorySaver};
use Swoole\Table;

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "SKIP: необходимы native Swoole и MySQL.\n");
    exit(77);
}

Environment::init();
$logger = Logger::getInstance();
(new ReflectionProperty($logger, 'islogFile'))->setValue($logger, false);
(new ReflectionProperty($logger, 'minLevel'))->setValue($logger, LogLevel::CRITICAL);
$db = Database::getInstance();
$temporary = [];

function celestialPersistenceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Native Tables без init(): Planets::init() проверяет настоящую planets.speed. */
function celestialPersistenceRuntime(string $class, string $sqlName, int $capacity): void
{
    $schema = (new ReflectionProperty($class, 'tableSchema'))->getValue();
    $table = new Table($capacity);
    foreach ($schema['columns'] as $key => $definition) {
        $table->column($key, $definition['swoole'][0], $definition['swoole'][1]);
    }
    $table->create();
    (new ReflectionProperty($class, 'table'))->setValue(null, $table);
    (new ReflectionProperty($class, 'tableName'))->setValue(null, $sqlName);
    (new ReflectionProperty($class, 'logger'))->setValue(null, Logger::getInstance());
    $sync = new Table(2048);
    foreach (['need_update', 'need_delete', 'last_id'] as $column) $sync->column($column, Table::TYPE_INT, 8);
    $sync->create();
    $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
    (new ReflectionProperty($class, 'syncTable'))->setValue(null, $sync);
    $indexes = [];
    foreach ((new ReflectionProperty($class, 'indexes'))->getValue() as $name => $definition) {
        $index = new Table($capacity);
        $index->column('id', Table::TYPE_INT, 8);
        if (!$definition['Unique']) $index->column('ids', Table::TYPE_STRING, 2048);
        $index->create();
        $indexes[$name] = $index;
    }
    (new ReflectionProperty($class, 'indexTables'))->setValue(null, $indexes);
}

function celestialPersistenceInsert(Database $db, string $name, array $rows): void
{
    $columns = array_keys($rows[0]);
    $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $values = [];
    foreach ($rows as $row) foreach ($columns as $column) $values[] = $row[$column];
    $db->query('INSERT INTO `' . $name . '` (`' . implode('`,`', $columns) . '`) VALUES '
        . implode(',', array_fill(0, count($rows), $placeholders)), $values);
}

function celestialPersistenceGeometry(Database $db, string $name): string
{
    $hash = hash_init('sha256');
    foreach ($db->fetchAll('SELECT * FROM `' . $name . '` ORDER BY id') as $row) {
        unset($row['name']);
        hash_update($hash, serialize($row));
    }
    return hash_final($hash);
}

try {
    $tables = [];
    foreach ([Galaxy::class, Planets::class] as $class) {
        $name = 'qa_celestial_' . bin2hex(random_bytes(8));
        $schema = (new ReflectionProperty($class, 'tableSchema'))->getValue();
        $ddl = [];
        foreach ($schema['columns'] as $column => $definition) $ddl[] = '`' . $column . '` ' . $definition['sql'];
        $ddl[] = 'PRIMARY KEY (`id`)';
        // Connection-local таблицы: ни DDL, ни UPDATE игровых таблиц не выполняются.
        $db->query('CREATE TEMPORARY TABLE `' . $name . '` (' . implode(',', $ddl) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $temporary[] = $tables[$class] = $name;
        celestialPersistenceRuntime($class, $name, $class === Galaxy::class ? 8192 : 64);
    }

    $galaxyTable = $tables[Galaxy::class];
    $batch = [];
    for ($id = 1; $id <= 2300; ++$id) {
        $batch[] = Galaxy::castRowToSchema(['id' => $id, 'galaxy' => 1, 'system' => $id,
            'name' => $id === 1 ? 'Ручная звезда' : ($id === 2 ? str_repeat('Q', 32) : '')], true);
        if (count($batch) === 100) {
            celestialPersistenceInsert($db, $galaxyTable, $batch);
            $batch = [];
        }
    }
    Galaxy::loadAll($galaxyTable);
    $geometry = celestialPersistenceGeometry($db, $galaxyTable);
    celestialPersistenceAssert(Galaxy::ensureNames() === 2298, 'Не заполнены все пустые имена.');
    celestialPersistenceAssert(Galaxy::ensureNames() === 0, 'Backfill не идемпотентен.');
    celestialPersistenceAssert(celestialPersistenceGeometry($db, $galaxyTable) === $geometry, 'Backfill изменил параметры систем.');
    $counts = $db->fetchOne('SELECT COUNT(DISTINCT name) AS names, SUM(name=\'\') AS empty_names FROM `' . $galaxyTable . '`');
    celestialPersistenceAssert((int)$counts['names'] === 2300 && (int)$counts['empty_names'] === 0, 'Потеряны/повторились имена систем.');
    $sync = (new ReflectionProperty(Galaxy::class, 'syncTable'))->getValue();
    celestialPersistenceAssert($sync->count() === 1 && Galaxy::getPendingSync()['update'] === [],
        'Backfill более 2048 имён переполнил/оставил sync-маркеры.');

    celestialPersistenceRuntime(Galaxy::class, $galaxyTable, 8192);
    Galaxy::loadAll($galaxyTable);
    celestialPersistenceAssert(Galaxy::ensureNames() === 0, 'После cold reload снова потребовалась генерация имён.');
    celestialPersistenceAssert(Galaxy::findExistingById(1)['name'] === 'Ручная звезда'
        && Galaxy::findExistingById(2)['name'] === str_repeat('Q', 32), 'Ранее сохранённые имена перезаписаны/обрезаны.');
    foreach ([3, 1024, 2300] as $id) {
        celestialPersistenceAssert(Galaxy::findExistingById($id)['name'] === Names::systemName(1, $id), 'Имя изменилось после reload.');
    }
    echo "PASS 2300 систем: пакетный backfill, уникальность, ручные имена, идемпотентность, геометрия и cold reload\n";

    // Ошибка SQL только у нашей несуществующей TEMPORARY цели: backfill обязан остановиться.
    $missing = Galaxy::castRowToSchema(['id' => 2301, 'galaxy' => 1, 'system' => 2301, 'name' => ''], true);
    celestialPersistenceInsert($db, $galaxyTable, [$missing]);
    Galaxy::add($missing, false);
    (new ReflectionProperty(Galaxy::class, 'tableName'))->setValue(null, $galaxyTable . '_missing');
    $failed = false;
    try { Galaxy::ensureNames(); }
    catch (RuntimeException $error) { $failed = true; }
    finally { (new ReflectionProperty(Galaxy::class, 'tableName'))->setValue(null, $galaxyTable); }
    celestialPersistenceAssert($failed && in_array(2301, Galaxy::getPendingSync()['update'], true),
        'SQL failure был скрыт или потерял dirty marker.');
    celestialPersistenceAssert($db->fetchOne('SELECT name FROM `' . $galaxyTable . '` WHERE id=2301')['name'] === '',
        'Отказ записи неожиданно изменил SQL.');
    celestialPersistenceRuntime(Galaxy::class, $galaxyTable, 8192);
    Galaxy::loadAll($galaxyTable);
    celestialPersistenceAssert(Galaxy::ensureNames() === 1, 'Повторный запуск не восстановил прерванное заполнение.');
    echo "PASS SQL failure: startup останавливается, dirty marker сохраняется, cold retry успешен\n";

    $names = [Names::planetName(1, 3, 2), Names::moonName(1, 3, 2, 1), 'Velaria', str_repeat('Я', 16)];
    $expected = [];
    foreach ($names as $offset => $name) {
        celestialPersistenceAssert(strlen($name) <= 32 && mb_check_encoding($name, 'UTF-8'), 'Имя не помещается в planets.name.');
        $row = Planets::castRowToSchema(['id' => $offset + 1, 'owner_id' => 7, 'name' => $name,
            'name_generated' => $offset < 2 ? 1 : 0, 'galaxy' => 1, 'system' => 3, 'planet' => $offset + 1,
            'planet_type' => $offset === 1 ? 3 : 1, 'create_time' => 100, 'update_time' => 100.0, 'rotation' => 0], true);
        Planets::add($row);
        $expected[$offset + 1] = $row;
    }
    $saver = new RepositorySaver();
    $saver->register(Planets::class);
    $saver->saveAll();
    celestialPersistenceAssert(Planets::getPendingSync()['update'] === [], 'Имена планет не сохранились.');
    celestialPersistenceRuntime(Planets::class, $tables[Planets::class], 64);
    Planets::loadAll($tables[Planets::class]);
    foreach ($expected as $id => $row) {
        $restored = Planets::findExistingById($id);
        celestialPersistenceAssert($restored === $row, 'Планета/луна изменилась после reload.');
        foreach (['ru', 'en'] as $lang) {
            $display = Names::planetDisplayName($restored, $lang);
            celestialPersistenceAssert($display === ($row['name_generated'] ? Names::localize($row['name'], $lang) : $row['name']),
                'Локализация изменила ручное имя или потеряла каноническое.');
            celestialPersistenceAssert(mb_check_encoding($display, 'UTF-8'), 'Некорректный UTF-8 отображаемого имени.');
        }
    }
    $list = Planets::getPlanetsList(['id' => 7]);
    celestialPersistenceAssert(count($list) === 4 && isset($list[0]['name_generated']), 'Список планет теряет признак автоимени.');
    Planets::update(['id' => 1, 'name' => 'Мой мир', 'name_generated' => 0]);
    $saver->saveAll();
    celestialPersistenceRuntime(Planets::class, $tables[Planets::class], 64);
    Planets::loadAll($tables[Planets::class]);
    $renamed = Planets::findExistingById(1);
    celestialPersistenceAssert($renamed['name'] === 'Мой мир' && $renamed['name_generated'] === 0
        && Names::planetDisplayName($renamed, 'en') === 'Мой мир', 'Переименование не сохранилось.');
    echo "PASS Swoole → RepositorySaver → TEMPORARY MySQL → cold reload: планеты/луны, 32 UTF-8 байта, ru/en и переименование\n";
} finally {
    foreach (array_reverse($temporary) as $name) $db->query('DROP TEMPORARY TABLE `' . $name . '`');
}

echo "RESULT celestial names persistence: 3 passed; игровые таблицы не изменялись\n";
