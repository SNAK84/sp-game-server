<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Enums\FleetMissionType;

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Services\FleetFunctions;

use SPGame\Game\Services\AccountData;

use SPGame\Game\Services\FleetMissionService;

class FleetsPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {

        $AvailableMissions = null;
        $TravelInfo = null;
        $Target = $this->Msg->getData('target', []);
        if (!is_array($Target)) {
            $Target = [];
        }

        Logger::getInstance()->info("FleetsPage getAction", [$this->Msg->getAction()]);


        if ($this->Msg->getAction() === 'GetAvailableMissions') {

            $coordinates = $this->Msg->getData('coordinates', []);

            // coordinates {"galaxy":1,"system":1,"type":1,"orbit":2}
            Logger::getInstance()->info("coordinates", $coordinates);

            $fleetMissionService = $this->getFleetMissionService();
            $fleetId = $this->Msg->getData('fleetId');

            $AvailableMissions = $fleetMissionService->getAvailableMissions(
                $AccountData,
                $fleetId,
                $coordinates
            );

            $TravelInfo = $fleetMissionService->getTravelInfo(
                $AccountData,
                $fleetId,
                $coordinates
            );
        }

        $Planet = &$AccountData['Planet'];

        // Список флотов игрока (активные, летящие, стоящие)
        $FleetList = $this->buildFleetList($AccountData);

        // Список кораблей на планете (в ангаре)
        $PlanetShips = $this->buildPlanetShips($AccountData);

