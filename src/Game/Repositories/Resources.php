<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use Swoole\Table;


class PlanetResources extends BaseRepository
{
    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'PlanetResources';
    protected static string $tableName = 'resources_planet';

    /** @var array Список изменённых ID для синхронизации */
    protected static array $dirtyIds = [];

    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) UNSIGNED NOT NULL', 'default' => 0],
            'metal'     => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
            'crystal'   => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
            'deuterium' => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
            'energy'    => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
        ],
    ];
}


class UserResources extends BaseRepository
{
    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'UserResources';
    protected static string $tableName = 'resources_user';

    /** @var array Список изменённых ID для синхронизации */
    protected static array $dirtyIds = [];

    protected static array $tableSchema = [
        'columns' => [
            'id'  => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) UNSIGNED NOT NULL', 'default' => 0],
            'credit'   => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
            'doubloon' => ['swoole' => [Table::TYPE_FLOAT], 'sql' => 'DOUBLE DEFAULT 0', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
        ],
    ];
}


class Resources
{
    public static function init(): void
    {
        PlanetResources::init();
        UserResources::init();
    }

    public static function getByUserId(int $userId): ?array
    {
        $user = Users::findById($userId);
        if (!$user) return null;

        $planetId = $user['current_planet'] ?? 0;

        $planetResources = PlanetResources::findById($planetId) ?? [
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'energy' => 0,
        ];

        $userResources = UserResources::findById($userId) ?? [
            'credit' => 0,
            'doubloon' => 0,
        ];

        return array_merge($planetResources, $userResources);
    }
}
