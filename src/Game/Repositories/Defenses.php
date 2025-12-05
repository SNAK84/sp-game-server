<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Defaults;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;


class Defenses extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Defenses';

    protected static string $tableName = 'defenses';

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

        foreach (array_merge(Vars::$reslist['defense'], Vars::$reslist['missile']) as $ResID) {
            self::$tableSchema['columns'][Vars::$resource[$ResID]] =  [
                'swoole' => [Table::TYPE_INT, 8],
                'sql'    => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                'default' => 0,
            ];
        }

        parent::init($saver);
    }

    public static function getAllDefenses(int $userId): array
    {
        $Defenses = [];
        $Planets = Planets::findByIndex('owner_id', $userId);
        //$Planets = Planets::getAllPlanets($userId);
        
        foreach ($Planets as $Planet) {
            $Defenses[] = Defenses::findById($Planet['id']);
        }

        return $Defenses;
    }
}
