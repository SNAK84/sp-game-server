<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Enums\FleetCommandType;
use Swoole\Table;

/**
 * Репозиторий команд флота (очередь действий).
 *
 * Хранит ожидающие и активированные команды флотов.
 *
 * Локальный порядок команд одного Fleet определяется id. execute_time
 * используется только после активации команды и задаёт глобальный порядок
 * игровых событий.
 */
class FleetCommands extends BaseRepository
{
    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'FleetCommands';
    protected static string $tableName = 'fleet_commands';

    /** @var array Схема таблицы */
    protected static array $tableSchema = [
        'columns' => [
            'id' => [
                'swoole' => [Table::TYPE_INT, 8],
                'sql' => 'BIGINT(20) UNSIGNED NOT NULL',
                'default' => Defaults::AUTOID,
            ],
            'fleet_id' => [
                'swoole' => [Table::TYPE_INT, 8],
                'sql' => 'BIGINT(20) UNSIGNED NOT NULL',
                'default' => 0,
            ],
            'command_type' => [
                'swoole' => [Table::TYPE_INT, 2],
                'sql' => 'SMALLINT(4) NOT NULL DEFAULT 0',
                'default' => 0,
            ],
            'execute_time' => [
                'swoole' => [Table::TYPE_FLOAT, 8],
                'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0',
                'default' => 0.0,
            ],
            'params' => [
                'swoole' => [Table::TYPE_STRING, 2048],
                'sql' => 'TEXT DEFAULT NULL',
                'default' => '',
            ],
            'executed' => [
                'swoole' => [Table::TYPE_INT, 1],
                'sql' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'default' => 0,
            ],
            'create_time' => [
                'swoole' => [Table::TYPE_FLOAT, 8],
                'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0',
                'default' => Defaults::MICROTIME,
            ],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_fleet', 'type' => 'INDEX', 'fields' => ['fleet_id']],
            ['name' => 'idx_execute_time', 'type' => 'INDEX', 'fields' => ['execute_time']],
        ],
    ];

    /** Индексы для Swoole */
    protected static array $indexes = [
        'fleet_id' => ['key' => ['fleet_id'], 'Unique' => false],
        // RAM-only: горячим селекторам не нужна выполненная/отменённая история.
        'fleet_state' => ['key' => ['fleet_id', 'executed'], 'Unique' => false],
        'execute_time' => ['key' => ['execute_time'], 'Unique' => false],
        'command_type' => ['key' => ['command_type'], 'Unique' => false],
    ];

    protected static Table $syncTable;
    private static ?\Swoole\Atomic\Long $scheduleRevision = null;
    private static ?\SplPriorityQueue $dueHeap = null;
    private static ?Table $heapTable = null;
    private static int $heapPid = 0;
    private static int $heapRevision = -1;
    private static bool $loadingHeap = false;
    private static array $changeFrames = [];
    private static int $changeSequence = 0;

    /** Вложенный локальный undo-журнал; SQL/снимок всей истории не нужен. */
    public static function beginChanges(array $fleetIds): int
    {
        $id = ++self::$changeSequence;
        self::$changeFrames[$id] = ['fleets' => array_fill_keys($fleetIds, true), 'rows' => []];
        return $id;
    }

    public static function endChanges(int $id): array
    {
        if (array_key_last(self::$changeFrames) !== $id) throw new \LogicException('Нарушен порядок undo-журналов команд.');
        $rows = self::$changeFrames[$id]['rows'];
        unset(self::$changeFrames[$id]);
        return $rows;
    }

    /** Журнал уже закрыт; восстановительные записи остаются видны внешнему frame. */
    public static function rollbackChanges(array $changes): void
    {
        foreach (array_reverse($changes, true) as $id => $before) {
            $current = static::findExistingById((int)$id);
            if ($before === null) {
                if ($current !== null) static::discardUncommittedRow($current);
            } elseif ($current === null) static::restoreDeletedRow($before);
            elseif ($current !== $before) static::update($before);
            if (static::findExistingById((int)$id) !== $before) {
                throw new \RuntimeException('Не удалось восстановить команду флота.');
            }
        }
    }

