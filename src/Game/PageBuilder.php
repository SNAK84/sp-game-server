<?php

namespace SPGame\Game;

use SPGame\Core\Connect;
use SPGame\Core\Logger;
use SPGame\Core\Message;

use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Techs;
use SPGame\Game\Repositories\Resources;
use SPGame\Game\Repositories\EntitySettings;

use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;


use SPGame\Game\Repositories\Queues;

use SPGame\Game\Repositories\Factors;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Repositories\PlayerQueue;

use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\QueuesServices;

class PageBuilder
{
    protected Logger $logger;

    private Message $Msg;
    private int $fd;
    private int $aid;

    public function __construct(Message $Msg, int $fd)
    {
        $this->logger = Logger::getInstance();

        $this->fd = $fd;
        $this->Msg = $Msg;
        $this->aid = Connect::getAccount($fd);
    }

    /**
     * Сборка ответа для клиента на запрошенную страницу
     */
    public function build(Message $response): Message
    {
        $result = [];
        switch ($this->Msg->getMode()) {

            case 'buildings':
                $result = $this->buildBuildings();
                break;
            case 'researchs':
                $result = $this->buildResearchs();
                break;
            case 'overview':
            default:
                $result = $this->buildOverview();
                $this->Msg->setMode('overview');
                break;
        }


        $response->setData('Page', $result);

        $response->setMode($this->Msg->getMode());
        $response->setData('TopNav', $this->GetTopNav());
        $response->setData('PlanetList', $this->GetPlanetList());

        return $response;
    }

    private function buildOverview(): array
    {


        $User = Users::findByAccount($this->aid);
        $Planet = Planets::findByUserId($User['id']);

        //Resources::ReBuildRes(Resources::getByUserId($User['id']), $User['id'], $Planet['id']);

        return [
            'page' => 'overview',
            'UserName' => $User['name'],
            'PlanetImage' => $Planet['image'],
            "PlanetName" => $Planet['name'],
            "diameter" => $Planet['size'],
            "field_used" => Planets::getCurrentFields($Planet['id']),
            "field_current" => Planets::getMaxFields($Planet['id']),
            "TempMin" => $Planet['temp_min'],
            "TempMax" => $Planet['temp_max'],
            "galaxy" => $Planet['galaxy'],
            "system" => $Planet['system']
        ];
    }


    private function buildBuildings(): array
    {
        $User = Users::findByAccount($this->aid);
        $Planet = Planets::findByUserId($User['id']);
        $Builds = Builds::findById($Planet['id']);
        $Techs = Techs::findById($User['id']);

        if ($this->Msg->getAction() === "build") {
            PlayerQueue::addQueue($this->aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueUpgarde, [
                'Element' => $this->Msg->getData("id"),
                'AddMode' => true
            ]);
        }
        if ($this->Msg->getAction() === "dismantle") {
            PlayerQueue::addQueue($this->aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueDismantle, [
                'Element' => $this->Msg->getData("id"),
                'AddMode' => false
            ]);
        }
        if ($this->Msg->getAction() === "cancel") {
            PlayerQueue::addQueue($this->aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueCancel, [
                'QueueId' => $this->Msg->getData("id"),
                'AddMode' => false
            ]);
        }

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::BUILDS, $User['id'], $Planet['id']) ?: [];
        $QueueList = [];

        $DemolishedQueue    = 0;
        $QueueBuildsLevels = [];
        foreach ($CurrentQueue as $Key => $Queue) {
            $Element = $Queue['object_id'];
            if ($Queue['action'] == 'destroy') {
                $QueueBuildsLevels[$Element] -= 1;
                // если демонтаж — освобождается 1 поле
                $DemolishedQueue++;
            } else
                $QueueBuildsLevels[$Element] += 1;

            $QueueList[$Queue['id']] = [
                'id'            => $Element,
                'qid'           => $Queue['id'],
                'name'          => Vars::$resource[$Element],
                'level'         => $Queue['count'],
                'start_time'    => $Queue['start_time'],
                'end_time'      => $Queue['end_time'],
                'action'        => $Queue['action'],
                'status'        => $Queue['status']
            ];
        }

        $CurrentFields = Planets::getCurrentFields($Planet['id']) + count($QueueList) - $DemolishedQueue * 2;


        $BuildEnergy        = $Techs[Vars::$resource[113]];
        $BuildLevelFactor   = 10;
        $BuildTemp          = $Planet['temp_max'];

