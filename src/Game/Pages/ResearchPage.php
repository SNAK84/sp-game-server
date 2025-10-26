<?php

namespace SPGame\Game\Pages;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Resources;
use SPGame\Game\Repositories\EntitySettings;


use SPGame\Game\Repositories\Config;

use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\QueuesServices;
use SPGame\Game\Services\AccountData;

class ResearchPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $Techs = &$AccountData['Techs'];

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::TECHS, $User['id'], $Planet['id']) ?: [];
        $QueueList = $this->buildQueueList($CurrentQueue);
        $ResearchList = $this->buildResearchList($AccountData, $CurrentQueue);

        $QueueActiveBuild = Queues::getActivePlanet(QueuesServices::BUILDS, $AccountData['Planet']['id']);
        $IsLabinBuild = !empty($QueueActiveBuild)
            && isset($QueueActiveBuild[0]['object_id'])
            && in_array((int)$QueueActiveBuild[0]['object_id'], [6, 31], true);

        return [
            'page' => 'researchs',
            'ResearchList' => $ResearchList,
            'QueueList' => $QueueList,
            'Types' => Vars::$reslist['nametype']['reseach'],
            'IsLabinBuild' => $IsLabinBuild,
            'CountQueue' => count($CurrentQueue),
            'MaxQueue' => QueuesServices::MaxQueue(QueuesServices::TECHS)
        ];
    }

    private function buildQueueList(array $CurrentQueue): array
    {
        $QueueList = [];
        foreach ($CurrentQueue as $Queue) {
            $Element = $Queue['object_id'];
            $QueuePlanet = Planets::findById($Queue['planet_id']);

            $QueueList[$Queue['id']] = [
                'id' => $Element,
                'qid' => $Queue['id'],
                'name' => Vars::$resource[$Element],
                'level' => $Queue['count'],
                'start_time' => $Queue['start_time'],
                'end_time' => $Queue['end_time'],
                'action' => $Queue['action'],
                'status' => $Queue['status'],
                'planet' => $QueuePlanet
            ];
        }
        return $QueueList;
    }

    private function buildResearchList(AccountData &$AccountData, array $CurrentQueue): array
    {
        $Techs = &$AccountData['Techs'];

        $QueueLevels = [];
        foreach ($CurrentQueue as $q) {
            $QueueLevels[$q['object_id']] = ($QueueLevels[$q['object_id']] ?? 0) + 1;
        }

        $ResearchList = [];
        foreach (Vars::$reslist['tech'] as $Element) {
            $currentLevel = $Techs[Vars::$resource[$Element]] ?? 0;
            $levelToBuild = $currentLevel + ($QueueLevels[$Element] ?? 0);

            $Prod = null;

            if (in_array($Element, Vars::$reslist['prod'])) {

                $ressIDs    = array_merge(array(), Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][2]);
                foreach ($ressIDs as $ID) {

                    if (!isset(Vars::$production[$Element][$ID]))
                        continue;
                    $BuildLevelFactor = EntitySettings::get($AccountData['Planet']['id'], $Element)['efficiency'];

                    $BuildLevel    = $levelToBuild;
                    $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $AccountData);
                    $Current = eval($eval);

                    $BuildLevel   = $levelToBuild + 1;
                    $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $AccountData);
                    $Next = eval($eval);

                    $Prod['Next'][$ID] = (
                        ($Next - $Current) *
                        ((in_array($ID, Vars::$reslist['resstype'][1]) ? Config::getValue('ResourceMultiplier') : Config::getValue('EnergySpeed'))));

                    
                }
            }

            $Accessible = BuildFunctions::isTechnologieAccessible($Element, $AccountData);
            $requirements = [];
            if (!$Accessible && isset(Vars::$requirement[$Element])) {
                foreach (Vars::$requirement[$Element] as $reqId => $reqLevel) {
                    $requirements[$reqId] = [
                        'count' => $reqLevel,
                        'name' => Vars::$resource[$reqId],
                        'own' => BuildFunctions::getElementLevel($reqId, $AccountData)
                    ];
                }
            }

            $cost = BuildFunctions::getElementPrice($Element, $AccountData, false, $levelToBuild + 1);
            $costOverflow = BuildFunctions::getRestPrice($Element, $AccountData, $cost);
            $time = BuildFunctions::getBuildingTime($Element, $AccountData, $cost);

            $ResearchList[$Element] = [
                'id'            => $Element,
                'name'          => Vars::$resource[$Element],
                'type'          => Vars::$attributes[$Element]['type'],
                'level'         => $currentLevel,
                'maxLevel'      => Vars::$attributes[$Element]['max'],
                'accessible'    => $Accessible,
                'requirements'  => $requirements,
                'Prod'          => $Prod,
                'costResources' => $cost,
                'costOverflow'  => $costOverflow,
                'elementTime'   => $time,
                'levelToBuild'  => $levelToBuild
            ];
        }

        return $ResearchList;
    }
}