    private static function rememberChange(int $id, ?array $before, ?array $after): void
    {
        foreach (self::$changeFrames as &$frame) {
            if (!isset($frame['fleets'][(int)($before['fleet_id'] ?? 0)])
                && !isset($frame['fleets'][(int)($after['fleet_id'] ?? 0)])) continue;
            if (!array_key_exists($id, $frame['rows'])) $frame['rows'][$id] = $before;
        }
        unset($frame);
    }

    /** Без создания default-строк; нужен также при расчёте ресурсов принимающей планеты. */
    public static function getActiveDefenseCommands(): array
    {
        if (!isset(static::$table)) return [];
        $result = [];
        $rows = isset(static::$indexTables['command_type'])
            ? (static::findByIndex('command_type', (string)FleetCommandType::DEFEND->value) ?? [])
            : static::$table;
        foreach ($rows as $row) {
            if ((int)$row['command_type'] === FleetCommandType::DEFEND->value
                && !(int)$row['executed'] && (float)$row['execute_time'] > 0) $result[] = $row;
        }
        usort($result, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $result;
    }

    // =====================
    //  Инициализация
    // =====================

    public static function init(?RepositorySaver $saver = null): void
    {
        self::$scheduleRevision = new \Swoole\Atomic\Long(0);
        self::$loadingHeap = true;
        try { parent::init($saver); }
        finally { self::$loadingHeap = false; }
        self::ensureHeap();
    }

    private static function ensureHeap(): void
    {
        $revision = self::$scheduleRevision?->get() ?? 0;
        if (self::$dueHeap !== null && self::$heapTable === static::$table && self::$heapPid === getmypid()
            && self::$heapRevision === $revision && self::$dueHeap->count() <= static::$table->count() * 2 + 1024) return;
        self::$dueHeap = new \SplPriorityQueue();
        self::$dueHeap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        foreach (static::$table as $row) self::heapInsert($row);
        self::$heapTable = static::$table;
        self::$heapPid = getmypid();
        self::$heapRevision = $revision;
    }

    private static function heapInsert(array $row): void
    {
        if (!(int)$row['executed'] && (float)$row['execute_time'] > 0) {
            self::$dueHeap->insert(['id' => (int)$row['id'], 'time' => (float)$row['execute_time']],
                [-(float)$row['execute_time'], -(int)$row['id']]);
        }
    }

    private static function scheduleChanged(?array $row): void
    {
        if (self::$loadingHeap) return;
        $before = self::$scheduleRevision?->get() ?? 0;
        $revision = self::$scheduleRevision?->add(1) ?? $before + 1;
        if (self::$heapTable === static::$table && self::$heapPid === getmypid() && self::$heapRevision === $before && self::$dueHeap !== null) {
            if ($row !== null) self::heapInsert($row);
            self::$heapRevision = $revision;
        } else self::$heapRevision = -1;
    }

    protected static function writeRuntimeRow(array $row, ?array $before, bool $sync, bool $adding, ?int $runtimeId = null, ?array $syncMarker = null): void
    {
        static::guarded(static function () use ($row, $before, $sync, $adding, $runtimeId, $syncMarker): void {
            self::rememberChange($runtimeId ?? (int)$row['id'], $before, $row);
            parent::writeRuntimeRow($row, $before, $sync, $adding, $runtimeId, $syncMarker);
            foreach (['execute_time', 'executed', 'fleet_id'] as $field) {
                if (($before[$field] ?? null) !== $row[$field]) { self::scheduleChanged($row); break; }
            }
        });
    }

    public static function delete(array $data): void
    {
        static::guarded(static function () use ($data): void {
            $id = (int)$data['id'];
            self::rememberChange($id, static::findExistingById($id), null);
            parent::delete($data); self::scheduleChanged(null);
        });
    }

    public static function discardUncommittedRow(array $row): void
    {
        static::guarded(static function () use ($row): void {
            self::rememberChange((int)$row['id'], static::findExistingById((int)$row['id']), $row);
            parent::discardUncommittedRow($row); self::scheduleChanged(null);
        });
    }

    /** Самое раннее допустимое событие; изменения другого процесса инвалидируют heap. */
    public static function nextDueCommand(float $time, callable $eligible): ?array
    {
        return static::guarded(static function () use ($time, $eligible): ?array {
            self::ensureHeap();
            $skipped = []; $result = null;
            try {
                while (!self::$dueHeap->isEmpty()) {
                    $head = self::$dueHeap->top();
                    if ($head['time'] > $time) break;
                    self::$dueHeap->extract();
                    $row = static::findExistingById($head['id']);
                    if ($row === null || (int)$row['executed'] || (float)$row['execute_time'] !== $head['time']) continue;
                    $skipped[$row['id']] = $row;
                    if ($eligible($row)) { $result = $row; break; }
                }
            } finally {
                foreach ($skipped as $row) self::heapInsert($row);
            }
            return $result;
        });
    }

    // =====================
    //  Методы управления
    // =====================

    /**
     * Добавить новую команду в очередь флота
     */
    public static function addCommand(
        int $fleetId,
        FleetCommandType $type,
        array $params = []
    ): array {
        $data = [
            'fleet_id' => $fleetId,
            'command_type' => $type->value,
            'execute_time' => 0.0,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'executed' => 0,
            'create_time' => microtime(true),
        ];

        $data = self::castRowToSchema($data, true);
        self::add($data);

        return $data;
    }

    /**
     * Активировать следующую команду и при необходимости сохранить её
     * рассчитанные command-specific params одним обновлением строки.
     */
    public static function activateCommand(
        int $id,
        float $executeTime,
        ?array $params = null
    ): ?array {
        if (!is_finite($executeTime) || $executeTime <= 0) {
            throw new \InvalidArgumentException('execute_time активной команды должен быть положительным timestamp.');
        }

        $command = self::$table->get((string)$id);
        if (!$command || $command['executed'] || (float)$command['execute_time'] !== 0.0) {
            return null;
        }

        $command['execute_time'] = $executeTime;
        if ($params !== null) {
            $command['params'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        self::update($command);
        return $command;
    }

    /**
     * Получить активную невыполненную команду Fleet.
     */
    public static function getActiveCommand(int $fleetId): ?array
    {
        $active = null;
        foreach (self::remainingRowsForFleet($fleetId) as $command) {
            if ((float)$command['execute_time'] <= 0.0) continue;
            // Порядок swap-delete индекса не равен порядку команд.
            if ($active === null || $command['id'] < $active['id']) $active = $command;
        }
        return $active;
    }

    /**
     * Получить все ACTIVE-команды Fleet для проверки повреждённого состояния.
     * В штатном lifecycle список должен содержать максимум одну запись.
     */
    public static function getActiveCommands(int $fleetId): array
    {
        $active = [];

        foreach (self::remainingRowsForFleet($fleetId) as $command) {
            if ((float)$command['execute_time'] <= 0.0) {
                continue;
            }

            $active[] = $command;
        }

        usort($active, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $active;
    }

    /**
     * Получить команду по ID без создания отсутствующей строки.
     */
    public static function getCommand(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return self::$table->get((string)$id) ?: null;
    }

    /** Проверка checkpoint: близкие float-времена могут иметь общий индексный ключ. */
    public static function assertTimeIndex(int $id, float $before, float $after): void
    {
        $contains = static fn(array $rows): bool => in_array($id, array_column($rows, 'id'), true);
        $differentKeys = static::buildIndexKey([$before]) !== static::buildIndexKey([$after]);
        if (!$contains(static::findByIndex('execute_time', [$after]) ?? [])
            || $differentKeys && $contains(static::findByIndex('execute_time', [$before]) ?? [])) {
            throw new \LogicException('Не подтверждён временной индекс команды checkpoint добычи.');
        }
    }

    /**
     * Получить все невыполненные команды, которые пора выполнить по времени
     */
    public static function getPendingTimeCommands(float $currentTime): array
    {
        $list = [];
        foreach (self::$table as $cmd) {
            if ($cmd['execute_time'] > 0 && $cmd['execute_time'] <= $currentTime && !$cmd['executed']) {
                $list[] = $cmd;
            }
        }

        usort($list, static function (array $left, array $right): int {
            $timeOrder = $left['execute_time'] <=> $right['execute_time'];
            return $timeOrder !== 0 ? $timeOrder : ($left['id'] <=> $right['id']);
        });

        return $list;
    }

    /**
     * Получить следующую неактивированную команду флота.
     */
    public static function getNextPendingCommand(int $fleetId): ?array
    {
        $next = null;
        foreach (self::remainingRowsForFleet($fleetId) as $cmd) {
            if ((float)$cmd['execute_time'] !== 0.0) {
                continue;
            }

            if ($next === null || $cmd['id'] < $next['id']) {
                $next = $cmd;
            }
        }

        return $next;
    }

    /**
     * Получить все команды для флота
     */
    public static function getFleetQueue(int $fleetId): array
    {
        $queue = [];
        foreach (self::remainingRowsForFleet($fleetId) as $cmd) $queue[] = $cmd;
        usort($queue, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $queue;
    }

    /**
     * Получить полную текущую цепочку команд Fleet, включая выполненные.
     *
     * Execution-код должен продолжать использовать getFleetQueue(): этот
     * метод предназначен для истории и клиентского представления миссии.
     */
    public static function getFleetCommandChain(int $fleetId): array
    {
        $commands = [];
        if (isset(static::$indexTables['fleet_id'])) {
            // Только read-only обход: результат уже будет полным снимком.
            // Не держим перед ним ещё один массив всех ID и всех строк.
            foreach (static::iterateIndexIds('fleet_id', [$fleetId]) as $id) {
                $row = static::findExistingById($id);
                if ($row !== null && (int)$row['fleet_id'] === $fleetId) $commands[] = $row;
            }
        } else {
            foreach (self::$table as $row) {
                if ((int)$row['fleet_id'] === $fleetId) $commands[] = $row;
            }
        }

        usort($commands, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $commands;
    }

    /** Только ID в рабочем снимке. Тела остаются в основной shared Table. */
    public static function view(int $fleetId): FleetCommandView
    {
        $ids = [];
        if (isset(static::$indexTables['fleet_id'])) {
            foreach (static::iterateIndexIds('fleet_id', [$fleetId]) as $id) $ids[] = $id;
        } else {
            foreach (static::$table as $row) if ((int)$row['fleet_id'] === $fleetId) $ids[] = (int)$row['id'];
        }
        return new FleetCommandView($fleetId, $ids);
    }

    /** Read-only поток оставшихся команд, не полной истории. Во время обхода
     * индекс не меняется; наружу селекторы возвращают готовый результат.
     */
    private static function remainingRowsForFleet(int $fleetId): \Generator
    {
        if (isset(static::$indexTables['fleet_state'])) {
            foreach (static::iterateIndexIds('fleet_state', [$fleetId, 0]) as $id) {
                $row = static::findExistingById($id);
                if ($row !== null && (int)$row['fleet_id'] === $fleetId && !$row['executed']) yield $row;
            }
            return;
        }
        // Диагностические read-only Table без init() не создают новые индексы.
        foreach (self::$table as $row) {
            if ((int)$row['fleet_id'] === $fleetId && !$row['executed']) yield $row;
        }
    }

    /** Ожидающие/приостановленные команды по индексу нулевого времени, не всему журналу. */
    public static function getInactiveCommands(): iterable
    {
        $rows = isset(static::$indexTables['execute_time'])
            ? (static::findByIndex('execute_time', '0') ?? [])
            : self::$table;
        foreach ($rows as $row) {
            if (!(int)$row['executed'] && (float)$row['execute_time'] === 0.0) yield $row;
        }
    }

    /**
     * Отметить команду как выполненную
     */
    public static function markExecuted(int $id): void
    {
        $cmd = self::$table->get((string)$id);
        if (!$cmd) {
            return;
        }
        $cmd['executed'] = 1;
        self::update($cmd);
    }

    /**
     * Удалить все команды флота (например, после уничтожения или сброса миссии)
     */
    public static function clearFleetQueue(int $fleetId): void
    {
        // Снимок ID неизменяем: удаление меняет native swap-delete индекс,
        // но не пропускает следующие ID и не копирует тела всей истории.
        foreach (self::view($fleetId) as $cmd) {
            self::delete(['id' => (int)$cmd['id']]);
        }
    }
}
