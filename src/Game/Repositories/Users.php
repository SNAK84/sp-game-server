<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Defaults;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

use Swoole\Table;


class Users extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Users';

    protected static string $tableName = 'users';

    protected static array $tableSchema = [
        'columns' => [
            'id' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT',
                'default' => Defaults::NONE,
            ],
            'account_id' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) NOT NULL',
                'default' => Defaults::NONE,
            ],
            'name' => [
                'swoole' => [Table::TYPE_STRING, 32],
                'sql'    => 'VARCHAR(32) NOT NULL',
                'default' => Defaults::NONE,
            ],
            'credit' => [
                'swoole' => [Table::TYPE_INT, 8],
                'sql'    => 'BIGINT NOT NULL DEFAULT 0',
                'default' => 0,
            ],
            'create_time' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) NOT NULL',
                'default' => Defaults::TIME,
            ],
            'online_time' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) NOT NULL DEFAULT 0',
                'default' => 0,
            ],
            'main_planet' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) NOT NULL',
                'default' => 0,
            ],
            'current_planet' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) NOT NULL',
                'default' => 0,
            ],
            'planet_sort' => [
                'swoole' => [Table::TYPE_INT, 1],
                'sql'    => 'TINYINT(1) NOT NULL DEFAULT 0',
                'default' => 0,
            ],
            'planet_sort_order' => [
                'swoole' => [Table::TYPE_INT, 1],
                'sql'    => 'TINYINT(1) NOT NULL DEFAULT 0',
                'default' => 0,
            ],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_account_id', 'type' => 'INDEX', 'fields' => ['account_id']],
            ['name' => 'uniq_name', 'type' => 'UNIQUE', 'fields' => ['name']],
        ],
    ];

    protected static array $indexes = [
        'account' => 'account_id'
    ];

    /** @var array Список изменённых ID для синхронизации */
    protected static array $dirtyIds = [];

    public static function create(array $data): array
    {
        $db = Database::getInstance();
        $tableName = self::$tableSchema['name'];
        $insert = [
            'account_id'       => $data['account_id'] ?? 0,
            'name'             => $data['name'] ?? 'Player' . ($data['account_id'] ?? 0),
            'credit'           => $data['credit'] ?? 0,
            'create_time'      => $data['create_time'] ?? time(),
            'online_time'      => $data['online_time'] ?? 0,
            'main_planet'      => $data['main_planet'] ?? 0,
            'current_planet'   => $data['current_planet'] ?? 0,
            'planet_sort'      => $data['planet_sort'] ?? 0,
            'planet_sort_order' => $data['planet_sort_order'] ?? 0,
        ];

        $columns = array_keys($insert);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($insert);

        $insert['id'] = $db->insert(
            "INSERT INTO `" . self::$tableName . "` (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            $values
        );

        self::add($insert);
        self::$logger->info("Created new user id={$insert['id']} account_id={$insert['account_id']}");

        return $insert;
    }

    public static function findByAccount(int $accountId): ?array
    {
        return self::findByIndex('account', $accountId);
    }
}
