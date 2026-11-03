<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Core\WSocket;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

class FleetsServices
{
    public const LOGISTICS_TECH_ID = 134;

    /** Общий source of truth видимости входящих Fleet для обзора и выбора цели. */
    public static function incomingFleetRows(int $viewerId): array
    {
        if ($viewerId <= 0) return [];
        $rows = [];
        foreach (\SPGame\Game\Repositories\Planets::findByIndex('owner_id', [$viewerId]) ?? [] as $planet) {
            foreach (Fleets::findByIndex('target_coordinate', [CoordinateType::OBJECT->value,
                (int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'], (int)$planet['planet_type']]) ?? [] as $row) {
                $rows[(int)$row['id']] = $row;
            }
        }
        foreach (\SPGame\Game\Repositories\ResourceBases::ownedBy($viewerId) as $base) {
            $site = \SPGame\Game\Repositories\OrbitalResourceSites::findExistingById((int)$base['id']);
            if ($site === null) continue;
            $at = FleetSurveyService::coordinate($site);
            foreach (Fleets::findByIndex('target_coordinate', [CoordinateType::OBJECT->value,
                $at->getGalaxy(), $at->getSystem(), $at->getOrbit(), $at->getObjectType()->value]) ?? [] as $row) {
                if ((int)($row['target_site_id'] ?? 0) === (int)$site['id']) $rows[(int)$row['id']] = $row;
            }
        }
        foreach (Fleets::findByIndex('owner_id', [$viewerId]) ?? [] as $own) {
            foreach (Fleets::findByIndex('target_fleet', [CoordinateType::FLEET->value, (int)$own['id']]) ?? [] as $row) {
                $rows[(int)$row['id']] = $row;
            }
        }
        return array_filter($rows, static function ($row) use ($viewerId): bool {
            if ((int)$row['owner_id'] <= 0 || (int)$row['owner_id'] === $viewerId
                || !empty($row['is_return']) || (int)$row['mission'] <= 0) return false;
            $active = FleetCommands::getActiveCommand((int)$row['id']);
            if ($active === null) {
                $pending = FleetCommands::getNextPendingCommand((int)$row['id']);
                if ($pending === null) return false;
                $pendingParams = json_decode($pending['params'], true);
                if (empty($pendingParams['blockedAfterBattle']) && !isset($pendingParams['pause'])
                    && !((int)$row['status'] === Fleet::STATUS_MISSION && in_array((int)$pending['command_type'], [10,11,12], true))) return false;
            }
            $params = $active === null ? [] : json_decode($active['params'], true);
            return empty($params['recallAfterTransition']);
        });
    }

    public static function missionSlotLimit(int $level): int
    {
        return 1 + max(0, $level);
    }

    public static function baseSlotLimit(int $level): int
    {
        return 2 + max(0, $level);
    }

    /** Завершённые исследования; очередь исследований не даёт слотов заранее. */
    public static function getLimits(AccountData $accountData, ?array $planet = null, ?int $excludeFleetId = null): array
    {
        $planet ??= $accountData['Planet'];
        $ownerId = (int)$accountData['User']['id'];
        $computerLevel = (int)($accountData['Techs']['computer_tech'] ?? 0);
        $logisticsLevel = (int)($accountData['Techs']['fleet_logistics_tech'] ?? 0);
        $missions = $based = $incoming = 0;
        foreach (Fleets::findByIndex('owner_id', (string)$ownerId) ?? [] as $row) {
            if ($excludeFleetId !== null && (int)$row['id'] === $excludeFleetId) continue;
            $onMission = (int)$row['status'] !== Fleet::STATUS_IDLE;
            if ($onMission) $missions++;
            // Отлетевший флот сохраняет место на домашней планете до возврата.
            if ((int)$row['home_planet_id'] === (int)$planet['id']) {
                $based++;
            } elseif ($onMission && (int)$row['mission'] === FleetMissionType::RELOCATE->value) {
                // Fleet.target хранит первый участок (в том числе границу системы),
                // поэтому конечную базу берём из существующей команды STAY.
                foreach (FleetCommands::getFleetQueue((int)$row['id']) as $command) {
                    if ((int)$command['command_type'] !== FleetCommandType::STAY->value) continue;
                    $params = is_array($command['params']) ? $command['params'] : json_decode($command['params'], true);
                    $at = $params['at'] ?? [];
                    if ((int)($at['type'] ?? 0) === CoordinateType::OBJECT->value
                        && (int)($at['galaxy'] ?? 0) === (int)$planet['galaxy']
                        && (int)($at['system'] ?? 0) === (int)$planet['system']
                        && (int)($at['orbit'] ?? 0) === (int)$planet['planet']
                        && (int)($at['objectType'] ?? 0) === (int)$planet['planet_type']) {
                        $incoming++;
                        break;
                    }
                }
            }
        }
        return [
            'missions' => ['used' => $missions, 'max' => self::missionSlotLimit($computerLevel), 'level' => $computerLevel],
            'base' => ['used' => $based + $incoming, 'max' => self::baseSlotLimit($logisticsLevel),
                'based' => $based, 'incoming' => $incoming, 'level' => $logisticsLevel],
        ];
    }

    public static function assertMissionSlot(AccountData $accountData): void
    {
        $slots = self::getLimits($accountData)['missions'];
        if ($slots['used'] >= $slots['max']) {
            throw new \LogicException('Достигнут лимит активных миссий. Повысьте компьютерную технологию.');
        }
    }

    public static function assertBaseSlot(AccountData $accountData, ?array $planet = null, ?int $excludeFleetId = null): void
    {
        $slots = self::getLimits($accountData, $planet, $excludeFleetId)['base'];
        if ($slots['used'] >= $slots['max']) {
            throw new \LogicException('Нет свободных мест базирования. Повысьте флотовую логистику.');
        }
    }

    /** Проверяется и при приёме команды, и перед её выполнением. */
    public static function getManageableFleet(int $fleetId, AccountData $accountData, bool $atPlanet = false): Fleet
    {
        $fleet = $accountData->getFleetsData()->get($fleetId);
        if ($fleet === null || $fleet->getOwnerId() !== (int)$accountData['User']['id']) {
            throw new \InvalidArgumentException('Флот недоступен.');
        }
        if ($atPlanet) {
            FleetBattleService::assertFleetAvailable($fleetId);
            FleetBattleService::assertPlanetAvailable($fleet->getHomePlanetId());
            $planet = $accountData['Planet'];
            $coordinate = $fleet->getCurrentCoordinate();
            if ($fleet->getStatus() !== Fleet::STATUS_IDLE
                || FleetCommands::getFleetQueue($fleetId) !== []
                || $fleet->getHomePlanetId() !== (int)$planet['id']
                || (int)$planet['owner_id'] !== $fleet->getOwnerId()
                || $coordinate->getType() !== CoordinateType::OBJECT
                || $coordinate->getGalaxy() !== (int)$planet['galaxy']
                || $coordinate->getSystem() !== (int)$planet['system']
                || $coordinate->getOrbit() !== (int)$planet['planet']
                || $coordinate->getObjectType()?->value !== (int)$planet['planet_type']) {
                throw new \LogicException('Расформировать можно только свободный флот у выбранной своей планеты.');
            }
        }
        return $fleet;
    }

    public static function RenameFleet(int $fleetId, mixed $name, AccountData $accountData): void
    {
        $fleet = self::getManageableFleet($fleetId, $accountData);
        $fleet->setName(Fleet::validateName($name));
        $fleet->save();
    }

    public static function AddToOrbit(array $items, AccountData &$AccountData, float $Time)
    {
        FleetBattleService::assertPlanetAvailable((int)$AccountData['Planet']['id']);

        $Logger = Logger::getInstance();

        if (empty($items)) {
            $Logger->warning("AddToOrbit: empty items");
            return;
        }

        // Не расходуем место базирования на пустой/недоступный состав.
        $validItems = [];
        foreach ($items as $element => $count) {
            $name = Vars::$resource[$element] ?? null;
            if (in_array((int)$element, Vars::$reslist['fleet'], true)
                && $name !== null && is_numeric($count) && (int)$count > 0
                && (int)$count <= (int)($AccountData['Ships'][$name] ?? 0)) {
                $validItems[(int)$element] = (int)$count;
            }
        }
        if ($validItems === []) return;

        $fleetsData = FleetsData::getInstance();
        $fleet = $fleetsData->create($AccountData);

        $CountShips = 0;
        foreach ($validItems as $Element => $Count) {
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

        foreach ([$fromFleet, $toFleet] as $participant) {
            if ($participant !== null) {
                FleetBattleService::assertFleetAvailable($participant->getId());
                FleetBattleService::assertPlanetAvailable($participant->getHomePlanetId());
            }
        }
        FleetBattleService::assertPlanetAvailable((int)$AccountData['Planet']['id']);

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

        self::getManageableFleet($fleetId, $AccountData, true);

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
                $name = $ship->getShipTypeName();
                $AccountData['Ships'][$name] = (int)($AccountData['Ships'][$name] ?? 0) + $ship->getCount();
            }
            $planetResources[903]['count'] += $Fleet->getFuelCurrent();
            $Fleet->setFuelCurrent(0.0);
            $Fleet->setFuelTotal(0.0);
        }

        if ($save) {
            $AccountData->save();
        }
        FleetCommands::clearFleetQueue($fleetId);
        // Удаляем флот из общего registry.
        $AccountData->getFleetsData()->remove($fleetId, $save);
        return true;
    }
}
