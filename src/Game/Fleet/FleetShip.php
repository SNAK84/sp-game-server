<?php

declare(strict_types=1);

namespace SPGame\Game\Fleet;

use SPGame\Game\Repositories\Vars;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\FleetFunctions;

final class FleetShip
{
    private ?\Closure $onChange;

    public function __construct(
        private int $shipTypeId,
        private string $shipTypeName,
        private int $count,
        private float $damage = 0.0,
        private float $experience = 0.0,
        private float $morale = 1.0,
        private int $status = 0,
        ?callable $onChange = null,
    ) {
        $this->onChange = $onChange !== null ? \Closure::fromCallable($onChange) : null;
    }

    /* Возвращает идентификатор типа корабля */
    public function getShipTypeId(): int
    {
        return $this->shipTypeId;
    }
    /* Возвращает название типа корабля */
    public function getShipTypeName(): string
    {
        return $this->shipTypeName;
    }
    /* Возвращает количество кораблей данного типа во флоте */
    public function getCount(): int
    {
        return $this->count;
    }
    /* Возвращает урон корабля */
    public function getDamage(): float
    {
        return $this->damage;
    }
    /* Возвращает опыт корабля */
    public function getExperience(): float
    {
        return $this->experience;
    }
    /* Возвращает мораль корабля */
    public function getMorale(): float
    {
        return $this->morale;
    }
    /* Возвращает статус корабля */
    public function getStatus(): int
    {
        return $this->status;
    }

    /* Устанавливает количество кораблей */
    public function setCount(int $count): void
    {
        $count = max(0, $count);

        if ($this->count === $count) {
            return;
        }

        $this->count = $count;
        $this->changed('count', $this->count);
    }
    /* Устанавливает урон корабля */
    public function setDamage(float $damage): void
    {
        $damage = max(0.0, $damage);
        if ($this->damage === $damage) {
            return;
        }
        $this->damage = $damage;
        $this->changed('damage', $this->damage);
    }
    /* Устанавливает опыт корабля */
    public function setExperience(float $experience): void
    {
        $experience = max(0.0, $experience);
        if ($this->experience === $experience) {
            return;
        }
        $this->experience = $experience;
        $this->changed('experience', $this->experience);
    }
    /* Устанавливает мораль корабля */
    public function setMorale(float $morale): void
    {
        $morale = max(0.0, $morale);
        if ($this->morale === $morale) {
            return;
        }
        $this->morale = $morale;
        $this->changed('morale', $this->morale);
    }

    /* Устанавливает статус корабля */
    public function setStatus(int $status): void
    {
        if (!in_array($status, [0, 1, 2], true)) {
            throw new \InvalidArgumentException("Недопустимый статус корабля: {$status}.");
        }
        if ($this->status === $status) {
            return;
        }
        $this->status = $status;
        $this->changed('status', $this->status);
    }

    private function changed(string $key, int|float $value): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this->shipTypeName . '_' . $key, $value);
        }
    }

    /* Возвращает вместимость одного корабля */
    public function getCapacity(): float
    {
        $shipCapacity = Vars::$attributes[$this->shipTypeId]['capacity'] ?? 0.0;
        return $shipCapacity;
    }

    /* Возвращает общую вместимость всех кораблей */
    public function getCapacityAll(): float
    {
        $shipCapacity = Vars::$attributes[$this->shipTypeId]['capacity'] ?? 0.0;
        return $shipCapacity * $this->count;
    }

    /* Возвращает скорость корабля с учетом технологий */
    public function getSpeed(AccountData $AccountData): float
    {
        $speed = FleetFunctions::GetShipSpeed($this->shipTypeId, $AccountData);
        return $speed;
    }

    /* Возвращает потребление топлива кораблем с учетом технологий */
    public function getConsumption(AccountData $AccountData): float
    {
        return FleetFunctions::GetShipConsumption($this->shipTypeId, $AccountData);
    }
    /* Возвращает потребление топлива всех кораблей */
    public function getConsumptionAll(AccountData $AccountData): float
    {
        return $this->getConsumption($AccountData) * $this->count;
    }
}
