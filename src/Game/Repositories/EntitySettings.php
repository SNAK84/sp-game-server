<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use SPGame\Core\Logger;
use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;

class EntitySettings extends BaseRepository
{
    protected static string $className = 'EntitySettings';

    protected static string $tableName = 'entity_settings';

    protected static array $tableSchema = [
        'columns' => [
            'planet_id'   => ['swoole' => [\Swoole\Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::NONE],
            'entity_id'   => ['swoole' => [\Swoole\Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::NONE],
            'efficiency'  => ['swoole' => [\Swoole\Table::TYPE_INT, 1], 'sql' => 'TINYINT(3) NOT NULL DEFAULT 100', 'default' => 100],
            'last_used'   => ['swoole' => [\Swoole\Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['planet_id', 'entity_id']],
            ['name' => 'idx_entity', 'type' => 'INDEX', 'fields' => ['entity_id']],
        ],
    ];

    /** @var Table */
    protected static Table $syncTable;

    /**
     * Формируем ключ для Swoole\Table по planet + entity
     */
    protected static function makeKey(int $planetId, int $entityId): string
    {
        return (string) ($planetId * 1000 + $entityId);
    }

    // Получение записи по planet + entity
    public static function get(int $planetId, int $entityId): ?array
    {
        $key = static::makeKey($planetId, $entityId);
        $row = static::$table->get($key);


        if (!$row) {
            static::add(['planet_id' => $planetId, 'entity_id' => $entityId]);
            $row = static::$table->get($key);
        }

        return $row !== false ? $row : null;
    }

    // Добавление или обновление записи
    public static function add(array $data, bool $sync = true): int
    {
        if (!isset($data['planet_id']) || !isset($data['entity_id'])) {
            self::$logger->warning("Missing planet_id or entity_id in data", $data);
            return 0;
        }

        $key = self::makeKey((int)$data['planet_id'], (int)$data['entity_id']);
        $row = self::castRowToSchema($data, true);
        $row['id'] = $key; // если BaseRepository требует поле 'id'

        self::$table->set($key, $row);

        foreach (static::$indexes as $indexKey => $col) {
            self::addIndex($indexKey, $row[$col['key']], $key);
        }

        // помечаем dirty для синхронизации в MySQL
        if ($sync) self::markForUpdate((int)$row['id']);

	return (int)$row['id'];
    }

    // Обновление
    public static function update(array $data): void
    {
        if (!isset($data['planet_id']) || !isset($data['entity_id'])) {
            self::$logger->warning("Missing planet_id or entity_id in data", $data);
            return;
        }

        $key = static::makeKey((int)$data['planet_id'], (int)$data['entity_id']);
        $current = static::$table->get($key);
        if (!$current) {
            self::$logger->warning("Attempted to update non-existent entity settings", ['planetId' => $data['planet_id'], 'entityId' => $data['entity_id']]);
            return;
        }

        $updated = array_merge($current, self::castRowToSchema($data));
        self::$table->set($key, $updated);

        // Обновляем индексы при необходимости
        // Обновляем индексы
        foreach (static::$indexes as $indexKey => $col) {
            self::updateIndex($indexKey, $current[$col['key']], $updated[$col['key']], $key);
        }

        // помечаем для синхронизации
        self::markForUpdate((int)$key);
    }
}
