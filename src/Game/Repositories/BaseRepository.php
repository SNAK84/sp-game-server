<?php

namespace SPGame\Game\Repositories;

use Swoole\Table;

use SPGame\Core\Defaults;
use SPGame\Core\Database;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

use SPGame\Game\Services\RepositorySaver;

abstract class BaseRepository
{
    protected static string $className = 'BaseRepository';

    /** @var Logger */
    protected static Logger $logger;

    // автоинкрементный ID для ключей
    // protected static int $lastId = 0;

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static int $tableSize = 1024 * 16;

    // Одна длинная группа занимает несколько строк, а не увеличенную колонку
    // во всех заранее выделенных строках каждого индекса.
    protected const INDEX_IDS_BYTES = 2048;
    protected const INDEX_IDS_PER_PART = 64;

    /** @var string Имя MySQL таблицы */
    protected static string $tableName;

    /** @var array Схема колонок ['column' => ['swoole' => [TYPE, size], 'sql' => '', 'default' => Defaults::...]] */
    protected static array $tableSchema = [];

    /** @var array Индексы (ключ => поле в основной таблице) */
    protected static array $indexes = [];


    /** @var Table Список изменённых ID для синхронизации */
    //protected static Table $dirtyIdsTable;
    /** @var Table Список изменённых ID для синхронизации */
    //protected static Table $dirtyIdsDelTable;

    /** @var Table */
    protected static Table $syncTable;

    /** По классу и конкретной Table: служебные runtime-копии не получают SQL allocator. */
    private static array $idRanges = [];

    /**
     * Инициализация репозитория
     */
    public static function init(?RepositorySaver $saver = null): void
    {
        $start = microtime(true);
        $before_memory = memory_get_usage();


        self::$logger = Logger::getInstance();

        // Создаём Swoole Table для dirtyIds
        /*static::$dirtyIdsTable = new Table(static::$tableSize);
        static::$dirtyIdsTable->column('id', Table::TYPE_INT);
        static::$dirtyIdsTable->create();*/
        // Создаём Swoole Table для dirtyIdsDel
        /*static::$dirtyIdsDelTable = new Table(static::$tableSize);
        static::$dirtyIdsDelTable->column('id', Table::TYPE_INT);
        static::$dirtyIdsDelTable->create();*/

        static::$syncTable = new Table(2048);
        // Флаги действий
        static::$syncTable->column('need_update', Table::TYPE_INT);
        static::$syncTable->column('need_delete', Table::TYPE_INT);
        // Храним последний ID (для автоинкремента)
        static::$syncTable->column('last_id', Table::TYPE_INT);
        static::$syncTable->create();
        // Инициализация мета-строки
        if (!static::$syncTable->exists('meta')) {
            static::$syncTable->set('meta', [
                'need_update' => 0,
                'need_delete' => 0,
                'last_id' => 0
            ]);
        }

        // Создаём основную таблицу Swoole
        static::$table = new Table(static::$tableSize);

        $count = 0;
        foreach (static::$tableSchema['columns'] as $col => $def) {
            static::$table->column($col, $def['swoole'][0], $def['swoole'][1] ?? 0);
        }
        static::$table->create();

        // Создаём индексы
        foreach (static::$indexes as $key => $val) {
            static::initIndex($key);
        }

        if (!empty(static::$tableName)) {
            static::ensureTableExists(static::$tableName, static::$tableSchema);
            $count = static::loadAll(static::$tableName);
            if ((static::$tableSchema['columns']['id']['swoole'][0] ?? null) === Table::TYPE_INT) {
                self::$idRanges[static::class] = [static::$syncTable,
                    new RepositoryIdRanges(static::$tableName, static::getLastId())];
            }
        }

        /*foreach (static::$dirtyIdsTable as $row) {
            static::$dirtyIdsTable->del($row['id']);
        }*/

        if ($saver) $saver->register(static::class);


        $duration = round(microtime(true) - $start, 3);
        $use_memory = memory_get_usage() - $before_memory;
        self::$logger->info(static::$className . " loaded {$count} rows into Swoole Table in {$duration}s use_memory " . Helpers::formatNumberShort($use_memory, 2));
    }

