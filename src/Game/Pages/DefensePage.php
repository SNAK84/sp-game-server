<?php

namespace SPGame\Game\Pages;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\QueuesServices;
use SPGame\Game\Services\AccountData;

class DefensePage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::HANGARS, $User['id'], $Planet['id']) ?: [];
        $QueueList = $this->buildQueueList($CurrentQueue);
        $DefenseList = $this->buildDefenseList($AccountData, $CurrentQueue);

        return [
            'page' => 'defense',
            'DefenseList' => $DefenseList,
            'QueueList' => $QueueList,
            'Types' => Vars::$reslist['nametype']['defense'],
            'CountQueue' => count($CurrentQueue),
            'MaxQueue' => QueuesServices::MaxQueue(QueuesServices::HANGARS)
        ];
    }

    private function buildQueueList(array $CurrentQueue): array
    {
        $QueueList = [];
        foreach ($CurrentQueue as $Queue) {
            $Element = $Queue['object_id'];
            $QueueList[$Queue['id']] = [
                'id' => $Element,
                'qid' => $Queue['id'],
                'name' => Vars::$resource[$Element],
                'count' => $Queue['count'],
                'start_time' => $Queue['start_time'],
                'end_time' => $Queue['end_time'],
                'status' => $Queue['status']
            ];
        }
        return $QueueList;
    }

    private function buildDefenseList(AccountData &$AccountData, array $CurrentQueue): array
    {
        $Planet = &$AccountData['Planet'];
        $QueueCounts = [];

        foreach ($CurrentQueue as $Queue) {
            $id = $Queue['object_id'];
            $QueueCounts[$id] = ($QueueCounts[$id] ?? 0) + $Queue['count'];
        }

        $DefenseList = [];
        foreach (Vars::$reslist['defense'] as $Element) {
            $currentCount = $Planet[Vars::$resource[$Element]] ?? 0;
            $queued = $QueueCounts[$Element] ?? 0;
            $totalFuture = $currentCount + $queued;

            $Accessible = BuildFunctions::isTechnologieAccessible($Element, $AccountData);
            $requirements = [];
            if (!$Accessible && isset(Vars::$requirement[$Element])) {
                foreach (Vars::$requirement[$Element] as $reqId => $reqLevel) {
                    $requirements[$reqId] = [
                        'count' => $reqLevel,
                        'name'  => Vars::$resource[$reqId],
                        'own'   => Helpers::getElementLevel($reqId, $AccountData)
                    ];
                }
            }

            $cost = BuildFunctions::getElementPrice($Element, $AccountData, false, 1);
            $costOverflow = BuildFunctions::getRestPrice($Element, $AccountData, $cost);
            $time = BuildFunctions::getBuildingTime($Element, $AccountData, $cost);

            $DefenseList[$Element] = [
                'id' => $Element,
                'name' => Vars::$resource[$Element],
                'count' => $currentCount,
                'queued' => $queued,
                'totalFuture' => $totalFuture,
                'accessible' => $Accessible,
                'requirements' => $requirements,
                'costResources' => $cost,
                'costOverflow' => $costOverflow,
                'elementTime' => $time
            ];
        }

        return $DefenseList;
    }
}
