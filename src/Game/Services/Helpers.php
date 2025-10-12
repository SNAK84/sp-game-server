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

        if (!isset($AccountData['Builds']) || !is_array($AccountData['Builds'])) {
            Logger::getInstance()->error("Helpers::getElementLevel: отсутствует 'Builds' в AccountData", [
                'element' => $Element,
                'account_id' => $AccountData['User']['id'] ?? null,
                'planet_id' => $AccountData['Planet']['id'] ?? null
            ]);
            // В отладке можно бросать исключение
            throw new \RuntimeException("Builds missing in AccountData");
            // В продакшене можно просто вернуть 0:
            // return 0;
        }

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

        $userId   = $AccountData['User']['id'] ?? 0;
        $planetId = $AccountData['Planet']['id'] ?? 0;

        // Загружаем все постройки пользователя и активные очереди
        $Builds = Builds::getAllBuilds($userId) ?? [];
        $Queues = Queues::getActive($userId) ?? [];

        // Уровень межпланетной сети (techKey) и лимит количества планет для сети
        $techs = $AccountData['Techs'] ?? [];
        $maxCount = ((int)($techs[$techKey] ?? 2)) + 1;

        // Список планет, где идёт строительство лаборатории (object_id = 31)
        $excludePlanets = array_column(
            array_filter(
                $Queues,
                static fn($q) =>
                isset($q['type'], $q['object_id']) &&
                    $q['type'] === \SPGame\Game\Services\QueuesServices::BUILDS &&
                    (int)$q['object_id'] === 31
            ),
            'planet_id'
        );

        // Проверяем, можно ли добавить текущую планету
        $addCurrentPlanet = ($planetId > 0 && !in_array($planetId, $excludePlanets, true));
        $excludePlanets[] = $planetId;

        // Фильтрация билдов (убираем планеты, где идёт постройка)
        $Builds = array_filter($Builds, static function ($b) use ($excludePlanets) {
            $id = is_object($b) ? ($b->id ?? null) : ($b['id'] ?? null);
            return $id !== null && !in_array($id, $excludePlanets, true);
        });

        // Сброс ключей и сортировка по уровню лаборатории (31)
        $Builds = array_values($Builds);
        uasort($Builds, static function ($a, $b) use ($buildKey) {
            $valA = is_object($a) ? ($a->$buildKey ?? 0) : ($a[$buildKey] ?? 0);
            $valB = is_object($b) ? ($b->$buildKey ?? 0) : ($b[$buildKey] ?? 0);
            return $valB <=> $valA;
        });

        // Добавляем текущую планету в начало, если можно
        if ($addCurrentPlanet && $planetId > 0) {
            $planetBuilds = Builds::findById($planetId);
            if ($planetBuilds) {
                array_unshift($Builds, $planetBuilds);
            }
        }

        // Обрезаем по лимиту сети
        $Builds = array_slice($Builds, 0, $maxCount);

        // Собираем уровни лабораторий
        $Levels = [];
        foreach ($Builds as $b) {
            if (is_int($b)) {
                // Если вдруг попало число (id), пропускаем
                continue;
            }
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
