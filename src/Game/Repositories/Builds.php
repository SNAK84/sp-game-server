<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Defaults;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;


class Builds extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Bulds';

    protected static string $tableName = 'bulds';

    protected static array $tableSchema = [
        'columns' => [
            'id' => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) UNSIGNED NOT NULL',
                'default' => Defaults::NONE,
            ]
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']]
        ],
    ];

    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsTable;
    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsDelTable;

    public static function init(RepositorySaver $saver = null): void
    {

        foreach (Vars::$reslist['build'] as $ResID) {
            self::$tableSchema['columns'][Vars::$resource[$ResID]] =  [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
                'default' => 0,
            ];
        }

        parent::init($saver);
    }

    public static function getAllBuilds(int $userId): array
    {
        $Builds = [];
        $Planets = Planets::findByIndex('owner_id', $userId);
        //$Planets = Planets::getAllPlanets($userId);
        
        foreach ($Planets as $Planet) {
            $Builds[] = Builds::findById($Planet['id']);
        }




        return $Builds;
    }
}
