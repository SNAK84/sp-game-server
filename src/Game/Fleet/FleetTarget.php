<?php

declare(strict_types=1);

namespace SPGame\Game\Fleet;

use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

final class FleetTarget
{
    private const LEGACY_POINT_TYPE = 4;
    private const LEGACY_FLEET_TYPE = 5;

    private function __construct(
        private CoordinateType $coordinateType,
        private int $galaxy,
        private int $system,
        private int $orbit,
        private int $type,
        private ?float $distance,
        private ?float $deg,
        private ?int $fleetId = null,
        private bool $legacyFormat = false,
        private ?int $siteId = null,
    ) {
    }

    public static function fromCoordinates(array $coordinates): self
    {
        $type = (int)($coordinates['type'] ?? 0);

        if (
            $type === CoordinateType::OBJECT->value
            && (
                array_key_exists('objectType', $coordinates)
                || (!array_key_exists('distance', $coordinates) && !array_key_exists('deg', $coordinates))
            )
        ) {
            return new self(
                coordinateType: CoordinateType::OBJECT,
                galaxy: (int)($coordinates['galaxy'] ?? 0),
                system: (int)($coordinates['system'] ?? 0),
                orbit: (int)($coordinates['orbit'] ?? 0),
                type: (int)($coordinates['objectType'] ?? 0),
                distance: null,
                deg: null,
                siteId: isset($coordinates['siteId']) ? (int)$coordinates['siteId'] : null,
            );
        }

        if (
            $type === CoordinateType::POINT->value
            && (array_key_exists('angle', $coordinates) || !array_key_exists('orbit', $coordinates))
        ) {
            return new self(
                coordinateType: CoordinateType::POINT,
                galaxy: (int)($coordinates['galaxy'] ?? 0),
                system: (int)($coordinates['system'] ?? 0),
                orbit: 0,
                type: self::LEGACY_POINT_TYPE,
                distance: isset($coordinates['distance']) ? (float)$coordinates['distance'] : null,
                deg: isset($coordinates['angle']) ? (float)$coordinates['angle'] : null,
            );
        }

        if ($type === CoordinateType::FLEET->value && array_key_exists('fleetId', $coordinates)) {
            return new self(
                coordinateType: CoordinateType::FLEET,
                galaxy: 0,
                system: 0,
                orbit: 0,
                type: self::LEGACY_FLEET_TYPE,
                distance: null,
                deg: null,
                fleetId: (int)$coordinates['fleetId'],
            );
        }

        // Временная совместимость с внутренними серверными вызовами старого формата.
        $coordinateType = match ($type) {
            self::LEGACY_POINT_TYPE => CoordinateType::POINT,
            self::LEGACY_FLEET_TYPE => CoordinateType::FLEET,
            default => CoordinateType::OBJECT,
        };

        return new self(
            coordinateType: $coordinateType,
            galaxy: (int)($coordinates['galaxy'] ?? 0),
            system: (int)($coordinates['system'] ?? 0),
            orbit: (int)($coordinates['orbit'] ?? 0),
            type: $type,
            distance: isset($coordinates['distance']) ? (float)$coordinates['distance'] : null,
            deg: isset($coordinates['deg']) ? (float)$coordinates['deg'] : null,
            fleetId: isset($coordinates['fleetId']) ? (int)$coordinates['fleetId'] : null,
            legacyFormat: true,
        );
    }

    public function getGalaxy(): int
    {
        return $this->galaxy;
    }

    public function getSystem(): int
    {
        return $this->system;
    }

    public function getOrbit(): int
    {
        return $this->orbit;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getCoordinateType(): CoordinateType
    {
        return $this->coordinateType;
    }

    public function getObjectType(): ?ObjectType
    {
        if ($this->coordinateType !== CoordinateType::OBJECT) {
            return null;
        }

        return ObjectType::tryFrom($this->type);
    }

    public function getDistance(): ?float
    {
        return $this->distance;
    }

    public function getDeg(): ?float
    {
        return $this->deg;
    }

    public function getFleetId(): ?int
    {
        return $this->fleetId;
    }

    public function getSiteId(): ?int { return $this->siteId; }

    public function toCoordinates(): array
    {
        if ($this->legacyFormat) {
            $coordinates = [
                'galaxy' => $this->galaxy,
                'system' => $this->system,
                'orbit' => $this->orbit,
                'type' => $this->type,
            ];
            if ($this->distance !== null) {
                $coordinates['distance'] = $this->distance;
            }
            if ($this->deg !== null) {
                $coordinates['deg'] = $this->deg;
            }
            if ($this->fleetId !== null) {
                $coordinates['fleetId'] = $this->fleetId;
            }
            return $coordinates;
        }

        return match ($this->coordinateType) {
            CoordinateType::OBJECT => array_filter([
                'type' => CoordinateType::OBJECT->value,
                'galaxy' => $this->galaxy,
                'system' => $this->system,
                'orbit' => $this->orbit,
                'objectType' => $this->type > 0 ? $this->type : null,
                'siteId' => $this->siteId,
            ], static fn(mixed $value): bool => $value !== null),
            CoordinateType::POINT => [
                'type' => CoordinateType::POINT->value,
                'galaxy' => $this->galaxy,
                'system' => $this->system,
                'distance' => $this->distance,
                'angle' => $this->deg,
            ],
            CoordinateType::FLEET => [
                'type' => CoordinateType::FLEET->value,
                'fleetId' => $this->fleetId,
            ],
        };
    }
}
