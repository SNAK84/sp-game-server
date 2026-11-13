<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/** Одна запись на неупорядоченную пару игроков; удаление связи логическое. */
class Friends extends BaseRepository
{
    public const PENDING = 0;
    public const ACCEPTED = 1;
    public const REMOVED = 2;
    public const REQUEST_MAX_LENGTH = 500;
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static string $className = 'Friends';
    protected static string $tableName = 'friends';
    protected static int $tableSize = 8192;
    protected static array $indexes = [
        'pair' => ['key' => ['user_low', 'user_high'], 'Unique' => true],
        'low' => ['key' => 'user_low', 'Unique' => false],
        'high' => ['key' => 'user_high', 'Unique' => false],
    ];
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => Defaults::AUTOID],
            'user_low' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'user_high' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'requester_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'status' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT NOT NULL DEFAULT 0', 'default' => 0],
            'created_at' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT NOT NULL DEFAULT 0', 'default' => 0],
            // UTF-8 байты: не зависим от старого utf8mb3 SQL-соединения (включая emoji).
            'request_text' => ['swoole' => [Table::TYPE_STRING, 2048], 'sql' => "VARBINARY(2048) NOT NULL DEFAULT ''", 'default' => ''],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_friends_pair', 'type' => 'UNIQUE', 'fields' => ['user_low', 'user_high']],
            ['name' => 'idx_friends_high', 'type' => 'INDEX', 'fields' => ['user_high']],
        ],
    ];

    public static function pair(int $a, int $b): ?array
    {
        if ($a <= 0 || $b <= 0 || $a === $b || !isset(static::$table)) return null;
        return static::findByIndex('pair', [min($a, $b), max($a, $b)]);
    }

    public static function areFriends(int $a, int $b): bool
    {
        return (static::pair($a, $b)['status'] ?? self::REMOVED) === self::ACCEPTED;
    }

    public static function forUser(int $userId): array
    {
        return array_merge(static::findByIndex('low', (string)$userId) ?? [], static::findByIndex('high', (string)$userId) ?? []);
    }
}
