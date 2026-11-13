<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/** Личное знание, не второй физический склад ресурса. */
final class ResourceSurveys extends BaseRepository
{
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static string $className = 'ResourceSurveys';
    protected static string $tableName = 'resource_surveys';
    protected static int $tableSize = 8192;
    protected static array $indexes = ['owner_site' => ['key' => ['owner_id', 'site_id'], 'Unique' => true]];
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'owner_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'site_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'coverage' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'reserve_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'observed_at' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'state' => ['swoole' => [Table::TYPE_STRING, 2048], 'sql' => 'TEXT NOT NULL', 'default' => '{}'],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_owner_site', 'type' => 'UNIQUE', 'fields' => ['owner_id', 'site_id']],
        ],
    ];
}
