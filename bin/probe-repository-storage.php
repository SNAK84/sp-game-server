#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Проверка структуры хранения, не второй мир: без init репозиториев и SQL. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Environment, Logger};
use SPGame\Game\Repositories\BaseRepository;
use Swoole\Table;

if (!in_array('--execute', $argv, true) || !extension_loaded('swoole')) {
    fwrite(STDERR, "Запуск: docker exec swoole_app php bin/probe-repository-storage.php --execute\n");
    exit(64);
}
Environment::init(__DIR__ . '/../.env');
$env = new ReflectionProperty(Environment::class, 'env');
$env->setValue(null, array_replace($env->getValue(), ['LOG_ECHO' => '0', 'LOG_FILE' => '/tmp/sp-game-storage-probe.log']));

class StorageFaultTable extends Table
{
    public ?string $failKey = null;
    public function set(string $key, array $value): bool
    {
        if ($key === $this->failKey) {
            $this->failKey = null;
            parent::set($key, $value);
            return false;
        }
        return parent::set($key, $value);
    }
}

final class StorageProbe extends BaseRepository
{
    protected static string $className = 'StorageProbe';
    protected static string $tableName = 'probe_never_written_to_sql';
    protected static int $tableSize = 32768;
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static array $indexes = [
        'group' => ['key' => 'group', 'Unique' => false],
        'code' => ['key' => 'code', 'Unique' => true],
    ];
    protected static array $tableSchema = ['columns' => [
        'id' => ['swoole' => [Table::TYPE_INT, 8]],
        'group' => ['swoole' => [Table::TYPE_INT, 8]],
        'code' => ['swoole' => [Table::TYPE_INT, 8]],
        'value' => ['swoole' => [Table::TYPE_INT, 8]],
    ]];

    public static function prepare(): void
    {
        self::$logger = Logger::getInstance();
        $layout = self::runtimeLayout();
        self::$table = new Table($layout['mainBuckets'], $layout['mainConflict']);
        foreach (self::$tableSchema['columns'] as $name => $def) self::$table->column($name, ...$def['swoole']);
        if (!self::$table->create()) throw new RuntimeException('Нет main Table пробы.');
        self::$syncTable = new Table($layout['syncBuckets'], $layout['syncConflict']);
        foreach (['need_update', 'need_delete', 'last_id'] as $name) self::$syncTable->column($name, Table::TYPE_INT, 8);
        if (!self::$syncTable->create()) throw new RuntimeException('Нет sync Table пробы.');
        self::$syncTable->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
        foreach (array_keys(self::$indexes) as $name) self::initIndex($name);
    }

    public static function tombstones(int $first, int $last): void
    {
        for ($id = $first; $id <= $last; ++$id) self::markForDelete($id);
    }

    public static function setLimit(int $size): void { self::$tableSize = $size; }

    public static function checkAck(int $id): void
    {
        $row = self::findExistingById($id);
        $old = self::$syncTable->get((string)$id);
        self::update(['id' => $id, 'value' => 3]);
        storageCheck(!self::acknowledgeSync($id, $old, $row), 'Старый ACK удалил новое dirty-поколение.');
        $latest = self::$syncTable->get((string)$id);
        storageCheck($latest['last_id'] > $old['last_id'] && self::acknowledgeSync($id, $latest, self::findExistingById($id)), 'Новый ACK не очищает журнал.');
        storageCheck(!self::$syncTable->exists((string)$id), 'Чистый marker занимает журнал.');
    }

    public static function seedLargeGroup(array $ids): void
    {
        self::$indexTables['group']->destroy();
        self::initIndex('group');
        foreach ($ids as $id) self::$table->set((string)$id, ['group' => 7]);
        self::replaceIndexIds(self::$indexTables['group'], self::buildIndexKey('7'), $ids, []);
    }

    public static function boundaryCodec(): void
    {
        $ids = [0, 1, 9007199254740993, PHP_INT_MAX];
        $table = new Table(64, 1.0);
        $table->column('id', Table::TYPE_INT, 8);
        $table->column('ids', Table::TYPE_STRING, self::INDEX_IDS_BYTES);
        $table->create();
        self::replaceIndexIds($table, 'bounds', $ids, []);
        storageCheck(self::readIndexIds($table, 'bounds') === $ids, 'Нет точности uint64/binary с нулевыми байтами.');
        $table->set('bounds', ['ids' => "\x01\x02short"]);
        try { self::readIndexIds($table, 'bounds'); throw new LogicException('Повреждённый блок принят.'); }
        catch (RuntimeException) { /* Ожидаемый отказ до изменения main. */ }
        $table->destroy();
    }

