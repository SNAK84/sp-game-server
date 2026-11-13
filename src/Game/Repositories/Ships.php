<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Defaults;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;


class Ships extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Ships';

    protected static string $tableName = 'ships';

    protected static array $tableSchema = [
        'columns' => [
            'id' => [
                'swoole' => [Table::TYPE_INT, 8],
                'sql'    => 'BIGINT(20) UNSIGNED NOT NULL',
                'default' => Defaults::NONE,
            ]
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']]
        ],
    ];

    /** @var Table */
    protected static Table $syncTable;

    public static function init(?RepositorySaver $saver = null): void
    {

        foreach (Vars::$reslist['fleet'] as $ResID) {
            self::$tableSchema['columns'][Vars::$resource[$ResID]] =  [
                'swoole' => [Table::TYPE_INT, 8],
                'sql'    => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                'default' => 0,
            ];
        }

        parent::init($saver);
    }

    public static function getAllShips(int $userId): array
    {
        $Ships = [];
        $Planets = Planets::findByIndex('owner_id', $userId);
        //$Planets = Planets::getAllPlanets($userId);
        
        foreach ($Planets as $Planet) {
            $Ships[] = Ships::findById($Planet['id']);
        }




        return $Ships;
    }
}
