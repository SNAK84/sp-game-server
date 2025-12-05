<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Vars;

class FleetsServices
{

    public static function AddToOrbit(array $items, AccountData &$AccountData, float $Time)
    {

        $Logger = Logger::getInstance();

        if (empty($items)) {
            $Logger->warning("AddToOrbit: empty items");
            return;
        }

        $Planet = $AccountData['Planet'];

        $Fleet = [
            'owner_id'           => $AccountData['User']['id'],
            'owner_id'           => $AccountData['User']['id'],
            'home_planet_id'     => $Planet['id'],
            'current_parent_id'  => $Planet['id'],
            'current_parent_type' => 'planet',
            'anchor_distance'    => 0.0,
        ];

        $Fleet = Fleets::castRowToSchema($Fleet, true);

        $FleetShips = [
            'id'        => $Fleet['id'],
            'fleet_id'  => $Fleet['id'],
            'owner_id'  => $Fleet['owner_id'],
        ];

        $CountShips = 0;
        foreach ($items as $Element => $Count) {
            $name = Vars::$resource[$Element];
            if (!$name) continue;
            if (!isset($AccountData['Ships'][$name])) continue;
            if ($Count < 1 || $Count > $AccountData['Ships'][$name]) continue;

            $FleetShips["{$name}_count"] = $Count;
            $AccountData['Ships'][$name] -= $Count;
            $CountShips++;
        }

        if ($CountShips === 0) {
            $Logger->info("AddToOrbit: no valid ships");
            return;
        }

        //Logger::getInstance()->info("AddToOrbit FleetShips", $FleetShips);
        if ($CountShips > 0) {

            // Добавляем флот в AccountData
            $AccountData['Fleets'][$Fleet['id']] = array_merge($Fleet, ['Ships' => $FleetShips]);

            //Fleets::add($Fleet);
            //FleetsShips::add($FleetShips);
            $AccountData->save();
        }
    }

    public static function MoveShip(
        int $toFleetId,
        int $fromFleetId,
        int $shipId,
        int $count,
        AccountData &$AccountData,
        float $Time,
        bool $save = true
    ) {
        $Logger = Logger::getInstance();

        if ($count <= 0) {
            $Logger->warning("MoveShip: invalid count {$count}");
            return;
        }

        $ShipName   = Vars::$resource[$shipId] ?? null;

        if (!$ShipName) {
            $Logger->error("MoveShip: Unknown ShipId {$shipId}");
            return;
        }
        
        $toPlanet   = ($toFleetId === 0);
        $fromPlanet = ($fromFleetId === 0);

        // Проверка существования флотов
        if (!$fromPlanet && empty($AccountData["Fleets"][$fromFleetId])) {
            $Logger->warning("MoveShip: fromFleetId {$fromFleetId} not found");
            return;
        }
        if (!$toPlanet && empty($AccountData["Fleets"][$toFleetId])) {
            $Logger->warning("MoveShip: toFleetId {$toFleetId} not found");
            return;
        }

        // Если участвует планета, укажем рабочую
        if ($toPlanet || $fromPlanet) {
            $AccountData["WorkPlanet"] = $AccountData["Fleets"][$fromFleetId]['start_id'] ?? $AccountData["WorkPlanet"];
        }

        // Перемещение между флотами
        if ($toFleetId > 0 && $fromFleetId > 0) {

            $fromShips = &$AccountData["Fleets"][$fromFleetId]['Ships'];
            $toShips   = &$AccountData["Fleets"][$toFleetId]['Ships'];

            $fromCount = (int)($fromShips["{$ShipName}_count"] ?? 0);
            $toCount   = (int)($toShips["{$ShipName}_count"] ?? 0);
            $fromExp   = (float)($fromShips["{$ShipName}_experience"] ?? 0);
            $toExp     = (float)($toShips["{$ShipName}_experience"] ?? 0);

            if ($count > $fromCount) $count = $fromCount;
            if ($count <= 0) return;

            // Средневзвешенный опыт
            $fromAvg = $fromCount > 0 ? $fromExp / $fromCount : 0;
            $toAvg   = $toCount > 0 ? $toExp / $toCount : 0;

            $fromCount -= $count;
            $toCount   += $count;

            $toExp   = ($toExp + $fromAvg * $count); // суммарный опыт
            $fromExp = $fromAvg * $fromCount;

            $fromShips["{$ShipName}_count"] = $fromCount;
            $fromShips["{$ShipName}_experience"] = $fromExp;

            $toShips["{$ShipName}_count"] = $toCount;
            $toShips["{$ShipName}_experience"] = $toExp;

            // Флот → Планета
        } elseif ($toPlanet) {
            $fromShips = &$AccountData["Fleets"][$fromFleetId]['Ships'];
            $planetShips = &$AccountData['Ships'];

            $fromCount = (int)($fromShips["{$ShipName}_count"] ?? 0);
            $fromExp   = (float)($fromShips["{$ShipName}_experience"] ?? 0);

            if ($count > $fromCount) $count = $fromCount;
            if ($count <= 0) return;

            $fromAvg = $fromCount > 0 ? $fromExp / $fromCount : 0;

            $fromCount -= $count;
            $planetShips[$ShipName] = (int)($planetShips[$ShipName] ?? 0) + $count;
            $fromShips["{$ShipName}_count"] = $fromCount;
            $fromShips["{$ShipName}_experience"] = $fromAvg * $fromCount;

            // Планета → Флот
        } elseif ($fromPlanet) {
            $planetShips = &$AccountData['Ships'];
            $toShips = &$AccountData["Fleets"][$toFleetId]['Ships'];

            $fromCount = (int)($planetShips[$ShipName] ?? 0);
            $toCount   = (int)($toShips["{$ShipName}_count"] ?? 0);
            $toExp     = (float)($toShips["{$ShipName}_experience"] ?? 0);

            if ($count > $fromCount) $count = $fromCount;
            if ($count <= 0) return;

            $fromCount -= $count;
            $toCount   += $count;

            $planetShips[$ShipName] = $fromCount;
            $toShips["{$ShipName}_count"] = $toCount;
        }

        // Проверяем — не стал ли флот пустым
        if (!$fromPlanet && self::isFleetEmpty($AccountData["Fleets"][$fromFleetId]['Ships'])) {
            self::DisbandFleet($fromFleetId, $AccountData, $Time, true, $save);
        }
        if (!$toPlanet && self::isFleetEmpty($AccountData["Fleets"][$toFleetId]['Ships'])) {
            self::DisbandFleet($toFleetId, $AccountData, $Time, true, $save);
        }

        // Сохранение
        if ($save) {
            $AccountData->save();
        }
    }

