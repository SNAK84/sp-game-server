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
                'swoole' => [Table::TYPE_STRING, 512],
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
        'execute_time' => ['key' => ['execute_time'], 'Unique' => false],
    ];

    protected static Table $syncTable;

    // =====================
    //  Инициализация
    // =====================

    public static function init(?RepositorySaver $saver = null): void
    {
        parent::init($saver);
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
        return self::getActiveCommands($fleetId)[0] ?? null;
    }

    /**
     * Получить все ACTIVE-команды Fleet для проверки повреждённого состояния.
     * В штатном lifecycle список должен содержать максимум одну запись.
     */
    public static function getActiveCommands(int $fleetId): array
    {
        $active = [];

        foreach (self::$table as $command) {
            if (
                (int)$command['fleet_id'] !== $fleetId
                || $command['executed']
                || (float)$command['execute_time'] <= 0.0
            ) {
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
        foreach (self::$table as $cmd) {
            if ((int)$cmd['fleet_id'] !== $fleetId || $cmd['executed'] || (float)$cmd['execute_time'] !== 0.0) {
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
        foreach (self::$table as $cmd) {
            if ((int)$cmd['fleet_id'] === $fleetId && !$cmd['executed']) {
                $queue[] = $cmd;
            }
        }
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
        foreach (self::$table as $cmd) {
            if ((int)$cmd['fleet_id'] === $fleetId) {
                $commands[] = $cmd;
            }
        }

        usort($commands, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $commands;
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
        foreach (self::$table as $id => $cmd) {
            if ((int)$cmd['fleet_id'] === $fleetId) {
                self::delete(['id' => $id]);
            }
        }
    }
}
