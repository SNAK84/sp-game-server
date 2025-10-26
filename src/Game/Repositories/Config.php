<?php

namespace SPGame\Game\Repositories;


use SPGame\Core\Database;
use SPGame\Core\Helpers;
use SPGame\Core\Defaults;
use SPGame\Core\Logger;

use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;

class Config extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Config';

    protected static string $tableName = 'config';

    /** @var Table */
    protected static Table $syncTable;

    private static array $DefaultConfig = [
        'LastGalaxyPos'      => 1,
        'LastSystemPos'      => 1,
        'MaxGalaxy'          => 3,
        'MaxSystem'          => 128,
        'SpeedPlanets'       => 24,      // Оборотов за час
        'StartRes901'        => 60000,
        'StartRes902'        => 40000,
        'StartRes903'        => 20000,
        'EnergySpeed'        => 10,
        'GameSpeed'          => 25000,
        'FactorUniversity'   => 10,
        'MinBuildTime'       => 1,
        'ResourceMultiplier' => 10,
        'StorageMultiplier'  => 10,
        'metalBasicIncome'   => 20,
        'crystalBasicIncome' => 10,
        'deuteriumBasicIncome'=> 0,
        'MaxQueueBuild'      => 10,
        'MaxQueueTech'       => 5,
        'MaxQueueHangar'     => 10,
        'MaxFleetPerBuild'   => 1000000,
        'FieldsByTerraformer'=> 5,
        'FieldsByMoonBasis'  => 3,
        'SiloFactor'         => 3,
    ];

    protected static array $tableSchema = [
        'columns' => [
            'id'    => [
                'swoole' => [Table::TYPE_INT, 4],
                'sql'    => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT',
                'default' => Defaults::AUTOID,
            ],
            'name'  => [
                'swoole' => [Table::TYPE_STRING, 254],
                'sql'    => 'VARCHAR(32) NOT NULL',
                'default' => Defaults::NONE,
            ],
            'value' => [
                'swoole' => [Table::TYPE_STRING, 254],
                'sql'    => 'VARCHAR(32) NOT NULL',
                'default' => Defaults::NONE,
            ],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_name', 'type' => 'UNIQUE', 'fields' => ['name']],
        ],
    ];

    protected static array $indexes = [
        'name' => ['key' => 'name', 'Unique' => true]
    ];
    /**
     * Инициализация репозитория с дефолтными значениями
     */
    public static function init(RepositorySaver $saver = null): void
    {
        parent::init($saver);

        // ===== Инициализация дефолтных значений =====
        foreach (self::$DefaultConfig as $key => $value) {
            $existing = self::findByIndex('name', $key);
            if (!$existing) {
                self::add(['name' => $key, 'value' => (string)$value]);
            }
        }
    }



    public static function getValue(string $key): ?string
    {
        $row = self::findByIndex('name', $key);
        return $row['value'] ?? null;
    }

    public static function setValue(string $key, string $value): void
    {
        $existing = self::findByIndex('name', $key);
        if ($existing) {
            self::update(['id' => $existing['id'], 'value' => $value]);
        } else {
            self::add(['name' => $key, 'value' => $value]);
        }

        // Сохраняем в MySQL
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO `" . self::$tableName . "` (`name`, `value`) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE `value` = :value_update;",
            [
                ':name' => $key,
                ':value' => $value,
                ':value_update' => $value,
            ]
        );
    }
}
