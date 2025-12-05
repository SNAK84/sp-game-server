<?php

namespace SPGame\Game\Repositories;

use Swoole\Table;
use SPGame\Core\Defaults;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Services\QueuesServices;

class Queues extends BaseRepository
{
    protected static string $className = 'Queues';
    protected static string $tableName = 'queues';
    protected static int $tableSize = 1024 * 8;

    /** @var Table Основная таблица */
    protected static Table $table;

    /** 
     * Схема таблицы 
     */
    protected static array $tableSchema = [
        'columns' => [
            'id'          => ['swoole' => [Table::TYPE_INT, 8],  'sql' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => Defaults::AUTOID],
            'user_id'     => ['swoole' => [Table::TYPE_INT, 8],  'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'planet_id'   => ['swoole' => [Table::TYPE_INT, 8],  'sql' => 'BIGINT(20) UNSIGNED NULL', 'default' => 0],
            'object_id'   => ['swoole' => [Table::TYPE_INT, 8],  'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'count'       => ['swoole' => [Table::TYPE_INT, 4],  'sql' => 'INT UNSIGNED DEFAULT 1', 'default' => 1],
            'action'      => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => "ENUM('build','destroy') DEFAULT 'build'", 'default' => 'build'],
            'type'        => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => "VARCHAR(32) NOT NULL", 'default' => 'building'],
            'time'        => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'start_time'  => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'end_time'    => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'status'      => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => "ENUM('queued','active','done','cancelled') DEFAULT 'queued'", 'default' => 'queued'],
        ],
        'indexes' => [
            ['type' => 'PRIMARY', 'fields' => ['id']],
            ['type' => 'INDEX', 'name' => 'idx_user', 'fields' => ['user_id']],
            ['type' => 'INDEX', 'name' => 'idx_planet', 'fields' => ['planet_id']],
            ['type' => 'INDEX', 'name' => 'idx_type', 'fields' => ['type']],
            ['type' => 'INDEX', 'name' => 'idx_status', 'fields' => ['status']],
        ],
    ];


    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'queue' => ['key' => ['planet_id', 'type'], 'Unique' => false],
        'queue_tech' => ['key' => ['user_id', 'type'], 'Unique' => false],
        'queue_p_status' => ['key' => ['planet_id', 'status'], 'Unique' => false],
        'queue_u_status' => ['key' => ['user_id', 'status'], 'Unique' => false],
        'queue_p_type_status' => ['key' => ['planet_id', 'type', 'status'], 'Unique' => false]
    ];

    /** @var Table */
    protected static Table $syncTable;

    public static function getCurrentQueue(string $QueueType, int $userId, int $planetId): ?array
    {

        $Queue = [];
        if ($QueueType === QueuesServices::TECHS) {
            $Queue = self::findByIndex('queue_tech', [$userId, $QueueType]);
        } else
            $Queue = self::findByIndex('queue', [$planetId, $QueueType]);

        if (!$Queue)  return null;
        
        // Сортируем по end_time (asc). При равенстве таймстампов — tie-breaker по id.
        usort($Queue, function (array $a, array $b) {
            $ta = (int) ($a['end_time'] ?? 0);
            $tb = (int) ($b['end_time'] ?? 0);
            if ($ta === $tb) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
            return $ta <=> $tb;
        });

        /*$Queue = [];
        foreach ($ids as $id) {
            $Queue[] = self::findById($id);
        }*/

        return $Queue;
    }

    public static function getActiveMinEndTimeByUser(int $userId): ?array
    {
        // Получаем записи/ид'ы по индексу (user_id, status='active')
        $items = self::findByIndex('queue_u_status', [$userId, 'active']) ?? [];

        if (empty($items)) {
            return null;
        }

        // Сортируем по end_time (asc). При равенстве таймстампов — tie-breaker по id.
        usort($items, function (array $a, array $b) {
            $ta = (int) ($a['end_time'] ?? 0);
            $tb = (int) ($b['end_time'] ?? 0);
            if ($ta === $tb) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
            return $ta <=> $tb;
        });

        // Возвращаем первую (минимальную)
        return $items[0];
    }


    /**
     * Получить текущую активную запись очереди с минимальным end_time для игрока (user_id) и типа (building/tech)
     */
    public static function getActiveMinEndTime(int $planetid): ?array
    {

        // Получаем записи/ид'ы по индексу (user_id, status='active')
        $items = self::findByIndex('queue_p_status', [$planetid, 'active']) ?? [];

        if (empty($items)) {
            return null;
        }

        // Сортируем по end_time (asc). При равенстве таймстампов — tie-breaker по id.
        usort($items, function (array $a, array $b) {
            $ta = (int) ($a['end_time'] ?? 0);
            $tb = (int) ($b['end_time'] ?? 0);
            if ($ta === $tb) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
            return $ta <=> $tb;
        });

        // Возвращаем первую (минимальную)
        return $items[0];
    }

    public static function getActive(int $userId): ?array
    {
        $items = self::findByIndex('queue_u_status', [$userId, 'active']) ?? [];

        if (empty($items)) {
            return null;
        }

        return $items;
    }

    public static function getActivePlanet(string $QueueType, int $planetId): ?array
    {

        $items = self::findByIndex('queue_p_type_status', [$planetId, $QueueType, 'active']);

        if (empty($items)) {
            return null;
        }

        return $items;
    }
}
