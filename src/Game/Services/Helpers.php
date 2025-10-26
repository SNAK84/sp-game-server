<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Builds;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Resources;

use SPGame\Game\Repositories\Config;

use SPGame\Game\Repositories\Vars;

use SPGame\Core\Logger;

class Helpers
{

    public static function getNetworkLevels(AccountData $AccountData): array
    {

        $buildKey = Vars::$resource[31];
        $techKey  = Vars::$resource[123];

        $userId   = $AccountData['User']['id'] ?? 0;
        $planetId = $AccountData['Planet']['id'] ?? 0;

        // Загружаем все постройки пользователя и активные очереди
        $Builds = $AccountData['All_Builds']->toArray();
        $Queues = Queues::getActive($userId) ?? [];

        // Уровень межпланетной сети (techKey) и лимит количества планет для сети
        $techs = $AccountData['Techs'] ?? [];
        $maxCount = ((int)($techs[$techKey] ?? 0)) + 1;

        // Список планет, где идёт строительство лаборатории (object_id = 31)
        $excludePlanets = array_column(
            array_filter(
                $Queues,
                static fn($q) =>
                isset($q['type'], $q['object_id']) &&
                    $q['type'] === \SPGame\Game\Services\QueuesServices::BUILDS &&
                    ((int)$q['object_id'] === 6 || (int)$q['object_id'] === 31)
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

    public static function getMaxFields(AccountData $AccountData): int
    {
        $fields = $AccountData['Planet']['fields'];
        $Builds = $AccountData['Builds'];

        $Terraformer = $Builds[Vars::$resource[33]];
        $Mondbasis = $Builds[Vars::$resource[33]];

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::BUILDS, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];
        foreach ($CurrentQueue as $Queue) {
            $objId = $Queue['object_id'];
            if ($objId !== 33 || $objId !== 41) continue;

            if ($Queue['action'] == 'destroy') {
                $Terraformer--;
                $Mondbasis--;
            } else {
                $Terraformer++;
                $Mondbasis++;
            }
        }

        $fields += ($Terraformer * Config::getValue("FieldsByTerraformer"));
        $fields += ($Mondbasis * Config::getValue("FieldsByMoonBasis"));

        return $fields;
    }

    public static function getCurrentFields(AccountData $AccountData): int
    {
        //$fields = self::findById($planetId)['fields'];
        $Builds = $AccountData['Builds'];

        $CurrentFields = 0;
        foreach (Vars::$reslist['build'] as $id)
            $CurrentFields += $Builds[Vars::$resource[$id]];

        return $CurrentFields;
    }

    public static function processPlanet(float $targetTime, AccountData $AccountData): bool
    {
        $sendMsg = false;

        $ProductionTime = $targetTime - $AccountData['Planet']['update_time']; // в микросекундах
        $hoursPassed = $ProductionTime / 3600; // в часах

        // Множитель количества оборотов в сутки (по умолчанию 24)
        $SpeedPlanets = Config::getValue('SpeedPlanets', 24);

        // Сколько градусов прошла планета за прошедшее время
        $degShift = $hoursPassed * $AccountData['Planet']['speed'] * ($SpeedPlanets / 24);

        // Учёт направления вращения
        if ($AccountData['Planet']['rotation'] == 0) {
            $degShift = -$degShift; // обратное вращение
        }

        // Новый угол и нормализация
        $deg = fmod($AccountData['Planet']['deg'] + $degShift, 360);
        if ($deg < 0) $deg += 360;

        $AccountData['Planet']['deg'] = $deg;

        $sendMsg = self::processHangar($targetTime, $AccountData) ? true : $sendMsg;
        Resources::processResources($targetTime, $AccountData);

        return $sendMsg;
    }

    protected static function processHangar(float $targetTime, AccountData $AccountData): bool
    {
        $sendMsg = false;
        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::HANGARS, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];
        if (!$CurrentQueue) return false;

        // Время, прошедшее с начала первой постройки
        $BuildTime = $targetTime - $CurrentQueue[0]['start_time'];
        if ($BuildTime <= 0) return false;

        //Logger::getInstance()->info("processHangar CurrentQueue", $CurrentQueue ?? []);

        foreach ($CurrentQueue as $k => $Queue) {

            $Element     = $Queue['object_id'];
            $Count       = (int)$Queue['count'];
            $timePerUnit = BuildFunctions::getBuildingTime($Element, $AccountData);

            if ($Count <= 0) {
                unset($CurrentQueue[$k]);
                Queues::delete($Queue);
                continue;
            }

            // Сколько единиц можно достроить за доступное время
            $CountMaxBuild = floor($BuildTime / $timePerUnit);
            if ($CountMaxBuild <= 0) break;

            $BuildCount = min($Count, $CountMaxBuild);
            $spentTime  = $BuildCount * $timePerUnit;

            if ($BuildCount < 1) break;

            BuildFunctions::addElementLevel($Element, $AccountData, $BuildCount);

            // Обновляем данные
            $Count -= $BuildCount;
            $BuildTime -= $spentTime;

            if ($Count < 0) {
                Logger::getInstance()->error("processHangar Count меньше 0 " . $Count);
            }

            if ($Count < 1) {
                $sendMsg = true;
                unset($CurrentQueue[$k]);
                Queues::delete($Queue);
            } else {

                $Queue['count'] = $Count;
                $Queue['start_time'] = $targetTime - $BuildTime;
                $Queue['end_time']   = $Queue['start_time'] + $Count * $timePerUnit;

                Queues::update($Queue);
                $CurrentQueue[$k] = $Queue;
            }

            if ($BuildTime < 0) {
                Logger::getInstance()->error("processHangar BuildTime меньше 0 " . $BuildTime);
            }

            if ($BuildTime < 1) break;
        }

        return $sendMsg;
    }
}
