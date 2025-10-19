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

use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\Helpers;


class QueuesServices
{

    public const BUILDS  = 'builds';
    public const TECHS   = 'techs';
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


    public static function AddToQueue(int $Element, AccountData &$AccountData, float $Time, bool $AddMode = true)
    {

        $QueueAccountData = $AccountData->deepCopy();

        $User       = &$AccountData['User'];
        $Planet     = &$AccountData['Planet'];
        $Builds     = &$AccountData['Builds'];
        $Techs      = &$AccountData['Techs'];
        $Resources  = &$AccountData['Resources'];

        $QueueType = self::QueueType($Element);

        if ($QueueType === self::BUILDS) {
            $planetType = $Planet['planet_type'] ?? null;
            $allowedOnPlanet = ($planetType !== null && isset(Vars::$reslist['allow'][$planetType]))
                ? Vars::$reslist['allow'][$planetType] : [];

            $buildResourceKey = Vars::$resource[$Element] ?? null;
            $buildCount = ($buildResourceKey !== null && isset($Builds[$buildResourceKey])) ? (int)$Builds[$buildResourceKey] : 0;

            if (!in_array($Element, $allowedOnPlanet, true)) {
                return;
            }
        }



        if (!BuildFunctions::isTechnologieAccessible($Element, $AccountData)) {
            Logger::getInstance()->info(
                "QueuesServices::AddToQueue: technology not accessible",
                [
                    'element' => $Element,
                    'user' => $AccountData['User']['id'],
                    'planet' => $AccountData['Planet']['id']
                ]
            );
            return;
        }

        $CurrentQueue       = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];

        $ActualCount        = count($CurrentQueue);
        $DemolishedQueue    = 0;
        //$BuildsLevels       = [];
        $QueueEndTime       = $Time;

        foreach ($CurrentQueue as $Queue) {
            $objId = $Queue['object_id'];
            $planetId = (int)($Queue['planet_id'] ?? ($QueueAccountData['Planet']['id'] ?? 0));
            $QueueAccountData['WorkPlanet'] = $planetId;
            $Level = BuildFunctions::getElementLevel($objId, $QueueAccountData);
            $Level  += ($Queue['action'] === 'destroy' ? -1 : 1);

            BuildFunctions::setElementLevel($objId, $QueueAccountData, $Level);



            /*if (!isset($BuildsLevels[$objId])) {
                $BuildsLevels[$objId] = 0;
            }

            if ($Queue['action'] == 'destroy') {
                $BuildsLevels[$objId] -= 1;
                // если демонтаж — освобождается 1 поле
                $DemolishedQueue++;
            } else
                $BuildsLevels[$objId] += 1;*/

            if (isset($Queue['end_time']) && $Queue['end_time'] > $QueueEndTime) {
                $QueueEndTime = $Queue['end_time'];
            }
        }

        //$DemolishedQueue = max(0, $DemolishedQueue);

        $MaxFields = Helpers::getMaxFields($QueueAccountData);
        $MaxQueue = self::MaxQueue($QueueType);

