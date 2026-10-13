<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Core\WSocket;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Space\ObjectType;

class FleetsServices
{

    public static function AddToOrbit(array $items, AccountData &$AccountData, float $Time)
    {

        $Logger = Logger::getInstance();

        if (empty($items)) {
            $Logger->warning("AddToOrbit: empty items");
            return;
        }

        $fleetsData = FleetsData::getInstance();

        $fleet = $fleetsData->create($AccountData);

        $CountShips = 0;
        foreach ($items as $Element => $Count) {
            $name = Vars::$resource[$Element];
            if (!$name) continue;
            if (!isset($AccountData['Ships'][$name])) continue;
            if ($Count < 1 || $Count > $AccountData['Ships'][$name]) continue;

            $fleet->addShip($Element, $Count);

            $AccountData['Ships'][$name] -= $Count;
            $CountShips++;
        }

        if ($CountShips === 0) {
            $Logger->info("AddToOrbit: no valid ships");
            return;
        }

        //Logger::getInstance()->info("AddToOrbit FleetShips", $FleetShips);
        if ($CountShips > 0) {
            // Создание и регистрация флота принадлежат FleetsData.
            $AccountData->save();
            $fleet->save();
        } else {
            $fleet->destroy();
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
        $fleetsData = $AccountData->getFleetsData();

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
        $fromFleet = $fromPlanet ? null : $fleetsData->get($fromFleetId);
        $toFleet = $toPlanet ? null : $fleetsData->get($toFleetId);
        if (!$fromPlanet && $fromFleet === null) {
            $Logger->warning("MoveShip: fromFleetId {$fromFleetId} not found");
            return;
        }
        if (!$toPlanet && $toFleet === null) {
            $Logger->warning("MoveShip: toFleetId {$toFleetId} not found");
            return;
        }

        // Если участвует планета, укажем рабочую
        if ($toPlanet || $fromPlanet) {
            $AccountData["WorkPlanet"] = $fromFleet?->getHomePlanetId() ?? $AccountData["WorkPlanet"];
        }

        // Перемещение между флотами
        if ($toFleetId > 0 && $fromFleetId > 0) {

            $fromShip = $fromFleet->getShip($shipId);
            $toShip   = $toFleet->getShip($shipId) ?? $toFleet->setShipCount($shipId, 0);


            $fromCount = $fromShip->getCount() ?? 0;
            $toCount   = $toShip?->getCount() ?? 0;
            $fromExp   = $fromShip->getExperience() ?? 0;
            $toExp     = $toShip?->getExperience() ?? 0;

            if ($count > $fromCount) $count = $fromCount;
            if ($count <= 0) return;

            // Средневзвешенный опыт
            $fromAvg = $fromCount > 0 ? $fromExp / $fromCount : 0;
            $toAvg   = $toCount > 0 ? $toExp / $toCount : 0;

            $fromCount -= $count;
            $toCount   += $count;

            $toExp   = ($toExp + $fromAvg * $count); // суммарный опыт
            $fromExp = $fromAvg * $fromCount;

            $fromShip->setCount($fromCount);
            $fromShip->setExperience($fromExp);

            $toShip->setCount($toCount);
            $toShip->setExperience($toExp);

            // Флот → Планета
        } elseif ($toPlanet) {
            $fromShip = $fromFleet->getShip($shipId);
            $planetShips = &$AccountData['Ships'];

            $fromCount = $fromShip->getCount() ?? 0;
            $fromExp   = $fromShip->getExperience() ?? 0;

            if ($count > $fromCount) $count = $fromCount;
            if ($count <= 0) return;

            $fromAvg = $fromCount > 0 ? $fromExp / $fromCount : 0;

            $fromCount -= $count;
            $planetShips[$ShipName] = (int)($planetShips[$ShipName] ?? 0) + $count;
            $fromShip->setCount($fromCount);
            $fromShip->setExperience($fromAvg * $fromCount);

            // Планета → Флот
        } elseif ($fromPlanet) {
            $planetShips = &$AccountData['Ships'];
            $toShip = $toFleet->getShip($shipId) ?? $toFleet->setShipCount($shipId, 0);

            $fromCount = (int)($planetShips[$ShipName] ?? 0);
            $toCount   = $toShip?->getCount() ?? 0;
            $toExp     = $toShip?->getExperience() ?? 0;

            if ($count > $fromCount) $count = $fromCount;
            if ($count <= 0) return;

            $fromCount -= $count;
            $toCount   += $count;

            $planetShips[$ShipName] = $fromCount;
            $toShip->setCount($toCount);
            $toShip->setExperience($toExp);

        }

        // Проверяем — не стал ли флот пустым
        if (!$fromPlanet && $fromFleet->getTotalShips() === 0) {
            self::DisbandFleet($fromFleetId, $AccountData, $Time, true, $save);
        }
        if (!$toPlanet && $toFleet->getTotalShips() === 0) {
            self::DisbandFleet($toFleetId, $AccountData, $Time, true, $save);
        }

        // Сохранение
        if ($save) {
            $AccountData->save();
            if (!$toPlanet) $toFleet->save();
            if (!$fromPlanet) $fromFleet->save();
        }
    }

    public static function DisbandFleet(int $fleetId, AccountData &$AccountData, float $Time, bool $returnToPlanet = true, bool $save = true): bool
    {
        $Logger = Logger::getInstance();

        $Fleet = $AccountData->getFleetsData()->get($fleetId);
        if ($Fleet === null) {
            $Logger->warning("DisbandFleet: fleetId {$fleetId} not found");
            return false;
        }

        // --- Возврат ресурсов ---
        if ($returnToPlanet) {
            $planetResources = &$AccountData['Resources'];

            $FleetResources = $Fleet->getResources();

            foreach ($FleetResources as $ResID => $Resource) {
                $planetResources[$ResID]['count'] += $Resource->GetCount();
                if ($Resource !== null)
                    $Resource->setCount(0);
            }

            $Logger->info("DisbandFleet: returned resources from fleet {$fleetId} to planet");

            // Возврат кораблей
            foreach ($Fleet->getShips() as $shipId => $ship) {
                $AccountData['Ships'][$ship->getShipTypeName()] += $ship->getCount();
            }
        }

        // Удаляем флот из общего registry.
        $AccountData->getFleetsData()->remove($fleetId, $save);

        if ($save) {
            $AccountData->save();
        }
        return true;
    }
}
