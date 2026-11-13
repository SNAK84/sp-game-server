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

    // Версия + число ID + 16 точных uint64. Индексы заново строятся из SQL
    // при старте; двоичный формат не меняет API и долговечные игровые данные.
    protected const INDEX_IDS_PER_PART = 16;
    protected const INDEX_IDS_BYTES = 2 + 8 * self::INDEX_IDS_PER_PART;

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
    private static array $loading = [];
    private static array $syncSequences = [];
    /** Адрес ID в каждом неуникальном индексе; служебная RAM-таблица, не SQL. */
    private static array $indexPositions = [];

    protected static function guarded(callable $operation): mixed
    {
        return RepositoryGuard::run(static::$className . ':runtime', $operation);
    }

    private static function nextSyncGeneration(): int
    {
        $entry = self::$syncSequences[static::class] ?? null;
        if ($entry === null || $entry[0] !== static::$syncTable) {
            $entry = self::$syncSequences[static::class] = [static::$syncTable, new \Swoole\Atomic\Long(0)];
        }
        return $entry[1]->add(1);
    }

    /** Физические колонки могут отличаться от логического SQL-образа. */
    public static function runtimeColumns(): array
    {
        return array_map(static fn(array $definition): array => $definition['swoole'], static::$tableSchema['columns']);
    }

    /** Временная история может не загружать уже просроченные SQL-строки. */
    protected static function loadingFilter(): array { return ['', []]; }

    /** Единый бюджет для init, диагностики и расчёта памяти; не размер RSS. */
    public static function runtimeLayout(): array
    {
        $main = RuntimeCapacity::budget(static::$className, static::$tableSize);
        return [
            'mainBuckets' => $main, 'mainConflict' => 1.0,
            'indexBuckets' => RuntimeCapacity::indexBudget(static::$className, $main), 'indexConflict' => 1.0,
            'indexIdsBytes' => self::INDEX_IDS_BYTES, 'indexIdsPerPart' => self::INDEX_IDS_PER_PART,
            'positionBuckets' => $main, 'positionConflict' => 1.0,
            'positionColumns' => array_keys(array_filter(static::$indexes, static fn(array $index): bool => !$index['Unique'])),
            // Живые dirty + запас tombstones; SQL-down не допускает безлимитного роста.
            'syncBuckets' => empty(static::$tableName) ? 64 : 2 * $main,
            'syncConflict' => 1.0,
        ];
    }

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

        $layout = static::runtimeLayout();
        static::$syncTable = new Table($layout['syncBuckets'], $layout['syncConflict']);
        // Флаги действий
        static::$syncTable->column('need_update', Table::TYPE_INT);
        static::$syncTable->column('need_delete', Table::TYPE_INT);
        // Храним последний ID (для автоинкремента)
        static::$syncTable->column('last_id', Table::TYPE_INT);
        static::$syncTable->column('dirty_since', Table::TYPE_FLOAT);
        if (!static::$syncTable->create()) throw new \RuntimeException('Не создан журнал сохранения ' . static::$className);
        self::$syncSequences[static::class] = [static::$syncTable, new \Swoole\Atomic\Long(0)];
        // Инициализация мета-строки
        if (!static::$syncTable->exists('meta')) {
            if (!static::$syncTable->set('meta', [
                'need_update' => 0,
                'need_delete' => 0,
                'last_id' => 0
            ])) throw new \RuntimeException('Не создана meta журнала ' . static::$className);
        }

        // Создаём основную таблицу Swoole
        static::$table = new Table($layout['mainBuckets'], $layout['mainConflict']);

        $count = 0;
        foreach (static::runtimeColumns() as $col => $def) {
            static::$table->column($col, $def[0], $def[1] ?? 0);
        }
        if (!static::$table->create()) throw new \RuntimeException('Не создана runtime таблица ' . static::$className);

        // Создаём индексы
        foreach (static::$indexes as $key => $val) {
            static::initIndex($key);
        }
        static::indexPositionTable();

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
        [$filter, $parameters] = static::loadingFilter();
        $total = (int)$db->fetchOne('SELECT COUNT(*) AS n FROM `' . $tableName . '`'
            . ($filter === '' ? '' : ' WHERE ' . $filter), $parameters)['n'];
        if ($total > static::runtimeLayout()['mainBuckets']) {
            throw new \OverflowException('SQL-таблица ' . $tableName . ' превышает runtime-бюджет: '
                . $total . ' > ' . static::runtimeLayout()['mainBuckets'] . '. Запуск без полной загрузки запрещён.');
        }
        $primary = null;
        foreach (static::$tableSchema['indexes'] as $index) {
            if ($index['type'] === 'PRIMARY') { $primary = $index['fields']; break; }
        }
        if ($primary === null) throw new \LogicException('Нет PRIMARY для загрузки ' . $tableName);
        $fields = '`' . implode('`,`', $primary) . '`';
        $cursor = null;
        $count = 0;
        $maxId = 0;
        $bulk = static::$table->count() === 0;
        foreach (static::$indexTables as $table) if ($table->count() !== 0) $bulk = false;
        if ($bulk) self::$loading[static::class] = true;
        try {
            do {
                // Keyset по настоящему PRIMARY, включая составной ключ EntitySettings.
                // В PHP одновременно находится одна ограниченная пачка, не вся SQL-таблица.
                $conditions = $filter === '' ? [] : [$filter];
                if ($cursor !== null) $conditions[] = '(' . $fields . ') > (' . implode(',', array_fill(0, count($primary), '?')) . ')';
                $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
                $rows = $db->fetchAll('SELECT * FROM `' . $tableName . '`' . $where . ' ORDER BY ' . $fields . ' LIMIT 512', array_merge($parameters, $cursor ?? []));
                foreach ($rows as $row) {
                    $id = static::add($row, false);
                    if ($id === null) throw new \RuntimeException('Не загружена строка ' . $tableName);
                    if ($bulk) self::appendLoadedIndexes($id);
                    $maxId = max($maxId, (int)($row['id'] ?? 0));
                    $cursor = array_map(static fn(string $field) => $row[$field], $primary);
                    ++$count;
                }
            } while (count($rows) === 512);
        } finally {
            if ($bulk) unset(self::$loading[static::class]);
        }

        // Обновляем last_id в syncTable
        if (isset(static::$syncTable)) {
            if ($filter !== '' && isset(static::$tableSchema['columns']['id'])) {
                $maxId = max($maxId, (int)($db->fetchOne('SELECT MAX(id) AS n FROM `' . $tableName . '`')['n'] ?? 0));
            }
            static::setLastId($maxId);
        }


        return $count;
    }

    /** Startup: добавляем только хвостовой блок, не переписываем растущую группу N раз. */
    private static function appendLoadedIndexes(int $id): void
    {
        $row = static::$table->get((string)$id);
        if ($row === false) throw new \RuntimeException('Потеряна загружаемая строка ' . static::$className);
        foreach (static::$indexes as $name => $index) {
            $values = array_map(static fn(string $field) => $row[$field] ?? null, (array)$index['key']);
            static::addIndex($name, $values, $id);
        }
    }

    // ===============================
    // Инициализация индекса
    // ===============================
    protected static function initIndex(string $indexKey): void
    {
        $Unique = static::$indexes[$indexKey]['Unique'];

        //self::$logger->info(static::$className . " initIndex Unique" . $Unique, static::$indexes[$indexKey]);

        $layout = static::runtimeLayout();
        $tbl = new Table($layout['indexBuckets'], $layout['indexConflict']);
        $tbl->column('id', Table::TYPE_INT);
        if (!$Unique) {
            //self::$logger->info(static::$className . " initIndex unUnique", static::$indexes[$indexKey]);

            $tbl->column('ids', Table::TYPE_STRING, self::INDEX_IDS_BYTES);
        }
        if (!$tbl->create()) throw new \RuntimeException('Не создан индекс ' . static::$className . '/' . $indexKey);
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
        foreach (static::indexChunks($table, $key) as [$row, $chunk]) {
            foreach ($chunk as $id) $ids[] = $id;
            $parts[] = $row;
        }
        return $ids;
    }

    /** Один блок16 ID, без материализации всего неуникального индекса. */
    protected static function indexChunks(Table $table, string $key): \Generator
    {
        for ($part = 0; ; ++$part) {
            $row = $table->get($key . ($part === 0 ? '' : ':' . $part));
            if ($row === false) break;
            yield [$row, self::decodeIndexChunk($row)];
        }
    }

    /** Версия2 хранит общий count в id головного блока; тела ID прежние. */
    private static function decodeIndexChunk(array $row): array
    {
        $encoded = $row['ids'];
        if (strlen($encoded) >= 2 && in_array($encoded[0], ["\x01", "\x02"], true)) {
            $size = ord($encoded[1]);
            if ($size < 1 || $size > self::INDEX_IDS_PER_PART || strlen($encoded) !== 2 + 8 * $size) {
                throw new \RuntimeException('Повреждён двоичный блок индекса ' . static::$className);
            }
            $chunk = array_values(unpack('J*', substr($encoded, 2)));
        } else {
            // Старые диагностические runtime-снимки; не клиентские значения.
            $chunk = strlen($encoded) <= 2048 && str_starts_with($encoded, 'a:')
                ? @unserialize($encoded, ['allowed_classes' => false]) : false;
        }
        if (!is_array($chunk)) throw new \RuntimeException('Повреждён список ID индекса ' . static::$className);
        foreach ($chunk as $id) {
            if (!is_int($id)) throw new \RuntimeException('Некорректный ID в индексе ' . static::$className);
        }
        return $chunk;
    }

    /** Поток кандидатов, не снимок. После паузы вызывающий перепроверяет строку:
     * swap-delete/изменение индекса между порциями допускают повтор/пропуск ID
     * до следующего обхода, но не дают права читать устаревший объект.
     */
    public static function iterateIndexIds(string $indexKey, array|string $val): \Generator
    {
        $key = static::buildIndexKey($val);
        $table = static::$indexTables[$indexKey] ?? null;
        if ($table === null) return;
        if (static::$indexes[$indexKey]['Unique'] ?? false) {
            $row = $table->get($key);
            if ($row !== false) yield $row['id'];
            return;
        }
        foreach (static::indexChunks($table, $key) as [, $ids]) foreach ($ids as $id) yield $id;
    }

    /** Одна строка на ID: для каждого индекса hash группы16B + позиция uint64. */
    protected static function indexPositionTable(): ?Table
    {
        $layout = static::runtimeLayout();
        $columns = $layout['positionColumns'];
        if ($columns === []) return null;
        $entry = self::$indexPositions[static::class] ?? null;
        if ($entry !== null && $entry['main'] === static::$table && $entry['columns'] === $columns) return $entry['table'];
        $table = new Table($layout['positionBuckets'], $layout['positionConflict']);
        foreach ($columns as $name) $table->column($name, Table::TYPE_STRING, 24);
        if (!$table->create()) throw new \RuntimeException('Не созданы позиции индексов ' . static::$className);
        self::$indexPositions[static::class] = ['main' => static::$table, 'columns' => $columns, 'table' => $table];
        return $table;
    }

    private static function indexLocation(string $name, int $id): ?array
    {
        $encoded = (static::indexPositionTable()?->get((string)$id) ?: [])[$name] ?? '';
        if ($encoded === '') return null;
        if (strlen($encoded) !== 24) throw new \RuntimeException('Повреждена позиция индекса ' . static::$className);
        $position = unpack('J', substr($encoded, 16))[1];
        if ($position < 1 || $position > static::runtimeLayout()['mainBuckets']) throw new \RuntimeException('Позиция индекса за пределами бюджета.');
        return ['key' => bin2hex(substr($encoded, 0, 16)), 'position' => $position - 1];
    }

    /** В journal только изменяемые native-строки, не все части группы. */
    private static function writeIndexEntry(Table $table, string $key, array|false $after, array &$undo): void
    {
        $before = $table->get($key);
        if ($before === $after) return;
        $slot = spl_object_id($table) . ':' . $key;
        if (!array_key_exists($slot, $undo)) $undo[$slot] = [$table, $key, $before];
        self::restoreRuntimeEntry($table, $key, $after);
    }

    private static function writeIndexLocation(string $name, int $id, ?string $key, int $position, array &$undo): void
    {
        $table = static::indexPositionTable();
        if ($table === null) throw new \LogicException('Нет таблицы позиций неуникального индекса.');
        $before = $table->get((string)$id);
        if ($before === false && $key === null) return;
        if ($before === false && $table->count() >= static::runtimeLayout()['positionBuckets']) {
            throw new \OverflowException('Достигнут бюджет позиций ' . static::$className);
        }
        $row = $before === false ? array_fill_keys(static::runtimeLayout()['positionColumns'], '') : $before;
        $row[$name] = $key === null ? '' : hex2bin($key) . pack('J', $position + 1);
        self::writeIndexEntry($table, (string)$id, array_filter($row, static fn($value) => $value !== '') === [] ? false : $row, $undo);
    }

    private static function restoreIndexUndo(array $undo): array
    {
        $failures = [];
        foreach (array_reverse($undo) as [$table, $key, $before]) {
            try { self::restoreRuntimeEntry($table, $key, $before); }
            catch (\Throwable $error) { $failures[] = 'index:' . $key . ': ' . $error->getMessage(); }
        }
        return $failures;
    }

    /** Самостоятельный protected-вызов атомарен; runtime row передаёт общий undo.
     * @param-out array $undo
     */
    private static function mutateIndex(callable $operation, ?array &$undo): void
    {
        $owned = $undo === null;
        $undo ??= [];
        try { $operation($undo); }
        catch (\Throwable $error) {
            if ($owned && ($failures = self::restoreIndexUndo($undo)) !== []) {
                self::$logger->critical('Не восстановлены блоки индекса', ['repository' => static::$className, 'failures' => $failures]);
                throw new \RuntimeException('Не выполнен rollback индекса ' . static::$className, 0, $error);
            }
            throw $error;
        }
    }

    private static function indexPartKey(string $key, int $part): string { return $key . ($part === 0 ? '' : ':' . $part); }

    private static function writeIndexChunk(Table $table, string $key, int $part, array $ids, int $total, array &$undo): void
    {
        $row = $ids === [] ? false : ['id' => $part === 0 ? $total : $ids[0],
            'ids' => "\x02" . chr(count($ids)) . pack('J*', ...$ids)];
        if (count($ids) > self::INDEX_IDS_PER_PART) throw new \LengthException('Слишком большой блок индекса.');
        self::writeIndexEntry($table, self::indexPartKey($key, $part), $row, $undo);
    }

    private static function indexHead(string $name, string $key, array &$undo): array|false
    {
        $table = static::$indexTables[$name];
        $head = $table->get($key);
        if ($head === false) return false;
        if (!str_starts_with($head['ids'], "\x02")) {
            // Только старые диагностические Table; production строится из SQL в v2.
            $ids = static::readIndexIds($table, $key, $parts);
            static::replaceIndexIds($table, $key, $ids, $parts, $undo);
            $head = $table->get($key);
        }
        $ids = self::decodeIndexChunk($head);
        if ($head['id'] < 1 || $head['id'] > static::runtimeLayout()['mainBuckets']
            || count($ids) !== min($head['id'], self::INDEX_IDS_PER_PART)) throw new \RuntimeException('Повреждён размер группы индекса.');
        return $head;
    }

    /** Точные целые, включая ID выше 2^53 и предельный PHP_INT_MAX. */
    protected static function encodeIndexIds(array $ids): array
    {
        $parts = [];
        foreach (array_chunk($ids, self::INDEX_IDS_PER_PART) as $chunk) {
            $encoded = "\x02" . chr(count($chunk)) . pack('J*', ...$chunk);
            if (strlen($encoded) > self::INDEX_IDS_BYTES) {
                throw new \LengthException('Часть индекса превышает допустимый размер.');
            }
            $parts[] = ['id' => $parts === [] ? count($ids) : $chunk[0], 'ids' => $encoded];
        }
        return $parts;
    }

    /** Диагностическая замена/upgrade: меняются действительно все части.
     * @param-out array $undo
     */
    protected static function replaceIndexIds(Table $table, string $key, array $ids, array $before, ?array &$undo = null): void
    {
        self::mutateIndex(static function (array &$journal) use ($table, $key, $ids, $before): void {
            $after = static::encodeIndexIds($ids);
            $name = array_search($table, static::$indexTables, true);
            if ($name !== false) foreach ($before as $row) foreach (self::decodeIndexChunk($row) as $oldId) {
                if ((self::indexLocation($name, $oldId)['key'] ?? null) === $key) self::writeIndexLocation($name, $oldId, null, 0, $journal);
            }
            for ($part = 0, $count = max(count($after), count($before)); $part < $count; ++$part) {
                self::writeIndexEntry($table, self::indexPartKey($key, $part), $after[$part] ?? false, $journal);
            }
            if ($name !== false) foreach ($ids as $position => $id) self::writeIndexLocation($name, $id, $key, $position, $journal);
        }, $undo);
    }

    // ===============================
    // Добавление в индекс
    // ===============================
    protected static function addIndex(string $indexKey, array|string $value, int $id, ?array &$undo = null): void
    {
        $key = static::buildIndexKey($value);
        if ($key === '') return;

        $Unique = static::$indexes[$indexKey]['Unique'] ?? false;

        $tbl = static::$indexTables[$indexKey] ?? null;
        if (!$tbl) return;

        self::mutateIndex(static function (array &$journal) use ($Unique, $tbl, $key, $id, $indexKey): void {
            if ($Unique) { self::writeIndexEntry($tbl, $key, ['id' => $id], $journal); return; }
            $head = self::indexHead($indexKey, $key, $journal);
            $location = self::indexLocation($indexKey, $id);
            if ($location !== null) {
                if ($location['key'] !== $key) throw new \RuntimeException('ID уже состоит в другой группе индекса.');
                $row = $tbl->get(self::indexPartKey($key, intdiv($location['position'], self::INDEX_IDS_PER_PART)));
                if ($row === false || (self::decodeIndexChunk($row)[$location['position'] % self::INDEX_IDS_PER_PART] ?? null) !== $id) {
                    throw new \RuntimeException('Позиция ID не соответствует блоку индекса.');
                }
                return;
            }
            $total = $head === false ? 0 : (int)$head['id'];
            if ($total >= static::runtimeLayout()['mainBuckets']) throw new \OverflowException('Достигнут бюджет группы индекса.');
            $part = intdiv($total, self::INDEX_IDS_PER_PART);
            $tail = $total % self::INDEX_IDS_PER_PART === 0 ? [] : self::decodeIndexChunk($tbl->get(self::indexPartKey($key, $part)));
            if (count($tail) !== $total % self::INDEX_IDS_PER_PART) throw new \RuntimeException('Повреждён хвост индекса.');
            $tail[] = $id;
            self::writeIndexChunk($tbl, $key, $part, $tail, $total + 1, $journal);
            if ($part > 0) { $head['id'] = $total + 1; self::writeIndexEntry($tbl, $key, $head, $journal); }
            self::writeIndexLocation($indexKey, $id, $key, $total, $journal);
        }, $undo);
    }

    /**
     * Удаление значения из индекса
     */
    protected static function removeIndex(string $indexKey, array|string $val, ?int $id = null, ?array &$undo = null): void
    {
        $key = static::buildIndexKey($val);
        if ($key === '') return;

        $Unique = static::$indexes[$indexKey]['Unique'] ?? false;
        $tbl = static::$indexTables[$indexKey] ?? null;
        if (!$tbl) return;

        self::mutateIndex(static function (array &$journal) use ($Unique, $tbl, $key, $id, $indexKey): void {
            if ($Unique) { self::writeIndexEntry($tbl, $key, false, $journal); return; }
            $head = self::indexHead($indexKey, $key, $journal);
            if ($head === false) return;
            if ($id === null) {
                // Полное удаление действительно меняет все блоки и позиции.
                foreach (static::indexChunks($tbl, $key) as $part => [, $ids]) {
                    foreach ($ids as $member) self::writeIndexLocation($indexKey, $member, null, 0, $journal);
                    self::writeIndexEntry($tbl, self::indexPartKey($key, $part), false, $journal);
                }
                return;
            }
            $location = self::indexLocation($indexKey, $id);
            if ($location === null || $location['key'] !== $key) return;
            $position = $location['position'];
            $total = (int)$head['id'];
            if ($position >= $total) throw new \RuntimeException('Позиция ID за пределами группы.');
            $part = intdiv($position, self::INDEX_IDS_PER_PART);
            $lastPart = intdiv($total - 1, self::INDEX_IDS_PER_PART);
            $chunk = self::decodeIndexChunk($tbl->get(self::indexPartKey($key, $part)));
            $tail = $part === $lastPart ? $chunk : self::decodeIndexChunk($tbl->get(self::indexPartKey($key, $lastPart)));
            if (($chunk[$position % self::INDEX_IDS_PER_PART] ?? null) !== $id || count($tail) !== ($total - 1) % self::INDEX_IDS_PER_PART + 1) {
                throw new \RuntimeException('Позиция ID не соответствует блоку индекса.');
            }
            $last = array_pop($tail);
            if ($part === $lastPart) $chunk = $tail;
            if ($position < $total - 1) {
                $chunk[$position % self::INDEX_IDS_PER_PART] = $last;
                self::writeIndexLocation($indexKey, $last, $key, $position, $journal);
            }
            self::writeIndexChunk($tbl, $key, $part, $chunk, $total - 1, $journal);
            if ($lastPart !== $part) self::writeIndexChunk($tbl, $key, $lastPart, $tail, $total - 1, $journal);
            if ($part !== 0) { $head['id'] = $total - 1; self::writeIndexEntry($tbl, $key, $head, $journal); }
            self::writeIndexLocation($indexKey, $id, null, 0, $journal);
        }, $undo);
    }

    /**
     * Обновление индекса (удаляем старое значение, добавляем новое)
     */
    protected static function updateIndex(string $indexKey, array|string $oldValue, array|string $newValue, int $id, ?array &$undo = null): void
    {
        self::mutateIndex(static function (array &$journal) use ($indexKey, $oldValue, $newValue, $id): void {
            static::removeIndex($indexKey, $oldValue, $id, $journal);
            static::addIndex($indexKey, $newValue, $id, $journal);
        }, $undo);
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

        // Каждое изменение имеет поколение, даже если строка уже dirty.
        $row['need_update'] = 1;
        $row['need_delete'] = 0;
        $row['last_id'] = self::nextSyncGeneration();
        $row = self::stampDirtyMarker($row);
            if (!static::$syncTable->set((string)$id, $row) || static::$syncTable->get((string)$id) !== $row) {
                throw new \RuntimeException('Не удалось сохранить sync marker ' . static::$className);
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
        $row['last_id'] = self::nextSyncGeneration();
        $row = self::stampDirtyMarker($row);
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

    private static function stampDirtyMarker(array $row): array
    {
        // Старые ограниченные diagnostic Table не содержат новую физическую колонку.
        if (array_key_exists('dirty_since', static::$syncTable->get('meta') ?: [])) {
            $row['dirty_since'] = (float)($row['dirty_since'] ?? 0) > 0 ? $row['dirty_since'] : microtime(true);
        }
        return $row;
    }

    public static function syncStats(float $now): array
    {
        $count = 0; $oldest = $now;
        foreach (static::$syncTable as $key => $marker) {
            if ($key === 'meta' || empty($marker['need_update']) && empty($marker['need_delete'])) continue;
            ++$count;
            if (($marker['dirty_since'] ?? 0) > 0) $oldest = min($oldest, $marker['dirty_since']);
        }
        return ['pending' => $count, 'oldestDirtySeconds' => max(0.0, $now - $oldest)];
    }

    /** Старый SQL ACK не имеет права снимать новое поколение dirty/DELETE. */
    protected static function acknowledgeSync(int $id, array $marker, ?array $row): bool
    {
        return static::guarded(static function () use ($id, $marker, $row): bool {
            if (static::$syncTable->get((string)$id) !== $marker || static::findExistingById($id) !== $row) return false;
            if (!static::$syncTable->del((string)$id)) throw new \RuntimeException('Не подтверждён журнал ' . static::$className);
            return true;
        });
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
        static::guarded(fn() => self::writeGuardedRuntimeRow($row, $before, $sync, $adding, $runtimeId, $syncMarker));
    }

    private static function writeGuardedRuntimeRow(array $row, ?array $before, bool $sync, bool $adding, ?int $runtimeId, ?array $syncMarker): void
    {
        // EntitySettings хранит составной ключ Table, но не имеет колонки id.
        $id = $runtimeId ?? (int)$row['id'];
        if ((static::$table->get((string)$id) ?: null) !== $before) {
            throw new \RuntimeException('Runtime строка изменилась до записи ' . static::$className);
        }
        if ($before === null && static::$table->count() >= static::runtimeLayout()['mainBuckets']) {
            throw new \OverflowException('Достигнут runtime-бюджет ' . static::$className . ': ' . static::runtimeLayout()['mainBuckets']);
        }
        $marker = static::$syncTable->get((string)$id);
        $changes = $snapshots = [];
        foreach (static::$indexes as $name => $index) {
            if (isset(self::$loading[static::class])) break;
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
        }

        try {
            if (!static::$table->set((string)$id, $row) || static::$table->get((string)$id) !== $row) {
                throw new \RuntimeException('Не удалось сохранить runtime строку ' . static::$className);
            }
            foreach ($changes as [$name, $old, $new]) {
                if ($before !== null && $old !== $new) static::updateIndex($name, $old, $new, $id, $snapshots);
                else static::addIndex($name, $new, $id, $snapshots);
            }
            if ($sync && static::$tableName !== '') {
                if ($syncMarker === null) static::markForUpdate($id);
                else {
                    $syncMarker['last_id'] = self::nextSyncGeneration();
                    $syncMarker = self::stampDirtyMarker($syncMarker);
                    if (!static::$syncTable->set((string)$id, $syncMarker) || static::$syncTable->get((string)$id) !== $syncMarker) {
                        throw new \RuntimeException('Не удалось сохранить marker восстановления ' . static::$className);
                    }
                }
            }
        } catch (\Throwable $error) {
            static::rollbackRuntimeRow($id, $before, $marker, $snapshots, $error);
        }
    }

    /** Одна компенсация для add/update, delete/discard и восстановления DELETE. */
    private static function rollbackRuntimeRow(int $id, ?array $before, array|false $marker, array $snapshots, \Throwable $error): never
    {
        $failures = self::restoreIndexUndo($snapshots);
        try { self::restoreRuntimeEntry(static::$table, (string)$id, $before ?? false); }
        catch (\Throwable $rollback) { $failures[] = 'row: ' . $rollback->getMessage(); }
        try { self::restoreRuntimeEntry(static::$syncTable, (string)$id, $marker); }
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
        static::guarded(fn() => self::removeGuardedRuntimeRow($row, $before));
    }

    private static function removeGuardedRuntimeRow(array $row, ?array $before): void
    {
        $id = (int)$row['id'];
        if ((static::$table->get((string)$id) ?: null) !== $before) {
            throw new \RuntimeException('Runtime строка изменилась до удаления ' . static::$className);
        }
        $marker = static::$syncTable->get((string)$id);
        $snapshots = $changes = [];
        $seen = [];
        // При discard attempted-образ может отличаться от реально оставшейся main row.
        foreach ($before === null ? [$row] : [$row, $before] as $image) {
            foreach (static::$indexes as $name => $index) {
                $table = static::$indexTables[$name] ?? null;
                if ($table === null) continue;
                $values = array_map(static fn($field) => $image[$field] ?? null, (array)$index['key']);
                $key = static::buildIndexKey($values);
                if (isset($seen[$name][$key])) continue;
                if ($index['Unique']) {
                    $entry = $table->get($key);
                    // Отсутствующая attempted-строка не владеет чужим unique-ключом.
                    if ($entry === false || (int)$entry['id'] !== $id) continue;
                }
                $seen[$name][$key] = true;
                $changes[] = [$name, $values];
            }
        }
        try {
            foreach ($changes as [$name, $values]) {
                static::removeIndex($name, $values, $id, $snapshots);
            }
            if (static::$table->exists((string)$id)
                && (!static::$table->del((string)$id) || static::$table->exists((string)$id))) {
                throw new \RuntimeException('Не удалось удалить runtime строку ' . static::$className);
            }
            static::markForDelete($id);
        } catch (\Throwable $error) {
            static::rollbackRuntimeRow($id, $before, $marker, $snapshots, $error);
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
        return RepositoryGuard::run(static::$className . ':save', fn() => static::guarded(fn() => self::flushGuardedCheckpointRow($id)));
    }

    private static function flushGuardedCheckpointRow(int $id): array
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
        if ($marker && empty($marker['need_delete']) && !static::$syncTable->del((string)$id)) {
            throw new \RuntimeException('Не подтверждён журнал checkpoint.');
        }
        return $stored;
    }

    /**
     * Подтвердить один уже поставленный DELETE до удаления durable receipt.
     * Не общий saver: ошибка не поглощается, другие строки не затрагиваются.
     */
    public static function flushCheckpointDeletion(int $id): void
    {
        RepositoryGuard::run(static::$className . ':save', fn() => static::guarded(fn() => self::flushGuardedCheckpointDeletion($id)));
    }

    private static function flushGuardedCheckpointDeletion(int $id): void
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

    public static function saveGuardKey(): string { return static::$className . ':save'; }

    /** Вызывается под world barrier; возвращает логические строки, не ссылки в Table. */
    public static function captureSaveSnapshot(): array
    {
        if (static::$tableName === '') return [];
        return static::guarded(static function (): array {
            $snapshot = [];
            foreach (static::$syncTable as $key => $marker) {
                $id = (int)$key;
                if ($id <= 0 || MiningCheckpoints::isProtected(static::$tableName, $id)) continue;
                if (empty($marker['need_update']) && empty($marker['need_delete'])) continue;
                $row = empty($marker['need_delete']) ? static::findExistingById($id) : null;
                if (empty($marker['need_delete']) && $row === null) throw new \RuntimeException('Отсутствует dirty-строка ' . static::$className);
                $snapshot[$id] = ['marker' => $marker, 'row' => $row];
                RepositorySaver::assertSnapshotBudget();
            }
            return $snapshot;
        });
    }

    /** Только SQL, без ACK. Ошибка не поглощается: транзакция saver должна откатиться. */
    public static function writeSaveSnapshot(array $snapshot, Database $db, int $batchSize = 100): void
    {
        if ($snapshot === []) return;
        $columns = array_keys(array_filter(static::$tableSchema['columns'], static fn(array $definition): bool => !empty($definition['sql'])));
        if ($columns === []) throw new \LogicException('Нет SQL-колонок snapshot.');
        $updates = array_filter($snapshot, static fn(array $entry): bool => $entry['row'] !== null);
        foreach (array_chunk($updates, max(1, $batchSize), true) as $chunk) {
            $values = []; $placeholders = [];
            foreach ($chunk as $entry) {
                foreach ($columns as $column) {
                    $value = $entry['row'][$column] ?? null;
                    if ($value === '' && !empty(static::$tableSchema['columns'][$column]['sql_empty_as_null'])) $value = null;
                    if (is_float($value)) $value = self::checkpointParameter(self::checkpointStorageNumber($value, static::$tableSchema['columns'][$column]['sql']));
                    $values[] = $value;
                }
                $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            }
            $db->query('INSERT INTO `' . static::$tableName . '` (`' . implode('`,`', $columns) . '`) VALUES '
                . implode(',', $placeholders) . ' ON DUPLICATE KEY UPDATE '
                . implode(',', array_map(static fn(string $column): string => '`' . $column . '`=VALUES(`' . $column . '`)', $columns)), $values);
        }
        $deletes = array_filter($snapshot, static fn(array $entry): bool => $entry['row'] === null);
        foreach (array_chunk(array_keys($deletes), max(1, $batchSize)) as $ids) {
            $clauses = []; $values = [];
            foreach ($ids as $id) {
                $identity = static::sqlDeletionIdentity((int)$id);
                $clauses[] = '(' . implode(' AND ', array_map(static fn(string $field): string => '`' . $field . '`=?', array_keys($identity))) . ')';
                array_push($values, ...array_values($identity));
            }
            $db->query('DELETE FROM `' . static::$tableName . '` WHERE ' . implode(' OR ', $clauses), $values);
        }
    }

    protected static function sqlDeletionIdentity(int $id): array { return ['id' => $id]; }

    /** Только после успешного COMMIT; новая generation остаётся dirty. */
    public static function acknowledgeSaveSnapshot(array $snapshot): void
    {
        foreach ($snapshot as $id => $entry) static::acknowledgeSync((int)$id, $entry['marker'], $entry['row']);
    }

    /** Синхронизация всех изменений в MySQL; только помеченные ID (dirty tracking). */
    public static function syncToDatabase(int $batchSize = 100): void
    {
        RepositoryGuard::run(static::$className . ':save', fn() => self::syncGuardedToDatabase($batchSize));
    }

    private static function syncGuardedToDatabase(int $batchSize): void
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
                $submitted = [];

                foreach ($chunk as $id) {
                    // получаем строку из локального хранилища
                    [$marker, $row] = static::guarded(fn() => [static::$syncTable->get((string)$id), static::findExistingById($id)]);
                    if (!$marker || empty($marker['need_update']) || !empty($marker['need_delete'])) continue;
                    if ($row === false || $row === null) {
                        self::$logger->warning(static::$className . " syncToDatabase: dirty row {$id} is missing from runtime table");
                        continue;
                    }

                    $actualSyncedIds[] = $id;
                    $submitted[$id] = [$marker, $row];
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
                    static::acknowledgeSync($id, $submitted[$id][0], $submitted[$id][1]);
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
                $submitted = static::guarded(static function () use ($chunkDel): array {
                    $result = [];
                    foreach ($chunkDel as $id) {
                        $marker = static::$syncTable->get((string)$id);
                        if ($marker && !empty($marker['need_delete'])) $result[$id] = $marker;
                    }
                    return $result;
                });
                $chunkDel = array_keys($submitted);
                if ($chunkDel === []) continue;

                $clauses = []; $parameters = [];
                foreach ($chunkDel as $id) {
                    $identity = static::sqlDeletionIdentity((int)$id);
                    $clauses[] = '(' . implode(' AND ', array_map(static fn(string $field): string => '`' . $field . '`=?', array_keys($identity))) . ')';
                    array_push($parameters, ...array_values($identity));
                }
                $sqlDel = 'DELETE FROM `' . static::$tableName . '` WHERE ' . implode(' OR ', $clauses);

                try {
                    $db->query($sqlDel, $parameters);
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
                    static::acknowledgeSync($id, $submitted[$id], null);
                }
            }
        }

        $totalTime = round((microtime(true) - $startAll) * 1000, 2);
        self::$logger->info(static::$className . " syncToDatabase: total {$totalSynced} rows synced, {$totalDeleted} rows deleted in {$totalTime} ms");
    }
}