    /**
     * Проверка наличия MySQL таблицы и колонок
     */
    protected static function ensureTableExists(string $tableName, array $tableSchema): void
    {
        $db = Database::getInstance();

        $exists = $db->fetchOne("SHOW TABLES LIKE '$tableName'");
        if (!$exists) {
            $columnsSql = [];
            foreach ($tableSchema['columns'] as $col => $def) {
                if (empty($def['sql'])) continue; // пропускаем колонки без SQL определения
                $columnsSql[] = "`$col` {$def['sql']}";
            }

            $indexesSql = [];
            foreach ($tableSchema['indexes'] as $index) {
                $type = strtoupper($index['type']);
                $fields = '`' . implode('`, `', $index['fields']) . '`';
                if ($type === 'PRIMARY') {
                    $indexesSql[] = "PRIMARY KEY ($fields)";
                } elseif ($type === 'UNIQUE') {
                    $indexesSql[] = "UNIQUE KEY `{$index['name']}` ($fields)";
                } elseif ($type === 'INDEX') {
                    $indexesSql[] = "KEY `{$index['name']}` ($fields)";
                }
            }

            $sql = "CREATE TABLE `$tableName` (" . implode(", ", array_merge($columnsSql, $indexesSql)) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $db->query($sql);
            self::$logger->info("Created table `$tableName`");
        }

        $existingColumns = $db->fetchAll("SHOW COLUMNS FROM `$tableName`");
        $existingColumns = array_column($existingColumns, 'Field');

        foreach ($tableSchema['columns'] as $col => $def) {
            if (empty($def['sql'])) continue; // пропускаем колонки без SQL определения

            if (!in_array($col, $existingColumns, true)) {
                $db->query("ALTER TABLE `$tableName` ADD COLUMN `$col` {$def['sql']}");
                self::$logger->info("Added column `$col` to table `$tableName`");
            }
        }

        $indexes = $db->fetchAll("SHOW INDEX FROM `$tableName`");
        $existingIndexNames = array_column($indexes, 'Key_name');

        foreach ($tableSchema['indexes'] as $index) {
            $indexName = $index['type'] === 'PRIMARY' ? 'PRIMARY' : $index['name'];
            $fields = '`' . implode('`, `', $index['fields']) . '`';
            if (!in_array($indexName, $existingIndexNames, true)) {
                switch ($index['type']) {
                    case 'PRIMARY':
                        $db->query("ALTER TABLE `$tableName` ADD PRIMARY KEY ($fields)");
                        self::$logger->info("Added PRIMARY KEY on `$tableName`");
                        break;
                    case 'UNIQUE':
                        $db->query("ALTER TABLE `$tableName` ADD UNIQUE KEY `{$index['name']}` ($fields)");
                        self::$logger->info("Added UNIQUE `{$index['name']}` on `$tableName`");
                        break;
                    case 'INDEX':
                        $db->query("ALTER TABLE `$tableName` ADD INDEX `{$index['name']}` ($fields)");
                        self::$logger->info("Added INDEX `{$index['name']}` on `$tableName`");
                        break;
                }
            }
        }
    }

    /**
     * Загружает все записи из MySQL в Swoole Table
     */
    public static function loadAll(string $tableName): int
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT * FROM `" . $tableName . "`");
        $maxId = 0;
        foreach ($rows as $row) {
            static::add($row, false);
            if (isset($row['id']) && (int)$row['id'] > $maxId) {
                $maxId = (int)$row['id'];
            }
        }

        // Обновляем last_id в syncTable
        if (isset(static::$syncTable)) {
            static::setLastId($maxId);
        }


