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


    public static function AddToQueue(int $Element, array &$AccountData, float $Time, bool $AddMode = true)
    {

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

            if (!in_array($Element, $allowedOnPlanet, true) || (!$AddMode && $buildCount <= 0)) {
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

        $MaxFields = Helpers::getMaxFields($AccountData);
        $MaxQueue = self::MaxQueue($QueueType);

        if ($ActualCount >= $MaxQueue) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: max queue reached", ['queueType' => $QueueType, 'actual' => $ActualCount, 'max' => $MaxQueue]);
            return;
        }

        $CurrentFields = Helpers::getCurrentFields($AccountData) + $ActualCount - $DemolishedQueue * 2;

        if ($AddMode && ($CurrentFields + $DemolishedQueue) >= $MaxFields) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: not enough fields", ['currentFields' => $CurrentFields, 'demolished' => $DemolishedQueue, 'maxFields' => $MaxFields]);
            return;
        }

        $BuildLevel = (int) Helpers::getElementLevel($Element, $AccountData);
        $BuildLevel += $AddMode ? 1 : 0;
        $BuildLevel += $BuildsLevels[$Element] ?? 0;


        if (isset(Vars::$attributes[$Element]['max']) && $BuildLevel > (int)Vars::$attributes[$Element]['max']) {
            Logger::getInstance()->info("QueuesServices::AddToQueue: level exceeds max", ['element' => $Element, 'level' => $BuildLevel]);
            return;
        }

        $elementTime    = BuildFunctions::getBuildingTime($Element, $AccountData, null, !$AddMode, $BuildLevel);
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
        $db = null;

        try {
            if ($ActualCount == 0) {
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
                Resources::updateByPlanetId($AccountData['Planet']['id'], $Resources);

                PlayerQueue::addQueue((int)$User['account_id'], (int)$User['id'], (int)$Planet['id'], PlayerQueue::ActionQueueReCalcTech);
            }

            Queues::add($addQueue);

            Logger::getInstance()->info("QueuesServices::AddToQueue: added to queue", $addQueue);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("QueuesServices::AddToQueue transaction error: " . $e->getMessage(), ['exception' => $e]);
            return;
        }
    }

    public static function CancelToQueue(int $QueueId, array &$AccountData, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CancelToQueue: queue not found", ['QueueId' => $QueueId]);
            return;
        }

        $Element     = $Queue['object_id'];
        $QueueType   = self::QueueType($Element);

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

        //$User = &$AccountData['User'];



        // Получаем актуальную очередь (от сервера/репозитория)
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("CancelToQueue: current queue empty", ['QueueId' => $QueueId]);
            return;
        }

        // Находим индекс элемента в текущей очереди по id (может быть не 0)
        $indexToRemove = array_search($QueueId, array_column($CurrentQueue, 'id'));
        if ($indexToRemove === false) {
            Logger::getInstance()->warning("CancelToQueue: queue id not found", ['QueueId' => $QueueId]);
            return;
        }


        // Определяем, является ли удаляемый элемент активным (первый в очереди и статус active)
        $isActive = ($indexToRemove === 0 && isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'active');
        $QueueEndTime = $isActive ? $Time : $CurrentQueue[0]['start_time'];

        try {

            // Удаляем из очереди
            Queues::delete($CurrentQueue[$indexToRemove]);
            unset($CurrentQueue[$indexToRemove]);
            $CurrentQueue = array_values($CurrentQueue);

            // 2) Если удаляем активный — возвращаем ресурсы отменённого задания
            if ($isActive) {
                self::refundActiveQueueResources($Queue, $AccountData, $Time);
                PlayerQueue::addQueue(
                    (int)$AccountData['User']['account_id'],
                    (int)$AccountData['User']['id'],
                    (int)$AccountData['Planet']['id'],
                    PlayerQueue::ActionQueueReCalcTech
                );
            }

            // Перерасчёт оставшейся очереди
            self::recalcQueueTimings($CurrentQueue, $AccountData, $QueueEndTime, $isActive, $Time);


            // 4) Попытка активировать новый первый элемент (если он существует и имеет статус queued)
            // Активация следующего, если возможно
            self::activateNextQueueItem($CurrentQueue, $AccountData);

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

    /**
     * Завершение задачи — строительство/исследование и т.п.
     */
    public static function CompleteQueue(int $QueueId, array &$AccountData, float $Time)
    {
        $Queue = Queues::findById($QueueId);
        if (!$Queue) {
            Logger::getInstance()->info("CompleteQueue: queue not found", ['QueueId' => $QueueId]);
            return;
        }

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


        $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("CompleteQueue: current queue empty", ['QueueId' => $QueueId]);
            return;
        }

        // Находим индекс завершённого
        $indexComplete = array_search($QueueId, array_column($CurrentQueue, 'id'));
        if ($indexComplete === false) {
            Logger::getInstance()->warning("CompleteQueue: queue id not found", ['QueueId' => $QueueId]);
            return;
        }

        try {

            // Завершаем постройку
            $Element = $Queue['object_id'];
            $action = $Queue['action'];

            $currentLevel = Helpers::getElementLevel($Element, $AccountData);
            $newLevel = $action === 'build' ? $currentLevel + 1 : ($action === 'destroy' ? max(0, $currentLevel - 1) : $currentLevel);

            BuildFunctions::setElementLevel($Element, $AccountData, $newLevel);

            // Удаляем завершённый элемент
            Queues::delete($Queue);
            unset($CurrentQueue[$indexComplete]);
            $CurrentQueue = array_values($CurrentQueue);

            // Пытаемся активировать следующий
            self::activateNextQueueItem($CurrentQueue, $AccountData);

            // Пересчитываем оставшиеся времена
            self::recalcQueueTimings($CurrentQueue, $AccountData, $Time);

            Logger::getInstance()->info("CompleteQueue: finished successfully", [
                'QueueId' => $QueueId,
                'remaining' => count($CurrentQueue)
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("CompleteQueue: transaction error: " . $e->getMessage(), [
                'exception' => $e,
                'QueueId' => $QueueId
            ]);
            return;
        }
    }

    public static function ReCalcTimeQueue(string $QueueType, array &$AccountData, float $Time)
    {

        // Получаем актуальную очередь (от сервера/репозитория)
        $CurrentQueue = Queues::getCurrentQueue($QueueType, $AccountData['User']['id'], $AccountData['Planet']['id']) ?: [];
        if (empty($CurrentQueue)) {
            Logger::getInstance()->info("ReCalcTimeQueue: current queue empty");
            return;
        }

        // Проверяем, активна ли первая задача
        $isActive = (isset($CurrentQueue[0]['status']) && $CurrentQueue[0]['status'] === 'active');
        $QueueStartTime = $CurrentQueue[0]['start_time'] ?? $Time;


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
            $QueueStartTime,
            $isActive,   // пересчёт прогресса активного элемента
            $Time
        );

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
    private static function recalcQueueTimings(array &$CurrentQueue, array &$AccountData, float $StartTime, bool $RecalcActiveProgress = false, float $CurrentTime = 0): void
    {
        $QueueEndTime = $StartTime;
        $BuildsLevels = [];

        foreach ($CurrentQueue as $k => $q) {
            $objId = $q['object_id'];

            if (!isset($BuildsLevels[$objId])) {
                $BuildsLevels[$objId] = (int) Helpers::getElementLevel($objId, $AccountData);
            }

            // Меняем уровень в зависимости от действия
            if ($q['action'] === 'build') {
                $BuildsLevels[$objId] += 1;
            } elseif ($q['action'] === 'destroy') {
                $BuildsLevels[$objId] -= 1;
            }

            // Устанавливаем новые значения
            $CurrentQueue[$k]['count'] = $BuildsLevels[$objId];
            $CurrentQueue[$k]['start_time'] = $QueueEndTime;

            // Обновляем данные планеты перед пересчётом
            $AccountData['Planet'] = Planets::findById($q['planet_id']);
            $AccountData['Builds'] = Builds::findById($q['planet_id']);

            $elementTime = BuildFunctions::getBuildingTime(
                $objId,
                $AccountData,
                null,
                $q['action'] === 'destroy',
                $BuildsLevels[$objId]
            );

            // Коррекция активного прогресса (если нужно)
            if ($RecalcActiveProgress && $k === 0 && $CurrentQueue[$k]['status'] === 'active' && $CurrentTime > 0) {
                $oldTime = $CurrentQueue[$k]['time'] ?? $elementTime;
                $passed = max(0, $CurrentTime - $CurrentQueue[$k]['start_time']);
                $progress = min(1, $passed / $oldTime);

                // пересчитываем end_time с учётом прогресса
                $QueueEndTime = $CurrentQueue[$k]['start_time'] +
                    ($oldTime * $progress) + ($elementTime * (1 - $progress));
            } else {
                $QueueEndTime += $elementTime;
            }

            $CurrentQueue[$k]['time'] = $elementTime;
            $CurrentQueue[$k]['end_time'] = $QueueEndTime;

            // сохраняем изменения в БД
            Queues::update($CurrentQueue[$k]);
        }
    }

    /**
     * Активирует следующий элемент очереди, который можно оплатить.
     * Удаляет недоступные queued-элементы, чтобы очередь не блокировалась.
     */
    private static function activateNextQueueItem(array &$CurrentQueue, array &$AccountData): bool
    {
        while (!empty($CurrentQueue)) {
            $next = $CurrentQueue[0];
            if ($next['status'] !== 'queued') break;

            $nextElement = (int)$next['object_id'];
            $nextCount   = (int)$next['count'];
            $isDestroy   = ($next['action'] === 'destroy');

            $cost = BuildFunctions::getElementPrice($nextElement, $AccountData, $isDestroy, $nextCount);

            // Проверяем, можем ли оплатить
            if (!BuildFunctions::isElementBuyable($nextElement, $AccountData, $cost)) {
                Logger::getInstance()->info("activateNextQueueItem: cannot pay, removing element", [
                    'id' => $next['id'],
                    'element' => $nextElement,
                    'cost' => $cost
                ]);

                // Удаляем элемент из очереди
                Queues::delete($next);
                array_shift($CurrentQueue);
                continue;
            }

            // Списываем ресурсы
            $Resources = &$AccountData['Resources'];
            foreach ($cost as $k => $v) {
                if (!isset($Resources[$k]['count'])) $Resources[$k]['count'] = 0;
                $Resources[$k]['count'] -= $v;
            }
            Resources::updateByPlanetId($AccountData['Planet']['id'], $Resources);

            // Активируем очередь
            $CurrentQueue[0]['status'] = 'active';
            Queues::update($CurrentQueue[0]);

            PlayerQueue::addQueue(
                (int)$AccountData['User']['account_id'],
                (int)$AccountData['User']['id'],
                (int)$AccountData['Planet']['id'],
                PlayerQueue::ActionQueueReCalcTech
            );

            Logger::getInstance()->info("activateNextQueueItem: activated new queue", [
                'id' => $next['id'],
                'element' => $nextElement
            ]);

            return true; // активировали — выходим
        }

        return false; // нечего активировать
    }

    /**
     * Возврат ресурсов при отмене активной постройки.
     * Пропорционально прогрессу выполнения.
     */
    private static function refundActiveQueueResources(array &$Queue, array &$AccountData, float $Time): void
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

        Resources::updateByPlanetId($AccountData['Planet']['id'], $Resources);
        Logger::getInstance()->info("refundActiveQueueResources: refunded", ['element' => $Element, 'progress' => $progress]);
    }
}
