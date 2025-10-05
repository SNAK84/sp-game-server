<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;

use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Techs;

use SPGame\Game\Repositories\Resources;
use SPGame\Game\Repositories\EntitySettings;
use SPGame\Game\Repositories\Queues;

use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;

use SPGame\Game\Repositories\PlayerQueue;

use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Vars;

class QueuesServices
{

    public const BUILDS = 'builds';
    public const TECHS = 'techs';
    public const HANGARS = 'hangars';

    public static function QueueType(int $Element): string
    {

        if (in_array($Element, Vars::$reslist['build'])) {
            return self::BUILDS;
        } elseif (
            in_array($Element, Vars::$reslist['fleet']) ||
            in_array($Element, Vars::$reslist['defense']) ||
            in_array($Element, Vars::$reslist['missile'])
        ) {
            return self::HANGARS;
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            return self::TECHS;
        }

        return self::BUILDS;
    }

    public static function MaxQueue(string $QueueType): int
    {
        $MaxQueue = 0;

        switch ($QueueType) {
            case self::BUILDS:
                $MaxQueue = (int)Config::getValue('MaxQueueBuild');
                break;
            case self::TECHS:
                $MaxQueue = (int)Config::getValue('MaxQueueTech');
                break;
            case self::HANGARS:
                $MaxQueue = (int)Config::getValue('MaxQueueHangar');
                break;
        }

        return $MaxQueue;
    }


    public static function AddToQueue(int $Element, int $userId, int $planetId, float $Time, bool $AddMode = true)
    {

        $User = Users::findById($userId);
        $Planet = Planets::findById($planetId);
        $Builds = Builds::findById($planetId);

        $QueueType = self::QueueType($Element);



        if ($QueueType === self::BUILDS) {
            $planetType = $Planet['planet_type'] ?? null;
            $allowedOnPlanet = ($planetType !== null && isset(Vars::$reslist['allow'][$planetType]))
                ? Vars::$reslist['allow'][$planetType] : [];

            $buildResourceKey = Vars::$resource[$Element] ?? null;
            $buildCount = ($buildResourceKey !== null && isset($Builds[$buildResourceKey])) ? (int)$Builds[$buildResourceKey] : 0;

            if (!in_array($Element, $allowedOnPlanet, true) || (!$AddMode && $buildCount <= 0)) {
                Logger::getInstance()->info("QueuesServices::AddToQueue: element not allowed or nothing to demolish", [
                    'element' => $Element,
                    'planet' => $planetId,
                    'addMode' => $AddMode
                ]);
                return;
            }
        }

        if (!BuildFunctions::isTechnologieAccessible($Element, $userId, $planetId)) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: technology not accessible", ['element' => $Element, 'user' => $userId]);
            return;
        }

        $CurrentQueue       = Queues::getCurrentQueue($QueueType, $userId, $planetId) ?: [];

        $ActualCount        = count($CurrentQueue);
        $DemolishedQueue    = 0;
        $BuildsLevels       = [];
        $QueueEndTime       = $Time;

        foreach ($CurrentQueue as $Queue) {
            $objId = $Queue['object_id'];
            if (!isset($BuildsLevels[$objId])) {
                $BuildsLevels[$objId] = 0;
            }

            if ($Queue['action'] == 'destroy') {
                $BuildsLevels[$objId] -= 1;
                // если демонтаж — освобождается 1 поле
                $DemolishedQueue++;
            } else
                $BuildsLevels[$objId] += 1;

            if (isset($Queue['end_time']) && $Queue['end_time'] > $QueueEndTime) {
                $QueueEndTime = $Queue['end_time'];
            }
        }

        $DemolishedQueue = max(0, $DemolishedQueue);

        $MaxFields = Planets::getMaxFields($planetId);
        $MaxQueue = self::MaxQueue($QueueType);

