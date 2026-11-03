<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/** Обломки на орбите планеты: без владельца и собственной траектории. */
class DebrisFields extends BaseRepository
{
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static string $className = 'DebrisFields';
    protected static string $tableName = 'debris_fields';
    protected static int $tableSize = 32768;

    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'galaxy' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'orbit' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'metal' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL DEFAULT 0', 'default' => 0.0],
            'crystal' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL DEFAULT 0', 'default' => 0.0],
            'update_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0', 'default' => Defaults::MICROTIME],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_debris_coordinate', 'type' => 'UNIQUE', 'fields' => ['galaxy', 'system', 'orbit']],
            ['name' => 'idx_debris_system', 'type' => 'INDEX', 'fields' => ['galaxy', 'system']],
        ],
    ];

    protected static array $indexes = [
        'galaxy_system_orbit' => ['key' => ['galaxy', 'system', 'orbit'], 'Unique' => true],
        'galaxy_system' => ['key' => ['galaxy', 'system'], 'Unique' => false],
    ];

    public static function findById(int $id): ?array
    {
        // Отсутствующее поле не должно создаваться при чтении.
        return static::$table->get((string)$id) ?: null;
    }

    public static function at(int $galaxy, int $system, int $orbit): ?array
    {
        return static::findByIndex('galaxy_system_orbit', [$galaxy, $system, $orbit]);
    }

    /** Пополнить запас; периодический RepositorySaver сохраняет dirty запись. */
    public static function addResources(
        int $galaxy, int $system, int $orbit, float $metal, float $crystal, float $time
    ): ?array {
        if ($galaxy <= 0 || $system <= 0 || $orbit <= 0
            || !is_finite($metal) || !is_finite($crystal) || $metal < 0 || $crystal < 0
            || !is_finite($time) || $time <= 0) {
            throw new \InvalidArgumentException('Некорректное пополнение поля обломков.');
        }
        $previous = static::at($galaxy, $system, $orbit);
        if ($metal + $crystal === 0.0) {
            return $previous;
        }
        $row = [
            'galaxy' => $galaxy, 'system' => $system, 'orbit' => $orbit,
            'metal' => (float)($previous['metal'] ?? 0) + $metal,
            'crystal' => (float)($previous['crystal'] ?? 0) + $crystal,
            'update_time' => $time,
        ];
        if (!is_finite($row['metal']) || !is_finite($row['crystal'])) {
            throw new \OverflowException('Переполнение запаса поля обломков.');
        }
        if ($previous !== null) {
            $row['id'] = $previous['id'];
            static::update($row);
        } else {
            $row['id'] = static::add($row);
        }
        $saved = $row['id'] === null ? null : static::findById((int)$row['id']);
        if ($saved === null || $saved['metal'] !== $row['metal'] || $saved['crystal'] !== $row['crystal']) {
            throw new \RuntimeException('Не удалось записать поле обломков в runtime.');
        }
        return $saved;
    }

    /** Уменьшить актуальный запас; пустая запись сохраняется с dirty marker. */
    public static function subtractResources(
        int $galaxy, int $system, int $orbit, float $metal, float $crystal, float $time
    ): array {
        if ($galaxy <= 0 || $system <= 0 || $orbit <= 0
            || !is_finite($metal) || !is_finite($crystal) || $metal < 0.0 || $crystal < 0.0
            || !is_finite($time) || $time <= 0.0) {
            throw new \InvalidArgumentException('Некорректное списание из поля обломков.');
        }
        $row = static::at($galaxy, $system, $orbit);
        if ($row === null || $metal > (float)$row['metal'] || $crystal > (float)$row['crystal']) {
            throw new \LogicException('В поле обломков недостаточно ресурсов.');
        }
        if ($metal + $crystal === 0.0) return $row;
        $row['metal'] = max(0.0, (float)$row['metal'] - $metal);
        $row['crystal'] = max(0.0, (float)$row['crystal'] - $crystal);
        $row['update_time'] = $time;
        static::update($row);
        $saved = static::findById((int)$row['id']);
        if ($saved === null || $saved['metal'] !== $row['metal'] || $saved['crystal'] !== $row['crystal']
            || $saved['update_time'] !== $time) {
            throw new \RuntimeException('Не удалось списать обломки в runtime.');
        }
        return $saved;
    }
}
