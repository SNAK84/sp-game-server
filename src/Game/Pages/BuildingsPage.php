<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Resources;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\EntitySettings;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\QueuesServices;
use SPGame\Game\Services\AccountData;

class BuildingsPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $Builds = &$AccountData['Builds'];
        $Techs = &$AccountData['Techs'];

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::BUILDS, $User['id'], $Planet['id']) ?: [];
        $QueueList = $this->buildQueueList($CurrentQueue);
        $BuildList = $this->buildAvailableList($AccountData, $CurrentQueue);

        $DemolishedQueue = 0;
        foreach ($CurrentQueue as $Queue) {
            $objId = $Queue['object_id'];
            if ($Queue['action'] == 'destroy')
                $DemolishedQueue++;
        }

        $DemolishedQueue = max(0, $DemolishedQueue);


        return [
            'page' => 'buildings',
            'BuildList' => $BuildList,
            'QueueList' => $QueueList,
            'Types' => Vars::$reslist['nametype']['build'],
            'field_used' => Helpers::getCurrentFields($AccountData) + count($CurrentQueue) - $DemolishedQueue * 2,
            'field_current' => Helpers::getMaxFields($AccountData),
            'CountQueue' => count($CurrentQueue),
            'MaxQueue' => QueuesServices::MaxQueue(QueuesServices::BUILDS)
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
                'level' => $Queue['count'],
                'start_time' => $Queue['start_time'],
                'end_time' => $Queue['end_time'],
                'action' => $Queue['action'],
                'status' => $Queue['status']
            ];
        }
        return $QueueList;
    }

    private function buildAvailableList(AccountData &$AccountData, array $CurrentQueue): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $Builds = &$AccountData['Builds'];

        $TempBuilds = $AccountData['Builds']->toArray();

        foreach ($CurrentQueue as $q) {
            $AccountData['Builds'][Vars::$resource[$q['object_id']]] += ($q['action'] === 'destroy' ? -1 : 1);
        }

        $BuildEnergy        = $AccountData['Techs'][Vars::$resource[113]];
        $BuildLevelFactor   = 100;
        $BuildTemp          = $Planet['temp_max'];

        $QueueActiveTech    = Queues::getActivePlanet(QueuesServices::TECHS, $Planet['id']);
        $QueueActiveHangar  = Queues::getActivePlanet(QueuesServices::HANGARS, $Planet['id']);

        $BuildList          = [];
        foreach (Vars::$reslist['allow'][$Planet['planet_type']] as $Element) {
            $currentLevel = $TempBuilds[Vars::$resource[$Element]] ?? 0;
            $levelToBuild = $AccountData['Builds'][Vars::$resource[$Element]];




            $Prod = null;

            if (in_array($Element, Vars::$reslist['prod'])) {

                $ressIDs    = array_merge(array(), Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][2]);
                foreach ($ressIDs as $ID) {

                    if (!isset(Vars::$production[$Element][$ID]))
                        continue;
                    $BuildLevelFactor = EntitySettings::get($Planet['id'], $Element)['efficiency'];


                    $BuildLevel    = $levelToBuild;
                    $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $AccountData);
                    $Current = eval($eval);

                    $BuildLevel   = $levelToBuild + 1;
                    $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $AccountData);
                    $Next = eval($eval);

                    $Prod['Next'][$ID] = (
                        ($Next - $Current) *
                        ((in_array($ID, Vars::$reslist['resstype'][1]) ? Config::getValue('ResourceMultiplier') : Config::getValue('EnergySpeed'))));

                    $BuildLevel   = $levelToBuild - 1;
                    if ($BuildLevel >= 0) {
                        $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $AccountData);
                        $Previous = eval($eval);

                        $Prod['Previous'][$ID] = (($Previous - $Current) * (in_array($ID, Vars::$reslist['resstype'][1]) ? Config::getValue('ResourceMultiplier') : Config::getValue('EnergySpeed')));
                    }
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

            $destroyCost = BuildFunctions::getElementPrice($Element, $AccountData, true);
            $destroyTime = BuildFunctions::getBuildingTime($Element, $AccountData, $destroyCost);
            $destroyOverflow = BuildFunctions::getRestPrice($Element, $AccountData, $destroyCost);

            $buyable = Vars::$attributes[$Element]['max'] > $levelToBuild;

            $buyable = true;
            $working = false;
            if (Vars::$attributes[$Element]['max'] > $levelToBuild) {
                $buyable = false;
            }
            if (
                ($QueueActiveTech && ($Element == 6 || $Element == 31)) ||
                ($QueueActiveHangar && ($Element == 15 || $Element == 21))
            ) {
                $buyable = false;
                $working = true;
            }

            $BuildList[$Element] = [
                'id'                => $Element,
                'name'              => Vars::$resource[$Element],
                'type'              => Vars::$attributes[$Element]['type'],
                'level'             => $currentLevel,
                'maxLevel'          => Vars::$attributes[$Element]['max'],
                'accessible'        => $Accessible,
                'requirements'      => $requirements,
                'Prod'              => $Prod,
                'costResources'     => $cost,
                'costOverflow'      => $costOverflow,
                'elementTime'       => $time,
                'destroyResources'  => $destroyCost,
                'destroyTime'       => $destroyTime,
                'destroyOverflow'   => $destroyOverflow,
                'levelToBuild'      => $levelToBuild,
                'buyable'           => $buyable,
                'working'           => $working
            ];
        }


        $AccountData['Builds'] = $TempBuilds;

        return $BuildList;
    }
}
