<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/** Неизменяемый receipt по UUID прохода: ID очищенной команды может повториться после restart. */
final class SurveyResults extends BaseRepository
{
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static array $indexes = ['token' => ['key' => ['token'], 'Unique' => true]];
    protected static string $className = 'SurveyResults';
    protected static string $tableName = 'survey_results';
    protected static int $tableSize = 4096;
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'token' => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => "VARCHAR(32) NOT NULL DEFAULT ''", 'default' => ''],
            'state' => ['swoole' => [Table::TYPE_STRING, 4096], 'sql' => 'TEXT NOT NULL', 'default' => ''],
        ],
        'indexes' => [['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_survey_token', 'type' => 'UNIQUE', 'fields' => ['token']]],
    ];
}
