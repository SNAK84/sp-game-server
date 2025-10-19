<?php

namespace SPGame\Game\Pages;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\QueuesServices;
use SPGame\Game\Services\AccountData;

class HangarPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::HANGARS, $User['id'], $Planet['id']) ?: [];
        $QueueList = $this->buildQueueList($CurrentQueue);
        $FleetList = $this->buildFleetList($AccountData, $CurrentQueue);

        $QueueActiveBuild = Queues::getActivePlanet(QueuesServices::BUILDS, $AccountData['Planet']['id']);
        $IsHangarBuild = !empty($QueueActiveBuild)
            && isset($QueueActiveBuild[0]['object_id'])
            && in_array((int)$QueueActiveBuild[0]['object_id'], [15, 21], true);

        return [
            'page'          => ($this->hangarMode == 'Ships') ? 'shipyard' : "defense",
            'FleetList'     => $FleetList,
            'QueueList'     => $QueueList,
            'Types'         => ($this->hangarMode == 'Ships') ? Vars::$reslist['nametype']['fleet'] : Vars::$reslist['nametype']['defense'],
            'IsHangarBuild' => $IsHangarBuild,
            'CountQueue'    => count($CurrentQueue),
            'MaxQueue'      => QueuesServices::MaxQueue(QueuesServices::HANGARS),
            'SiloLevel'     => BuildFunctions::getElementLevel(44, $AccountData),
            'SiloFactor'    => max(Config::getValue("SiloFactor"), 1),
            'MissileList'   => Vars::$reslist['missile']
        ];
    }

    private function buildQueueList(array $CurrentQueue): array
    {
        $QueueList = [];
        foreach ($CurrentQueue as $Queue) {
            $Element = $Queue['object_id'];
            $QueueList[$Queue['id']] = [
                'id'         => $Element,
                'qid'        => $Queue['id'],
                'name'       => Vars::$resource[$Element],
                'count'      => $Queue['count'],
                'time'       => $Queue['time'],
                'start_time' => $Queue['start_time'],
                'end_time'   => $Queue['end_time'],
                'status'     => $Queue['status']
            ];
        }
        return $QueueList;
    }

    private function buildFleetList(AccountData &$AccountData, array $CurrentQueue): array
    {
        $Planet = &$AccountData['Planet'];
        $Ships = &$AccountData['Ships'];
        $Defenses = &$AccountData['Defenses'];
        $QueueCounts = [];

        foreach ($CurrentQueue as $Queue) {
            $id = $Queue['object_id'];
            $QueueCounts[$id] = ($QueueCounts[$id] ?? 0) + $Queue['count'];
        }

        if ($this->hangarMode == 'Ships') {
            $elementIDs    = Vars::$reslist['fleet'];
        } elseif ($this->hangarMode == 'Defenses') {
            $elementIDs    = array_merge(Vars::$reslist['defense'], Vars::$reslist['missile']);
        }

        $FleetList = [];
        foreach ($elementIDs as $Element) {
            $currentCount = BuildFunctions::getElementLevel($Element, $AccountData);

            $queued = $QueueCounts[$Element] ?? 0;
            $totalFuture = $currentCount + $queued;

            $Accessible = BuildFunctions::isTechnologieAccessible($Element, $AccountData);
            $requirements = [];
            if (!$Accessible && isset(Vars::$requirement[$Element])) {
                foreach (Vars::$requirement[$Element] as $reqId => $reqLevel) {
                    $requirements[$reqId] = [
                        'count' => $reqLevel,
                        'name'  => Vars::$resource[$reqId],
                        'own'   => BuildFunctions::getElementLevel($reqId, $AccountData)
                    ];
                }
            }

            $cost = BuildFunctions::getElementPrice($Element, $AccountData, false, 1);
            $costOverflow = BuildFunctions::getRestPrice($Element, $AccountData, $cost);
            $time = BuildFunctions::getBuildingTime($Element, $AccountData, $cost);

            $FleetList[$Element] = [
                'id'            => $Element,
                'name'          => Vars::$resource[$Element],
                'type'          => Vars::$attributes[$Element]['type'],
                'count'         => $currentCount,
                'one'           => in_array($Element, Vars::$reslist['one']),
                'queued'        => $queued,
                'totalFuture'   => $totalFuture,
                'accessible'    => $Accessible,
                'requirements'  => $requirements,
                'costResources' => $cost,
                'costOverflow'  => $costOverflow,
                'elementTime'   => $time
            ];
        }

        return $FleetList;
    }
}
