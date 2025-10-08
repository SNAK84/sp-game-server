<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

class PlayerQueue extends BaseRepository
{
    public const ActionQueueUpgarde     = "ActionQueueUpgarde";
    public const ActionQueueDismantle   = "ActionQueueDismantle";
    public const ActionQueueCancel      = "ActionQueueCancel";


    public const ActionQueueReCalcTech  = "ActionQueueReCalcTech";
    public const SendActualeData        = "SendActualeData";

    protected static Table $table;
    protected static string $className = 'PlayerQueue';
    protected static string $tableName = "";

    // автоинкрементный ID для ключей
    protected static int $lastId = 0;

    protected static array $tableSchema = [
        'columns' => [
            'id'         => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'account_id' => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'user_id'    => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'planet_id'  => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'action'     => ['swoole' => [Table::TYPE_STRING, 32], 'default' => ''],
            'priority'   => ['swoole' => [Table::TYPE_INT, 1], 'default' => 1],
            'added_at'   => ['swoole' => [Table::TYPE_FLOAT], 'default' => Defaults::MICROTIME],
            'data'       => ['swoole' => [Table::TYPE_STRING, 512], 'default' => ''],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'user', 'type' => 'INDEX', 'fields' => ['user_id']],
            ['name' => 'account', 'type' => 'INDEX', 'fields' => ['account_id']],
        ],
    ];

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'account_id' => ['key' => ['account_id'], 'Unique' => false],
        'user_id' => ['key' => ['user_id'], 'Unique' => false],
        'planet_id' => ['key' => ['planet_id'], 'Unique' => false]
    ];

    public static function addQueue(int $accountId, int $userId, int $planetId, string $action, array $data = [], int $priority = 1): int
    {
        $id = ++self::$lastId;

        $row = [
            'id'         => $id,
            'account_id' => $accountId,
            'user_id'    => $userId,
            'planet_id'  => $planetId,
            'action'     => $action,
            'priority'   => $priority,
            'added_at'   => microtime(true),
            'data'       => serialize($data),
        ];

        self::$logger->info("PlayerQueue addQueue", $row);

        self::add($row);

        return $id;
    }

    public static function getByAccaunt(int $accountId): ?array
    {
        $Queues = self::findByIndex('account_id', $accountId);
        if (!$Queues) {
            //self::$logger->info("PlayerQueue not Queues findByIndex");
            return null;
        }
        foreach ($Queues as $key => $Queue) {
            $Queues[$key]['data'] = unserialize($Queue['data']);
        }

        // Сортировка по priority и added_at
        usort($Queues, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['added_at'] <=> $b['added_at'];
            }
            return $a['priority'] <=> $b['priority'];
        });

        return $Queues;
    }

    public static function popByAccaunt(int $accountId): ?array
    {
        $Queues = self::findByIndex('account_id', $accountId);
        if (!$Queues) {
            //self::$logger->info("PlayerQueue not Queues findByIndex");
            return null;
        }
        foreach ($Queues as $key => $Queue) {
            $Queues[$key]['data'] = unserialize($Queue['data']);
        }

        // Сортировка по priority и added_at
        usort($Queues, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['added_at'] <=> $b['added_at'];
            }
            return $a['priority'] <=> $b['priority'];
        });

        // Берём первый элемент
        $Event = $Queues[0];
        self::delete($Event);

        return $Event;
    }
}