        return [
            'page'          => 'fleet',
            'planet_id'     => $Planet['id'],
            'FleetList'     => $FleetList,
            'PlanetShips'   => $PlanetShips,
            'FleetTypes'    => Vars::$reslist['nametype']['fleet'],
            'FleetMissions' => FleetMissionType::list(),
            'AvailableMissions' => $AvailableMissions,
            'TravelInfo'    => $TravelInfo,
            'FleetCommands' => FleetCommandType::list(),
            'Target'        => $Target,
        ];
    }

    /**
     * Собирает список флотов игрока
     */
    private function buildFleetList(AccountData &$AccountData): array
    {
        $fleets = $AccountData->getFleetsData()->getForPlanet(
            (int) $AccountData['User']['id'],
            (int) $AccountData['Planet']['id']
        );

        $FleetList = [];

        foreach ($fleets as $fleet) {
            if ($fleet === null) {
                continue;
            }

            $shipsNormalized = [];

            $countTotal = 0;
            $types = 0;
            $capacityTotal = 0.0;
            $consumptionTotal = 0.0;
            $speedList = [];

            // Перебираем фактический состав доменного Fleet.
            foreach ($fleet->getShips() as $ShipId => $ship) {
                $ShipId = (int) $ShipId;
                //$name = Vars::$resource[$ShipId] ?? (string) $ShipId;

                $shipCount = $ship->getCount();
                if ($shipCount <= 0) {
                    // сохраним нулевой корабль (опционально) — но для клиента лучше не класть нулевые элементы
                    continue;
                }

                // Атрибуты корабля из Vars
                //$attr = Vars::$attributes[$ShipId] ?? [];
                //$shipCapacity = $attr['capacity'] ?? 0.0;

                // Функции расчёта, использующие AccountData (например модификаторы)
                //$shipSpeed = FleetFunctions::GetShipSpeed($ShipId, $AccountData);
                //$shipConsumption = FleetFunctions::GetShipConsumption($ShipId, $AccountData);

                // Нормализованный объект корабля
                $shipsNormalized[$ShipId] = [
                    'id'          => $ShipId,
                    'name'        => $ship->getShipTypeName(),
                    'count'       => $shipCount,
                    'speed'       => $ship->getSpeed($AccountData),

                    'capacity'    => $ship->getCapacity(),
                    'consumption' => $ship->getConsumption($AccountData),

                    'damage'      => $ship->getDamage(),
                    'experience'  => $ship->getExperience(),
                    'morale'      => $ship->getMorale(),
                    'status'      => $ship->getStatus(),
                ];

                // Агрегаты флота
                $countTotal += $shipCount;
                $types++;
                $capacityTotal += $ship->getCapacityAll();
                $consumptionTotal += $ship->getConsumptionAll($AccountData);
                $speedList[] = $ship->getSpeed($AccountData);
            }

            $fleetSpeed = $fleet->getTotalSpeed($AccountData);
            $startPosition = $fleet->StartPosition();
            $endPosition = $fleet->EndPosition();
            $currentPosition = $fleet->CurrentPosition();

            // Сформируем запись для клиента
            $FleetList[$fleet->getId()] = [
                'id'            => $fleet->getId(),
                'mission'       => $fleet->getMission(),
                'status'        => $fleet->getStatus(),
                //'is_return'     => $fleet->isReturn() ? 1 : 0,
                'speed'         => $fleetSpeed,
                'count'         => $countTotal,
                'types'         => $types,
                'capacity'      => $capacityTotal,
                'consumption'   => $consumptionTotal,
                'fuel'          => $fleet->getFuelCurrent(),
                'experience'    => $fleet->getExperience(),
                'resources'     => $this->extractResources($fleet),
                'ships'         => $shipsNormalized,
                'start'         => [
                    'galaxy' => $startPosition->getGalaxy(),
                    'system' => $startPosition->getSystem(),
                    'orbit' => $startPosition->getOrbit(),
                    'object_type' => $startPosition->getObjectType(),
                    'distance' => $startPosition->getDistance(),
                    'deg' => $startPosition->getDeg(),
                    'type' => $startPosition->getType(),
                ],
                'end'           => [
                    'galaxy' => $endPosition->getGalaxy(),
                    'system' => $endPosition->getSystem(),
                    'orbit' => $endPosition->getOrbit(),
                    'object_type' => $endPosition->getObjectType(),
                    'distance' => $endPosition->getDistance(),
                    'deg' => $endPosition->getDeg(),
                    'type' => $endPosition->getType(),
                ],
                'current'       => [
                    'galaxy' => $currentPosition->getGalaxy(),
                    'system' => $currentPosition->getSystem(),
                    'distance' => $currentPosition->getDistance(),
                    'deg' => $currentPosition->getDeg(),
                ],
                'start_time'    => $fleet->getStartTime(),
                'end_time'      => $fleet->getEndTime(),
                'stay_time'     => $fleet->getStayTime(),
            ];
        }

        return $FleetList;
    }

    /**
     * Собирает список кораблей, находящихся на планете (ангар)
     */
    private function buildPlanetShips(AccountData &$AccountData): array
    {
        $Ships = &$AccountData['Ships'];
        $PlanetFleet = [];

        foreach (Vars::$reslist['fleet'] as $ShipId) {
            $count = $Ships[Vars::$resource[$ShipId]] ?? 0;

            if (Vars::$attributes[$ShipId]['speed'] > 0)
                $PlanetFleet[$ShipId] = [
                    'id'            => $ShipId,
                    'name'          => Vars::$resource[$ShipId],
                    'count'         => $count,
                    'capacity'      => Vars::$attributes[$ShipId]['capacity'],
                    'speed'         => FleetFunctions::GetShipSpeed($ShipId, $AccountData),
                    'consumption'   => FleetFunctions::GetShipConsumption($ShipId, $AccountData),
                    'structure'     => Vars::$attributes[$ShipId]['cost'][901] + Vars::$attributes[$ShipId]['cost'][902],
                    'attack'        => Vars::$CombatCaps[$ShipId]['attack'],
                    'shield'        => Vars::$CombatCaps[$ShipId]['shield'],
                ];
        }

        return $PlanetFleet;
    }

    /**
     * Извлекает ресурсы из строки флота
     */
    private function extractResources(Fleet $fleet): array
    {
        $resources = [];

        foreach ($fleet->getResources() as $resource) {
            $resources[$resource->getID()] = [
                'id' => $resource->getID(),
                'name' => $resource->getName(),
                'count' => $resource->getCount(),
            ];
        }

        return $resources;
    }

    protected FleetMissionService $fleetMissionService;

    protected function getFleetMissionService(): FleetMissionService
    {
        return $this->fleetMissionService ??= new FleetMissionService();
    }
}