        if ($ActualCount >= $MaxQueue) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: max queue reached", ['queueType' => $QueueType, 'actual' => $ActualCount, 'max' => $MaxQueue]);
            return;
        }

        $CurrentFields = Planets::getCurrentFields($planetId) + $ActualCount - $DemolishedQueue * 2;

        if ($AddMode && ($CurrentFields + $DemolishedQueue) >= $MaxFields) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: not enough fields", ['currentFields' => $CurrentFields, 'demolished' => $DemolishedQueue, 'maxFields' => $MaxFields]);
            return;
        }

        $BuildLevel = (int) Helpers::getElementLevel($Element, $userId, $planetId);
        $BuildLevel += $AddMode ? 1 : 0;
        $BuildLevel += $BuildsLevels[$Element] ?? 0;


        if (isset(Vars::$attributes[$Element]['max']) && $BuildLevel > (int)Vars::$attributes[$Element]['max']) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: level exceeds max", ['element' => $Element, 'level' => $BuildLevel]);
            return;
        }

        $elementTime    = BuildFunctions::getBuildingTime($Element, $userId, $planetId, null, !$AddMode, $BuildLevel);
        $BuildEndTime   = $QueueEndTime + $elementTime;

        $addQueue = [
            'user_id'   => $userId,
            'planet_id' => $planetId,
            'object_id' => $Element,
            'count'     => $BuildLevel,
            'action'    => ($AddMode) ? 'build' : 'destroy',
            'type'      => $QueueType,
            'time'      => $elementTime,
            'start_time' => $QueueEndTime,
            'end_time'  => $BuildEndTime,
            'status'    => ($ActualCount > 0) ? 'queued' : 'active'
        ];

        // транзакция для атомарности списания ресурсов и добавления в очередь
        $db = null;

        try {
            if ($ActualCount == 0) {
                $costResources = BuildFunctions::getElementPrice($Element, $userId, $planetId, !$AddMode, $BuildLevel);
                if (!BuildFunctions::isElementBuyable($Element, $userId, $planetId, $costResources)) {
                    Logger::getInstance()->info("QueuesServices::AddToQueue: not buyable", ['element' => $Element]);
                    return;
                }

                $Resources = Resources::getByPlanetId($planetId);

                foreach ($costResources as $key => $value) {
                    if (!isset($Resources[$key]['count'])) $Resources[$key]['count'] = 0;
                    $Resources[$key]['count'] -= $value;
                }
                Resources::updateByPlanetId($planetId, $Resources);

                PlayerQueue::addQueue((int)$User['account_id'], (int)$User['id'], (int)$Planet['id'], PlayerQueue::ActionQueueReCalcTech);
            }

            Queues::add($addQueue);

            Logger::getInstance()->info("QueuesServices::AddToQueue: added to queue", $addQueue);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("QueuesServices::AddToQueue transaction error: " . $e->getMessage(), ['exception' => $e]);
            return;
        }
    }

    public static function CancelToQueue(int $QueueId, int $userId, int $planetId, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CancelToQueue: queue not found", ['QueueId' => $QueueId]);
            return;
        }

        // Защита: удостоверимся, что очередь принадлежит указанному пользователю/планете
        if ((int)$Queue['user_id'] !== $userId || (int)$Queue['planet_id'] !== $planetId) {
            Logger::getInstance()->warning("CancelToQueue: queue ownership mismatch", [
                'QueueId' => $QueueId,
                'queue_user' => $Queue['user_id'],
                'queue_planet' => $Queue['planet_id'],
                'req_user' => $userId,
                'req_planet' => $planetId
            ]);
            return;
        }

        $User = Users::findById($userId);

        $Element     = $Queue['object_id'];
        $QueueType   = self::QueueType($Element);

        // Получаем актуальную очередь (от сервера/репозитория)
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $userId, $planetId) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("CancelToQueue: current queue empty", ['QueueId' => $QueueId]);
            return;
        }

        // Находим индекс элемента в текущей очереди по id (может быть не 0)
        $indexToRemove = null;
        foreach ($CurrentQueue as $idx => $q) {
            if ((int)$q['id'] === (int)$QueueId) {
                $indexToRemove = $idx;
                break;
            }
        }

        if ($indexToRemove === null) {
            Logger::getInstance()->warning("CancelToQueue: queue id not found in current queue", [
                'QueueId' => $QueueId,
                'CurrentQueue' => $CurrentQueue
            ]);
            return;
        }

        // Определяем, является ли удаляемый элемент активным (первый в очереди и статус active)
        $isActive = ($indexToRemove === 0 && isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'active');
        $QueueEndTime = $isActive ? $Time : $CurrentQueue[0]['start_time'];

        try {


            // 1) Удаляем элемент из БД (по id) и из локального массива
            Queues::delete($CurrentQueue[$indexToRemove]);
            unset($CurrentQueue[$indexToRemove]);
            $CurrentQueue = array_values($CurrentQueue); // пересчёт индексов

            // 2) Если удаляем активный — возвращаем ресурсы отменённого задания
            if ($isActive) {
                $BuildLevel = (int)$Queue['count'];
                $BuildMode  = $Queue['action']; // 'build' или 'destroy'
                // Формирование стоимости: параметр isDestroy = ($BuildMode == 'destroy')

                $duration = $CurrentQueue[0]['end_time'] - $CurrentQueue[0]['start_time'];
                $Progress = $duration > 0 ? (1 - ($Time - $CurrentQueue[0]['start_time']) / $duration) : 0;
                $Progress = max(0, min(1, $Progress));

                $costResources = BuildFunctions::getElementPrice($Element, $userId, $planetId, $BuildMode === 'destroy', $BuildLevel);

                // Возврат ресурсов на планету
                $Resources = Resources::getByPlanetId($planetId);
                foreach ($costResources as $key => $value) {
                    if (!isset($Resources[$key]['count'])) $Resources[$key]['count'] = 0;
                    $value *= $Progress;
                    $Resources[$key]['count'] += $value;
                }
                Resources::updateByPlanetId($planetId, $Resources);

                Logger::getInstance()->info("CancelToQueue: refunded resources for active queue", [
                    'QueueId' => $QueueId,
                    'cost' => $costResources
                ]);

                // Если есть новый первый элемент — сдвигаем его старт во времени на $Time
                if (!empty($CurrentQueue)) {
                    $CurrentQueue[0]['start_time'] = $Time;
                }
                PlayerQueue::addQueue((int)$User['account_id'], (int)$User['id'], $planetId, PlayerQueue::ActionQueueReCalcTech);
            }

            // 3) Пересчитываем уровни, start_time и end_time для оставшихся элементов очереди
            $BuildsLevels = [];

            foreach ($CurrentQueue as $k => $q) {
                $objId = $q['object_id'];

                if (!isset($BuildsLevels[$objId])) {
                    $BuildsLevels[$objId] = (int) Helpers::getElementLevel($objId, $userId, $planetId);
                }

                if ($q['action'] === 'build') {
                    $BuildsLevels[$objId] += 1;
                } elseif ($q['action'] === 'destroy') {
                    $BuildsLevels[$objId] -= 1;
                }

                // Обновляем count/start/end во временной очереди (и для записи в БД потом)
                $CurrentQueue[$k]['count'] = $BuildsLevels[$objId];
                $CurrentQueue[$k]['start_time'] = $QueueEndTime;

                $elementTime = BuildFunctions::getBuildingTime(
                    $objId,
                    $userId,
                    $planetId,
                    null,
                    $q['action'] === 'destroy',
                    $BuildsLevels[$objId]
                );
                $QueueEndTime += $elementTime;

                $CurrentQueue[$k]['time'] = $elementTime;
                $CurrentQueue[$k]['end_time'] = $QueueEndTime;
            }

            // 4) Попытка активировать новый первый элемент (если он существует и имеет статус queued)
            if (!empty($CurrentQueue) && isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'queued') {
                $first = $CurrentQueue[0];

                $costResources = BuildFunctions::getElementPrice(
                    $first['object_id'],
                    $userId,
                    $planetId,
                    $first['action'] === 'destroy',
                    $first['count']
                );

                // Проверяем, можно ли оплатить (возьмёт текущие ресурсы планеты)
                if (BuildFunctions::isElementBuyable($first['object_id'], $userId, $planetId, $costResources)) {
                    // Списываем ресурсы для нового активного задания
                    $Resources = Resources::getByPlanetId($planetId);
                    foreach ($costResources as $key => $value) {
                        if (!isset($Resources[$key]['count'])) $Resources[$key]['count'] = 0;
                        $Resources[$key]['count'] -= $value;
                    }
                    Resources::updateByPlanetId($planetId, $Resources);

                    // Помечаем как active
                    $CurrentQueue[0]['status'] = 'active';

                    PlayerQueue::addQueue((int)$User['account_id'], (int)$User['id'], $planetId, PlayerQueue::ActionQueueReCalcTech);
                } else {
                    // Нельзя оплатить — оставляем в queued состоянии
                    Logger::getInstance()->info("CancelToQueue: cannot promote first queued element due to insufficient resources", [
                        'first' => $first
                    ]);
                }
            }

            // 5) Сохраняем изменения очереди в БД (обновляем все оставшиеся элементы)
            foreach ($CurrentQueue as $q) {
                // Обновляем запись через репозиторий (Queues::update должен принимать полную структуру)
                Queues::update($q);
            }

            Logger::getInstance()->info("CancelToQueue: finished successfully", [
                'QueueId' => $QueueId,
                'remaining' => count($CurrentQueue)
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("CancelToQueue: transaction error: " . $e->getMessage(), [
                'exception' => $e,
                'QueueId' => $QueueId
            ]);
            // не продолжаем — откат
            return;
        }
    }

    /**
     * Завершение задачи — строительство/исследование и т.п.
     */
    public static function CompleteQueue(int $QueueId, int $userId, int $planetId, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CompleteQueue: queue not found", ['QueueId' => $QueueId]);
            return;
        }

        // Проверка владельца
        if ((int)$Queue['user_id'] !== $userId || (int)$Queue['planet_id'] !== $planetId) {
            Logger::getInstance()->warning("CompleteQueue: ownership mismatch", [
                'QueueId' => $QueueId,
                'queue_user' => $Queue['user_id'],
                'queue_planet' => $Queue['planet_id'],
                'req_user' => $userId,
                'req_planet' => $planetId
            ]);
            return;
        }

        //Logger::getInstance()->info("CompleteQueue start", ['Queue' => $Queue]);

        $Element   = $Queue['object_id'];
        $QueueType = self::QueueType($Element);

        $CurrentQueue = Queues::getCurrentQueue($QueueType, $userId, $planetId) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("CompleteQueue: current queue empty", ['QueueId' => $QueueId]);
            return;
        }

        $indexComplete = null;
        foreach ($CurrentQueue as $idx => $q) {
            if ((int)$q['id'] === (int)$QueueId) {
                $indexComplete = $idx;
                break;
            }
        }

        if ($indexComplete === null) {
            Logger::getInstance()->warning("CompleteQueue: queue id not found", [
                'QueueId' => $QueueId,
                'CurrentQueue' => $CurrentQueue
            ]);
            return;
        }

        try {

            $User = Users::findById($userId);

            // 1️⃣ Завершаем постройку / исследование
            $BuildLevel = (int)$Queue['count'];
            $BuildMode  = $Queue['action']; // build/destroy

            // Получаем текущий уровень элемента
            $currentLevel = Helpers::getElementLevel($Element, $userId, $planetId);

            // Вычисляем новый уровень в зависимости от действия
            if ($BuildMode === 'build') {
                $newLevel = $currentLevel + 1;
            } elseif ($BuildMode === 'destroy') {
                $newLevel = max(0, $currentLevel - 1); // чтобы не было отрицательного уровня
            } else {
                $newLevel = $currentLevel;
            }

            // Устанавливаем новый уровень через универсальный метод
            BuildFunctions::setElementLevel($Element, $userId, $planetId, $newLevel);

            // 2️⃣ Помечаем завершённую очередь
            //$Queue['status'] = 'done';
            //Queues::update($Queue);

            // 3️⃣ Удаляем завершённый элемент из массива
            Queues::delete($CurrentQueue[$indexComplete]);
            unset($CurrentQueue[$indexComplete]);
            $CurrentQueue = array_values($CurrentQueue);


            // 4️⃣ Активируем первую очередную задачу, которая может быть оплачена
            while (!empty($CurrentQueue)) {
                $next = $CurrentQueue[0];
                if ($next['status'] !== 'queued') break;

                $nextElement = (int)$next['object_id'];
                $nextCount   = (int)$next['count'];
                $isDestroy   = ($next['action'] === 'destroy');

                // Проверка стоимости
                $costResources = BuildFunctions::getElementPrice($nextElement, $userId, $planetId, $isDestroy, $nextCount);

                if (!BuildFunctions::isElementBuyable($nextElement, $userId, $planetId, $costResources)) {
                    // Не хватает ресурсов → удаляем и идём к следующей
                    Logger::getInstance()->info("CompleteQueue: cannot pay, removing queued element", [
                        'id' => $next['id'],
                        'element' => $nextElement,
                        'cost' => $costResources
                    ]);

                    Queues::delete($next);
                    array_shift($CurrentQueue);
                    continue;
                }

                // Списываем ресурсы
                $Resources = Resources::getByPlanetId($planetId);
                foreach ($costResources as $key => $value) {
                    if (!isset($Resources[$key]['count'])) $Resources[$key]['count'] = 0;
                    $Resources[$key]['count'] -= $value;
                }
                Resources::updateByPlanetId($planetId, $Resources);

                // Активируем первую очередь
                $CurrentQueue[0]['status'] = 'active';

                PlayerQueue::addQueue((int)$User['account_id'], (int)$User['id'], $planetId, PlayerQueue::ActionQueueReCalcTech);

                break; // активировали задачу — выходим из цикла
            }

            // 5️⃣ Пересчитываем start_time и end_time для всей очереди заново
            $QueueTime = $Time;
            $BuildsLevels = [];

            foreach ($CurrentQueue as $k => $q) {
                $objId = $q['object_id'];

                if (!isset($BuildsLevels[$objId])) {
                    $BuildsLevels[$objId] = (int) Helpers::getElementLevel($objId, $userId, $planetId);
                }

                if ($q['action'] === 'build') {
                    $BuildsLevels[$objId] += 1;
                } elseif ($q['action'] === 'destroy') {
                    $BuildsLevels[$objId] -= 1;
                }

                $CurrentQueue[$k]['count'] = $BuildsLevels[$objId];
                $CurrentQueue[$k]['start_time'] = $QueueTime;

                $elTime = BuildFunctions::getBuildingTime($objId, $userId, $planetId, null, $q['action'] === 'destroy', $BuildsLevels[$objId]);
                $QueueTime += $elTime;
                $CurrentQueue[$k]['time'] = $elTime;
                $CurrentQueue[$k]['end_time'] = $QueueTime;

                // Обновляем запись в БД
                Queues::update($CurrentQueue[$k]);
            }

            Logger::getInstance()->info("CompleteQueue: finished successfully", [
                'QueueId' => $QueueId,
                'remaining' => count($CurrentQueue)
            ]);

            /*

            // 3️⃣ Пересчитываем оставшиеся start/end
            $BuildsLevels = [];
            $QueueEndTime = $Time;

            foreach ($CurrentQueue as $k => $q) {
                $objId = $q['object_id'];

                if (!isset($BuildsLevels[$objId])) {
                    $BuildsLevels[$objId] = (int) Helpers::getElementLevel($objId, $userId, $planetId);
                }

                if ($q['action'] === 'build') {
                    $BuildsLevels[$objId] += 1;
                } elseif ($q['action'] === 'destroy') {
                    $BuildsLevels[$objId] -= 1;
                }

                $CurrentQueue[$k]['count'] = $BuildsLevels[$objId];
                $CurrentQueue[$k]['start_time'] = $QueueEndTime;

                $elementTime = BuildFunctions::getBuildingTime(
                    $objId,
                    $userId,
                    $planetId,
                    null,
                    $q['action'] === 'destroy',
                    $BuildsLevels[$objId]
                );
                $QueueEndTime += $elementTime;
                $CurrentQueue[$k]['end_time'] = $QueueEndTime;
            }

            // 4️⃣ Активируем следующую очередь (если есть queued)
            while (!empty($CurrentQueue) && isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'queued') {
                $next = $CurrentQueue[0];

                $nextElement = (int)$next['object_id'];
                $nextCount   = (int)$next['count'];
                $isDestroy   = ($next['action'] === 'destroy');

                // 4.a) считаем стоимость для next
                $costResources = BuildFunctions::getElementPrice($nextElement, $userId, $planetId, $isDestroy, $nextCount);

                // 4.b) проверяем, может ли планета оплатить
                if (!BuildFunctions::isElementBuyable($nextElement, $userId, $planetId, $costResources)) {
                    Logger::getInstance()->info("CompleteQueue: cannot pay, removing queued element", [
                        'id' => $next['id'],
                        'element' => $nextElement,
                        'cost' => $costResources
                    ]);

                    // Удаляем запись из очереди (из базы и локального массива)
                    Queues::delete($next['id']);
                    array_shift($CurrentQueue);

                    // продолжаем цикл, пытаясь активировать следующую
                    continue;
                }

                // 4.c) списываем ресурсы и активируем эту очередь
                $Resources = Resources::getByPlanetId($planetId);
                foreach ($costResources as $key => $value) {
                    if (!isset($Resources[$key]['count'])) $Resources[$key]['count'] = 0;
                    $Resources[$key]['count'] -= $value;
                }
                Resources::updateByPlanetId($planetId, $Resources);

                // пересчитаем время для первого элемента
                $start = $Time;
                $elTime = BuildFunctions::getBuildingTime($nextElement, $userId, $planetId, null, $isDestroy, $nextCount);

                $CurrentQueue[0]['status'] = 'active';
                $CurrentQueue[0]['start_time'] = $start;
                $CurrentQueue[0]['end_time'] = $start + $elTime;

                Queues::update($CurrentQueue[0]);

                Logger::getInstance()->info("CompleteQueue: activated queued element", [
                    'id' => $CurrentQueue[0]['id'],
                    'cost' => $costResources
                ]);

                // нашли активируемую задачу — выходим из цикла
                break;
            }
            

            // 5️⃣ Сохраняем изменения
            foreach ($CurrentQueue as $q) {
                Queues::update($q);
            }



            Logger::getInstance()->info("CompleteQueue: finished successfully", [
                'QueueId' => $QueueId,
                'remaining' => count($CurrentQueue)
            ]);
            */
        } catch (\Throwable $e) {
            Logger::getInstance()->error("CompleteQueue: transaction error: " . $e->getMessage(), [
                'exception' => $e,
                'QueueId' => $QueueId
            ]);
            return;
        }
    }

    public static function ReCalcTimeQueue(string $QueueType, int $userId, int $planetId, float $Time)
    {

        // Получаем актуальную очередь (от сервера/репозитория)
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $userId, $planetId) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("ReCalcTimeQueue: current queue empty");
            return;
        }

        // Определяем, является ли удаляемый элемент активным (первый в очереди и статус active)
        $isActive = (isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'active');
        $QueueEndTime = $CurrentQueue[0]['start_time'];

        // 3) Пересчитываем уровни, start_time и end_time для оставшихся элементов очереди
        $BuildsLevels = [];

        Logger::getInstance()->info("ReCalcTimeQueue Start");

        foreach ($CurrentQueue as $k => $q) {
            $objId = $q['object_id'];

            if (!isset($BuildsLevels[$objId])) {
                $BuildsLevels[$objId] = (int) Helpers::getElementLevel($objId, $userId, $planetId);
            }

            if ($q['action'] === 'build') {
                $BuildsLevels[$objId] += 1;
            } elseif ($q['action'] === 'destroy') {
                $BuildsLevels[$objId] -= 1;
            }

            // Обновляем count/start/end во временной очереди (и для записи в БД потом)
            $CurrentQueue[$k]['count'] = $BuildsLevels[$objId];
            $CurrentQueue[$k]['start_time'] = $QueueEndTime;

            $elementTime = BuildFunctions::getBuildingTime(
                $objId,
                $userId,
                $planetId,
                null,
                $q['action'] === 'destroy',
                $BuildsLevels[$objId]
            );

            $QueueEndTime += $elementTime;


            if ($isActive && $elementTime != $CurrentQueue[$k]['time']) {


                $passedTime = $Time - $CurrentQueue[$k]['start_time'];
                $Progres = $passedTime / $CurrentQueue[$k]['time'];

                $QueueEndTime = $CurrentQueue[$k]['start_time'] +
                    ($CurrentQueue[$k]['time'] * $Progres) +
                    ($elementTime * (1 - $Progres));

                Logger::getInstance()->info("ReCalcTimeQueue time", [
                    'end_time'=>$CurrentQueue[$k]['end_time'],
                    'QueueEndTime'=>$QueueEndTime
                ]);
            }

            /*if ($isActive) {
                Logger::getInstance()->info("ReCalcTimeQueue ", [
                    $elementTime,
                    $CurrentQueue[$k]['time']
                ]);
            }*/



            $CurrentQueue[$k]['time'] = $elementTime;
            $CurrentQueue[$k]['end_time'] = $QueueEndTime;

            Queues::update($CurrentQueue[$k]);
        }
    }
}
