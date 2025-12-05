<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use SPGame\Core\Logger;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Enums\FleetCommandType;
use Swoole\Table;

/**
 * Репозиторий команд флота (очередь действий).
 *
 * Хранит как запланированные, так и отложенные по событиям команды.
 * Команды выполняются в порядке execute_time или после события trigger_event.
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
            'trigger_event' => [
                'swoole' => [Table::TYPE_STRING, 64],
                'sql' => 'VARCHAR(64) NOT NULL DEFAULT ""',
                'default' => '',
            ],
            'target_id' => [
                'swoole' => [Table::TYPE_INT, 8],
                'sql' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                'default' => 0,
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
            ['name' => 'idx_trigger_event', 'type' => 'INDEX', 'fields' => ['trigger_event']],
        ],
    ];

    /** Индексы для Swoole */
    protected static array $indexes = [
        'fleet_id' => ['key' => ['fleet_id'], 'Unique' => false],
        'execute_time' => ['key' => ['execute_time'], 'Unique' => false],
        'trigger_event' => ['key' => ['trigger_event'], 'Unique' => false],
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
        array $params = [],
        float $executeTime = 0.0,
        string $triggerEvent = ''
    ): array {
        $data = [
            'fleet_id' => $fleetId,
            'command_type' => $type->value,
            'execute_time' => $executeTime,
            'trigger_event' => $triggerEvent,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'executed' => 0,
            'create_time' => microtime(true),
        ];

        $data = self::castRowToSchema($data, true);
        self::add($data);

        Logger::getInstance()->debug("Added fleet command", [
            'fleet_id' => $fleetId,
            'command' => $type->getLangKey(),
            'execute_time' => $executeTime,
            'trigger' => $triggerEvent,
        ]);

        return $data;
    }

    /**
     * Получить все невыполненные команды, которые пора выполнить по времени
     */
    public static function getPendingTimeCommands(float $currentTime): array
    {
        $list = [];
        foreach (self::$table as $cmd) {
            if ($cmd['execute_time'] > 0 && $cmd['execute_time'] <= $currentTime && !$cmd['executed']) {
                $list[$cmd['id']] = $cmd;
            }
        }
        return $list;
    }

    /**
     * Получить команды, ожидающие указанного события
     */
    public static function getPendingEventCommands(string $eventName): array
    {
        $list = [];
        foreach (self::$table as $cmd) {
            if ($cmd['trigger_event'] === $eventName && !$cmd['executed']) {
                $list[$cmd['id']] = $cmd;
            }
        }
        return $list;
    }

    /**
     * Получить все команды для флота
     */
    public static function getFleetQueue(int $fleetId): array
    {
        $queue = [];
        foreach (self::$table as $cmd) {
            if ($cmd['fleet_id'] === $fleetId && !$cmd['executed']) {
                $queue[$cmd['id']] = $cmd;
            }
        }
        usort($queue, fn($a, $b) => $a['execute_time'] <=> $b['execute_time']);
        return $queue;
    }

    /**
     * Отметить команду как выполненную
     */
    public static function markExecuted(int $id): void
    {
        $cmd = self::findById($id);
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
            if ($cmd['fleet_id'] === $fleetId) {
                self::delete($id);
            }
        }
    }
}
