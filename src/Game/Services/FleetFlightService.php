<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\ValueObjects\SystemCoordinate;

/**
 * Расчёт расстояния и времени полёта флота.
 *
 * Пространство игры имеет три уровня:
 *  - внутри системы: реальная дистанция по polar coordinates;
 *  - между системами: линейное расстояние по номеру системы;
 *  - между галактиками: линейное расстояние по номеру галактики.
 */
final class FleetFlightService
{
    /** Расстояние между соседними системами. */
    public const SYSTEM_DISTANCE = 100_000.0;

    /** Расстояние между соседними галактиками. */
    public const GALAXY_DISTANCE = 10_000_000.0;

    /** Сколько секунд занимает 1 единица расстояния при speed=1. */
    public const TIME_PER_DISTANCE = 1.0;

    /**
     * Рассчитать расстояние между двумя координатами.
     */
    public function calculateDistance(
        SystemCoordinate $start,
        SystemCoordinate $target
    ): float {
        if (
            $start->galaxy === $target->galaxy &&
            $start->system === $target->system
        ) {
            return $start->distanceTo($target);
        }

        if ($start->galaxy === $target->galaxy) {
            return abs($start->system - $target->system)
                * self::SYSTEM_DISTANCE;
        }

        return abs($start->galaxy - $target->galaxy)
            * self::GALAXY_DISTANCE;
    }

    /**
     * Рассчитать время полёта.
     *
     * Возвращает секунды.
     */
    public function calculateFlightTime(
        float $distance,
        float $speed
    ): float {
        if ($distance <= 0.0) {
            return 0.0;
        }

        if ($speed <= 0.0) {
            throw new \InvalidArgumentException(
                'Скорость флота должна быть больше нуля.'
            );
        }

        return ($distance / $speed) * self::TIME_PER_DISTANCE;
    }

    /**
     * Рассчитать расстояние и время полёта.
     */
    public function calculate(
        SystemCoordinate $start,
        SystemCoordinate $target,
        float $speed
    ): array {
        $distance = $this->calculateDistance($start, $target);

        return [
            'distance' => $distance,
            'duration' => $this->calculateFlightTime($distance, $speed),
            'speed' => $speed,
            'type' => $this->getFlightType($start, $target),
        ];
    }

    /**
     * Определить тип перелёта.
     */
    public function getFlightType(
        SystemCoordinate $start,
        SystemCoordinate $target
    ): string {
        if ($start->galaxy !== $target->galaxy) {
            return 'between_galaxies';
        }

        if ($start->system !== $target->system) {
            return 'between_systems';
        }

        return 'in_system';
    }
}