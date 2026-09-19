<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Vars;

final class FleetsData
{
    private static ?self $instance = null;
    /** @var array<int, Fleet> */
    private array $Fleets = [];

    /** @var array<int, bool> */
    private array $FleetsDirty = [];

    public function __construct() {}

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function get(int $fleetId): ?Fleet
    {
        if ($fleetId <= 0) {
            return null;
        }

        if (isset($this->Fleets[$fleetId])) {
            return $this->Fleets[$fleetId];
        }

        if (Fleets::getById($fleetId) === null) {
            return null;
        }

        $fleet = new Fleet($fleetId);

        $this->Fleets[$fleetId] = $fleet;
        $this->FleetsDirty[$fleetId] = false;
        return $fleet;
    }

    /** @return array<int, Fleet> */
    public function getMany(array $ids): array
    {
        $fleets = [];
        foreach ($ids as $id) {
            $fleet = $this->get((int) $id);
            if ($fleet !== null) {
                $fleets[$fleet->getId()] = $fleet;
            }
        }
        return array_values($fleets);
    }

    /** @return array<int, Fleet> */
    public function getForAccount(int $accountId): array
    {
        if ($accountId <= 0) {
            return [];
        }

        $rows = Fleets::findByIndex('owner_id', (string) $accountId) ?? [];
        $fleets = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $fleet = $this->get($id);
            if ($fleet !== null) {
                $fleets[$fleet->getId()] = $fleet;
            }
        }
        return $fleets;
    }

    /** @return array<int, Fleet> */
    public function getForPlanet(int $accountId, int $planetId): array
    {
        $fleets = [];
        foreach (Fleets::findByIndex('owner_id_home_planet_id_status', [$accountId, $planetId, 0]) ?? [] as $row) {
            $fleet = $this->get((int) ($row['id'] ?? 0));
            if ($fleet !== null) {
                $fleets[$fleet->getId()] = $fleet;
            }
        }
        return $fleets;
    }

    public function create(AccountData $AccountData): Fleet
    {

        $Planet = $AccountData['Planet'];
        $Fleet = [
            'owner_id'           => $AccountData['User']['id'],
            'home_planet_id'     => $Planet['id'],
            'current_parent_id'  => $Planet['id'],
            'current_parent_type' => 'planet',
            'anchor_distance'    => 0.0,

            // Координаты старта = координаты планеты
            'start_galaxy'       => $Planet['galaxy'],
            'start_system'       => $Planet['system'],
            'start_orbit'        => $Planet['planet'],
            'start_object_type'  => 'planet',

            // Текущие координаты = координаты планеты
            'current_galaxy'     => $Planet['galaxy'],
            'current_system'     => $Planet['system'],
            'current_distance'   => 0.0,
            'current_deg'        => 0.0,
        ];


        $row = Fleets::castRowToSchema($Fleet, true);
        $fleetId = Fleets::add($row);
        if ($fleetId === null) {
            throw new \RuntimeException('Не удалось создать флот в runtime repository.');
        }

        $shipRow = [
            'id' => $fleetId,
            'fleet_id' => $fleetId,
            'owner_id' => (int) ($row['owner_id'] ?? 0),
        ];
        FleetsShips::add(FleetsShips::castRowToSchema($shipRow, true));

        $fleet = $this->get($fleetId);
        if ($fleet === null) {
            throw new \RuntimeException("Не удалось загрузить созданный флот #{$fleetId}.");
        }
        $this->FleetsDirty[$fleetId] = true;
        return $fleet;
    }

    public function markDirty(int $fleetId): void
    {
        if (isset($this->Fleets[$fleetId])) {
            $this->FleetsDirty[$fleetId] = true;
        }
    }

    public function remove(int $fleetId, bool $deleteFromRepository = true): void
    {
        $fleet = $this->Fleets[$fleetId] ?? null;
        if ($fleet === null) {
            return;
        }
        if ($deleteFromRepository) {
            Fleets::delete(['id' => $fleetId]);
            FleetsShips::delete(['id' => $fleetId]);
        }
        unset($this->Fleets[$fleetId], $this->FleetsDirty[$fleetId]);
    }

    public function isDirty(int $fleetId): bool
    {
        return $this->FleetsDirty[$fleetId] ?? false;
    }

    public function save(): void
    {
        foreach ($this->FleetsDirty as $fleetId => $dirty) {
            if (!$dirty || !isset($this->Fleets[$fleetId])) {
                continue;
            }
            $this->Fleets[$fleetId]->save();
            $this->FleetsDirty[$fleetId] = false;
        }
    }
}
