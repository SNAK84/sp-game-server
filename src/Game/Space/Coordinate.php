<?php

declare(strict_types=1);

namespace SPGame\Game\Space;

use InvalidArgumentException;

/**
 * Неизменяемая координата цели или положения в игровом пространстве.
 *
 * Координата FLEET содержит identity цели и её последнее известное положение.
 * Последнее известное положение ограничено POINT, чтобы исключить рекурсивные
 * ссылки и не смешивать snapshot time с описанием координаты.
 */
final class Coordinate implements \JsonSerializable
{
    private function __construct(
        private readonly CoordinateType $type,
        private readonly ?int $galaxy = null,
        private readonly ?int $system = null,
        private readonly ?int $orbit = null,
        private readonly ?float $distance = null,
        private readonly ?float $angle = null,
        private readonly ?ObjectType $objectType = null,
        private readonly ?int $fleetId = null,
        private readonly ?self $lastKnownPosition = null,
        private readonly ?int $siteId = null,
    ) {
    }

    public static function object(
        int $galaxy,
        int $system,
        int $orbit,
        ?ObjectType $objectType = null,
        ?float $angle = null,
        ?int $siteId = null
    ): self
    {
        self::validateGalaxy($galaxy);
        self::validateSystem($system);
        self::validateOrbit($orbit);
        $resource = in_array($objectType, [ObjectType::GAS_GIANT, ObjectType::ASTEROID_SECTOR], true);
        if ($resource ? ($siteId === null || $siteId < 1) : $siteId !== null) {
            throw new InvalidArgumentException('siteId обязателен только для ресурсного объекта.');
        }
        if ($angle !== null) {
            self::validateAngle($angle);
        }

        return new self(
            type: CoordinateType::OBJECT,
            galaxy: $galaxy,
            system: $system,
            orbit: $orbit,
            angle: $angle,
            objectType: $objectType,
            siteId: $siteId,
        );
    }

    public static function point(int $galaxy, int $system, float $distance, float $angle): self
    {
        self::validateGalaxy($galaxy);
        self::validateSystem($system);
        self::validateDistance($distance);
        self::validateAngle($angle);

        return new self(
            type: CoordinateType::POINT,
            galaxy: $galaxy,
            system: $system,
            distance: $distance,
            angle: $angle,
        );
    }

    public static function fleet(int $fleetId, self $lastKnownPosition): self
    {
        if ($fleetId < 1) {
            throw new InvalidArgumentException('Fleet ID must be greater than 0.');
        }
        if ($lastKnownPosition->type !== CoordinateType::POINT) {
            throw new InvalidArgumentException('Fleet last known position must be a point coordinate.');
        }

        return new self(
            type: CoordinateType::FLEET,
            fleetId: $fleetId,
            lastKnownPosition: $lastKnownPosition,
        );
    }

    /**
     * Восстановить Coordinate из сериализованного command/Fleet payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $typeValue = (int)($data['type'] ?? 0);
        $type = CoordinateType::tryFrom($typeValue);

        if ($type === null) {
            throw new InvalidArgumentException('Неизвестный тип Coordinate.');
        }

        return match ($type) {
            CoordinateType::OBJECT => self::object(
                galaxy: (int)($data['galaxy'] ?? 0),
                system: (int)($data['system'] ?? 0),
                orbit: (int)($data['orbit'] ?? 0),
                objectType: self::readObjectType($data),
                angle: array_key_exists('angle', $data) ? (float)$data['angle'] : null,
                siteId: isset($data['siteId']) ? (int)$data['siteId'] : null
            ),
            CoordinateType::POINT => self::point(
                galaxy: (int)($data['galaxy'] ?? 0),
                system: (int)($data['system'] ?? 0),
                distance: (float)($data['distance'] ?? 0),
                angle: (float)($data['angle'] ?? 0)
            ),
            CoordinateType::FLEET => self::fleet(
                fleetId: (int)($data['fleetId'] ?? 0),
                lastKnownPosition: self::fromArray(
                    is_array($data['lastKnownPosition'] ?? null)
                        ? $data['lastKnownPosition']
                        : []
                )
            ),
        };
    }

    public function getType(): CoordinateType { return $this->type; }
    public function getGalaxy(): ?int { return $this->galaxy; }
    public function getSystem(): ?int { return $this->system; }
    public function getOrbit(): ?int { return $this->orbit; }
    public function getDistance(): ?float { return $this->distance; }
    public function getAngle(): ?float { return $this->angle; }
    public function getObjectType(): ?ObjectType { return $this->objectType; }
    public function getFleetId(): ?int { return $this->fleetId; }
    public function getLastKnownPosition(): ?self { return $this->lastKnownPosition; }
    public function getSiteId(): ?int { return $this->siteId; }

    /**
     * Сохраняет Coordinate внутри универсального params команды.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return match ($this->type) {
            CoordinateType::OBJECT => array_filter([
                'type' => $this->type->value,
                'galaxy' => $this->galaxy,
                'system' => $this->system,
                'orbit' => $this->orbit,
                'objectType' => $this->objectType?->value,
                'angle' => $this->angle,
                'siteId' => $this->siteId,
            ], static fn(mixed $value): bool => $value !== null),
            CoordinateType::POINT => [
                'type' => $this->type->value,
                'galaxy' => $this->galaxy,
                'system' => $this->system,
                'distance' => $this->distance,
                'angle' => $this->angle,
            ],
            CoordinateType::FLEET => [
                'type' => $this->type->value,
                'fleetId' => $this->fleetId,
                'lastKnownPosition' => $this->lastKnownPosition->toArray(),
            ],
        };
    }

    private static function validateGalaxy(int $galaxy): void
    {
        if ($galaxy < 1) throw new InvalidArgumentException('Galaxy must be greater than 0.');
    }

    private static function validateSystem(int $system): void
    {
        if ($system < 1) throw new InvalidArgumentException('System must be greater than 0.');
    }

    private static function validateOrbit(int $orbit): void
    {
        if ($orbit < 1) throw new InvalidArgumentException('Orbit must be greater than 0.');
    }

    private static function validateDistance(float $distance): void
    {
        if (!is_finite($distance) || $distance < 0) {
            throw new InvalidArgumentException('Distance must be a non-negative finite value.');
        }
    }

    private static function validateAngle(float $angle): void
    {
        if (!is_finite($angle) || $angle < 0 || $angle >= 360) {
            throw new InvalidArgumentException('Angle must be in range 0..360.');
        }
    }

    /** @param array<string, mixed> $data */
    private static function readObjectType(array $data): ?ObjectType
    {
        if (!array_key_exists('objectType', $data) || $data['objectType'] === null) {
            return null;
        }

        $value = (int)$data['objectType'];
        if ($value === 0) {
            return null;
        }

        return ObjectType::tryFrom($value)
            ?? throw new InvalidArgumentException('Неизвестный ObjectType.');
    }
}
