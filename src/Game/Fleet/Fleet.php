<?php

declare(strict_types=1);

namespace SPGame\Game\Fleet;

use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Services\FleetsData;
use SPGame\Game\Services\AccountData;

final class Fleet
{
	private const VALID_STATUSES = [0, 1, 2, 3, 4];

	private int $id;

	private array $rawData;
	private array $rawDataShips;

	private array $ships = [];

	private bool $dirty = false;
	private bool $shipsDirty = false;

	private ?FleetPosition $startPosition = null;
	private ?FleetPosition $currentPosition = null;
	private ?FleetPosition $endPosition = null;

	private array $resources = [];
	public function __construct(int $id)
	{
		$this->rawData = Fleets::getById($id);
		if ($this->rawData === null) {
			throw new \InvalidArgumentException("Флот #{$id} не найден.");
		}
		$this->rawDataShips = FleetsShips::findById($id);

		$this->id = $id;


		$this->loadShips();

		$ressIDs = array_merge(Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][3]);
		foreach ($ressIDs as $ResID) {
			$resourceName = Vars::$resource[$ResID];
			$this->resources[$ResID] = new FleetResource(
				ID: $ResID,
				name: $resourceName,
				count: $this->rawData["resource_" . Vars::$resource[$ResID]],
				onChange: function (string $field, float $value) {
					$this->changeResources($field, $value);
				},
				maxCountProvider: function () use ($ResID): float {
					return max(
						0,
						$this->getFreeCapacity() + $this->resources[$ResID]->getCount()
					);
				}
			);
		}
	}

	public function __destruct()
	{
		$this->destroy();
	}
	/* Загрузка кораблей флота */
	private function loadShips(): void
	{
		$this->ships = [];

		foreach (Vars::$reslist['fleet'] as $ResID) {
			$name = Vars::$resource[$ResID];

			if (
				!isset($this->rawDataShips["{$name}_count"]) ||
				$this->rawDataShips["{$name}_count"] === 0
			) {
				continue;
			}
			$this->createShip($ResID, $name);
		}
	}
	/* Довавить новый корабль в флот */
	public function createShip(int $ResID, string $name)
	{
		$ship = new FleetShip(
			shipTypeId: $ResID,
			shipTypeName: $name,
			count: (int)$this->rawDataShips["{$name}_count"],
			damage: (float)$this->rawDataShips["{$name}_damage"],
			experience: (float)($this->rawDataShips["{$name}_experience"] ?? 0.0),
			morale: (float)($this->rawDataShips["{$name}_morale"] ?? 1.0),
			status: (int)($this->rawDataShips["{$name}_status"] ?? 0),
			onChange: function (string $field, int|float $value) use ($name) {
				$this->markShipsDirty($name, $field, $value);
			}
		);
		$this->ships[$ResID] = $ship;
	}
	/* Добавить корабли в флот */
	public function addShip(int $shipTypeId, int $count): FleetShip
	{
		$ship = $this->ships[$shipTypeId] ?? null;
		if ($ship === null) {
			$this->createShip($shipTypeId, Vars::$resource[$shipTypeId]);
			$ship = $this->ships[$shipTypeId];
		}
		$ship->addCount($count);
		$this->markShipsDirty(Vars::$resource[$shipTypeId], 'count', $ship->getCount());
		return $ship;
	}
	/* Уничтожить флот */
	public function destroy(): void
	{
		FleetsData::getInstance()->remove($this->id);
	}
	/* Изменить количество ресурсов флота */
	public function changeResources(string $field, float $value): void
	{
		$this->dirty = true;
		$this->rawData[$field] = $value;
		FleetsData::getInstance()->markDirty($this->id);

		/*$this->usedCapacity = 0;
		foreach ($this->resources as $resource) {
			$this->usedCapacity += $resource->getCount();
		}*/
	}
	/* Пометить флот как изменённый */
	public function markDirty(): void
	{
		$this->dirty = true;
		FleetsData::getInstance()->markDirty($this->id);
	}

	/* Пометить позицию флота как изменённую */
	public function markPositionDirty(string $typePosition, string $field, int|float $value): void
	{
		$this->dirty = true;
		$this->rawData["{$typePosition}_{$field}"] = $value;
		FleetsData::getInstance()->markDirty($this->id);
	}

	/* Пометить корабли флота как изменённые */
	public function markShipsDirty(string $shipTypeName, string $field, int|float $value): void
	{
		$this->shipsDirty = true;
		$this->rawDataShips["{$shipTypeName}_{$field}"] = $value;
		FleetsData::getInstance()->markDirty($this->id);
	}
	/* Проверить, изменён ли флот */
	public function isDirty(): bool
	{
		return $this->dirty || $this->shipsDirty;
	}
	/* Сохранить изменения флота */
	public function save(): void
	{
		if (!$this->isDirty()) {
			return;
		}

		if ($this->dirty) {
			Fleets::update($this->rawData);
			$this->dirty = false;
		}
		if ($this->shipsDirty) {
			FleetsShips::update($this->rawDataShips);
			$this->shipsDirty = false;
		}
	}

	/* Получить ID флота */
	public function getId(): int
	{
		return $this->rawData['id'];
	}
	/* Получить ID владельца флота */
	public function getOwnerId(): int
	{
		return $this->rawData['owner_id'];
	}

	/* Получить ID домашней планеты флота */
	public function getHomePlanetId(): int
	{
		return $this->rawData['home_planet_id'];
	}
	/* Получить ID текущего родителя флота */
	public function getCurrentParentId(): int
	{
		return $this->rawData['current_parent_id'];
	}
	/* Получить тип текущего родителя флота */
	public function getCurrentParentType(): string
	{
		return $this->rawData['current_parent_type'];
	}
	/* Получить расстояние до якоря флота */
	public function getAnchorDistance(): float
	{
		return $this->rawData['anchor_distance'];
	}

	/* Координаты старта флота */
	public function StartPosition(): FleetPosition
	{
		if ($this->startPosition === null) {
			$this->startPosition = new FleetPosition(
				typePosition: 'start',
				galaxy: (int)$this->rawData['start_galaxy'],
				system: (int)$this->rawData['start_system'],
				orbit: (int)$this->rawData['start_planet'],
				objectType: (string)$this->rawData['start_type'],
				distance: (float)$this->rawData['start_distance'],
				deg: (float)$this->rawData['start_deg'],
				type: (string)$this->rawData['start_object_type'],
				onChange: function (string $key, int|float $value): void {
					$this->markPositionDirty('start', $key, $value);
				}
			);
		}
		return $this->startPosition;
	}
	/* Координаты цели */
	public function EndPosition(): FleetPosition
	{
		if ($this->endPosition === null) {
			$this->endPosition = new FleetPosition(
				typePosition: 'end',
				galaxy: (int)$this->rawData['end_galaxy'],
				system: (int)$this->rawData['end_system'],
				orbit: (int)$this->rawData['end_planet'],
				objectType: (string)$this->rawData['end_type'],
				distance: (float)$this->rawData['end_distance'],
				deg: (float)$this->rawData['end_deg'],
				type: (string)$this->rawData['end_object_type'],
				onChange: function (string $key, int|float $value): void {
					$this->markPositionDirty('end', $key, $value);
				}
			);
		}
		return $this->endPosition;
	}
	/* Текущие координаты */
	public function CurrentPosition(): FleetPosition
	{
		if ($this->currentPosition === null) {
			$this->currentPosition = new FleetPosition(
				typePosition: 'current',
				galaxy: (int)$this->rawData['current_galaxy'],
				system: (int)$this->rawData['current_system'],
				orbit: (int)$this->rawData['current_planet'],
				objectType: (string)$this->rawData['current_type'],
				distance: (float)$this->rawData['current_distance'],
				deg: (float)$this->rawData['current_deg'],
				type: (string)$this->rawData['current_object_type'],
				onChange: function (string $key, int|float $value): void {
					$this->markPositionDirty('current', $key, $value);
				}
			);
		}
		return $this->currentPosition;
	}

	// === Тайминги ===
	public function getStartTime(): float
	{
		return $this->rawData['start_time'];
	}
	public function getEndTime(): float
	{
		return $this->rawData['end_time'];
	}
	public function getStayTime(): float
	{
		return $this->rawData['stay_time'];
	}

	// === Состояние и поведение ===
	public function getMission(): int
	{
		return $this->rawData['mission'];
	}
	public function getStatus(): int
	{
		return $this->rawData['status'];
	}
	public function isReturn(): bool
	{
		return $this->rawData['is_return'];
	}
	public function getSpeed(): float
	{
		return $this->rawData['speed'];
	}

	// === Ресурсы и топливо ===
	public function getFuelTotal(): float
	{
		return $this->rawData['fuel_total'];
	}
	public function getFuelCurrent(): float
	{
		return $this->rawData['fuel_current'];
	}

	/* Получить все ресурсы флота */
	public function getResources(): array
	{
		return $this->resources;
	}
	/* Получить конкретный ресурс по имени */
	public function getResource(string $resourceName): ?FleetResource
	{
		return $this->resources[$resourceName] ?? null;
	}
	/* Получить использованную вместимость флота */
	public function getUsedCapacity(): float
	{
		$usedCapacity = 0;
		foreach ($this->resources as $resource) {
			$usedCapacity += $resource->getCount();
		}
		return $usedCapacity;
	}
	/* Получить свободную вместимость флота */
	public function getFreeCapacity(): float
	{
		return $this->getCapacity() - $this->getUsedCapacity() - $this->getFuelCurrent();
	}

	// === Статистика боя / эффективности ===
	public function getExperience(): float
	{
		return $this->rawData['experience'];
	}
	public function getMorale(): float
	{
		return $this->rawData['morale'];
	}
	public function getDamageFactor(): float
	{
		return $this->rawData['damage_factor'];
	}
	public function getEfficiency(): float
	{
		return $this->rawData['efficiency'];
	}

	public function getCreateTime(): float
	{
		return $this->rawData['create_time'];
	}
	public function getUpdateTime(): float
	{
		return $this->rawData['update_time'];
	}

	/* Получить Объект корабля по ID */
	public function getShip(int $shipTypeId): ?FleetShip
	{
		return $this->ships[$shipTypeId] ?? null;
	}
	/* Проверить наличие корабля по ID */
	public function hasShip(int $shipTypeId): bool
	{
		return isset($this->ships[$shipTypeId]);
	}

	/* Получить количество кораблей по ID */
	public function getShipCount(int $shipTypeId): int
	{
		return $this->ships[$shipTypeId]?->getCount() ?? 0;
	}

	/* Получить общее количество кораблей */
	public function getTotalShips(): int
	{
		$total = 0;

		foreach ($this->ships as $ship) {
			$total += $ship->getCount();
		}

		return $total;
	}

	/* Получить все объекты кораблей */
	public function getShips(): array
	{
		return $this->ships;
	}
	/* Получить общую вместимость флота */
	public function getCapacity(): int
	{
		$capacity = 0;

		foreach ($this->ships as $ship) {
			$capacity += $ship->getCapacityAll();
		}

		return $capacity;
	}
	/* Получить общий расход топлива флота */
	public function getTotalFuelConsumption(AccountData $AccountData): float
	{
		$consumption = 0;

		foreach ($this->ships as $ship) {
			$consumption += $ship->getConsumptionAll($AccountData);
		}

		return $consumption;
	}
	/* Получить скорость флота */
	public function getTotalSpeed(AccountData $AccountData): float
	{
		$speedList = [];

		foreach ($this->ships as $ship) {
			$speedList[] = $ship->getSpeed($AccountData);
		}

		return empty($speedList) ? 0 : min($speedList);
	}
}
