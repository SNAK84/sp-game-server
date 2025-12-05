<?php

namespace SPGame\Game\ValueObjects;

/**
 * Координата объекта внутри звёздной системы
 * (использует полярные координаты: radius + angle)
 */
class SystemCoordinate
{
    public int $galaxy;
    public int $system;
    public float $radius;          // Расстояние от звезды
    public float $angle;           // Положение по орбите
    public ?int $orbit;            // Номер орбиты (для планет)
    public string $type;           // planet | moon | debris | base | fleet
    public float $rotationSpeed;   // °/сек (для вращающихся объектов)
    public bool $rotation;         // Направление вращения

    public function __construct(
        int $galaxy,
        int $system,
        float $radius,
        float $angle,
        string $type = 'fleet',
        ?int $orbit = null,
        float $rotationSpeed = 0.0,
        bool $rotation = true
    ) {
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->radius = $radius;
        $this->angle = fmod($angle, 360.0);
        $this->type = $type;
        $this->orbit = $orbit;
        $this->rotationSpeed = $rotationSpeed;
    }

    /** Строковое представление (например "1:23@310/132.5") */
    public function __toString(): string
    {
        return "{$this->galaxy}:{$this->system}@{$this->radius}/{$this->angle}";
    }

    /** Расстояние между двумя точками в одной системе */
    public function distanceTo(SystemCoordinate $other): float
    {
        if ($this->galaxy !== $other->galaxy || $this->system !== $other->system) {
            throw new \LogicException('distanceTo: координаты из разных систем!');
        }

        $r1 = $this->radius;
        $r2 = $other->radius;
        $a1 = deg2rad($this->angle);
        $a2 = deg2rad($other->angle);

        return sqrt(
            ($r1 ** 2) + ($r2 ** 2) - 2 * $r1 * $r2 * cos($a1 - $a2)
        );
    }

    /** Получить актуальный угол с учётом вращения */
    public function getAngleAt(float $currentTime, float $lastUpdateTime): float
    {
        $delta = $currentTime - $lastUpdateTime;
        return fmod($this->angle + $delta * $this->rotationSpeed, 360.0);
    }

    public function toArray(): array
    {
        return [
            'galaxy' => $this->galaxy,
            'system' => $this->system,
            'radius' => $this->radius,
            'angle' => $this->angle,
            'orbit' => $this->orbit,
            'type' => $this->type,
            'rotation_speed' => $this->rotationSpeed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['galaxy'],
            $data['system'],
            $data['radius'],
            $data['angle'],
            $data['type'] ?? 'fleet',
            $data['orbit'] ?? null,
            $data['rotation_speed'] ?? 0.0
        );
    }
}
