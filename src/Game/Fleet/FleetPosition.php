<?php

declare(strict_types=1);

namespace SPGame\Game\Fleet;

final class FleetPosition
{
    private ?\Closure $onChange;

    public function __construct(
        private string $typePosition,
        private int $galaxy,
        private int $system,
        private int $orbit,
        private string $objectType,
        private float $distance,
        private float $deg,
        private string $type,
        ?callable $onChange = null,
    ) {
        $this->onChange = $onChange !== null ? \Closure::fromCallable($onChange) : null;
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
    public function getObjectType(): string
    {
        return $this->objectType;
    }
    public function getDistance(): float
    {
        return $this->distance;
    }
    public function getDeg(): float
    {
        return $this->deg;
    }
    public function getType(): string
    {
        return $this->type;
    }

    public function setGalaxy(int $galaxy): void
    {
        if ($this->galaxy === $galaxy) return;

        $this->galaxy = $galaxy;
        $this->changed('galaxy', $galaxy);
    }

    public function setSystem(int $system): void
    {
        if ($this->system === $system) return;

        $this->system = $system;
        $this->changed('system', $system);
    }
    public function setOrbit(int $orbit): void
    {
        if ($this->orbit === $orbit) return;

        $this->orbit = $orbit;
        $this->changed('orbit', $orbit);
    }

    public function setObjectType(string $objectType): void
    {
        $this->objectType = $objectType;
        $this->changed('object_type', 0); // Object type is a string, so we pass 0 as a placeholder
    }

    public function setDistance(float $distance): void
    {
        $this->distance = $distance;
        $this->changed('distance', $distance);
    }

    public function setDeg(float $deg): void
    {
        $this->deg = $deg;
        $this->changed('deg', $deg);
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->changed('type', 0); // Type is a string, so we pass 0 as a placeholder
    }

    private function changed(string $key, int|float $value): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this->typePosition . '_' . $key, $value);
        }
    }
}
