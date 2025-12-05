<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Enums\FleetMissionType;

use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Services\FleetFunctions;

use SPGame\Game\Services\AccountData;

use SPGame\Game\Services\FleetsServices;

class FleetsPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {

        if ($this->Msg->getAction() === 'MoveShip') {

            $fromFleetId = $this->Msg->getData('fromFleetId', 0);
            $toFleetId = $this->Msg->getData('toFleetId', 0);
            $shipId = $this->Msg->getData('shipId', 0);
            $count = $this->Msg->getData('count', 0);

            FleetsServices::MoveShip($toFleetId, $fromFleetId, $shipId, $count, $AccountData, microtime(true), false);

            //$this->logger->info("MoveShip Fleet to Fleet to " . $AccountData["Fleets"][$toFleetId]['Ships']["destructor_count"]);
        }
        if ($this->Msg->getAction() === 'DisbandFleet') {
            FleetsServices::DisbandFleet($this->Msg->getData('FleetId', 0), $AccountData, microtime(true), true, false);
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
            'FleetCommands' => FleetCommandType::list(),
        ];
    }

    /**
     * Собирает список флотов игрока
     */
    private function buildFleetList(AccountData &$AccountData): array
    {

        Logger::getInstance()->info("buildFleetList",  $AccountData["Fleets"]->toArray());

        $Fleets = $AccountData["Fleets"]->toArray();
        $FleetList = [];

        // Построим карту name -> id только для кораблей (fleet)
        /*$nameToId = [];
        foreach (Vars::$reslist['fleet'] as $fid) {
            $nameToId[Vars::$resource[$fid]] = $fid;
        }*/

        foreach ($Fleets as $Fleet) {

            // Если в строке флота нет блока Ships — пропускаем
            $rawShips = $Fleet['Ships'] ?? [];
            $shipsNormalized = [];

            $countTotal = 0;
            $types = 0;
            $capacityTotal = 0.0;
            $consumptionTotal = 0.0;
            $speedList = [];

            // Перебираем все потенциальные корабли по списку ресурсов fleet
            foreach (Vars::$reslist['fleet'] as $ShipId) {
                $name = Vars::$resource[$ShipId];

                // Поля в флоте имеют формат: {name}_count, {name}_damage, {name}_experience, {name}_morale, {name}_status
                $countKey = $name . '_count';
                if (!isset($rawShips[$countKey])) {
                    // возможно имя в raw отличается (например underscore vs dash) — но по твоему примеру совпадает
                    continue;
                }

                $shipCount = (int)($rawShips[$countKey] ?? 0);
                if ($shipCount <= 0) {
                    // сохраним нулевой корабль (опционально) — но для клиента лучше не класть нулевые элементы
                    continue;
                }

                // Получаем дополнительные поля, если они есть
                $damageKey = $name . '_damage';
                $expKey = $name . '_experience';
                $moraleKey = $name . '_morale';
                $statusKey = $name . '_status';


                $shipDamage = isset($rawShips[$damageKey]) ? (float)$rawShips[$damageKey] : 0.0;
                $shipExp = isset($rawShips[$expKey]) ? (float)$rawShips[$expKey] : 0.0;
                $shipMorale = isset($rawShips[$moraleKey]) ? (float)$rawShips[$moraleKey] : 1.0;
                $shipStatus = isset($rawShips[$statusKey]) ? (int)$rawShips[$statusKey] : 0;

                // Атрибуты корабля из Vars
                $attr = Vars::$attributes[$ShipId] ?? [];
                $shipCapacity = $attr['capacity'] ?? 0.0;

                // Функции расчёта, использующие AccountData (например модификаторы)
                $shipSpeed = FleetFunctions::GetShipSpeed($ShipId, $AccountData);
                $shipConsumption = FleetFunctions::GetShipConsumption($ShipId, $AccountData);

                // Нормализованный объект корабля
                $shipsNormalized[$ShipId] = [
                    'id'          => $ShipId,
                    'name'        => $name,
                    'count'       => $shipCount,
                    'speed'       => $shipSpeed,
                    'capacity'    => $shipCapacity,
                    'consumption' => $shipConsumption,
                    'damage'      => $shipDamage,
                    'experience'  => $shipExp,
                    'morale'      => $shipMorale,
                    'status'      => $shipStatus,
                ];

                // Агрегаты флота
                $countTotal += $shipCount;
                $types++;
                $capacityTotal += $shipCapacity * $shipCount;
                $consumptionTotal += $shipConsumption * $shipCount;
                $speedList[] = $shipSpeed;
            }

            // вычислим скорость флота — минимальная из скоростей составляющих кораблей, если не задана
            $fleetSpeed = $Fleet['speed'] ?? 0;
            if (empty($fleetSpeed)) {
                $fleetSpeed = (count($speedList) ? min($speedList) : 0);
            }

            // Сформируем запись для клиента
            $FleetList[$Fleet['id']] = [
                'id'            => $Fleet['id'],
                'mission'       => $Fleet['mission'],
                'status'        => $Fleet['status'] ?? 0,
                'is_return'     => $Fleet['is_return'] ?? 0,
                'speed'         => $fleetSpeed,
                'count'         => $countTotal,
                'types'         => $types,
                'capacity'      => $capacityTotal,
                'consumption'   => $consumptionTotal,
                'fuel'          => $Fleet['fuel_current'] ?? 0.0,
                'experience'    => $Fleet['experience'] ?? 0.0,
                'resources'     => $this->extractResources($Fleet),
                'ships'         => $shipsNormalized,
                'start'         => [
                    'galaxy' => $Fleet['start_galaxy'],
                    'system' => $Fleet['start_system'],
                    'planet' => $Fleet['start_planet'],
                ],
                'end'           => [
                    'galaxy' => $Fleet['end_galaxy'],
                    'system' => $Fleet['end_system'],
                    'planet' => $Fleet['end_planet'],
                ],
                'start_time'    => $Fleet['start_time'],
                'end_time'      => $Fleet['end_time'],
                'stay_time'     => $Fleet['stay_time'],
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
    private function extractResources(array $Fleet): array
    {
        $resources = [];
        $ressIDs = array_merge(Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][3]);
        foreach ($ressIDs as $ResID) {
            $key = "resource_" . Vars::$resource[$ResID];
            if (isset($Fleet[$key])) {
                $resources[$ResID] = [
                    'id' => $ResID,
                    'name' => Vars::$resource[$ResID],
                    'count' => (float)$Fleet[$key],
                ];
            }
        }
        return $resources;
    }
}
