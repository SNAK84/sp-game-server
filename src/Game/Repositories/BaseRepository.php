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
    protected static int $lastId = 0;

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static int $tableSize = 1024 * 16;

    /** @var string Имя MySQL таблицы */
    protected static string $tableName;

    /** @var array Схема колонок ['column' => ['swoole' => [TYPE, size], 'sql' => '', 'default' => Defaults::...]] */
    protected static array $tableSchema = [];

    /** @var array Индексы (ключ => поле в основной таблице) */
    protected static array $indexes = [];


    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsTable;
    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsDelTable;

    /**
     * Инициализация репозитория
     */
    public static function init(RepositorySaver $saver = null): void
    {
        $start = microtime(true);
        $before_memory = memory_get_usage();


        self::$logger = Logger::getInstance();

        // Создаём Swoole Table для dirtyIds
        static::$dirtyIdsTable = new Table(static::$tableSize);
        static::$dirtyIdsTable->column('id', Table::TYPE_INT);
        static::$dirtyIdsTable->create();
        // Создаём Swoole Table для dirtyIdsDel
        static::$dirtyIdsDelTable = new Table(static::$tableSize);
        static::$dirtyIdsDelTable->column('id', Table::TYPE_INT);
        static::$dirtyIdsDelTable->create();

        // Создаём основную таблицу Swoole
        static::$table = new Table(static::$tableSize);


        foreach (static::$tableSchema['columns'] as $col => $def) {
            static::$table->column($col, $def['swoole'][0], $def['swoole'][1] ?? null);
        }
        static::$table->create();

        // Создаём индексы
        foreach (static::$indexes as $key => $val) {
            static::initIndex($key);
        }

        if (!empty(static::$tableName)) {
            static::ensureTableExists(static::$tableName, static::$tableSchema);
            $count = static::loadAll(static::$tableName);
        }

        foreach (static::$dirtyIdsTable as $row) {
            static::$dirtyIdsTable->del($row['id']);
        }

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
        foreach ($rows as $row) {
            static::add($row);
            if ($row['id'] > static::$lastId) static::$lastId = $row['id'];
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

            $tbl->column('ids', Table::TYPE_STRING, 1024); // JSON-массив id
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
            sort($value, SORT_STRING); // сортируем элементы для уникальности вне зависимости от порядка
            $key = implode('_', array_map(fn($v) => (string)$v, $value));
        } else {
            $key = (string)$value;
        }

        return mb_strtolower(trim($key));
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

        if ($Unique)
            $tbl->set($key, ['id' => $id]);
        else {
            $existing = $tbl->get($key);
            $ids = [];

            if ($existing && !empty($existing['ids'])) {
                $ids = unserialize($existing['ids']) ?: [];
            }

            // Добавляем id, если его там ещё нет
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            $tbl->set($key, [
                'id'  => $ids[0] ?? 0,   // для удобства хранится первый id
                'ids' => serialize($ids)
            ]);
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

        if ($Unique || $id === null) {
            $tbl->del($key);
        } else {
            $existing = $tbl->get($key);
            if (is_string($existing)) {
                $existing = unserialize($existing) ?: [];
            }

            if (!$existing || empty($existing['ids'])) return;

            $ids = $existing['ids'] ?? [];
            if (is_string($ids)) {
                $ids = unserialize($ids) ?: [];
            }
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_filter($ids, fn($i) => $i !== $id);

            if (empty($ids)) {
                $tbl->del($key);
            } else {
                $tbl->set($key, [
                    'id'  => (int)$ids[0],
                    'ids' => serialize($ids)
                ]);
            }
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
    protected static function findByIndex(string $indexKey, array|string $val): ?array
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
            if (empty($indexRow['ids'])) return null;
            $ids = unserialize($indexRow['ids']) ?: [];
            $result = [];
            foreach ($ids as $id) {
                $row = static::$table->get((string)$id);
                if ($row) $result[] = $row;
            }
            return !empty($result) ? $result : null;
        }
    }

    /**
     * Добавление или обновление записи
     */
    public static function add(array $data): void
    {

        $row = static::castRowToSchema($data, true);

        if (!isset($row['id'])) {
            static::$logger->warning("Cannot add row without 'id'", $data);
            return;
        }

        static::$table->set((string)$row['id'], $row);


        // Добавляем в индексы
        foreach (static::$indexes as $indexKey => $col) {
            // Получаем ключи из $col['key'], приводим к массиву для единообразия
            $keys = is_array($col['key']) ? $col['key'] : [$col['key']];
            $values = [];
            foreach ($keys as $k) {
                if (!array_key_exists($k, $row)) {
                    $values[] = null; // можно решать, как обрабатывать отсутствующие поля
                } else {
                    $values[] = $row[$k];
                }
            }
            static::addIndex($indexKey, $values, (int)$row['id']);
        }

        // Помечаем для синхронизации
        static::$dirtyIdsTable->set((string)$row['id'], ['id' => (int)$row['id']]);
    }

    /**
     * Обновление записи
     */
    public static function update(array $data): void
    {

        $id = (int)($data['id'] ?? 0);

        $current = static::$table->get((string)$id);
        if (!$current) {
            self::$logger->warning("Attempted to update non-existent " . static::$className . ": " . json_encode($data));
            return;
        }

        $updated = array_merge($current, static::castRowToSchema($data));
        static::$table->set((string)$id, $updated);

        // Обновляем индексы
        foreach (static::$indexes as $indexKey => $col) {
            // Приводим ключи к массиву
            $keys = is_array($col['key']) ? $col['key'] : [$col['key']];
            $oldValues = [];
            $newValues = [];

            foreach ($keys as $k) {
                $oldValues[] = $current[$k] ?? null;
                $newValues[] = $updated[$k] ?? null;
            }
            static::updateIndex($indexKey, $oldValues, $newValues, $id);
        }

        // Помечаем для синхронизации
        static::$dirtyIdsTable->set((string)$id, ['id' => (int)$id]);
    }

    public static function delete(array $data): void
    {
        $id = (int)($data['id'] ?? 0);

        $current = static::$table->get((string)$id);
        if (!$current) {
            self::$logger->warning("Attempted to delete non-existent " . static::$className . ": " . json_encode($data));
            return;
        }

        //self::$logger->warning("Delete non-existent " . static::$className . ": " . json_encode($data));
            

        // Удаляем индексы
        foreach (static::$indexes as $indexKey => $col) {
            $keys = is_array($col['key']) ? $col['key'] : [$col['key']];
            $Values = [];
            foreach ($keys as $k) {
                $Values[] = $current[$k] ?? null;
            }
            static::removeIndex($indexKey, $Values, $id);
        }

        static::$table->del((string)$id);

        // Помечаем для удаления
        static::$dirtyIdsDelTable->set((string)$id, ['id' => (int)$id]);
        static::$dirtyIdsTable->del((string)$id);
    }

    public static function count(): int
    {
        return static::$table->count();
    }

    /**
     * Приведение строки к схеме (с Defaults)
     */
    protected static function castRowToSchema(array $data, bool $fillDefaults = false): array
    {
        $row = [];

        //static::$logger->info(static::$className . " castRowToSchema", $data);

        foreach (static::$tableSchema['columns'] as $col => $def) {
            $hasValue = array_key_exists($col, $data);
            $resolvedDefault = null;

            if ($fillDefaults && !$hasValue) {
                $defaultValue = $def['default'] ?? Defaults::NONE;



                if ($defaultValue == Defaults::AUTOID) {
                    $resolvedDefault = ++static::$lastId;
                } else {
                    $resolvedDefault = Defaults::resolve($defaultValue);
                }
                /*
                $resolved = Defaults::resolve($defaultValue);
                if ($resolved !== null) {
                    $row[$col] = $resolved;
                }*/
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
            //if ($hasValue) {
            //$value = $data[$col];
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
            //}
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

    /**
     * Синхронизация всех изменений в MySQL
     * Работает только с помеченными ID (dirty tracking)
     */
    public static function syncToDatabase(int $batchSize = 100): void
    {
        $dirtyIds = [];
        foreach (static::$dirtyIdsTable as $row) {
            $dirtyIds[] = (int)$row['id'];
        }

        $dirtyIdsDelTable = [];
        foreach (static::$dirtyIdsDelTable as $row) {
            $dirtyIdsDelTable[] = (int)$row['id'];
        }

        // Если нет ни обновлений, ни удалений — выходим
        if (empty($dirtyIds) && empty($dirtyIdsDelTable)) {
            //self::$logger->info(static::$className . " syncToDatabase: nothing to sync");
            return;
        }

        if (empty(static::$tableName) || empty(static::$tableSchema['columns'])) {
            self::$logger->warning(static::$className . " syncToDatabase: tableName or tableSchema['columns'] is empty");
            return;
        }

        if (empty(static::$tableName)) {
            self::$logger->warning(static::$className . " syncToDatabase: tableName is empty");
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

                foreach ($chunk as $id) {
                    // получаем строку из локального хранилища
                    $row = static::$table->get((string)$id);
                    if ($row === false || $row === null) {
                        continue;
                    }

                    $rowValues = [];
                    foreach ($columns as $col) {
                        $rowValues[] = $row[$col] ?? null;
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
                }
            }
        }

        // -----------------------
        // DELETE (если есть dirtyIdsDelTable)
        // -----------------------
        if (!empty($dirtyIdsDelTable)) {
            $chunksDel = array_chunk($dirtyIdsDelTable, $batchSize);

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
                }
            }
        }

        $totalTime = round((microtime(true) - $startAll) * 1000, 2);
        self::$logger->info(static::$className . " syncToDatabase: total {$totalSynced} rows synced, {$totalDeleted} rows deleted in {$totalTime} ms");

        // Очищаем список dirty ID после синхронизации (и вставок, и удалений)
        foreach (static::$dirtyIdsTable as $row) {
            static::$dirtyIdsTable->del($row['id']);
        }
        foreach (static::$dirtyIdsDelTable as $row) {
            static::$dirtyIdsDelTable->del($row['id']);
        }
    }
}