    /**
     * Проверка пуст ли флот
     */
    private static function isFleetEmpty(Data $ships): bool
    {
        foreach ($ships->toArray() as $key => $value) {
            if (str_ends_with($key, '_count') && $value > 0) return false;
        }
        return true;
    }

    public static function DisbandFleet(int $fleetId, AccountData &$AccountData, float $Time, bool $returnToPlanet = true, bool $save = true): bool
    {
        $Logger = Logger::getInstance();

        if (empty($AccountData["Fleets"][$fleetId])) {
            $Logger->warning("DisbandFleet: fleetId {$fleetId} not found");
            return false;
        }

        $Fleet = &$AccountData["Fleets"][$fleetId];
        $Ships = &$Fleet['Ships'];

        // --- Возврат ресурсов ---
        if ($returnToPlanet) {
            $planetResources = &$AccountData['Resources'];

            $ressIDs = array_merge(Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][3]);
            foreach ($ressIDs as $ResID) {
                $value = (float)($Fleet["resource_" . Vars::$resource[$ResID]] ?? 0);
                if ($value > 0) {
                    $planetResources[$ResID]['count'] += $value;
                    $Fleet["resource_" . Vars::$resource[$ResID]] = 0;
                }
            }

            $Logger->info("DisbandFleet: returned resources from fleet {$fleetId} to planet");
        }

        if (self::isFleetEmpty($Ships)) {
            $Logger->info("DisbandFleet: fleetId {$fleetId} has no ships", $Ships->toArray());
            unset($AccountData["Fleets"][$fleetId]);
            if ($save) {
                Fleets::delete($Fleet->toArray());
                FleetsShips::delete($Ships->toArray());
                $AccountData->save();
            }
            return true;
        }

        // Возвращаем корабли на планету (если включено)
        if ($returnToPlanet) {
            foreach ($Ships->toArray() as $key => $value) {
                if (str_ends_with($key, '_count')) {
                    $ShipName = str_replace('_count', '', $key);
                    $count = (int)$value;
                    if ($count > 0) {
                        $AccountData['Ships'][$ShipName] += $count;
                    }
                }
            }
        }



        $Logger->info("DisbandFleet: removing fleet {$fleetId}");

        // Удаляем флот и записи
        unset($AccountData["Fleets"][$fleetId]);
        if ($save) {
            Fleets::delete($Fleet->toArray());
            FleetsShips::delete($Ships->toArray());
            $AccountData->save();
        }
        return true;
    }
}
