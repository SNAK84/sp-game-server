<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use SPGame\Game\Services\GalaxyGenerator;
use SPGame\Game\Services\CelestialNameGenerator;
use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;

class Galaxy extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'Galaxy';

    protected static string $tableName = 'galaxy';
    protected static array $tableSchema = [
        'columns' => [
            'id'           => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'galaxy'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'name'         => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => "VARCHAR(32) NOT NULL DEFAULT ''", 'default' => ''],
            'star_type'    => ['swoole' => [Table::TYPE_STRING, 8], 'sql' => 'VARCHAR(8) NOT NULL', 'default' => 'G'],
            'star_size'    => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'min_distance' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'max_distance' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'max_orbits'   => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'rotation'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1', 'default' => 1],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_galaxy_system', 'type' => 'INDEX', 'fields' => ['galaxy', 'system']],
        ],
    ];

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'galaxy_system' => ['key' => ['galaxy', 'system'], 'Unique' => false]
    ];

    /** @var Table */
    protected static Table $syncTable;

    public static function init(?RepositorySaver $saver = null): void
    {
        parent::init($saver);
        if (static::$tableName !== '') static::ensureNames();
    }

    /**
     * Стартовое заполнение после загрузки репозитория, до запуска игровых обработчиков.
     * Меняет только пустые имена; ошибка сохранения прерывает запуск и требует новой загрузки.
     */
    public static function ensureNames(): int
    {
        $changed = 0;
        $pending = [];
        foreach (static::$table as $row) {
            if ((string)$row['name'] !== '') continue;
            $name = CelestialNameGenerator::systemName((int)$row['galaxy'], (int)$row['system']);
            $id = (int)$row['id'];
            static::update(['id' => $id, 'name' => $name]);
            $marker = static::$syncTable->get((string)$id);
            if ((static::$table->get((string)$id)['name'] ?? null) !== $name
                || $marker === false || (int)$marker['need_update'] !== 1) {
                throw new \RuntimeException('Не удалось пометить имя звёздной системы для сохранения: ' . $id);
            }
            $pending[] = $id;
            ++$changed;
            if (count($pending) === 256) {
                static::flushNames($pending);
                $pending = [];
            }
        }
        if ($pending !== []) static::flushNames($pending);
        return $changed;
    }

    /** Малые пакеты не переполняют syncTable при первичном заполнении большой галактики. */
    private static function flushNames(array $ids): void
    {
        static::syncToDatabase();
        foreach ($ids as $id) {
            $marker = static::$syncTable->get((string)$id);
            if ($marker === false || (int)$marker['need_update'] !== 0 || (int)$marker['need_delete'] !== 0) {
                // Общий saver логирует SQL-ошибки; запуск с несохранённым backfill запрещён.
                throw new \RuntimeException('Не удалось сохранить имя звёздной системы: ' . $id);
            }
            // Штатный flush обнуляет флаг, но оставляет строку: освобождаем только наш пакет.
            if (!static::$syncTable->del((string)$id)) {
                throw new \RuntimeException('Не удалось освободить маркер сохранённого имени: ' . $id);
            }
        }
    }

    /**
     * Единый профиль звёзд для генератора и представления галактики.
     * mass/radius указаны в долях Солнца, temperature — в Кельвинах.
     */
    public static array $starTypes = [
        'O' => ['color' => 'lightblue',   'size' => [25, 30], 'temperature' => [30000, 45000], 'mass' => [16.0, 40.0], 'radius' => [6.6, 12.0], 'max_orbits' => 15],
        'B' => ['color' => 'deepskyblue', 'size' => [22, 26], 'temperature' => [10000, 30000], 'mass' => [2.1, 16.0],  'radius' => [1.8, 6.6],  'max_orbits' => 13],
        'A' => ['color' => 'white',       'size' => [18, 23], 'temperature' => [7500, 10000],  'mass' => [1.4, 2.1],   'radius' => [1.4, 1.8],  'max_orbits' => 11],
        'F' => ['color' => 'beige',       'size' => [16, 20], 'temperature' => [6000, 7500],   'mass' => [1.04, 1.4],  'radius' => [1.15, 1.4], 'max_orbits' => 9],
        'G' => ['color' => 'yellow',      'size' => [14, 18], 'temperature' => [5200, 6000],   'mass' => [0.8, 1.04],  'radius' => [0.96, 1.15], 'max_orbits' => 7],
        'K' => ['color' => 'orange',      'size' => [12, 16], 'temperature' => [3700, 5200],   'mass' => [0.45, 0.8],  'radius' => [0.7, 0.96], 'max_orbits' => 6],
        'M' => ['color' => 'red',         'size' => [10, 14], 'temperature' => [2400, 3700],   'mass' => [0.08, 0.45], 'radius' => [0.1, 0.7],  'max_orbits' => 5],
    ];

    /**
     * Получить конкретную систему
     */
    public static function getSystem(int $galaxy, int $system): ?array
    {
        $star = self::findByIndex('galaxy_system', [$galaxy, $system]);
        if (!$star) $star[] = GalaxyGenerator::generateSystem($galaxy, $system);

        GalaxyGenerator::ensureSpecialOrbits($star[0]);

        return $star[0];
    }

}
