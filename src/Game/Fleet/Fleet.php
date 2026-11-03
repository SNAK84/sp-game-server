<?php

declare(strict_types=1);

namespace SPGame\Game\Fleet;

use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Services\FleetsData;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\FleetMissionService;
use SPGame\Game\Services\FleetExperienceService;

use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

final class Fleet
{
	public const STATUS_IDLE = 0;
	public const STATUS_MISSION = 1;

	private const VALID_STATUSES = [0, 1, 2, 3, 4];

	private int $id;

	private array $rawData;
	private array $rawDataShips;

	private array $ships = [];

	private bool $dirty = false;
	private bool $shipsDirty = false;

	private ?Coordinate $startCoordinate = null;
	private bool $startCoordinateLoaded = false;
	private ?Coordinate $currentCoordinate = null;
	private ?Coordinate $targetCoordinate = null;
	private bool $targetCoordinateLoaded = false;

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

		$this->loadResources();
	}

	private function loadResources(): void
	{
		$this->resources = [];
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
					if ($ResID === 921) return PHP_FLOAT_MAX; // Кредиты не занимают трюм.
					return max(0, $this->getFreeCapacity($ResID));

					/*return max(
						0,
						$this->getFreeCapacity() + $this->resources[$ResID]->getCount()
					);*/
				}
			);
		}
	}

	/** Обновить тот же объект после проверенного runtime commit. */
	public function reloadFromRuntime(): void
	{
		$row = Fleets::getById($this->id);
		$ships = FleetsShips::findExistingById($this->id);
		if ($row === null || $ships === null) throw new \LogicException('Нельзя обновить отсутствующий Fleet.');
		$this->rawData = $row;
		$this->rawDataShips = $ships;
		$this->startCoordinate = $this->currentCoordinate = $this->targetCoordinate = null;
		$this->startCoordinateLoaded = $this->targetCoordinateLoaded = false;
		$this->loadShips();
		$this->loadResources();
		$this->dirty = $this->shipsDirty = false;
	}

	// Освобождение PHP-объекта не удаляет игровую сущность. Удаление — только
	// явный destroy()/FleetsData::remove() из игровой операции.
	/** Read-only preview другого состава без мутации runtime и dirty-маркеров. */
	public function projectShips(array $ships): self
	{
		if (($ships['id'] ?? null) !== $this->getId() || ($ships['owner_id'] ?? null) !== $this->getOwnerId()) {
			throw new \LogicException('Состав другого флота в preview.');
		}
		$preview = clone $this;
		$preview->rawDataShips = $ships;
		$preview->loadShips();
		$preview->loadResources();
		$preview->dirty = $preview->shipsDirty = false;
		return $preview;
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
		$ship->setCount($ship->getCount() + $count);
		$this->markShipsDirty(Vars::$resource[$shipTypeId], 'count', $ship->getCount());
		return $ship;
	}
	/* Установить количество кораблей в флоте */
	public function setShipCount(int $shipTypeId, int $count): FleetShip
	{
		$ship = $this->ships[$shipTypeId] ?? null;
		if ($ship === null) {
			$this->createShip($shipTypeId, Vars::$resource[$shipTypeId]);
			$ship = $this->ships[$shipTypeId];
		}
		$ship->setCount($count);
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

		if ($this->shipsDirty) {
			$experience = $this->getExperience();
			if ($this->rawData['experience'] !== $experience) {
				$this->rawData['experience'] = $experience;
				$this->dirty = true;
			}
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

	public function getName(): string
	{
		return (string)($this->rawData['name'] ?? '');
	}

	public static function validateName(mixed $name): string
	{
		if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
			throw new \InvalidArgumentException('Некорректное название флота.');
		}
		$name = trim($name);
		if ($name !== '' && (mb_strlen($name) < 2 || mb_strlen($name) > 32
			|| !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]*[\p{L}\p{N}]$/u', $name))) {
			throw new \InvalidArgumentException('Название: 2–32 символа, буквы, цифры, пробел, точка, дефис или подчёркивание. По краям — буквы или цифры.');
		}
		return $name;
	}

	public function setName(string $name): void
	{
		$this->rawData['name'] = self::validateName($name);
		$this->markDirty();
	}
	/* Получить ID владельца флота */
	public function getOwnerId(): int
	{
		return $this->rawData['owner_id'];
	}

	/* ID планетарной базы; для ресурсной базы равен0, не является Planet ID. */
	public function getHomePlanetId(): int
	{
		return $this->rawData['home_planet_id'];
	}
	public function setHomePlanetId(int $planetId): void
	{
		if ($planetId <= 0) {
			throw new \InvalidArgumentException('ID домашней планеты Fleet должен быть положительным.');
		}

		$this->rawData['home_planet_id'] = $planetId;
		// До полного restart мастер ещё может держать прежнюю схему Tables.
		// Старые планетарные операции не пишут отсутствующие в ней колонки.
		if (array_key_exists('home_site_id', $this->rawData)) {
			$this->rawData['home_site_id'] = 0;
			$this->rawData['fallback_planet_id'] = $planetId;
		}
		$this->setBaseFuelState(null);
		$this->markDirty();
	}

	public function getHomeSiteId(): int
	{
		return (int)($this->rawData['home_site_id'] ?? 0);
	}

	public function getFallbackPlanetId(): int
	{
		$id = (int)($this->rawData['fallback_planet_id'] ?? 0);
		return $id > 0 ? $id : $this->getHomePlanetId();
	}

	/** Владение, прибытие и лимиты проверяет ResourceBaseService. */
	public function setHomeResourceSite(int $siteId, int $fallbackPlanetId): void
	{
		if ($siteId <= 0 || $fallbackPlanetId <= 0) {
			throw new \InvalidArgumentException('Для ресурсной базы нужны положительные siteId и ID резервной планеты/луны.');
		}
		if (!array_key_exists('home_site_id', $this->rawData)
			|| !array_key_exists('fallback_planet_id', $this->rawData)) {
			throw new \LogicException('Схема ресурсных баз ещё не включена: нужен полный restart сервера.');
		}
		$this->rawData['home_planet_id'] = 0;
		$this->rawData['home_site_id'] = $siteId;
		$this->rawData['fallback_planet_id'] = $fallbackPlanetId;
		$this->markDirty();
	}
	public function getBaseFuelState(): ?array
	{
		return (float)($this->rawData['base_fuel_time'] ?? 0) > 0 ? [
			'time' => (float)$this->rawData['base_fuel_time'],
			'rate' => (float)$this->rawData['base_fuel_rate'],
			'reserve' => (float)$this->rawData['base_fuel_reserve'],
			'speed' => (float)$this->rawData['base_fuel_speed'],
		] : null;
	}

	public function setBaseFuelState(?array $state): void
	{
		if ($state !== null && (!isset($state['speed']) || !is_numeric($state['speed'])
			|| !is_finite((float)$state['speed']) || $state['speed'] < 1 || $state['speed'] > 10)) {
			throw new \InvalidArgumentException('Некорректная скорость эвакуации ресурсной базы.');
		}
		foreach (['time', 'rate', 'reserve', 'speed'] as $key) {
			if ($state !== null && (!isset($state[$key]) || !is_numeric($state[$key])
				|| !is_finite((float)$state[$key]) || (float)$state[$key] < 0)) {
				throw new \InvalidArgumentException('Некорректный checkpoint топлива ресурсной базы.');
			}
			if (!array_key_exists('base_fuel_' . $key, $this->rawData)) {
				if ($state === null) return; // Совместимость прежней планетарной схемы.
				throw new \LogicException('Схема расхода стоянки ещё не установлена.');
			}
		}
		foreach (['time', 'rate', 'reserve', 'speed'] as $key) $this->rawData['base_fuel_' . $key] = (float)($state[$key] ?? 0);
		$this->markDirty();
	}

	public function getStartCoordinate(): ?Coordinate
	{
		if (!$this->startCoordinateLoaded) {
			$this->startCoordinate = $this->readCoordinate('start', allowNull: true, allowFleet: false);
			$this->startCoordinateLoaded = true;
		}

		return $this->startCoordinate;
	}

	public function setStartCoordinate(?Coordinate $coordinate): void
	{
		if ($coordinate?->getType() === CoordinateType::FLEET) {
			throw new \InvalidArgumentException('Собственный startCoordinate не может иметь тип FLEET.');
		}
		$this->assertStationaryObjectAngle($coordinate, 'startCoordinate');

		$this->startCoordinate = $coordinate;
		$this->startCoordinateLoaded = true;
		$this->writeCoordinate('start', $coordinate, allowFleet: false);
		$this->markDirty();
	}

	public function getCurrentCoordinate(): Coordinate
	{
		if ($this->currentCoordinate === null) {
			$coordinate = $this->readCoordinate('current', allowNull: false, allowFleet: false);
			if ($coordinate === null) {
				throw new \LogicException('У Fleet отсутствует обязательный currentCoordinate.');
			}
			$this->currentCoordinate = $coordinate;
		}

		return $this->currentCoordinate;
	}

	public function getCurrentPositionTime(): float
	{
		$positionTime = (float)$this->rawData['current_position_time'];
		$this->assertTimestamp($positionTime, 'current_position_time');
		return $positionTime;
	}

	public function setCurrentCoordinate(Coordinate $coordinate, float $positionTime): void
	{
		$this->assertTimestamp($positionTime, 'current_position_time');
		if ($coordinate->getType() === CoordinateType::FLEET) {
			throw new \InvalidArgumentException('Собственный currentCoordinate не может иметь тип FLEET.');
		}
		$this->assertStationaryObjectAngle($coordinate, 'currentCoordinate');

		$this->currentCoordinate = $coordinate;
		$this->writeCoordinate('current', $coordinate, allowFleet: false);
		$this->rawData['current_position_time'] = $positionTime;
		$this->markDirty();
	}

	public function getTargetCoordinate(): ?Coordinate
	{
		if (!$this->targetCoordinateLoaded) {
			$this->targetCoordinate = $this->readCoordinate('target', allowNull: true, allowFleet: true);
			$this->targetCoordinateLoaded = true;
		}

		return $this->targetCoordinate;
	}

	public function getTargetSnapshotTime(): ?float
	{
		$target = $this->getTargetCoordinate();
		if ($target?->getType() !== CoordinateType::FLEET) {
			return null;
		}

		$snapshotTime = (float)$this->rawData['target_snapshot_time'];
		$this->assertTimestamp($snapshotTime, 'target_snapshot_time');
		return $snapshotTime;
	}

	public function setTargetCoordinate(?Coordinate $coordinate, ?float $targetSnapshotTime = null): void
	{
		if ($coordinate?->getType() === CoordinateType::FLEET) {
			if ($targetSnapshotTime === null) {
				throw new \InvalidArgumentException('Для target FLEET требуется target_snapshot_time.');
			}
			$this->assertTimestamp($targetSnapshotTime, 'target_snapshot_time');
		} elseif ($targetSnapshotTime !== null) {
			throw new \InvalidArgumentException('target_snapshot_time допустим только для target FLEET.');
		}
		$this->assertStationaryObjectAngle($coordinate, 'targetCoordinate');

		$this->targetCoordinate = $coordinate;
		$this->targetCoordinateLoaded = true;
		$this->writeCoordinate('target', $coordinate, allowFleet: true);
		$this->rawData['target_snapshot_time'] = $targetSnapshotTime ?? 0.0;
		$this->markDirty();
	}

	private function readCoordinate(string $prefix, bool $allowNull, bool $allowFleet): ?Coordinate
	{
		$typeValue = (int)($this->rawData["{$prefix}_coordinate_type"] ?? 0);
		if ($typeValue === 0) {
			if ($allowNull) {
				return null;
			}
			throw new \LogicException("У Fleet отсутствует обязательная {$prefix}Coordinate.");
		}

		try {
			$type = CoordinateType::from($typeValue);
		} catch (\ValueError $exception) {
			throw new \LogicException("Неизвестный тип {$prefix}Coordinate: {$typeValue}.", 0, $exception);
		}

		if ($type === CoordinateType::FLEET) {
			if (!$allowFleet) {
				throw new \LogicException("{$prefix}Coordinate Fleet не может иметь тип FLEET.");
			}

			$lastKnownPosition = Coordinate::point(
				galaxy: (int)$this->rawData["{$prefix}_galaxy"],
				system: (int)$this->rawData["{$prefix}_system"],
				distance: (float)$this->rawData["{$prefix}_distance"],
				angle: (float)$this->rawData["{$prefix}_angle"]
			);

			return Coordinate::fleet(
				fleetId: (int)$this->rawData["{$prefix}_fleet_id"],
				lastKnownPosition: $lastKnownPosition
			);
		}

		$objectTypeValue = (int)($this->rawData["{$prefix}_object_type"] ?? 0);
		$objectType = $type === CoordinateType::OBJECT && $objectTypeValue > 0
			? ObjectType::from($objectTypeValue)
			: null;

		return match ($type) {
			CoordinateType::OBJECT => Coordinate::object(
				galaxy: (int)$this->rawData["{$prefix}_galaxy"],
				system: (int)$this->rawData["{$prefix}_system"],
				orbit: (int)$this->rawData["{$prefix}_orbit"],
				objectType: $objectType,
				siteId: (int)($this->rawData["{$prefix}_site_id"] ?? 0) ?: null,
				angle: $objectType === null
					? (float)$this->rawData["{$prefix}_angle"]
					: null
			),
			CoordinateType::POINT => Coordinate::point(
				galaxy: (int)$this->rawData["{$prefix}_galaxy"],
				system: (int)$this->rawData["{$prefix}_system"],
				distance: (float)$this->rawData["{$prefix}_distance"],
				angle: (float)$this->rawData["{$prefix}_angle"]
			),
			CoordinateType::FLEET => throw new \LogicException('Недопустимый тип координаты.'),
		};
	}

	private function writeCoordinate(string $prefix, ?Coordinate $coordinate, bool $allowFleet): void
	{
		$this->rawData["{$prefix}_coordinate_type"] = $coordinate?->getType()->value ?? 0;
		if ($prefix === 'target') {
			$this->rawData['target_fleet_id'] = 0;
		}
		$this->rawData["{$prefix}_galaxy"] = 0;
		$this->rawData["{$prefix}_system"] = 0;
		$this->rawData["{$prefix}_orbit"] = 0;
		$this->rawData["{$prefix}_object_type"] = 0;
		$this->rawData["{$prefix}_site_id"] = 0;
		$this->rawData["{$prefix}_distance"] = 0.0;
		$this->rawData["{$prefix}_angle"] = 0.0;

		if ($coordinate === null) {
			return;
		}
		if (!$allowFleet && $coordinate->getType() === CoordinateType::FLEET) {
			throw new \InvalidArgumentException("{$prefix}Coordinate не может иметь тип FLEET.");
		}

		if ($coordinate->getType() === CoordinateType::FLEET) {
			$lastKnownPosition = $coordinate->getLastKnownPosition();
			if ($lastKnownPosition === null || $lastKnownPosition->getType() !== CoordinateType::POINT) {
				throw new \LogicException('FLEET coordinate должна содержать lastKnownPosition POINT.');
			}
			$this->rawData['target_fleet_id'] = $coordinate->getFleetId();
			$this->rawData["{$prefix}_galaxy"] = $lastKnownPosition->getGalaxy();
			$this->rawData["{$prefix}_system"] = $lastKnownPosition->getSystem();
			$this->rawData["{$prefix}_distance"] = $lastKnownPosition->getDistance();
			$this->rawData["{$prefix}_angle"] = $lastKnownPosition->getAngle();
			return;
		}

		$this->rawData["{$prefix}_galaxy"] = $coordinate->getGalaxy();
		$this->rawData["{$prefix}_system"] = $coordinate->getSystem();
		$this->rawData["{$prefix}_orbit"] = $coordinate->getOrbit() ?? 0;
		$this->rawData["{$prefix}_object_type"] = $coordinate->getObjectType()?->value ?? 0;
		$this->rawData["{$prefix}_site_id"] = $coordinate->getSiteId() ?? 0;
		$this->rawData["{$prefix}_distance"] = $coordinate->getDistance() ?? 0.0;
		$this->rawData["{$prefix}_angle"] = $coordinate->getAngle() ?? 0.0;
	}

	private function assertTimestamp(float $timestamp, string $field): void
	{
		if (!is_finite($timestamp) || $timestamp < 0) {
			throw new \InvalidArgumentException("{$field} должен быть конечным Unix timestamp.");
		}
	}

	private function assertStationaryObjectAngle(?Coordinate $coordinate, string $field): void
	{
		if (
			$coordinate?->getType() === CoordinateType::OBJECT
			&& $coordinate->getObjectType() === null
			&& $coordinate->getAngle() === null
		) {
			throw new \InvalidArgumentException("{$field}: для неподвижного OBJECT требуется angle Fleet.");
		}
	}
	// === Состояние и поведение ===
	public function getMission(): int
	{
		return $this->rawData['mission'];
	}
	public function setMission(int|FleetMissionType $mission): void
	{
		$missionId = $mission instanceof FleetMissionType ? $mission->value : $mission;
		if ($missionId < 0 || ($missionId > 0 && FleetMissionType::tryFrom($missionId) === null)) {
			throw new \InvalidArgumentException("Неизвестная миссия Fleet: {$missionId}.");
		}

		$this->rawData['mission'] = $missionId;
		$this->markDirty();
	}
	public function getStatus(): int
	{
		return $this->rawData['status'];
	}
	public function setStatus(int $status): void
	{
		if (!in_array($status, self::VALID_STATUSES, true)) {
			throw new \InvalidArgumentException("Неизвестный статус Fleet: {$status}.");
		}

		$this->rawData['status'] = $status;
		$this->markDirty();
	}
	public function isReturn(): bool
	{
		return (bool)$this->rawData['is_return'];
	}
	public function setReturn(bool $isReturn): void
	{
		$this->rawData['is_return'] = $isReturn ? 1 : 0;
		$this->markDirty();
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
	public function setFuelTotal(float $fuel): void
	{
		if (!is_finite($fuel) || $fuel < 0.0) {
			throw new \InvalidArgumentException('Общий запас топлива должен быть конечным неотрицательным числом.');
		}

		$this->rawData['fuel_total'] = $fuel;
		$this->markDirty();
	}
	public function setFuelCurrent(float $fuel): void
	{
		if (!is_finite($fuel) || $fuel < 0.0) {
			throw new \InvalidArgumentException('Текущий запас топлива должен быть конечным неотрицательным числом.');
		}
		if ($fuel > $this->getFuelTotal()) {
			throw new \InvalidArgumentException('Текущий запас топлива не может превышать общий запас.');
		}

		$this->rawData['fuel_current'] = $fuel;
		$this->markDirty();
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
			if ($resource->getID() === 921) continue;
			$usedCapacity += $resource->getCount();
		}
		return $usedCapacity;
	}
	/* Получить свободную вместимость флота */
	public function getFreeCapacity(?int $excludeResourceId = null): float
	{
		$usedCapacity = 0;

		foreach ($this->resources as $resourceId => $resource) {
			if ($resourceId === $excludeResourceId || $resourceId === 921) {
				continue;
			}

			$usedCapacity += $resource->getCount();
		}

		return $this->getCapacity()
			- $usedCapacity
			- $this->getFuelCurrent();
	}

	// === Статистика боя / эффективности ===
	public function getExperience(): float
	{
		$hasExperience = false;
		$groups = [];
		foreach ($this->ships as $id => $ship) {
			$hasExperience = $hasExperience || ($ship->getCount() > 0 && $ship->getExperience() > 0.0);
			$groups[] = [
				'count' => $ship->getCount(),
				'experience' => $ship->getExperience(),
				'unitCost' => (float)(Vars::$pricelist[$id][901] ?? 0.0) + (float)(Vars::$pricelist[$id][902] ?? 0.0),
			];
		}
		// У нового/неопытного флота показатель равен нулю независимо от кэша.
		return $hasExperience ? FleetExperienceService::fleetAverage($groups) : 0.0;
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
	public function getCapacity(): float
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
			if ($ship->getCount() <= 0) continue;
			$speedList[] = $ship->getSpeed($AccountData);
		}

		return empty($speedList) ? 0 : min($speedList);
	}

	public function getAvailableMissions(FleetTarget $target, AccountData $accountData): array
	{
		return (new FleetMissionService())->getAvailableMissions($this, $target, $accountData);
	}

	public function getTravelInfo(
		FleetTarget $target,
		AccountData $accountData,
		?float $speedFactor = null,
		?\SPGame\Game\Enums\FleetMissionType $mission = null
	): ?array
	{
		return (new FleetMissionService())->getTravelInfo($this, $target, $accountData, $speedFactor, $mission);
	}
}