        return count($rows);
    }

    // ===============================
    // Инициализация индекса
    // ===============================
    protected static function initIndex(string $indexKey): void
    {
        $Unique = static::$indexes[$indexKey]['Unique'];

        //self::$logger->info(static::$className . " initIndex Unique" . $Unique, static::$indexes[$indexKey]);

        $tbl = new Table(static::$tableSize);
        $tbl->column('id', Table::TYPE_INT);
        if (!$Unique) {
            //self::$logger->info(static::$className . " initIndex unUnique", static::$indexes[$indexKey]);

            $tbl->column('ids', Table::TYPE_STRING, self::INDEX_IDS_BYTES); // Сериализованная часть списка ID
        }
        $tbl->create();
        static::$indexTables[$indexKey] = $tbl;
    }

    // ===============================
    // Формируем индексный ключ
    // ===============================
    protected static function buildIndexKey(array|string $value): string
    {
        if (is_array($value)) {
            // sort($value, SORT_STRING); // сортируем элементы для уникальности вне зависимости от порядка
            // Для составных индексов порядок полей важен: [galaxy, system, orbit]
            // и [galaxy, orbit, system] — это разные ключи, даже если по смыслу
            // элементы похожи. Сортировка ломает корректность поиска.
            $key = implode('_', array_map(fn($v) => (string)$v, $value));
        } else {
            $key = (string)$value;
        }

        $key = md5($key);

        return mb_strtolower(trim($key));
    }

    /** Части имеют короткие последовательные ключи; первая сохраняет прежний ключ. */
    protected static function readIndexIds(Table $table, string $key, ?array &$parts = null): array
    {
        $parts = [];
        $ids = [];
        for ($part = 0; ; ++$part) {
            $row = $table->get($key . ($part === 0 ? '' : ':' . $part));
            if ($row === false) break;
            $chunk = @unserialize($row['ids'], ['allowed_classes' => false]);
            if (!is_array($chunk)) {
                throw new \RuntimeException('Повреждён список ID индекса ' . static::$className . '/' . $key);
            }
            foreach ($chunk as $id) {
                if (!is_int($id)) throw new \RuntimeException('Некорректный ID в индексе ' . static::$className);
                $ids[] = $id;
            }
            $parts[] = $row;
        }
        return $ids;
    }

    /** 64 даже максимальных 64-битных ID помещаются в прежние 2048 байт. */
    protected static function encodeIndexIds(array $ids): array
    {
        $parts = [];
        foreach (array_chunk($ids, self::INDEX_IDS_PER_PART) as $chunk) {
            $encoded = serialize($chunk);
            if (strlen($encoded) > self::INDEX_IDS_BYTES) {
                throw new \LengthException('Часть индекса превышает допустимый размер.');
            }
            $parts[] = ['id' => $chunk[0], 'ids' => $encoded];
        }
        return $parts;
    }

    /** Не оставляем незаметно обрезанную строку либо половину прежнего списка. */
    protected static function replaceIndexIds(Table $table, string $key, array $ids, array $before): void
    {
        $after = static::encodeIndexIds($ids);
        $write = static function (array $rows, int $oldCount) use ($table, $key): void {
            for ($part = 0, $count = max(count($rows), $oldCount); $part < $count; ++$part) {
                $partKey = $key . ($part === 0 ? '' : ':' . $part);
                if (isset($rows[$part])) {
                    if (!$table->set($partKey, $rows[$part]) || $table->get($partKey) !== $rows[$part]) {
                        throw new \RuntimeException('Не удалось сохранить часть индекса ' . static::$className);
                    }
                } elseif ($table->exists($partKey) && (!$table->del($partKey) || $table->exists($partKey))) {
                    throw new \RuntimeException('Не удалось удалить пустую часть индекса ' . static::$className);
                }
            }
        };
        try {
            $write($after, count($before));
        } catch (\Throwable $error) {
            try {
                $write($before, count($after));
            } catch (\Throwable $rollback) {
                self::$logger->critical('Не восстановлены части индекса', [
                    'repository' => static::$className, 'key' => $key, 'error' => $rollback->getMessage(),
                ]);
            }
            throw $error;
        }
    }

    // ===============================
    // Добавление в индекс
    // ===============================
    protected static function addIndex(string $indexKey, array|string $value, int $id): void
    {
        $key = static::buildIndexKey($value);
        if ($key === '') return;

        $Unique = static::$indexes[$indexKey]['Unique'] ?? false;

        $tbl = static::$indexTables[$indexKey] ?? null;
        if (!$tbl) return;

        if ($Unique) {
            if (!$tbl->set($key, ['id' => $id]) || $tbl->get($key) !== ['id' => $id]) {
                throw new \RuntimeException('Не удалось сохранить уникальный индекс ' . static::$className . '/' . $indexKey);
            }
        } else {
            $ids = static::readIndexIds($tbl, $key, $parts);
            if (in_array($id, $ids, true)) return;
            $ids[] = $id;
            static::replaceIndexIds($tbl, $key, $ids, $parts);
        }
    }

    /**
     * Удаление значения из индекса
     */
    protected static function removeIndex(string $indexKey, array|string $val, ?int $id = null): void
    {
        $key = static::buildIndexKey($val);
        if ($key === '') return;

        $Unique = static::$indexes[$indexKey]['Unique'] ?? false;
        $tbl = static::$indexTables[$indexKey] ?? null;
        if (!$tbl) return;

        if ($Unique) {
            if ($tbl->exists($key) && (!$tbl->del($key) || $tbl->exists($key))) {
                throw new \RuntimeException('Не удалось удалить уникальный индекс ' . static::$className . '/' . $indexKey);
            }
        } else {
            $ids = static::readIndexIds($tbl, $key, $parts);
            if (!$parts || ($id !== null && !in_array($id, $ids, true))) return;
            $remaining = $id === null ? [] : array_values(array_filter($ids, static fn($value) => $value !== $id));
            static::replaceIndexIds($tbl, $key, $remaining, $parts);
        }
    }

    /**
     * Обновление индекса (удаляем старое значение, добавляем новое)
     */
    protected static function updateIndex(string $indexKey, array|string $oldValue, array|string $newValue, int $id): void
    {
        self::removeIndex($indexKey, $oldValue, $id);
        self::addIndex($indexKey, $newValue, $id);
    }

    /**
     * Поиск по индексу
     */
    public static function findByIndex(string $indexKey, array|string $val): ?array
    {
        $key = static::buildIndexKey($val);
        if ($key === '') return null;

        $Unique = static::$indexes[$indexKey]['Unique'] ?? false;
        $tbl = static::$indexTables[$indexKey] ?? null;
        if (!$tbl) return null;

        $indexRow = $tbl->get($key);
        if (!$indexRow) {
            return null;
        }

        if ($Unique) {
            $mainRow = static::$table->get((string)$indexRow['id']);
            return $mainRow ?: null;
        } else {
            $ids = static::readIndexIds($tbl, $key);
            $result = [];
            foreach ($ids as $id) {
                $row = static::$table->get((string)$id);
                if ($row) $result[] = $row;
            }
            return !empty($result) ? $result : null;
        }
    }

    /**
     * Пометить запись как изменённую (для обновления MySQL)
     */
    protected static function markForUpdate(int $id): void
    {
        // Временные репозитории без SQL-таблицы используют syncTable только для meta/ID.
        if (static::$tableName === '') return;
        $row = static::$syncTable->get((string)$id) ?: [
            'need_update' => 0,
            'need_delete' => 0,
            'last_id' => 0
        ];

        // Только если не была уже помечена
        if ($row['need_update'] === 0) {
            $row['need_update'] = 1;
            if (!static::$syncTable->set((string)$id, $row) || static::$syncTable->get((string)$id) !== $row) {
                throw new \RuntimeException('Не удалось сохранить sync marker ' . static::$className);
            }
        }
    }

    /**
     * Пометить запись как удалённую (для удаления из MySQL)
     */
    protected static function markForDelete(int $id): void
    {
        if (static::$tableName === '') return;
        $row = static::$syncTable->get((string)$id) ?: [
            'need_update' => 0,
            'need_delete' => 0,
            'last_id' => 0
        ];

        $row['need_delete'] = 1;
        $row['need_update'] = 0;
        if (!static::$syncTable->set((string)$id, $row) || static::$syncTable->get((string)$id) !== $row) {
            throw new \RuntimeException('Не удалось сохранить DELETE marker ' . static::$className);
        }
    }

    /**
     * Получить все ID, требующие синхронизации
     */
    public static function getPendingSync(): array
    {
        $result = [
            'update' => [],
            'delete' => [],
            'last_id' => static::getLastId()
        ];

        foreach (static::$syncTable as $id => $row) {
            if ($id === 'meta') continue;
            if ($row['need_update'] === 1) $result['update'][] = (int)$id;
            if ($row['need_delete'] === 1) $result['delete'][] = (int)$id;
        }

        return $result;
    }

    /**
     * Установить новый last_id
     */
    public static function setLastId(int $id): void
    {
        if ($ranges = static::idRanges()) {
            $ranges->advanceTo($id);
            return;
        }
        $meta = static::$syncTable->get('meta') ?? ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0];
        $meta['last_id'] = $id;
        static::$syncTable->set('meta', $meta);
    }

    /**
     * Получить текущий last_id
     */
    public static function getLastId(): int
    {
        if ($ranges = static::idRanges()) return $ranges->current();
        return static::$syncTable->get('meta')['last_id'] ?? 0;
    }

    private static function idRanges(): ?RepositoryIdRanges
    {
        $entry = self::$idRanges[static::class] ?? null;
        return $entry !== null && $entry[0] === static::$syncTable ? $entry[1] : null;
    }

    /**
     * Атомарно выделяет следующий ID и возвращает его.
     * Аналог ++static::$lastId, но безопасно для нескольких воркеров.
     */
    public static function nextId(): int
    {
        if ($ranges = static::idRanges()) return $ranges->next();
        // Только временные репозитории (PlayerQueue) и runtime-копии проб.
        $id = static::$syncTable->incr('meta', 'last_id', 1);
        if ($id === false || $id <= 0) throw new \RuntimeException('Не удалось выделить runtime ID.');
        return (int)$id;
    }

    /**
     * Добавление или обновление записи
     */
    public static function add(array $data, bool $sync = true): ?int
    {

        $row = static::castRowToSchema($data, true);

        if (!isset($row['id'])) {
            static::$logger->warning("Cannot add row without 'id'", $data);
            return null;
        }

        static::writeRuntimeRow($row, static::findExistingById((int)$row['id']), $sync, true);

        return (int)$row['id'];
    }

    /**
     * Обновление записи
     */
    public static function update(array $data): void
    {

        $id = (int)($data['id'] ?? 0);
        MiningCheckpoints::assertMutable(static::$tableName, $id);

        $current = static::$table->get((string)$id);
        if (!$current) {
            self::$logger->warning("Attempted to update non-existent " . static::$className, ['record' => $data]);
            return;
        }

        $updated = array_merge($current, static::castRowToSchema($data));
        // Проверяем, есть ли реальные изменения
        $hasChanges = false;
        foreach ($updated as $key => $value) {
            if (!array_key_exists($key, $current) || $current[$key] !== $value) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            static::writeRuntimeRow($updated, $current, true, false);
        }
    }

    /** Одна runtime-запись: при отказе возвращаем строку, затронутые индексы и marker. Не SQL-транзакция. */
    protected static function writeRuntimeRow(array $row, ?array $before, bool $sync, bool $adding, ?int $runtimeId = null, ?array $syncMarker = null): void
    {
        // EntitySettings хранит составной ключ Table, но не имеет колонки id.
        $id = $runtimeId ?? (int)$row['id'];
        $marker = static::$syncTable->get((string)$id);
        $changes = $snapshots = [];
        foreach (static::$indexes as $name => $index) {
            $table = static::$indexTables[$name] ?? null;
            if ($table === null) continue;
            $old = $new = [];
            foreach ((array)$index['key'] as $field) {
                $old[] = $before[$field] ?? null;
                $new[] = $row[$field] ?? null;
            }
            // Изменение params/груза не требует перезаписи неизменившихся групп индекса.
            if (!$adding && $old === $new) continue;
            $changes[] = [$name, $old, $new];
            foreach ($before === null ? [$new] : [$old, $new] as $values) {
                $key = static::buildIndexKey($values);
                if (isset($snapshots[$name][$key])) continue;
                if ($index['Unique']) $snapshot = ['row' => $table->get($key)];
                else {
                    $ids = static::readIndexIds($table, $key, $parts);
                    $snapshot = ['ids' => $ids, 'parts' => $parts];
                }
                $snapshots[$name][$key] = $snapshot;
            }
        }

        $indexesTouched = false;
        try {
            if (!static::$table->set((string)$id, $row) || static::$table->get((string)$id) !== $row) {
                throw new \RuntimeException('Не удалось сохранить runtime строку ' . static::$className);
            }
            foreach ($changes as [$name, $old, $new]) {
                $indexesTouched = true;
                if ($before !== null && $old !== $new) static::updateIndex($name, $old, $new, $id);
                else static::addIndex($name, $new, $id);
            }
            if ($sync && static::$tableName !== '') {
                if ($syncMarker === null) static::markForUpdate($id);
                elseif (!static::$syncTable->set((string)$id, $syncMarker) || static::$syncTable->get((string)$id) !== $syncMarker) {
                    throw new \RuntimeException('Не удалось сохранить marker восстановления ' . static::$className);
                }
            }
        } catch (\Throwable $error) {
            static::rollbackRuntimeRow($id, $before, $marker, $indexesTouched ? $snapshots : [], $error);
        }
    }

    /** Одна компенсация для add/update, delete/discard и восстановления DELETE. */
    private static function rollbackRuntimeRow(int $id, ?array $before, array|false $marker, array $snapshots, \Throwable $error): never
    {
        $failures = [];
        foreach ($snapshots as $name => $entries) foreach ($entries as $key => $snapshot) {
            try {
                $table = static::$indexTables[$name];
                if (array_key_exists('row', $snapshot)) {
                    static::restoreRuntimeEntry($table, $key, $snapshot['row']);
                } else {
                    static::readIndexIds($table, $key, $parts);
                    if ($parts !== $snapshot['parts']) static::replaceIndexIds($table, $key, $snapshot['ids'], $parts);
                }
            } catch (\Throwable $rollback) {
                $failures[] = 'index:' . $name . ': ' . $rollback->getMessage();
            }
        }
        try { static::restoreRuntimeEntry(static::$table, (string)$id, $before ?? false); }
        catch (\Throwable $rollback) { $failures[] = 'row: ' . $rollback->getMessage(); }
        try { static::restoreRuntimeEntry(static::$syncTable, (string)$id, $marker); }
        catch (\Throwable $rollback) { $failures[] = 'marker: ' . $rollback->getMessage(); }
        if ($failures !== []) {
            self::$logger->critical('Не восстановлена runtime запись после отказа', [
                'repository' => static::$className, 'id' => $id, 'failures' => $failures,
            ]);
            throw new \RuntimeException('Не выполнен rollback runtime записи ' . static::$className, 0, $error);
        }
        throw $error;
    }

    /** Точный before-образ, включая отсутствие строки; не создаёт dirty/DELETE marker. */
    private static function restoreRuntimeEntry(Table $table, string $key, array|false $before): void
    {
        if ($table->get($key) === $before) return;
        if ($before === false) {
            if (!$table->del($key) || $table->exists($key)) throw new \RuntimeException('Не удалена незавершённая запись.');
        } elseif (!$table->set($key, $before) || $table->get($key) !== $before) {
            throw new \RuntimeException('Не восстановлен прежний образ записи.');
        }
    }

    public static function delete(array $data): void
    {
        MiningCheckpoints::assertMutable(static::$tableName, (int)($data['id'] ?? 0));
        $id = (int)($data['id'] ?? 0);

        $current = static::$table->get((string)$id);
        if (!$current) {
            self::$logger->warning("Attempted to delete non-existent " . static::$className, ['record' => $data]);
            return;
        }

        static::removeRuntimeRow($current, $current);
    }

    /** Удаление и cleanup неуспешного add: точный before возвращается при любом отказе. */
    private static function removeRuntimeRow(array $row, ?array $before): void
    {
        $id = (int)$row['id'];
        $marker = static::$syncTable->get((string)$id);
        $snapshots = $changes = [];
        // При discard attempted-образ может отличаться от реально оставшейся main row.
        foreach ($before === null ? [$row] : [$row, $before] as $image) {
            foreach (static::$indexes as $name => $index) {
                $table = static::$indexTables[$name] ?? null;
                if ($table === null) continue;
                $values = array_map(static fn($field) => $image[$field] ?? null, (array)$index['key']);
                $key = static::buildIndexKey($values);
                if (isset($snapshots[$name][$key])) continue;
                if ($index['Unique']) {
                    $entry = $table->get($key);
                    // Отсутствующая attempted-строка не владеет чужим unique-ключом.
                    if ($entry === false || (int)$entry['id'] !== $id) continue;
                    $snapshot = ['row' => $entry];
                } else {
                    $ids = static::readIndexIds($table, $key, $parts);
                    $snapshot = ['ids' => $ids, 'parts' => $parts];
                }
                $snapshots[$name][$key] = $snapshot;
                $changes[] = [$name, $values];
            }
        }
        $indexesTouched = false;
        try {
            foreach ($changes as [$name, $values]) {
                $indexesTouched = true;
                static::removeIndex($name, $values, $id);
            }
            if (static::$table->exists((string)$id)
                && (!static::$table->del((string)$id) || static::$table->exists((string)$id))) {
                throw new \RuntimeException('Не удалось удалить runtime строку ' . static::$className);
            }
            static::markForDelete($id);
        } catch (\Throwable $error) {
            static::rollbackRuntimeRow($id, $before, $marker, $indexesTouched ? $snapshots : [], $error);
        }
    }

    public static function count(): int
    {
        return static::$table->count();
    }

    /**
     * Приведение строки к схеме (с Defaults)
     */
    public static function castRowToSchema(array $data, bool $fillDefaults = false): array
    {
        $row = [];

        foreach (static::$tableSchema['columns'] as $col => $def) {
            $hasValue = array_key_exists($col, $data);
            $resolvedDefault = null;

            if ($fillDefaults && !$hasValue) {
                $defaultValue = $def['default'] ?? Defaults::NONE;



                if ($defaultValue == Defaults::AUTOID) {
                    $resolvedDefault = static::nextId();
                } else {
                    $resolvedDefault = Defaults::resolve($defaultValue);
                }
            }

            // Если есть значение из входного массива — используем его,
            // иначе — используем вычисленный дефолт (если он не null)
            if ($hasValue) {
                $value = $data[$col];
            } elseif ($resolvedDefault !== null) {
                $value = $resolvedDefault;
            } else {
                // Нет значения и нет дефолта — пропускаем колонку
                continue;
            }
            $type = $def['swoole'][0] ?? Table::TYPE_STRING;
            switch ($type) {
                case Table::TYPE_INT:
                    if ($value === '' || $value === null) {
                        $row[$col] = 0;
                    } else {
                        $row[$col] = (int)$value;
                    }
                    break;
                case Table::TYPE_FLOAT:
                    if ($value === '' || $value === null) {
                        $row[$col] = 0.0;
                    } else {
                        $row[$col] = (float)$value;
                    }
                    break;
                case Table::TYPE_STRING:
                default:
                    $row[$col] = (string)$value;
                    break;
            }
        }

        return $row;
    }

    /**
     * Получить запись по ID
     */
    public static function findById(int $id): ?array
    {
        $mainRow = static::$table->get((string)$id);
        if (!$mainRow) {
            $mainRow  = static::castRowToSchema(['id' => $id], true);
            static::add($mainRow);
        }

        return $mainRow;
    }

    /** Прочитать runtime без создания default-строки: для проверки commit/rollback. */
    public static function findExistingById(int $id): ?array
    {
        $row = static::$table->get((string)$id);
        return $row === false ? null : $row;
    }

    /** Только rollback новой строки: add() мог создать индексы после set(false). */
    public static function discardUncommittedRow(array $row): void
    {
        static::removeRuntimeRow($row, static::findExistingById((int)$row['id']));
    }

    /** Локальный rollback удаления: возвращает row и отменяет его DELETE при следующем flush. */
    public static function restoreDeletedRow(array $row): void
    {
        $id = (int)$row['id'];
        $current = static::findExistingById($id);
        if ($current !== null && $current !== $row) throw new \LogicException('Восстанавливаемая runtime строка уже изменена.');
        $marker = static::$syncTable->get((string)$id) ?: ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0];
        $marker['need_update'] = 1;
        $marker['need_delete'] = 0;
        static::writeRuntimeRow($row, $current, true, true, $id, $marker);
    }

    public static function getAll(): ?array
    {

        if (!isset(static::$table)) {
            return null;
        }

        $result = [];
        foreach (static::$table as $key => $row) {
            $result[$key] = $row;
        }

        return $result;
    }

    /** Точное представление параметра; PDO иначе использует precision=14. */
    private static function checkpointParameter(mixed $value): mixed
    {
        return is_float($value)
            ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : $value;
    }

    /** Чистая проверка SQL-числа, также доступна baseline без Swoole. */
    public static function assertCheckpointNumber(float $value, string $sql): void
    {
        if (!is_finite($value)) throw new \InvalidArgumentException('Неконечное значение checkpoint.');
        if (preg_match('/DOUBLE\((\d+),(\d+)\)/i', $sql, $m)
            && ((float)sprintf('%.*F', (int)$m[2], $value) !== $value || abs($value) >= 10 ** ((int)$m[1] - (int)$m[2]))) {
            throw new \InvalidArgumentException('Checkpoint требует явного согласования точности SQL.');
        }
    }

    public static function checkpointStorageNumber(float $value, string $sql): float
    {
        self::assertCheckpointNumber($value, 'DOUBLE');
        if (preg_match('/DOUBLE\((\d+),(\d+)\)/i', $sql, $m)) $value = (float)sprintf('%.*F', (int)$m[2], $value);
        self::assertCheckpointNumber($value, $sql);
        return $value;
    }

    /**
     * Checkpoint не должен молча округлять экономику. Расчётный обработчик
     * обязан заранее подготовить представимые after и сохранить свой carry.
     * Только проверка: ничего не округляем и не пишем.
     */
    public static function assertCheckpointValues(array $values, bool $exact = true): void
    {
        foreach ($values as $field => $value) {
            $definition = static::$tableSchema['columns'][$field] ?? null;
            if ($definition === null || static::castRowToSchema([$field => $value])[$field] !== $value) {
                throw new \InvalidArgumentException('Значение checkpoint не соответствует схеме: ' . $field);
            }
            if (is_float($value)) {
                self::assertCheckpointNumber($value, $exact ? $definition['sql'] : 'DOUBLE');
            }
            if (is_string($value) && strlen($value) > ($definition['swoole'][1] ?? PHP_INT_MAX)) {
                throw new \OverflowException('Строка checkpoint не помещается в runtime: ' . $field);
            }
        }
    }

    /** Известный SQL-образ before/guard, а не epsilon для произвольных изменений. */
    public static function checkpointStorageValues(array $values): array
    {
        static::assertCheckpointValues($values, false);
        foreach ($values as $field => &$value) {
            $sql = static::$tableSchema['columns'][$field]['sql'];
            if (is_float($value)) $value = self::checkpointStorageNumber($value, $sql);
        }
        unset($value);
        return $values;
    }

    /** SELECT без создания default-строк; только для проверяемого checkpoint. */
    public static function checkpointDurableRow(int $id): ?array
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM `' . static::$tableName . '` WHERE id=?', [$id]);
        return $row === null ? null : static::castRowToSchema($row);
    }

    /**
     * Точечный durable flush СУЩЕСТВУЮЩЕЙ runtime-строки под внешним барьером.
     * Не вызывает общий saver, не маскирует SQL-ошибку и не воскрешает удалённое.
     * Вызывающий обязан сверить возвращённые guard/after перед ACK журнала.
     */
    public static function flushCheckpointRow(int $id): array
    {
        MiningCheckpoints::assertMutable(static::$tableName, $id);
        $row = static::findExistingById($id);
        if ($row === null || static::checkpointDurableRow($id) === null
            || in_array($id, static::getPendingSync()['delete'], true)) {
            throw new \LogicException('Сущность checkpoint удалена или ещё не сохранена.');
        }
        $columns = array_keys(static::$tableSchema['columns']);
        $columns = array_values(array_filter($columns, static fn($field) => $field !== 'id'));
        $values = array_map(static function ($field) use ($row) {
            $value = $row[$field];
            if ($value === '' && !empty(static::$tableSchema['columns'][$field]['sql_empty_as_null'])) $value = null;
            // Задаём SQL-образ явно даже для raw before. Поэтому recovery
            // не зависит от различий округления PHP/MySQL на десятичной границе.
            if (is_float($value)) $value = self::checkpointStorageNumber($value, static::$tableSchema['columns'][$field]['sql']);
            return self::checkpointParameter($value);
        }, $columns);
        $values[] = $id;
        Database::getInstance()->query('UPDATE `' . static::$tableName . '` SET '
            . implode(',', array_map(static fn($field) => '`' . $field . '`=?', $columns)) . ' WHERE id=?', $values);
        $stored = static::checkpointDurableRow($id);
        if ($stored === null || static::findExistingById($id) !== $row) {
            throw new \RuntimeException('Строка изменилась во время flush checkpoint.');
        }
        $marker = static::$syncTable->get((string)$id);
        if ($marker && empty($marker['need_delete'])) {
            $marker['need_update'] = 0;
            static::$syncTable->set((string)$id, $marker);
        }
        return $stored;
    }

    /**
     * Подтвердить один уже поставленный DELETE до удаления durable receipt.
     * Не общий saver: ошибка не поглощается, другие строки не затрагиваются.
     */
    public static function flushCheckpointDeletion(int $id): void
    {
        MiningCheckpoints::assertMutable(static::$tableName, $id);
        if ($id <= 0 || static::findExistingById($id) !== null) throw new \LogicException('Удаляемая checkpoint-строка ещё существует в runtime.');
        $marker = static::$syncTable->get((string)$id);
        if (static::checkpointDurableRow($id) !== null) {
            if (empty($marker['need_delete'])) throw new \LogicException('Нет намерения удаления checkpoint-строки.');
            Database::getInstance()->query('DELETE FROM `' . static::$tableName . '` WHERE id=?', [$id]);
            if (static::checkpointDurableRow($id) !== null) throw new \RuntimeException('Не подтверждено durable-удаление checkpoint-строки.');
        }
        static::$syncTable->del((string)$id);
    }

    /** Синхронизация всех изменений в MySQL; только помеченные ID (dirty tracking). */
    public static function syncToDatabase(int $batchSize = 100): void
    {
        if (!isset(static::$syncTable)) {
            self::$logger->warning(static::$className . " syncToDatabase: syncTable is not initialized");
            return;
        }

        $dirtyIds = [];
        $deleteIds = [];

        // Собираем ID, помеченные на обновление и удаление
        foreach (static::$syncTable as $key => $row) {
            $id = (int)$key;
            if ($id <= 0) continue;
            if (MiningCheckpoints::isProtected(static::$tableName, $id)) continue;

            if (!empty($row['need_update'])) {
                $dirtyIds[] = $id;
            }
            if (!empty($row['need_delete'])) {
                $deleteIds[] = $id;
            }
        }

        if (empty($dirtyIds) && empty($deleteIds)) {
            return;
        }

        if (empty(static::$tableName) || empty(static::$tableSchema['columns'])) {
            self::$logger->warning(static::$className . " syncToDatabase: tableName or schema empty");
            return;
        }

        // Подготавливаем столбцы только если нужно делать INSERT/UPDATE
        $columns = [];
        if (!empty($dirtyIds)) {
            if (empty(static::$tableSchema['columns'])) {
                self::$logger->warning(static::$className . " syncToDatabase: tableSchema['columns'] is empty");
                return;
            }

            foreach (static::$tableSchema['columns'] as $col => $def) {
                if (!empty($def['sql'])) {
                    $columns[] = $col;
                }
            }

            if (empty($columns)) {
                self::$logger->warning(static::$className . " syncToDatabase: no SQL columns found in schema");
                return;
            }
        }

        $db = Database::getInstance();
        //$dirtyIds = array_keys($dirtyIds);



        $startAll = microtime(true);
        $totalSynced = 0;   // количество вставленных/обновлённых "рядов" (батчи)
        $totalDeleted = 0;  // количество удалённых id

        // -----------------------
        // INSERT / ON DUPLICATE KEY UPDATE (если есть dirtyIds)
        // -----------------------
        if (!empty($dirtyIds)) {
            $chunks = array_chunk($dirtyIds, $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                $startChunk = microtime(true);

                $values = [];
                $placeholders = [];
                $actualSyncedIds = [];

                foreach ($chunk as $id) {
                    // получаем строку из локального хранилища
                    $row = static::$table->get((string)$id);
                    if ($row === false || $row === null) {
                        self::$logger->warning(static::$className . " syncToDatabase: dirty row {$id} is missing from runtime table");
                        continue;
                    }

                    $actualSyncedIds[] = $id;
                    $rowValues = [];
                    foreach ($columns as $col) {
                        $value = $row[$col] ?? null;
                        if ($value === '' && !empty(static::$tableSchema['columns'][$col]['sql_empty_as_null'])) $value = null;
                        // Тот же явный SQL-образ, что у checkpoint flush. Иначе
                        // PDO precision=14 обрезает также микросекунды epoch,
                        // превращая уже подтверждённый after в третье значение.
                        if (is_float($value)) {
                            $value = self::checkpointParameter(self::checkpointStorageNumber(
                                $value, static::$tableSchema['columns'][$col]['sql']));
                        }
                        $rowValues[] = $value;
                    }

                    $values = array_merge($values, $rowValues);
                    $placeholders[] = '(' . rtrim(str_repeat('?,', count($columns)), ',') . ')';
                }

                if (empty($placeholders)) {
                    continue;
                }

                // Формируем часть ON DUPLICATE KEY UPDATE
                $updateSets = [];
                foreach ($columns as $col) {
                    $updateSets[] = "`$col` = VALUES(`$col`)";
                }

                $sql = "INSERT INTO `" . static::$tableName . "` (`" . implode('`,`', $columns) . "`) VALUES "
                    . implode(',', $placeholders)
                    . " ON DUPLICATE KEY UPDATE " . implode(', ', $updateSets);

                try {
                    $db->query($sql, $values);
                    $chunkTime = round((microtime(true) - $startChunk) * 1000, 2);
                    $rows = count($placeholders);
                    $totalSynced  += $rows;

                    self::$logger->info(static::$className . " syncToDatabase: synced {$rows} rows in chunk #{$chunkIndex} ({$chunkTime} ms)");
                } catch (\Throwable $e) {
                    self::$logger->error(static::$className . " syncToDatabase error: " . $e->getMessage(), [
                        'sql' => $sql,
                        'rows' => count($placeholders)
                    ]);
                    continue;
                }

                // Сбрасываем флаг обновления только для строк,
                // реально вошедших в успешно выполненный SQL.
                foreach ($actualSyncedIds as $id) {
                    $row = static::$syncTable->get((string)$id);
                    if ($row) {
                        $row['need_update'] = 0;
                        static::$syncTable->set((string)$id, $row);
                    }
                }
            }
        }

        // -----------------------
        // DELETE (если есть dirtyIdsDelTable)
        // -----------------------
        if (!empty($deleteIds)) {
            $chunksDel = array_chunk($deleteIds, $batchSize);

            foreach ($chunksDel as $chunkIndex => $chunkDel) {
                $startChunk = microtime(true);

                // placeholders для IN (?, ?, ...)
                $placeholders = rtrim(str_repeat('?,', count($chunkDel)), ',');
                $sqlDel = "DELETE FROM `" . static::$tableName . "` WHERE `id` IN ($placeholders)";

                try {
                    $db->query($sqlDel, $chunkDel);
                    $chunkTime = round((microtime(true) - $startChunk) * 1000, 2);
                    $deleted = count($chunkDel);
                    $totalDeleted += $deleted;

                    self::$logger->info(static::$className . " syncToDatabase: deleted {$deleted} rows in chunk #{$chunkIndex} ({$chunkTime} ms)");
                } catch (\Throwable $e) {
                    self::$logger->error(static::$className . " syncToDatabase delete error: " . $e->getMessage(), [
                        'sql' => $sqlDel,
                        'ids' => $chunkDel
                    ]);
                    continue;
                }

                // Удаляем sync markers только после успешного DELETE.
                foreach ($chunkDel as $id) {
                    static::$syncTable->del((string)$id);
                }
            }
        }

        $totalTime = round((microtime(true) - $startAll) * 1000, 2);
        self::$logger->info(static::$className . " syncToDatabase: total {$totalSynced} rows synced, {$totalDeleted} rows deleted in {$totalTime} ms");
    }
}
