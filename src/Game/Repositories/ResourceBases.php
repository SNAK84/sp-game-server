<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/** Только занятые ресурсные базы. id — siteId, не Planet ID и не ID флота. */
final class ResourceBases extends BaseRepository
{
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static string $className = 'ResourceBases';
    protected static string $tableName = 'resource_bases';
    protected static int $tableSize = 32768;
    protected static array $indexes = ['owner_id' => ['key' => ['owner_id'], 'Unique' => false]];
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => Defaults::NONE],
            'owner_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'created_at' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL', 'default' => Defaults::MICROTIME],
            'updated_at' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL', 'default' => Defaults::MICROTIME],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_resource_base_owner', 'type' => 'INDEX', 'fields' => ['owner_id']],
        ],
    ];

    public static function isReady(): bool
    {
        return isset(static::$table);
    }

    /** Чтение неизвестного siteId не создаёт пустую базу с owner_id=0. */
    public static function findById(int $id): ?array
    {
        return self::findExistingById($id);
    }

    public static function findExistingById(int $id): ?array
    {
        return self::isReady() && $id > 0 ? parent::findExistingById($id) : null;
    }

    public static function ownedBy(int $ownerId): array
    {
        return self::isReady() && $ownerId > 0 ? (self::findByIndex('owner_id', [$ownerId]) ?? []) : [];
    }
}
