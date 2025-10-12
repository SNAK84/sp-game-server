<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Defaults;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;


class Techs extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Techs';

    protected static string $tableName = 'techs';

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

    /** @var Table */
    protected static Table $syncTable;

    public static function init(RepositorySaver $saver = null): void
    {

        foreach (Vars::$reslist['tech'] as $ResID) {
            self::$tableSchema['columns'][Vars::$resource[$ResID]] =  [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
                'default' => 0,
            ];
        }

        parent::init($saver);
    }

}