        foreach (Vars::$reslist['allow'][$Planet['planet_type']] as $Element) {

            $levelToBuild    = $Builds[Vars::$resource[$Element]] + $QueueBuildsLevels[$Element];

            $Prod = null;

            if (in_array($Element, Vars::$reslist['prod'])) {

                $ressIDs    = array_merge(array(), Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][2]);
                foreach ($ressIDs as $ID) {

                    if (!isset(Vars::$production[$Element][$ID]))
                        continue;
                    $BuildLevelFactor = EntitySettings::get($Planet['id'], $Element)['efficiency'];


                    $BuildLevel    = $levelToBuild;
                    $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $User['id'], $Planet['id']);
                    $Current = eval($eval);

                    $BuildLevel   = $levelToBuild + 1;
                    $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $User['id'], $Planet['id']);
                    $Next = eval($eval);

                    $Prod['Next'][$ID] = (
                        ($Next - $Current) *
                        ((in_array($ID, Vars::$reslist['resstype'][1]) ? Config::getValue('ResourceMultiplier') : Config::getValue('EnergySpeed'))));

                    $BuildLevel   = $levelToBuild - 1;
                    if ($BuildLevel >= 0) {
                        $eval = Resources::getProd(Vars::$production[$Element][$ID], $Element, $User['id'], $Planet['id']);
                        $Previous = eval($eval);

                        $Prod['Previous'][$ID] = (($Previous - $Current) * (in_array($ID, Vars::$reslist['resstype'][1]) ? Config::getValue('ResourceMultiplier') : Config::getValue('EnergySpeed')));
                    }
                }
            }

            $costResources      = BuildFunctions::getElementPrice($Element, $User['id'], $Planet['id'], false, $levelToBuild + 1);
            $costOverflow       = BuildFunctions::getRestPrice($Element, $User['id'], $Planet['id'],  $costResources);
            $elementTime        = BuildFunctions::getBuildingTime($Element, $User['id'], $Planet['id'],  $costResources);
            $destroyResources   = BuildFunctions::getElementPrice($Element, $User['id'], $Planet['id'],  true);
            $destroyTime        = BuildFunctions::getBuildingTime($Element, $User['id'], $Planet['id'],  $destroyResources);
            $destroyOverflow    = BuildFunctions::getRestPrice($Element, $User['id'], $Planet['id'],  $destroyResources);

            $buyable = true;
            $working = false;
            if (Vars::$attributes[$Element]['max'] == $levelToBuild) {
                $buyable = false;
            } /*elseif (($USER['Queue']['EndTime'] != 0 && ($Element == 6 || $Element == 31)) || !empty($PLANET['Queue']['Hangar']) && ($Element == 15 || $Element == 21)) {
                $buyable = false;
                $working = true;
            }*/

            $Accessible         = BuildFunctions::isTechnologieAccessible($Element, $User['id'], $Planet['id']);
            $requirementsList   = array();
            if (!$Accessible) {

                if (isset(Vars::$requirement[$Element])) {
                    foreach (Vars::$requirement[$Element] as $requireID => $RedCount) {
                        $requirementsList[$requireID]    = array(
                            'count' => $RedCount,
                            'name'  => Vars::$resource[$requireID],
                            'own'   => Helpers::getElementLevel($requireID, $User['id'], $Planet['id'])
                        );
                    }
                }
            }

            $BuildList[$Element]    = array(
                'id'                => $Element,
                'name'              => Vars::$resource[$Element],
                'type'              => Vars::$attributes[$Element]['type'],
                'level'             => $Builds[Vars::$resource[$Element]],
                'maxLevel'          => Vars::$attributes[$Element]['max'],
                'buyable'           => $buyable,
                'working'           => $working,

                'accessible'        => $Accessible,
                'requirements'      => $requirementsList,
                'Prod'              => $Prod,
                //'infoEnergy'           => $infoEnergy,
                'costResources'     => $costResources,
                'costOverflow'      => $costOverflow,
                'elementTime'       => $elementTime,
                'destroyResources'  => $destroyResources,
                'destroyTime'       => $destroyTime,
                'destroyOverflow'   => $destroyOverflow,
                'levelToBuild'      => $levelToBuild,

                /*"RoomIsOk" => $PLANET['CurrentFields'] < ($PLANET["MaxFields"] - $QueueDestroy),
                "CanBuildElement" => $CanBuildElement,
                'isBusy' => array('shipyard' => !empty($PLANET['Queue']['Hangar']), 'research' => $USER['Queue']['EndTime'] != 0),*/

            );
        }



        return [
            'page' => 'buildings',
            'BuildList' => $BuildList ?? [],
            'QueueList' => $QueueList ?? [],
            "Types" => Vars::$reslist['nametype']['build'],
            "field_used" => $CurrentFields,
            "field_current" => Planets::getMaxFields($Planet['id']),
            'CountQueue' => count($CurrentQueue),
            'MaxQueue' => QueuesServices::MaxQueue(QueuesServices::BUILDS)

        ];
    }

    private function buildResearchs(): array
    {

        $User = Users::findByAccount($this->aid);
        $Planet = Planets::findByUserId($User['id']);
        $Builds = Builds::findById($Planet['id']);
        $Techs = Techs::findById($User['id']);

        if ($this->Msg->getAction() === "build") {
            PlayerQueue::addQueue($this->aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueUpgarde, [
                'Element' => $this->Msg->getData("id"),
                'AddMode' => true
            ]);
        }
        if ($this->Msg->getAction() === "cancel") {
            PlayerQueue::addQueue($this->aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueCancel, [
                'QueueId' => $this->Msg->getData("id"),
                'AddMode' => false
            ]);
        }

        $CurrentQueue = Queues::getCurrentQueue(QueuesServices::TECHS, $User['id'], $Planet['id']) ?: [];
        $QueueList = [];

        $QueueTechsLevels = [];

        foreach ($CurrentQueue as $Key => $Queue) {
            $Element = $Queue['object_id'];
            $QueueTechsLevels[$Element] += 1;

            $QueueList[$Queue['id']] = [
                'id'            => $Element,
                'qid'           => $Queue['id'],
                'name'          => Vars::$resource[$Element],
                'level'         => $Queue['count'],
                'start_time'    => $Queue['start_time'],
                'end_time'      => $Queue['end_time'],
                'action'        => $Queue['action'],
                'status'        => $Queue['status']
            ];
        }

        $ResearchList = [];

        foreach (Vars::$reslist['tech'] as $Element) {

            $levelToBuild       = $Techs[Vars::$resource[$Element]] + $QueueTechsLevels[$Element];


            $costResources      = BuildFunctions::getElementPrice($Element, $User['id'], $Planet['id'], false, $levelToBuild + 1);
            $costOverflow       = BuildFunctions::getRestPrice($Element, $User['id'], $Planet['id'],  $costResources);
            $elementTime        = BuildFunctions::getBuildingTime($Element, $User['id'], $Planet['id'],  $costResources);


            $Accessible         = BuildFunctions::isTechnologieAccessible($Element, $User['id'], $Planet['id']);
            $requirementsList   = array();
            if (!$Accessible) {

                if (isset(Vars::$requirement[$Element])) {
                    foreach (Vars::$requirement[$Element] as $requireID => $RedCount) {
                        $requirementsList[$requireID]    = array(
                            'count' => $RedCount,
                            'name'  => Vars::$resource[$requireID],
                            'own'   => Helpers::getElementLevel($requireID, $User['id'], $Planet['id'])
                        );
                    }
                }
            }

            $ResearchList[$Element]    = array(
                'id'                => $Element,
                'name'              => Vars::$resource[$Element],
                'type'              => Vars::$attributes[$Element]['type'],
                'level'             => $Techs[Vars::$resource[$Element]],
                'maxLevel'          => Vars::$attributes[$Element]['max'],

                'accessible'        => $Accessible,
                'requirements'      => $requirementsList,


                'costResources'     => $costResources,
                'costOverflow'      => $costOverflow,
                'elementTime'       => $elementTime,
                'levelToBuild'      => $levelToBuild,

                /*"RoomIsOk" => $PLANET['CurrentFields'] < ($PLANET["MaxFields"] - $QueueDestroy),
                "CanBuildElement" => $CanBuildElement,
                'isBusy' => array('shipyard' => !empty($PLANET['Queue']['Hangar']), 'research' => $USER['Queue']['EndTime'] != 0),*/

            );
        }


        return [
            'page'          => 'researchs',
            'ResearchList'  => $ResearchList ?? [],
            'QueueList'     => $QueueList ?? [],
            "Types"         => Vars::$reslist['nametype']['reseach'],
            "IsLabinBuild"  => false,
            'CountQueue'    => count($CurrentQueue),
            'MaxQueue'      => QueuesServices::MaxQueue(QueuesServices::TECHS)

        ];
    }

    public function GetTopNav()
    {

        $User = Users::findByAccount($this->aid);
        $Resources = Resources::getByUserId($User['id']);

        return [
            'Resources' => $Resources
        ];
    }

    public function GetPlanetList()
    {

        $User = Users::findByAccount($this->aid);
        $Planets = Planets::getPlanetsList($User['id']);

        return [
            'current_planet'    => $User['current_planet'],
            'main_planet'       => $User['main_planet'],
            'Planets'           => $Planets
        ];
    }
}
