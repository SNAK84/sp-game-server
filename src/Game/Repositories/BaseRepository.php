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

    /** @var array Список изменённых ID для синхронизации */
    protected static array $dirtyIds = [];

    /**
     * Инициализация репозитория
     */
    public static function init(RepositorySaver $saver = null): void
    {
        $start = microtime(true);
        $before_memory = memory_get_usage();


        self::$logger = Logger::getInstance();


        // Создаём основную таблицу Swoole
        static::$table = new Table(static::$tableSize);


        foreach (static::$tableSchema['columns'] as $col => $def) {
            static::$table->column($col, $def['swoole'][0], $def['swoole'][1] ?? null);
        }
        static::$table->create();

        // Создаём индексы
        foreach (static::$indexes as $key => $col) {
            $tbl = new Table(static::$tableSize);
            $tbl->column('id', Table::TYPE_INT);
            $tbl->create();
            static::$indexTables[$key] = $tbl;
        }

        static::ensureTableExists(static::$tableName, static::$tableSchema);
        $count = static::loadAll(static::$tableName);

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
        }
        return count($rows);
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
        foreach (static::$indexes as $key => $col) {
            $value = mb_strtolower(trim($row[$col] ?? ''));
            if ($value !== '') {
                static::$indexTables[$key]->set((string)$value, ['id' =>  (int)$row['id']]);
            }
        }

        // Помечаем для синхронизации
        static::$dirtyIds[(int)$row['id']] = true;
    }

    /**
     * Обновление записи
     */
    public static function update(array $data): void
    {

        $id = (int)($data['id'] ?? 0);

        $current = static::$table->get((string)$id);
        if (!$current) {
            self::$logger->warning("Attempted to update non-existent user: " . json_encode($data));
            return;
        }

        $updated = array_merge($current, static::castRowToSchema($data));
        static::$table->set((string)$id, $updated);

        // Обновляем индексы
        foreach (static::$indexes as $key => $col) {
            $oldValue = mb_strtolower(trim($current[$col] ?? ''));
            $newValue = mb_strtolower(trim($updated[$col] ?? ''));
            if ($oldValue !== '' && $oldValue !== $newValue) {
                static::$indexTables[$key]->del($oldValue);
            }
            if ($newValue !== '') {
                static::$indexTables[$key]->set($newValue, ['id' => $id]);
            }
        }

        // Помечаем для синхронизации
        static::$dirtyIds[(int)$id] = true;
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

            if ($fillDefaults && !$hasValue) {
                $defaultValue = $def['default'] ?? Defaults::NONE;
                $resolved = Defaults::resolve($defaultValue);
                if ($resolved !== null) {
                    $row[$col] = $resolved;
                }
            }

            if ($hasValue) {
                $value = $data[$col];
                switch ($def['swoole'][0]) {
                    case Table::TYPE_INT:
                        $row[$col] = (int)$value;
                        break;
                    case Table::TYPE_FLOAT:
                        $row[$col] = (float)$value;
                        break;
                    case Table::TYPE_STRING:
                    default:
                        $row[$col] = (string)$value;
                        break;
                }
            }
        }

        return $row;
    }

    /**
     * Найти запись по индексу
     */
    public static function findByIndex(string $indexKey, string $value): ?array
    {
        $value = mb_strtolower(trim($value));
        $indexRow = static::$indexTables[$indexKey]->get($value);

        if ($indexRow === false || !isset($indexRow['id'])) {
            return null;
        }

        $mainRow = static::$table->get((string)$indexRow['id']);
        return $mainRow !== false ? $mainRow : null;
    }

    /**
     * Получить запись по ID
     */
    public static function findById(int $id): ?array
    {
        return static::$table->get((string)$id);
    }


    /**
     * Синхронизация всех изменений в MySQL
     * Работает только с помеченными ID (dirty tracking)
     */
    public static function syncToDatabase(int $batchSize = 100): void
    {
        if (empty(static::$dirtyIds)) {
            return;
        }

        if (empty(static::$tableName) || empty(static::$tableSchema['columns'])) {
            self::$logger->warning(static::$className . " syncToDatabase: tableName or tableSchema['columns'] is empty");
            return;
        }

        // Собираем список колонок, которые реально присутствуют в MySQL (имеют 'sql')
        $columns = [];
        foreach (static::$tableSchema['columns'] as $col => $def) {
            if (!empty($def['sql'])) {
                $columns[] = $col;
            }
        }

        if (empty($columns)) {
            self::$logger->warning(static::$className . " syncToDatabase: no SQL columns found in schema");
            return;
        }

        $db = Database::getInstance();
        $dirtyIds = array_keys(static::$dirtyIds);

        // Разбиваем на батчи
        $chunks = array_chunk($dirtyIds, $batchSize);

        $startAll = microtime(true);
        $totalRows = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $startChunk = microtime(true);

            $values = [];
            $placeholders = [];

            foreach ($chunk as $id) {
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
                $totalRows += $rows;

                self::$logger->info(static::$className . " syncToDatabase: synced {$rows} rows in chunk #{$chunkIndex} ({$chunkTime} ms)");
            } catch (\Throwable $e) {
                self::$logger->error(static::$className . " syncToDatabase error: " . $e->getMessage(), [
                    'sql' => $sql,
                    'rows' => count($placeholders)
                ]);
            }
        }

        $totalTime = round((microtime(true) - $startAll) * 1000, 2);
        self::$logger->info(static::$className . " syncToDatabase: total {$totalRows} rows synced in {$totalTime} ms");

        // Очищаем список dirty ID после синхронизации
        static::$dirtyIds = [];
    }
}