    public static function faultTable(string $name): StorageFaultTable
    {
        $old = $name === 'sync' ? self::$syncTable : self::$indexTables[$name];
        $layout = self::runtimeLayout();
        $new = new StorageFaultTable($name === 'sync' ? $layout['syncBuckets'] : $layout['indexBuckets'], 1.0);
        if ($name === 'sync') foreach (['need_update', 'need_delete', 'last_id'] as $column) $new->column($column, Table::TYPE_INT, 8);
        else {
            $new->column('id', Table::TYPE_INT, 8);
            $new->column('ids', Table::TYPE_STRING, self::INDEX_IDS_BYTES);
        }
        $new->create();
        foreach ($old as $key => $row) $new->set((string)$key, $row);
        $old->destroy();
        if ($name === 'sync') self::$syncTable = $new;
        else self::$indexTables[$name] = $new;
        return $new;
    }

    public static function digests(): array
    {
        $result = [];
        foreach (['main' => self::$table, 'sync' => self::$syncTable, 'positions' => self::indexPositionTable()] + self::$indexTables as $name => $table) {
            $keys = [];
            foreach ($table as $key => $row) $keys[] = (string)$key;
            sort($keys, SORT_STRING);
            $hash = hash_init('sha256');
            foreach ($keys as $key) hash_update($hash, $key . serialize($table->get($key)));
            $result[$name] = hash_final($hash);
        }
        return $result;
    }
}

function storageCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function storageRollback(callable $operation): void
{
    $before = StorageProbe::digests();
    $failed = false;
    try { $operation(); } catch (RuntimeException) { $failed = true; }
    storageCheck($failed && StorageProbe::digests() === $before, 'Rollback изменил main/index/sync.');
}

$started = microtime(true);
StorageProbe::prepare();
StorageProbe::boundaryCodec();
for ($id = 1; $id <= 20000; ++$id) StorageProbe::add(['id' => $id, 'group' => $id, 'code' => $id, 'value' => 0], false);
for ($id = 1; $id <= 20000; ++$id) StorageProbe::update(['id' => $id, 'value' => 1]);
StorageProbe::tombstones(100001, 112000);
$pending = StorageProbe::getPendingSync();
storageCheck(count($pending['update']) === 20000 && count($pending['delete']) === 12000, 'Потеря dirty/tombstone выше прежнего лимита2048.');
StorageProbe::setLimit(20000);
storageRollback(static fn() => StorageProbe::add(['id' => 20001, 'group' => 20001, 'code' => 20001, 'value' => 0]));
StorageProbe::setLimit(32768);
StorageProbe::seedLargeGroup(range(1, 20000));
storageCheck(array_column(StorageProbe::findByIndex('group', '7'), 'id') === range(1, 20000), 'Большая группа потеряла ID.');
$index = StorageProbe::faultTable('group');
$index->failKey = md5('7') . ':62'; // swap-delete затрагивает блок ID1000 и хвост, а не всю группу.
storageRollback(static fn() => StorageProbe::update(['id' => 1000, 'group' => 8]));
$sync = StorageProbe::faultTable('sync');
$sync->set('1000', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
$sync->failKey = '1000';
storageRollback(static fn() => StorageProbe::update(['id' => 1000, 'group' => 8]));
StorageProbe::update(['id' => 1000, 'group' => 8]);
storageCheck(array_column(StorageProbe::findByIndex('group', '8'), 'id') === [1000], 'Retry не переместил membership.');
StorageProbe::delete(['id' => 1000]);
storageCheck(StorageProbe::findByIndex('group', '8') === null, 'Удаление оставило membership.');
StorageProbe::restoreDeletedRow(['id' => 1000, 'group' => 8, 'code' => 1000, 'value' => 1]);
storageCheck(array_column(StorageProbe::findByIndex('group', '8'), 'id') === [1000], 'Restore не вернул membership.');
StorageProbe::checkAck(1000);
echo 'REPOSITORY_STORAGE_PROBE_OK ' . json_encode(['rows' => 20000, 'dirty' => 20000, 'tombstones' => 12000,
    'largeGroup' => 20000, 'indexBytes' => StorageProbe::runtimeLayout()['indexIdsBytes'],
    'rollback' => true, 'generationAck' => true, 'elapsedSeconds' => round(microtime(true) - $started, 3)], JSON_THROW_ON_ERROR) . "\n";