        if ($ActualCount >= $MaxQueue) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: max queue reached", ['queueType' => $QueueType, 'actual' => $ActualCount, 'max' => $MaxQueue]);
            return;
        }

        $CurrentFields = Helpers::getCurrentFields($QueueAccountData);

        if ($AddMode && ($CurrentFields) >= $MaxFields) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: not enough fields", ['currentFields' => $CurrentFields, 'demolished' => $DemolishedQueue, 'maxFields' => $MaxFields]);
            return;
        }

        $BuildLevel = (int) BuildFunctions::getElementLevel($Element, $QueueAccountData);
        $BuildLevel += $AddMode ? 1 : 0;
        //$BuildLevel += $BuildsLevels[$Element] ?? 0;

        if ($BuildLevel < 1) {
            Logger::getInstance()->warning("QueuesServices::AddToQueue: BuildLevel < 0", ['element' => $Element, 'level' => $BuildLevel]);
            return;
        }

        if (isset(Vars::$attributes[$Element]['max']) && $BuildLevel > (int)Vars::$attributes[$Element]['max']) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: level exceeds max", ['element' => $Element, 'level' => $BuildLevel]);
            return;
        }

        $elementTime    = BuildFunctions::getBuildingTime($Element, $QueueAccountData, null, !$AddMode, $BuildLevel);
        $BuildEndTime   = $QueueEndTime + $elementTime;

        $addQueue = [
            'user_id'   => $User['id'],
            'planet_id' => $Planet['id'],
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

        try {
            if ($ActualCount == 0) {

                if ($QueueType === self::BUILDS) {
                    $QueueActiveTech    = Queues::getActivePlanet(QueuesServices::TECHS, $AccountData['Planet']['id']);
                    $QueueActiveHangar  = Queues::getActivePlanet(QueuesServices::HANGARS, $AccountData['Planet']['id']);
                    if (
                        ($QueueActiveTech && ($Element == 6 || $Element == 31)) ||
                        ($QueueActiveHangar && ($Element == 15 || $Element == 21))
                    ) {
                        Logger::getInstance()->info("AddToQueue: element is busy, removing element", [
                            'element' => $Element
                        ]);

                        return;
                    }
                }
                if ($QueueType === self::TECHS) {
                    $QueueActiveBuild  = Queues::getActivePlanet(QueuesServices::BUILDS,  $AccountData['Planet']['id']);
                    if (
                        !empty($QueueActiveBuild)
                        && isset($QueueActiveBuild[0]['object_id'])
                        && in_array((int)$QueueActiveBuild[0]['object_id'], [6, 31], true)
                    ) {
                        Logger::getInstance()->info("AddToQueue: element is busy, removing element", [
                            'element' => $Element
                        ]);

                        return;
                    }
                }

                $costResources = BuildFunctions::getElementPrice($Element, $AccountData, !$AddMode, $BuildLevel);
                if (!BuildFunctions::isElementBuyable($Element, $AccountData, $costResources)) {
                    Logger::getInstance()->info("QueuesServices::AddToQueue: not buyable", ['element' => $Element]);
                    return;
                }

                $Resources = &$AccountData['Resources'];

                foreach ($costResources as $key => $value) {
                    if (!isset($Resources[$key]['count'])) $Resources[$key]['count'] = 0;
                    $Resources[$key]['count'] -= $value;
                }

                PlayerQueue::addQueue((int)$User['account_id'], (int)$User['id'], (int)$Planet['id'], PlayerQueue::ActionQueueReCalcTech);
            }

            Queues::add($addQueue);

            Logger::getInstance()->info("QueuesServices::AddToQueue: added to queue", $addQueue);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("QueuesServices::AddToQueue transaction error: " . $e->getMessage(), ['exception' => $e]);
            return;
        }
    }

    public static function AddToQueueHangar(array $items, AccountData &$AccountData, float $Time)
    {

        $userId   = $AccountData['User']['id'];
        $planetId = $AccountData['Planet']['id'];

        $CurrentQueue = Queues::getCurrentQueue(self::HANGARS, $userId, $planetId) ?: [];
        $MaxQueue = self::MaxQueue(self::HANGARS);
        $CurrentQueueCount = count($CurrentQueue);


        if ($CurrentQueueCount >= $MaxQueue) {
            Logger::getInstance()->info("QueuesServices::AddToQueueHangar: max queue reached", ['actual' => count($CurrentQueue), 'max' => $MaxQueue]);
            return;
        }

        $LastQueue = $CurrentQueueCount > 0 ? end($CurrentQueue) : null;
        $QueueStartTime = $CurrentQueueCount > 0 ? $LastQueue['end_time'] : $Time;
        $maxPerBuild = Config::getValue("MaxFleetPerBuild");

        $Missiles = [];
        foreach (Vars::$reslist['missile'] as $elementID) {
            $Missiles[$elementID] = BuildFunctions::getElementLevel($elementID, $AccountData);
        }

        foreach ($items as $Element => $Count) {
            if ($CurrentQueueCount >= $MaxQueue) break;
            if (
                empty($Count)
                || !in_array($Element, array_merge(Vars::$reslist['fleet'], Vars::$reslist['defense'], Vars::$reslist['missile']))
                || !BuildFunctions::isTechnologieAccessible($Element, $AccountData)
            ) {
                continue;
            }

            $MaxElements = BuildFunctions::getMaxConstructibleElements($Element, $AccountData);
            $Count       = max(0, min((int)$Count, $maxPerBuild, $MaxElements));

            if (in_array($Element, Vars::$reslist['missile'])) {
                $MaxMissiles         = BuildFunctions::getMaxConstructibleRockets($AccountData);
                $Count               = min($Count, $MaxMissiles[$Element]);
                $Missiles[$Element] += $Count;
            } elseif (in_array($Element, Vars::$reslist['one'])) {
                $InBuild    = false;
                foreach ($CurrentQueue as $Queue) {
                    if ($Queue['object_id'] == $Element) {
                        $InBuild = true;
                        break;
                    }
                }

                $ElementCount = BuildFunctions::getElementLevel($Element, $AccountData);

                if ($InBuild || $ElementCount > 0)
                    continue;


                if ($Count != 0 && $ElementCount == 0 && $InBuild === false)
                    $Count =  1;
            }


            if ($Count < 1) continue;

            $elementTime    = BuildFunctions::getBuildingTime($Element, $AccountData);
            $costResources = BuildFunctions::getElementPrice($Element, $AccountData);

            // Проверка: можно ли объединить с последней очередью
            if ($LastQueue && $LastQueue['object_id'] === $Element) {
                $availableToAdd = $maxPerBuild - $LastQueue['count'];

                if ($availableToAdd > 0) {
                    $addCount = min($Count, $availableToAdd);
                    $LastQueue['count'] += $addCount;
                    $LastQueue['end_time'] += $addCount * $elementTime;

                    $QueueStartTime = $LastQueue['end_time'];

                    Queues::update($LastQueue);

                    // Списываем ресурсы
                    foreach ($costResources as $key => $value) {
                        $AccountData['Resources'][$key]['count'] -= $value * $addCount;
                    }

                    $Count -= $addCount;
                    if ($Count <= 0) continue;
                }

                // если лимит достигнут — создаём новую очередь
            }




            $addQueue = [
                'user_id'   => $userId,
                'planet_id' => $planetId,
                'object_id' => $Element,
                'count'     => $Count,
                'action'    => 'build',
                'type'      => self::HANGARS,
                'time'      => $elementTime,
                'status'    => 'queued',
                'start_time' => $QueueStartTime,
                'end_time'   => $QueueStartTime + $elementTime * $Count,
            ];


            Queues::add($addQueue);

            foreach ($costResources as $key => $value) {
                if (!isset($AccountData['Resources'][$key]['count'])) $AccountData['Resources'][$key]['count'] = 0;
                $AccountData['Resources'][$key]['count'] -= $value * $Count;
            }

            $QueueStartTime = $addQueue['end_time'];
            $LastQueue = $addQueue;
            $CurrentQueueCount++;
        }
    }

    public static function CancelToQueue(int $QueueId, AccountData &$AccountData, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CancelToQueue: queue not found", ['QueueId' => $QueueId]);
            return;
        }

        $planetId = (int)$Queue['planet_id'];
        $Element     = $Queue['object_id'];
        $QueueType   = self::QueueType($Element);

        $AccountData['WorkPlanet'] = $planetId;
        // Проверяем владельца
        if (
            (int)$Queue['user_id'] !== $AccountData['User']['id'] ||
            ((int)$Queue['planet_id'] !== $AccountData['Planet']['id'] && $QueueType !== QueuesServices::TECHS)
        ) {
            Logger::getInstance()->warning("CancelToQueue: queue ownership mismatch", [
                'QueueId' => $QueueId,
                'queue_user' => $Queue['user_id'],
                'queue_planet' => $Queue['planet_id'],
                'req_user' => $AccountData['User']['id'],
                'req_planet' => $AccountData['Planet']['id']
            ]);
            return;
        }

        // --- Загружаем актуальную очередь ---
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $planetId) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("CancelToQueue: current queue empty", ['QueueId' => $QueueId]);
            return;
        }

        // Находим индекс
        $indexToRemove = array_search($QueueId, array_column($CurrentQueue, 'id'));
        if ($indexToRemove === false) {
            Logger::getInstance()->warning("CancelToQueue: queue id not found", ['QueueId' => $QueueId]);
            return;
        }

        // Активная ли это очередь
        $isActive = ($indexToRemove === 0 && $CurrentQueue[$indexToRemove]['status'] === 'active');

        try {

            // Удаляем из очереди
            Queues::delete($CurrentQueue[$indexToRemove]);
            unset($CurrentQueue[$indexToRemove]);
            $CurrentQueue = array_values($CurrentQueue);

            // 2) Если удаляем активный — возвращаем ресурсы отменённого задания
            if ($isActive) {
                self::refundActiveQueueResources($Queue, $AccountData, $Time);
            }

            // Перерасчёт оставшейся очереди
            self::recalcQueueTimings($CurrentQueue, $AccountData, $Time);


            // 4) Попытка активировать новый первый элемент (если он существует и имеет статус queued)
            // --- Активация следующего ---
            self::activateNextQueueItem($CurrentQueue, $AccountData, $Time);


            if ($QueueType === self::BUILDS) PlayerQueue::addQueue(
                (int)$AccountData['User']['account_id'],
                (int)$AccountData['User']['id'],
                (int)$AccountData['Planet']['id'],
                PlayerQueue::ActionQueueReCalcTech
            );
            // 5) Сохраняем изменения очереди в БД (обновляем все оставшиеся элементы)
            foreach ($CurrentQueue as $q) {
                // Обновляем запись через репозиторий (Queues::update должен принимать полную структуру)
                Queues::update($q);
            }

            Logger::getInstance()->info("CancelToQueue: finished", [
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

    public static function CancelToQueueHangar(int $QueueId, AccountData &$AccountData, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CancelToQueueHangar: queue not found", ['QueueId' => $QueueId]);
            return;
        }

        $planetId = (int)$Queue['planet_id'];
        $Element     = $Queue['object_id'];
        $QueueType   = self::QueueType($Element);

        $AccountData['WorkPlanet'] = $planetId;

        // --- Загружаем актуальную очередь ---
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $planetId) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("CancelToQueueHangar: current queue empty", ['QueueId' => $QueueId]);
            return;
        }

        $indexToRemove = array_search($QueueId, array_column($CurrentQueue, 'id'));
        if ($indexToRemove === false) {
            Logger::getInstance()->warning("CancelToQueue: queue id not found", ['QueueId' => $QueueId]);
            return;
        }

        $cost = BuildFunctions::getElementPrice($Element, $AccountData);

        $count = $Queue['count'];

        if ($indexToRemove === 0) {
            $elapsed = max(0, $Time - $Queue['start_time']); // сколько времени прошло
            $perItemTime = max(1, $Queue['time']);           // защита от деления на 0

            // Сколько полных объектов уже построено
            $builtCount = floor($elapsed / $perItemTime);

            // Прогресс текущего (строящегося) объекта
            $partialProgress = ($elapsed % $perItemTime) / $perItemTime;

            // Сколько осталось построить (включая частичный)
            $count = $Queue['count'] - $builtCount - $partialProgress;

            /*$count = floor(($Queue['end_time'] - $Queue['start_time']) / $Queue['time']);

            $progress = 1 - $Queue['time'] / ($Time - $Queue['start_time']);

            $count += $progress;*/
        }

        $Resources = &$AccountData['Resources'];
        foreach ($cost as $k => $v) {
            if (!isset($Resources[$k]['count'])) $Resources[$k]['count'] = 0;
            $Resources[$k]['count'] += $v * $count;
        }


        Queues::delete($CurrentQueue[$indexToRemove]);
        unset($CurrentQueue[$indexToRemove]);
        $CurrentQueue = array_values($CurrentQueue);

        if (empty($CurrentQueue)) return;

        $QueueStartTime = $indexToRemove === 0 ? $Time : $CurrentQueue[0]['start_time'];

        foreach ($CurrentQueue as $index => $Queue) {

            $elementTime         = BuildFunctions::getBuildingTime($Queue['object_id'], $AccountData);
            $Queue['time']       = $elementTime;
            $Queue['start_time'] = $QueueStartTime;
            $Queue['end_time']   = $QueueStartTime + $elementTime * $Queue['count'];
            $QueueStartTime      = $Queue['end_time'];
            Queues::update($Queue);
        }


        /* foreach ($CurrentQueue as $q) {
            Queues::update($q);
        }*/
    }
    /**
     * Завершение задачи — строительство/исследование и т.п.
     */
    public static function CompleteQueue(int $QueueId, AccountData &$AccountData, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CompleteQueue: queue not found", ['QueueId' => $QueueId]);
            return;
        }

        $planetId = (int)$Queue['planet_id'];

        $AccountData['WorkPlanet'] = $planetId;

        $Element   = $Queue['object_id'];
        $QueueType   = self::QueueType($Element);

        // Проверка владельца
        if (
            (int)$Queue['user_id'] !== $AccountData['User']['id'] ||
            ((int)$Queue['planet_id'] !== $AccountData['Planet']['id'] && $QueueType !== QueuesServices::TECHS)
        ) {
            Logger::getInstance()->warning("CompleteQueue: ownership mismatch", [
                'QueueId' => $QueueId,
                'queue_user' => $Queue['user_id'],
                'queue_planet' => $Queue['planet_id'],
                'req_user' => $AccountData['User']['id'],
                'req_planet' => $AccountData['Planet']['id']
            ]);
            return;
        }

        Logger::getInstance()->info("CompleteQueue start", ['Queue' => $Queue]);

        // --- Пересчитать ресурсы до конца очереди ---
        Helpers::processPlanet($Time, $AccountData);

        try {

            // Завершаем постройку
            $Element = $Queue['object_id'];
            $action = $Queue['action'];

            // --- Обновляем уровень ---
            $currentLevel = BuildFunctions::getElementLevel($Element, $AccountData);
            $newLevel = match ($action) {
                'build'   => $currentLevel + 1,
                'destroy' => max(0, $currentLevel - 1),
                default   => $currentLevel,
            };
            BuildFunctions::setElementLevel($Element, $AccountData, $newLevel);

            // --- Удаляем элемент ---
            Queues::delete($Queue);

            // --- Получаем оставшуюся очередь ---
            $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $planetId) ?: [];

            if (!empty($CurrentQueue)) {
                // --- Активируем следующую задачу ---
                self::activateNextQueueItem($CurrentQueue, $AccountData, $Time);

                // --- Пересчёт таймингов ---
                self::recalcQueueTimings($CurrentQueue, $AccountData, $Time);
            }

            /*
            // --- Сохраняем обратно в кэш ---
            $PlanetsData[$planetId]['Planet']    = $tmpAccount['Planet'];
            $PlanetsData[$planetId]['Builds']    = $tmpAccount['Builds'];
            $PlanetsData[$planetId]['Resources'] = $tmpAccount['Resources'];
            */

            // 5) Сохраняем изменения очереди в БД (обновляем все оставшиеся элементы)
            foreach ($CurrentQueue as $q) {
                Queues::update($q);
            }

            Logger::getInstance()->info("CompleteQueue: done", [
                'queue_id' => $QueueId,
                'planet' => $planetId,
                'element' => $Element,
                'new_level' => $newLevel
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("CompleteQueue: transaction error: " . $e->getMessage(), [
                'exception' => $e,
                'QueueId' => $QueueId
            ]);
            return;
        }
    }

    public static function ReCalcTimeQueue(string $QueueType, AccountData &$AccountData, float $Time)
    {

        // Получаем актуальную очередь (от сервера/репозитория)
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("ReCalcTimeQueue: current queue empty");
            return;
        }

        // Проверяем, активна ли первая задача
        $isActive = (isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'active');

        // 3) Пересчитываем уровни, start_time и end_time для оставшихся элементов очереди
        $BuildsLevels = [];

        Logger::getInstance()->info("ReCalcTimeQueue: start recalculation", [
            'queueType' => $QueueType,
            'active' => $isActive,
            'queueCount' => count($CurrentQueue)
        ]);

        // Вызываем общий перерасчёт таймингов
        self::recalcQueueTimings(
            $CurrentQueue,
            $AccountData,
            $Time
        );

        // 5) Сохраняем изменения очереди в БД (обновляем все оставшиеся элементы)
        foreach ($CurrentQueue as $q) {
            Queues::update($q);
        }

        Logger::getInstance()->info("ReCalcTimeQueue: recalculation completed", [
            'queueType' => $QueueType,
            'user' => $AccountData['User']['id'],
            'planet' => $AccountData['Planet']['id'],
            'count' => count($CurrentQueue)
        ]);
    }

    /**
     * Перерасчёт таймингов всех очередей.
     * Обновляет start_time, end_time, time, count.
     * Используется после отмены, завершения и ручного пересчёта.
     */
    private static function recalcQueueTimings(array &$CurrentQueue, AccountData &$AccountData, float $CurrentTime = 0): void
    {
        //$QueueEndTime = $StartTime;

        $QueueAccountData = $AccountData->deepCopy();

        Logger::getInstance()->info("recalcQueueTimings Start");

        $QueueEndTime = $CurrentQueue[0]['start_time'];

        foreach ($CurrentQueue as $k => $q) {
            $objId = (int)$q['object_id'];
            $planetId = (int)($q['planet_id'] ?? ($QueueAccountData['Planet']['id'] ?? 0));
            $action = $q['action'] ?? 'build';

            $QueueAccountData['WorkPlanet'] = $planetId;


            // Инициализируем массив уровней для планеты
            //if (!isset($BuildsLevels[$planetId])) {
            //    $BuildsLevels[$planetId] = [];
            //}

            // Если для этого элемента ещё нет уровня — взять текущий уровень из кэша Builds
            //if (!isset($BuildsLevels[$planetId][$objId])) {               
            //    $BuildsLevels[$planetId][$objId] = (int) BuildFunctions::getElementLevel($objId, $QueueAccountData);
            //}

            // Применяем действие очереди к уровню (build/destroy)
            $Level = BuildFunctions::getElementLevel($objId, $QueueAccountData);
            if ($action === 'build') {
                $Level += 1;
            } elseif ($action === 'destroy') {
                $Level -= 1;
            }

            BuildFunctions::setElementLevel($objId, $QueueAccountData, $Level);

            // Устанавливаем count (уровень после применения) и start_time
            $CurrentQueue[$k]['count'] = $Level;
            $CurrentQueue[$k]['start_time'] = $QueueEndTime;

            // Если требуется, сначала довести ресурсы этой планеты до current start_time
            // (в большинстве сценариев resources уже будут соответствовать моменту, но безопасно вызывать)
            /*if (isset($QueueAccountData['Planet']['update_time'])) {
                Helpers::processPlanet($CurrentQueue[$k]['start_time'], $QueueAccountData);
            }*/

            // Рассчитываем duration/time используя актуальный tmpAcc и count
            $elementTime = BuildFunctions::getBuildingTime(
                $objId,
                $QueueAccountData,
                null,
                ($action === 'destroy'),
                $Level
            );

            // Корректировка активного прогресса
            if (
                //$RecalcActiveProgress &&
                $k === 0 &&
                $CurrentQueue[$k]['time'] !== $elementTime &&
                isset($CurrentQueue[$k]['status']) &&
                $CurrentQueue[$k]['status'] === 'active' &&
                $CurrentTime > 0
            ) {
                $oldTime = $CurrentQueue[$k]['time'] ?? $elementTime;
                $passed  = max(0, $CurrentTime - $CurrentQueue[$k]['start_time']);
                $progress = ($oldTime > 0) ? min(1.0, $passed / $oldTime) : 0.0;

                // Новый QueueEndTime = текущее время + остаток от перерасчитанного elementTime
                // Но нужно учитывать изменение в elementTime: если элемент пересчитан (например, по уровню), то
                // остаток = elementTime * (1 - progress)
                $QueueEndTime = $CurrentQueue[$k]['start_time'] + ($oldTime * $progress) + ($elementTime * (1 - $progress));
            } else {
                $QueueEndTime += $elementTime;
            }

            // Присваиваем рассчитанные значения
            $CurrentQueue[$k]['time'] = $elementTime;
            $CurrentQueue[$k]['end_time'] = $QueueEndTime;

            // сохраняем изменения в БД (как было раньше)
            //Queues::update($CurrentQueue[$k]);
        }

        Logger::getInstance()->info("recalcQueueTimings Stop");
    }

    /**
     * Активирует следующий элемент очереди, который можно оплатить.
     * Удаляет недоступные queued-элементы, чтобы очередь не блокировалась.
     */
    public static function activateNextQueueItem(array &$CurrentQueue, AccountData &$AccountData, float $Time): bool
    {
        while (!empty($CurrentQueue)) {
            $next = $CurrentQueue[0];
            if (($next['status'] ?? '') !== 'queued') break;

            $planetId = (int)($next['planet_id'] ?? 0);

            $AccountData['WorkPlanet'] = $planetId;

            // --- Пересчитываем ресурсы планеты до момента активации ---
            Helpers::processPlanet($Time, $AccountData);

            $element    = (int)$next['object_id'];
            $isDestroy  = ($next['action'] === 'destroy');

            // --- Пересчёт уровня ---
            $currentLevel = BuildFunctions::getElementLevel($element, $AccountData);
            $nextLevel    = $isDestroy ? max(1, $currentLevel) : $currentLevel + 1;

            // --- Расчёт стоимости ---
            $cost = BuildFunctions::getElementPrice($element, $AccountData, $isDestroy, $nextLevel);
            if (!BuildFunctions::isElementBuyable($element, $AccountData, $cost)) {
                Logger::getInstance()->info("activateNextQueueItem: cannot pay", ['planet' => $planetId, 'element' => $element]);

                if ($isDestroy) {
                    $QueueAction = 'destroy';
                } else {
                    if ($next['type'] === self::TECHS)
                        $QueueAction = 'tech';
                    else
                        $QueueAction = 'build';
                }

                Notification::sendBuildBuyable($AccountData, $cost, $QueueAction, $element, $Time);


                Queues::delete($next);
                array_shift($CurrentQueue);
                continue;
            }

            // Проверяем на занятость
            if ($next['type'] === self::BUILDS) {
                $QueueActiveTech    = Queues::getActivePlanet(QueuesServices::TECHS, $AccountData['Planet']['id']);
                $QueueActiveHangar  = Queues::getActivePlanet(QueuesServices::HANGARS, $AccountData['Planet']['id']);
                if (
                    ($QueueActiveTech && ($element == 6 || $element == 31)) ||
                    ($QueueActiveHangar && ($element == 15 || $element == 21))
                ) {
                    Logger::getInstance()->info("activateNextQueueItem: element is busy, removing element", [
                        'id' => $next['id'],
                        'element' => $element
                    ]);

                    if ($element == 6 || $element == 31) {
                        // Notification::sendBuildBusy
                    }
                    if ($element == 15 || $element == 21) {
                        // Notification::sendBuildBusy
                    }
                    // Удаляем элемент из очереди
                    Queues::delete($next);
                    array_shift($CurrentQueue);
                    continue;
                }
            }
            if ($next['type'] === self::TECHS) {
                $QueueActiveBuild = Queues::getActivePlanet(QueuesServices::BUILDS, $AccountData['Planet']['id']);
                if (
                    !empty($QueueActiveBuild)
                    && isset($QueueActiveBuild[0]['object_id'])
                    && in_array((int)$QueueActiveBuild[0]['object_id'], [6, 31], true)
                ) {
                    Logger::getInstance()->info("activateNextQueueItem: element is busy, removing element", [
                        'id' => $next['id'],
                        'element' => $element
                    ]);

                    // Notification::sendBuildBusy

                    // Удаляем элемент из очереди
                    Queues::delete($next);
                    array_shift($CurrentQueue);
                    continue;
                }
            }

            /*if ($BuildLevel < 1) {
                Logger::getInstance()->warning("QueuesServices::AddToQueue: BuildLevel < 0", ['element' => $Element, 'level' => $BuildLevel]);
                return;
            }*/

            // Списываем ресурсы
            foreach ($cost as $k => $v) {
                if (!isset($AccountData['Resources'][$k]['count'])) $AccountData['Resources'][$k]['count'] = 0;
                $AccountData['Resources'][$k]['count'] -= $v;
            }

            $buildTime = BuildFunctions::getBuildingTime(
                $element,
                $AccountData,
                null,
                $isDestroy,
                $nextLevel
            );
            // Активируем очередь
            // --- Активация ---
            $CurrentQueue[0]['count'] = $nextLevel;
            $CurrentQueue[0]['status'] = 'active';
            $CurrentQueue[0]['start_time'] = $Time;
            $CurrentQueue[0]['time'] = $buildTime;
            $CurrentQueue[0]['end_time'] = $Time + $buildTime;

            $CurrentQueue[0]['status'] = 'active';
            Queues::update($CurrentQueue[0]);

            if ($next['type'] === self::BUILDS)
                PlayerQueue::addQueue(
                    (int)$AccountData['User']['account_id'],
                    (int)$AccountData['User']['id'],
                    (int)$AccountData['Planet']['id'],
                    PlayerQueue::ActionQueueReCalcTech
                );

            Logger::getInstance()->info("activateNextQueueItem: activated", [
                'id' => $next['id'],
                'planet' => $planetId,
                'element' => $element,
                'level' => $nextLevel,
                'time' => $buildTime
            ]);

            return true; // активировали — выходим
        }

        return false; // нечего активировать
    }

    /**
     * Возврат ресурсов при отмене активной постройки.
     * Пропорционально прогрессу выполнения.
     */
    private static function refundActiveQueueResources(array &$Queue, AccountData &$AccountData, float $Time): void
    {
        $Element = $Queue['object_id'];
        $isDestroy = ($Queue['action'] === 'destroy');
        $Level = (int)$Queue['count'];

        $duration = $Queue['end_time'] - $Queue['start_time'];
        $progress = ($duration > 0) ? max(0, min(1, 1 - (($Time - $Queue['start_time']) / $duration))) : 0;

        $cost = BuildFunctions::getElementPrice($Element, $AccountData, $isDestroy, $Level);

        $Resources = &$AccountData['Resources'];
        foreach ($cost as $k => $v) {
            if (!isset($Resources[$k]['count'])) $Resources[$k]['count'] = 0;
            $Resources[$k]['count'] += $v * $progress;
        }

        //Resources::updateByPlanetId($AccountData['Planet']['id'], $Resources);
        Logger::getInstance()->info("refundActiveQueueResources: refunded", ['element' => $Element, 'progress' => $progress]);
    }
}
