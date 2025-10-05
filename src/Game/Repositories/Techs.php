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

    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsTable;
    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsDelTable;

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

    public static function getNetworkLevels(int $userId, int $planetId): array
    {

        $User = Users::findById($userId);
        if (!$User) {
            throw new \RuntimeException("User $userId not found");
        }

        $buildKey = Vars::$resource[31];
        $techKey  = Vars::$resource[123];

        $Builds = Builds::getAllBuilds($userId);
        $Queues = Queues::getActive($userId) ?? [];
        $Tech   = Techs::findById($userId);
        $maxCount = ($Tech[$techKey] ?? 2) + 1;

        // Список планет, где идёт строительство 31
        $excludePlanets = array_column(
            array_filter(
                $Queues,
                static fn($q) =>
                $q['type'] === \SPGame\Game\Services\QueuesServices::BUILDS &&
                    $q['object_id'] === 31
            ),
            'planet_id'
        );

        // Проверяем, можно ли добавить текущую планету
        $addCurrentPlanet = !in_array($planetId, $excludePlanets, true);
        $excludePlanets[] = $planetId;

        // Фильтрация билдов
        $Builds = array_filter($Builds, static function ($b) use ($excludePlanets) {
            $id = is_object($b) ? ($b->id ?? null) : ($b['id'] ?? null);
            return !in_array($id, $excludePlanets, true);
        });

        // Сброс ключей для безопасного array_unshift
        $Builds = array_values($Builds);

        // Сортировка по убыванию уровня постройки
        uasort($Builds, static function ($a, $b) use ($buildKey) {
            $valA = is_object($a) ? ($a->$buildKey ?? 0) : ($a[$buildKey] ?? 0);
            $valB = is_object($b) ? ($b->$buildKey ?? 0) : ($b[$buildKey] ?? 0);
            return $valB <=> $valA;
        });

        // Добавляем текущую планету первой (если можно)
        if ($addCurrentPlanet) {
            $planetBuild = Builds::findById($planetId);
            if ($planetBuild) {
                array_unshift($Builds, $planetBuild);
            }
        }

        // Обрезаем до лимита
        $Builds = array_slice($Builds, 0, $maxCount, true);

        // Формируем уровни
        $Levels = [];
        foreach ($Builds as $b) {
            $level = is_object($b) ? ($b->$buildKey ?? 0) : ($b[$buildKey] ?? 0);
            $Levels[] = (int)$level;
        }

        return $Levels;
    }
}
