<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Fleet\{Fleet, FleetShip};
use SPGame\Game\Repositories\{Builds, Config, Defenses, FleetCommands, Fleets, FleetsShips, Galaxy, GalaxyOrbits, PlanetResources, Planets, Ships, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Правила и локальный runtime commit колонизации; MySQL сохраняет RepositorySaver. */
final class FleetColonizationService
{
    public const COLONIZER = 208;

    public static function planetLimit(AccountData $account): int
    {
        $key = Vars::$resource[124] ?? null;
        $level = $key === null ? 0 : max(0, (int)($account['Techs'][$key] ?? 0));
        return 1 + intdiv($level + 1, 2);
    }

    public static function availabilityReason(Fleet $fleet, AccountData $account, Coordinate $target): ?string
    {
        $orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [$target->getGalaxy(), $target->getSystem(), $target->getOrbit()]);
        if ($target->getType() !== CoordinateType::OBJECT || $target->getObjectType() !== ObjectType::PLANET
            || $orbit === null || (int)$orbit['type'] !== GalaxyOrbits::EMPTY
            || (Planets::findByIndex('galaxy_system_planet', [$target->getGalaxy(), $target->getSystem(), $target->getOrbit()]) ?? []) !== []) {
            return 'Орбита недоступна для колонизации.';
        }
        $systems = Galaxy::findByIndex('galaxy_system', [$target->getGalaxy(), $target->getSystem()]) ?? [];
        if (!isset($systems[0]) || $target->getOrbit() > (int)$systems[0]['max_orbits']) {
            return 'Орбита недоступна для колонизации.';
        }
        if ((($fleet->getShips()[self::COLONIZER] ?? null)?->getCount() ?? 0) < 1) {
            return 'Для колонизации необходим колонизатор.';
        }
        $planets = Planets::findByIndex('owner_id', [(int)$account['User']['id']]) ?? [];
        $count = count(array_filter($planets, static fn(array $planet): bool => (int)$planet['planet_type'] === ObjectType::PLANET->value));
        $limit = self::planetLimit($account);
        if ($count >= $limit) {
            return "Достигнут лимит планет ({$count}/{$limit}). Необходимо повысить астрофизику.";
        }
        return null;
    }

    /** Состав возврата без мутации Fleet и его dirty flags. */
    public static function returnShips(Fleet $fleet): array
    {
        $result = [];
        foreach ($fleet->getShips() as $id => $ship) {
            $count = $ship->getCount() - ($id === self::COLONIZER ? 1 : 0);
            if ($count > 0) {
                $result[$id] = new FleetShip($id, $ship->getShipTypeName(), $count,
                    $ship->getDamage(), $ship->getExperience(), $ship->getMorale(), $ship->getStatus());
            }
        }
        return $result;
    }

    public static function execute(array $command, Fleet $fleet, AccountData $account, array $params): array
    {
        $target = Coordinate::fromArray($params['target'] ?? []);
        $current = $fleet->getCurrentCoordinate();
        $orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [$target->getGalaxy(), $target->getSystem(), $target->getOrbit()]);
        if ($target->getObjectType() !== ObjectType::PLANET || $orbit === null
            || $current->getType() !== CoordinateType::POINT
            || $current->getGalaxy() !== $target->getGalaxy() || $current->getSystem() !== $target->getSystem()
            || abs($current->getDistance() - (float)$orbit['distance']) > 0.000001) {
            throw new \LogicException('COLONIZE выполняется только после прибытия на выбранную орбиту.');
        }
        $commandSnapshot = FleetCommands::getCommand((int)$command['id']);
        if ($commandSnapshot === null || (int)$commandSnapshot['executed'] !== 0
            || (int)$commandSnapshot['fleet_id'] !== $fleet->getId()) {
            throw new \LogicException('Некорректное состояние команды колонизации.');
        }

        $reason = self::availabilityReason($fleet, $account, $target);
        if ($reason !== null) {
            $result = ['coordinate' => $target->toArray(), 'success' => false, 'reason' => $reason];
            self::finishCommand($commandSnapshot, $params, $result);
            return $result;
        }

        // Prepare: физика существующего генератора, cargo и остаток вместимости.
        $system = Galaxy::getSystem($target->getGalaxy(), $target->getSystem());
        $time = $fleet->getCurrentPositionTime();
        $planet = GalaxyGenerator::generatePlanet((string)$system['star_type'], (int)$orbit['distance'],
            false, (int)$system['star_size'], (int)$system['rotation']);
        $planet = array_replace($planet, [
            'owner_id' => $fleet->getOwnerId(),
            'name' => CelestialNameGenerator::planetName(
                $target->getGalaxy(), $target->getSystem(), $target->getOrbit(),
                array_column(Planets::findByIndex('galaxy_system', [$target->getGalaxy(), $target->getSystem()]) ?? [], 'name')
            ),
            'name_generated' => 1,
            'galaxy' => $target->getGalaxy(), 'system' => $target->getSystem(), 'planet' => $target->getOrbit(),
            'planet_type' => ObjectType::PLANET->value, 'distance' => (int)$orbit['distance'],
            'deg' => $current->getAngle(), 'create_time' => (int)$time, 'update_time' => $time,
        ]);
        $cargo = [];
        foreach ([901, 902, 903] as $id) $cargo[$id] = $fleet->getResources()[$id]->getCount();
        $ship = $fleet->getShips()[self::COLONIZER];
        $oldCount = $ship->getCount();
        $oldFuel = $fleet->getFuelCurrent();
        $returnCapacity = array_sum(array_map(static fn(FleetShip $ship): float => $ship->getCapacityAll(), self::returnShips($fleet)));
        $keepFuel = min($oldFuel, $returnCapacity);
        $depositedFuel = $oldFuel - $keepFuel;
        $planetId = null;
        $planetAttempted = false;
        $createdRepositories = [];

        try {
            // ID выделяется до записи, чтобы rollback знал точный target даже при set(false).
            $planet = Planets::castRowToSchema($planet, true);
            $planetId = (int)$planet['id'];
            if (Planets::findExistingById($planetId) !== null) throw new \LogicException('ID новой планеты уже занят.');
            $planetAttempted = true;
            Planets::add($planet);
            self::assertRow(Planets::class, $planetId, $planet);
            foreach ([Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $repo) {
                if ($repo::findExistingById($planetId) !== null) throw new \LogicException('Служебные данные для ID новой планеты уже существуют.');
                $row = $repo::castRowToSchema(['id' => $planetId], true);
                if ($repo === PlanetResources::class) {
                    foreach ($cargo as $id => $count) $row[Vars::$resource[$id]] += $count;
                    $row[Vars::$resource[903]] += $depositedFuel;
                }
                $createdRepositories[$repo] = $row;
                $repo::add($row);
                self::assertRow($repo, $planetId, $row);
            }
            GalaxyOrbits::update(['id' => (int)$orbit['id'], 'type' => GalaxyOrbits::PLANET]);
            self::assertRow(GalaxyOrbits::class, (int)$orbit['id'], ['type' => GalaxyOrbits::PLANET]);

            // Сначала выгрузка, затем уменьшение вместимости: никакого обрезания cargo.
            foreach ($cargo as $id => $count) $fleet->getResources()[$id]->setCount(0.0);
            $fleet->setFuelCurrent($keepFuel);
            $ship->setCount($oldCount - 1);
            $fleet->setCurrentCoordinate($target, $time);
            $fleet->save();
            $fleetRow = ['fuel_current' => $keepFuel];
            foreach ($cargo as $id => $count) $fleetRow['resource_' . Vars::$resource[$id]] = 0.0;
            self::assertRow(Fleets::class, $fleet->getId(), $fleetRow);
            self::assertRow(FleetsShips::class, $fleet->getId(), [Vars::$resource[self::COLONIZER] . '_count' => $oldCount - 1]);
            $result = ['coordinate' => $target->toArray(), 'success' => true, 'planet' => $planet,
                'resources' => $cargo, 'depositedFuel' => $depositedFuel, 'consumed' => $fleet->getTotalShips() === 0];
            self::finishCommand($commandSnapshot, $params, $result);
            return $result;
        } catch (\Throwable $exception) {
            try {
                $ship->setCount($oldCount);
                foreach ($cargo as $id => $count) $fleet->getResources()[$id]->setCount($count);
                $fleet->setFuelCurrent($oldFuel);
                $fleet->setCurrentCoordinate($current, $time);
                $fleet->save();
                $restore = ['fuel_current' => $oldFuel];
                foreach ($cargo as $id => $count) $restore['resource_' . Vars::$resource[$id]] = $count;
                self::assertRow(Fleets::class, $fleet->getId(), $restore);
                self::assertRow(FleetsShips::class, $fleet->getId(), [Vars::$resource[self::COLONIZER] . '_count' => $oldCount]);
                foreach ($createdRepositories as $repo => $row) {
                    $repo::discardUncommittedRow($row);
                    if ($repo::findExistingById($planetId) !== null) throw new \RuntimeException('Не удалось удалить runtime-строку новой колонии.');
                }
                if ($planetAttempted) {
                    // Даже если add() не сохранил row, удаление снимает созданные им индексы.
                    Planets::discardUncommittedRow($planet);
                    if (Planets::findExistingById($planetId) !== null) throw new \RuntimeException('Не удалось удалить новую планету.');
                }
                GalaxyOrbits::update($orbit);
                self::assertRow(GalaxyOrbits::class, (int)$orbit['id'], $orbit);
                FleetCommands::update($commandSnapshot);
                self::assertRow(FleetCommands::class, (int)$command['id'], ['executed' => 0]);
            } catch (\Throwable $rollback) {
                Logger::getInstance()->error('CRITICAL: rollback COLONIZE завершился ошибкой', [
                    'fleetId' => $fleet->getId(), 'message' => $rollback->getMessage(), 'originalMessage' => $exception->getMessage(),
                ]);
            }
            Logger::getInstance()->error('Колонизация не применена', ['fleetId' => $fleet->getId(), 'message' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private static function finishCommand(array $command, array $params, array $result): void
    {
        // params ограничен 512 байтами в runtime: физические данные планеты,
        // груз и текст отчёта остаются в результате для Notification, не в команде.
        $params['colonyResult'] = ['success' => $result['success'], 'planetId' => $result['planet']['id'] ?? null];
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        FleetCommands::update(['id' => (int)$command['id'], 'params' => $json]);
        self::assertRow(FleetCommands::class, (int)$command['id'], ['params' => $json]);
        FleetCommands::markExecuted((int)$command['id']);
        self::assertRow(FleetCommands::class, (int)$command['id'], ['executed' => 1]);
    }

    private static function assertRow(string $repo, int $id, array $expected): void
    {
        $row = $repo::findExistingById($id);
        if ($row === null) throw new \RuntimeException("Не удалось сохранить {$repo} #{$id} в runtime.");
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $row) || (is_numeric($value)
                ? abs((float)$row[$key] - (float)$value) > 0.000001 : $row[$key] !== $value)) {
                throw new \RuntimeException("Runtime {$repo} #{$id}: поле {$key} не сохранено.");
            }
        }
    }
}
