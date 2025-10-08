<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Builds;

use SPGame\Game\Repositories\Queues;

use SPGame\Game\Repositories\Config;

use SPGame\Game\Repositories\Vars;

use SPGame\Core\Logger;

class Helpers
{

    public static function getElementLevel(int $Element, array $AccountData): int
    {

        $BuildLevel = 0;
        if (in_array($Element, Vars::$reslist['build'])) {
            $BuildLevel = $AccountData['Builds'][Vars::$resource[$Element]] ?? 0;
        } elseif (in_array($Element, Vars::$reslist['fleet'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['defense'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            $BuildLevel = $AccountData['Techs'][Vars::$resource[$Element]] ?? 0;
        } elseif (in_array($Element, Vars::$reslist['officier'])) {
            $BuildLevel = 0;
        }

        return $BuildLevel;
    }

    public static function getNetworkLevels(array $AccountData): array
    {

        $buildKey = Vars::$resource[31];
        $techKey  = Vars::$resource[123];

        $Builds = Builds::getAllBuilds($AccountData['User']['id']);

        $Queues = Queues::getActive($AccountData['User']['id']) ?? [];


        $maxCount = ($AccountData['Techs'][$techKey] ?? 2) + 1;

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
        $addCurrentPlanet = !in_array($AccountData['Planet']['id'], $excludePlanets, true);
        $excludePlanets[] = $AccountData['Planet']['id'];

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
            $planetBuild = $AccountData['Planet']['id'];
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

    public static function getMaxFields(array $AccountData): int
    {
        $fields = $AccountData['Planet']['fields'];
        $Builds = $AccountData['Builds'];

        $fields += ($Builds[Vars::$resource[33]] * Config::getValue("FieldsByTerraformer"));
        $fields += ($Builds[Vars::$resource[41]] * Config::getValue("FieldsByMoonBasis"));

        return $fields;
    }

    public static function getCurrentFields(array $AccountData): int
    {
        //$fields = self::findById($planetId)['fields'];
        $Builds = $AccountData['Builds'];

        $CurrentFields = 0;
        foreach (Vars::$reslist['build'] as $id)
            $CurrentFields += $Builds[Vars::$resource[$id]];

        return $CurrentFields;
    }
}
