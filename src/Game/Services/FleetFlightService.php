<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

/**
 * Расчёт расстояния и времени полёта флота.
 *
 * Пространство игры имеет три уровня:
 *  - внутри системы: реальная дистанция по polar coordinates;
 *  - между адресами систем: диагональное расстояние по формуле Nova.
 */
final class FleetFlightService
{
    private const ARRIVAL_MAX_ITERATIONS = 32;
    private const ARRIVAL_TIME_TOLERANCE = 0.000001;

    /** Коэффициенты адресного расстояния из Nova. */
    private const ADDRESS_DISTANCE_STEP = 95.0;
    private const ADDRESS_DISTANCE_BASE = 2700.0;

    /** Минимальное время одного физического MOVE-сегмента, как в Nova. */
    private const MIN_FLIGHT_DURATION = 5.0;

    /** Условная игровая дистанция между объектами одного orbital anchor. */
    private const LOCAL_OBJECT_TRANSFER_DISTANCE = 5.0;

    /** Базовая внутрисистемная дистанция и добавка за одну условную AU из Nova. */
    private const IN_SYSTEM_DISTANCE_BASE = 1000.0;
    private const IN_SYSTEM_DISTANCE_PER_AU = 5.0;

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

        return $this->calculateTransitionDistance(
            $start->galaxy,
            $start->system,
            $target->galaxy,
            $target->system
        );
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

        return $this->calculateGameDuration($distance, $speed);
    }

    /**
     * Преобразовать физическую 2D-дистанцию внутри системы в игровой масштаб
     * Nova. Геометрия остаётся в физических единицах, а время и топливо
     * получают базовые 1000 единиц плюс 5 за каждую условную AU.
     */
    public function calculateInSystemGameDistance(float $physicalDistance): float
    {
        if ($physicalDistance <= 0.0) {
            return 0.0;
        }

        return self::IN_SYSTEM_DISTANCE_BASE
            + self::IN_SYSTEM_DISTANCE_PER_AU
                * ($physicalDistance / GalaxyGenerator::DISTANCE_PER_AU);
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
        $durationDistance = $this->getFlightType($start, $target) === 'in_system'
            ? $this->calculateInSystemGameDistance($distance)
            : $distance;

        return [
            'distance' => $distance,
            'duration' => $this->calculateFlightTime($durationDistance, $speed),
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

    /**
     * Получить игровую дистанцию локального перехода между объектами одной
     * орбиты. Физически PLANET/MOON/DEBRIS имеют общий orbital anchor, поэтому
     * условная дистанция используется только для времени и топлива MOVE.
     */
    public function getLocalObjectTransferDistance(
        Coordinate $start,
        Coordinate $target
    ): ?float {
        if (
            $start->getType() !== CoordinateType::OBJECT
            || $target->getType() !== CoordinateType::OBJECT
            || $start->getGalaxy() !== $target->getGalaxy()
            || $start->getSystem() !== $target->getSystem()
            || $start->getOrbit() !== $target->getOrbit()
            || $start->getObjectType() === $target->getObjectType()
        ) {
            return null;
        }

        return self::LOCAL_OBJECT_TRANSFER_DISTANCE;
    }

    /**
     * Рассчитать длительность конкретной MOVE-команды.
     *
     * Для MOVE_IN_SYSTEM между разными системами рассчитывается только
     * участок до границы исходной системы. Сам переход обрабатывается
     * отдельной MOVE_BETWEEN_* командой.
     */
    public function calculateCommandDuration(
        FleetCommandType $commandType,
        Coordinate $start,
        Coordinate $target,
        float $startTime,
        float $speed,
        ?float $speedFactor = null
    ): float {
        return match ($commandType) {
            FleetCommandType::MOVE_IN_SYSTEM => $this->calculateInSystemDuration(
                $start,
                $target,
                $startTime,
                $speed,
                $speedFactor
            ),
            FleetCommandType::MOVE_BETWEEN_SYSTEMS => $this->calculateJumpDuration(
                $start,
                $target,
                $speed,
                $speedFactor
            ),
            FleetCommandType::MOVE_BETWEEN_GALAXIES => $this->calculateJumpDuration(
                $start,
                $target,
                $speed,
                $speedFactor
            ),
            default => throw new \LogicException(
                "Команда {$commandType->name} не является MOVE-командой."
            ),
        };
    }

    /**
     * Рассчитать фактическую дистанцию уже материализованной MOVE-команды.
     * Используется при активации для authoritative списания топлива.
     *
     * @param array<string, mixed> $params
     */
    public function calculateCommandDistance(
        FleetCommandType $commandType,
        Coordinate $start,
        Coordinate $target,
        array $params,
        float $startTime,
        float $eventTime
    ): float {
        $localTransferDistance = $this->getLocalObjectTransferDistance($start, $target);
        if ($commandType === FleetCommandType::MOVE_IN_SYSTEM && $localTransferDistance !== null) {
            return $localTransferDistance;
        }

        $end = $this->resolveCommandEndCoordinate(
            $commandType,
            $start,
            $target,
            $params,
            $startTime,
            $eventTime
        );

        return $this->calculateDistance(
            $this->resolvePhysicalPoint($start, $startTime),
            $this->resolvePhysicalPoint($end, $eventTime)
        );
    }

    /**
     * Рассчитать игровую дистанцию MOVE-команды для Nova-формул времени и
     * топлива. Адресные переходы уже используют шкалу Nova, физические
     * внутрисистемные сегменты преобразуются здесь ровно один раз.
     *
     * @param array<string, mixed> $params
     */
    public function calculateCommandGameDistance(
        FleetCommandType $commandType,
        Coordinate $start,
        Coordinate $target,
        array $params,
        float $startTime,
        float $eventTime
    ): float {
        $localTransferDistance = $this->getLocalObjectTransferDistance($start, $target);
        if ($commandType === FleetCommandType::MOVE_IN_SYSTEM && $localTransferDistance !== null) {
            return $localTransferDistance;
        }

        $distance = $this->calculateCommandDistance(
            $commandType,
            $start,
            $target,
            $params,
            $startTime,
            $eventTime
        );

        return $commandType === FleetCommandType::MOVE_IN_SYSTEM
            ? $this->calculateInSystemGameDistance($distance)
            : $distance;
    }

    /**
     * Материализовать command-specific OBJECT с углом Fleet.
     * Угол сохраняется в params активной команды и переживает restart.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function materializeCommandCoordinates(
        FleetCommandType $commandType,
        Coordinate $start,
        Coordinate $target,
        array $params,
        float $startTime
    ): array {
        if (isset($params['resolvedTarget'])) {
            $resolvedTarget = Coordinate::fromArray(
                is_array($params['resolvedTarget']) ? $params['resolvedTarget'] : []
            );
            $this->assertStationaryObject($resolvedTarget, 'resolvedTarget');
            return $params;
        }

        $startPoint = $this->resolvePhysicalPoint($start, $startTime);

        if ($commandType === FleetCommandType::MOVE_IN_SYSTEM) {
            if ($this->getSystemAddress($start) !== $this->getSystemAddress($target)) {
                $edge = GalaxyGenerator::getSpecialOrbit(
                    $startPoint->galaxy,
                    $startPoint->system,
                    GalaxyOrbits::SYSTEM_EDGE
                );
                $params['resolvedTarget'] = Coordinate::object(
                    galaxy: $startPoint->galaxy,
                    system: $startPoint->system,
                    orbit: (int)$edge['orbit'],
                    objectType: null,
                    angle: $startPoint->angle
                )->toArray();
                return $params;
            }

            if ($this->isStationaryOrbitCoordinate($target)) {
                $params['resolvedTarget'] = $this->withObjectAngle($target, $startPoint->angle)->toArray();
                return $params;
            }
        }

        if (!in_array(
            $commandType,
            [FleetCommandType::MOVE_BETWEEN_SYSTEMS, FleetCommandType::MOVE_BETWEEN_GALAXIES],
            true
        )) {
            return $params;
        }

        if (isset($params['transitionObject'])) {
            $transitionObject = Coordinate::fromArray(
                is_array($params['transitionObject']) ? $params['transitionObject'] : []
            );
            $this->assertStationaryObject($transitionObject, 'transitionObject', GalaxyOrbits::SYSTEM_EDGE);
            $destination = $this->getTransitionDestination($target, $params);
            if (
                $transitionObject->getGalaxy() !== $destination['galaxy']
                || $transitionObject->getSystem() !== $destination['system']
            ) {
                throw new \LogicException('transitionObject не соответствует destination команды.');
            }
            return $params;
        }

        $destination = $this->getTransitionDestination($target, $params);
        $edge = GalaxyGenerator::getSpecialOrbit(
            $destination['galaxy'],
            $destination['system'],
            GalaxyOrbits::SYSTEM_EDGE
        );

        $params['transitionObject'] = Coordinate::object(
            galaxy: $destination['galaxy'],
            system: $destination['system'],
            orbit: (int)$edge['orbit'],
            objectType: null,
            angle: $startPoint->angle
        )->toArray();

        return $params;
    }

    /**
     * Определить физический результат завершения MOVE-команды.
     *
     * @param array<string, mixed> $params
     */
    public function resolveCommandEndCoordinate(
        FleetCommandType $commandType,
        Coordinate $start,
        Coordinate $target,
        array $params,
        float $startTime,
        float $eventTime
    ): Coordinate {
        if (
            $commandType === FleetCommandType::MOVE_BETWEEN_SYSTEMS
            || $commandType === FleetCommandType::MOVE_BETWEEN_GALAXIES
        ) {
            if (isset($params['transitionObject']) && is_array($params['transitionObject'])) {
                $transitionObject = Coordinate::fromArray($params['transitionObject']);
                $this->assertStationaryObject(
                    $transitionObject,
                    'transitionObject',
                    GalaxyOrbits::SYSTEM_EDGE
                );
                return $transitionObject;
            }

            // Совместимость с уже активированными командами старого формата.
            if (!isset($params['transitionPoint']) || !is_array($params['transitionPoint'])) {
                throw new \LogicException('У активной transition-команды отсутствует материализованная цель.');
            }

            $transitionPoint = Coordinate::fromArray($params['transitionPoint']);
            if ($transitionPoint->getType() !== CoordinateType::POINT) {
                throw new \LogicException('transitionPoint должна иметь тип POINT.');
            }
            return $transitionPoint;
        }

        if (isset($params['resolvedTarget']) && is_array($params['resolvedTarget'])) {
            $resolvedTarget = Coordinate::fromArray($params['resolvedTarget']);
            $this->assertStationaryObject($resolvedTarget, 'resolvedTarget');
            return $resolvedTarget;
        }

        $startAddress = $this->getSystemAddress($start);
        $targetAddress = $this->getSystemAddress($target);
        if ($startAddress !== $targetAddress) {
            $startPoint = $this->resolvePhysicalPoint($start, $startTime);
            $edge = GalaxyGenerator::getSpecialOrbit(
                $startPoint->galaxy,
                $startPoint->system,
                GalaxyOrbits::SYSTEM_EDGE
            );
            return Coordinate::object(
                galaxy: $startPoint->galaxy,
                system: $startPoint->system,
                orbit: (int)$edge['orbit'],
                objectType: null,
                angle: $startPoint->angle
            );
        }

        if ($target->getType() === CoordinateType::FLEET) {
            return $target->getLastKnownPosition();
        }

        // OBJECT сохраняет identity объекта, а не превращается в snapshot POINT.
        return $target;
    }

    /**
     * Рассчитать физическую POINT-позицию Fleet на момент времени.
     *
     * Метод только читает Fleet и raw command. Ни Fleet, ни command не
     * изменяются и не сохраняются.
     *
     * Для обычного MOVE_IN_SYSTEM используется линейная интерполяция в
     * декартовых координатах между физическим anchor и физическим результатом
     * команды. Для перехода между системами до event time возвращается
     * source anchor, а на event time — физическая точка сохранённого transitionObject.
     */
    public function calculateCurrentCoordinate(
        Fleet $fleet,
        array $command,
        float $atTime
    ): Coordinate {
        if (!is_finite($atTime)) {
            throw new \InvalidArgumentException('Время расчёта позиции должно быть конечным числом.');
        }

        $commandFleetId = (int)($command['fleet_id'] ?? 0);
        if ($commandFleetId > 0 && $commandFleetId !== $fleet->getId()) {
            throw new \LogicException('FleetCommand принадлежит другому Fleet.');
        }

        if ((int)($command['executed'] ?? 0) !== 0) {
            throw new \LogicException('Нельзя рассчитывать позицию по завершённой FleetCommand.');
        }

        $executeTime = (float)($command['execute_time'] ?? 0.0);
        if (!is_finite($executeTime) || $executeTime <= 0.0) {
            throw new \LogicException('Для расчёта текущей позиции нужен ACTIVE FleetCommand.');
        }

        $commandType = FleetCommandType::tryFrom((int)($command['command_type'] ?? 0));
        if ($commandType === null || !$this->isMoveCommand($commandType)) {
            throw new \LogicException('Текущая позиция поддерживается только для MOVE-команд.');
        }

        $params = $this->decodeCommandParams($command['params'] ?? []);
        if (!isset($params['target']) || !is_array($params['target'])) {
            throw new \LogicException('У ACTIVE MOVE-команды отсутствует params.target.');
        }

        $target = Coordinate::fromArray($params['target']);
        $startCoordinate = $fleet->getCurrentCoordinate();
        $startTime = $fleet->getCurrentPositionTime();
        $startPoint = $this->resolvePhysicalPoint($startCoordinate, $startTime);

        if ($atTime <= $startTime) {
            return $this->toPointCoordinate($startPoint);
        }

        $endCoordinate = $this->resolveCommandEndCoordinate(
            $commandType,
            $startCoordinate,
            $target,
            $params,
            $startTime,
            $executeTime
        );
        $endPoint = $this->resolvePhysicalPoint($endCoordinate, $executeTime);

        if ($atTime >= $executeTime) {
            return $this->toPointCoordinate($endPoint);
        }

        if (
            $commandType === FleetCommandType::MOVE_BETWEEN_SYSTEMS
            || $commandType === FleetCommandType::MOVE_BETWEEN_GALAXIES
        ) {
            return $this->toPointCoordinate($startPoint);
        }

        $duration = $executeTime - $startTime;
        if ($duration <= 0.0) {
            return $this->toPointCoordinate($endPoint);
        }

        $progress = min(1.0, max(0.0, ($atTime - $startTime) / $duration));
        return $this->interpolatePoint($startPoint, $endPoint, $progress);
    }

    /**
     * Разрешить Coordinate в физическую точку на указанное игровое время.
     */
    public function resolvePhysicalPoint(Coordinate $coordinate, float $atTime): SystemCoordinate
    {
        if ($coordinate->getType() === CoordinateType::FLEET) {
            return $this->resolvePhysicalPoint($coordinate->getLastKnownPosition(), $atTime);
        }

        if ($coordinate->getType() === CoordinateType::POINT) {
            return new SystemCoordinate(
                galaxy: $coordinate->getGalaxy(),
                system: $coordinate->getSystem(),
                radius: $coordinate->getDistance(),
                angle: $coordinate->getAngle(),
                type: 'point'
            );
        }

        return $this->resolveObjectPoint($coordinate, $atTime);
    }

    private function calculateInSystemDuration(
        Coordinate $start,
        Coordinate $target,
        float $startTime,
        float $speed,
        ?float $speedFactor = null
    ): float {
        $localTransferDistance = $this->getLocalObjectTransferDistance($start, $target);
        if ($localTransferDistance !== null) {
            // Проверяем существование общего orbital anchor в runtime state в
            // тот же момент, когда фактически активируется MOVE-команда.
            $this->resolvePhysicalPoint($start, $startTime);
            $this->resolvePhysicalPoint($target, $startTime);

            return $this->calculateGameDuration($localTransferDistance, $speed, $speedFactor);
        }

        $startPoint = $this->resolvePhysicalPoint($start, $startTime);

        if ($this->getSystemAddress($start) !== $this->getSystemAddress($target)) {
            $boundary = new SystemCoordinate(
                galaxy: $startPoint->galaxy,
                system: $startPoint->system,
                radius: $this->getSystemBoundaryDistance($startPoint->galaxy, $startPoint->system),
                angle: $startPoint->angle,
                type: 'system_boundary'
            );

            return $this->calculateGameDuration(
                $this->calculateInSystemGameDistance(
                    $this->calculateDistance($startPoint, $boundary)
                ),
                $speed,
                $speedFactor
            );
        }

        if (
            $this->isStationaryOrbitCoordinate($target)
            && $target->getAngle() === null
        ) {
            $target = $this->withObjectAngle($target, $startPoint->angle);
        }

        // Фиксированная POINT даёт точный расчёт за одну итерацию.
        $duration = $this->calculateGameDuration(
            $this->calculateInSystemGameDistance(
                $this->calculateDistance($startPoint, $this->resolvePhysicalPoint($target, $startTime))
            ),
            $speed,
            $speedFactor
        );

        // Для вращающегося OBJECT уточняем точку на предполагаемое время
        // прибытия. Это изолированный resolver, а не общий resolvedTarget.
        $bestDuration = $duration;
        $bestResidual = INF;
        $previousDuration = null;

        for ($iteration = 0; $iteration < self::ARRIVAL_MAX_ITERATIONS; $iteration++) {
            $arrivalTime = $startTime + $duration;
            $targetPoint = $this->resolvePhysicalPoint($target, $arrivalTime);
            $nextDuration = $this->calculateGameDuration(
                $this->calculateInSystemGameDistance(
                    $this->calculateDistance($startPoint, $targetPoint)
                ),
                $speed,
                $speedFactor
            );

            $residual = abs($nextDuration - $duration);
            if (
                $residual < $bestResidual - self::ARRIVAL_TIME_TOLERANCE
                || (
                    abs($residual - $bestResidual) <= self::ARRIVAL_TIME_TOLERANCE
                    && $duration < $bestDuration
                )
            ) {
                $bestDuration = $duration;
                $bestResidual = $residual;
            }

            if ($residual <= self::ARRIVAL_TIME_TOLERANCE) {
                return $nextDuration;
            }

            if (
                $previousDuration !== null
                && abs($nextDuration - $previousDuration) <= self::ARRIVAL_TIME_TOLERANCE
            ) {
                break;
            }

            $previousDuration = $duration;
            $duration = $nextDuration;
        }

        return $bestDuration;
    }

    private function isMoveCommand(FleetCommandType $commandType): bool
    {
        return in_array(
            $commandType,
            [
                FleetCommandType::MOVE_IN_SYSTEM,
                FleetCommandType::MOVE_BETWEEN_SYSTEMS,
                FleetCommandType::MOVE_BETWEEN_GALAXIES,
            ],
            true
        );
    }

    /** @param string|array<string, mixed> $params */
    private function decodeCommandParams(string|array $params): array
    {
        if (is_array($params)) {
            return $params;
        }

        if ($params === '') {
            return [];
        }

        $decoded = json_decode($params, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function toPointCoordinate(SystemCoordinate $point): Coordinate
    {
        $angle = fmod($point->angle, 360.0);
        if ($angle < 0.0) {
            $angle += 360.0;
        }

        return Coordinate::point(
            galaxy: $point->galaxy,
            system: $point->system,
            distance: $point->radius,
            angle: $angle
        );
    }

    private function interpolatePoint(
        SystemCoordinate $start,
        SystemCoordinate $end,
        float $progress
    ): Coordinate {
        if ($start->galaxy !== $end->galaxy || $start->system !== $end->system) {
            throw new \LogicException('Нельзя интерполировать POINT между разными системами.');
        }

        $startAngle = deg2rad($start->angle);
        $endAngle = deg2rad($end->angle);
        $startX = $start->radius * cos($startAngle);
        $startY = $start->radius * sin($startAngle);
        $endX = $end->radius * cos($endAngle);
        $endY = $end->radius * sin($endAngle);
        $x = $startX + (($endX - $startX) * $progress);
        $y = $startY + (($endY - $startY) * $progress);
        $distance = hypot($x, $y);
        $angle = rad2deg(atan2($y, $x));
        if ($angle < 0.0) {
            $angle += 360.0;
        }

        return Coordinate::point(
            galaxy: $start->galaxy,
            system: $start->system,
            distance: $distance,
            angle: $angle
        );
    }

    /**
     * Рассчитать адресную часть расстояния по формуле Nova.
     *
     * Внутрисистемная геометрия сюда не входит: она рассчитывается отдельно
     * по реальным двумерным координатам SystemCoordinate.
     */
    public function calculateTransitionDistance(
        int $startGalaxy,
        int $startSystem,
        int $targetGalaxy,
        int $targetSystem
    ): float {
        $galaxyDistance = $this->calculateAddressAxisDistance(abs($startGalaxy - $targetGalaxy));
        $systemDistance = $this->calculateAddressAxisDistance(abs($startSystem - $targetSystem));

        return hypot($galaxyDistance, $systemDistance);
    }

    /**
     * Рассчитать расход топлива по формуле Nova для игровых MOVE-сегментов.
     * Физические внутрисистемные дистанции должны быть предварительно
     * преобразованы через calculateInSystemGameDistance().
     *
     * @param list<float> $segmentDistances
     */
    public function calculateFuelConsumption(
        Fleet $fleet,
        AccountData $accountData,
        array $segmentDistances,
        ?float $speedFactor = null
    ): float {
        $fleetSpeed = $fleet->getTotalSpeed($accountData);
        if ($fleetSpeed <= 0.0) {
            return 0.0;
        }

        $gameSpeed = (float)(Config::getValue('FleetGameSpeed') ?? 10.0);
        if ($gameSpeed <= 0.0) {
            throw new \LogicException('Некорректная настройка скорости игры для расчёта топлива.');
        }

        $consumption = 0.0;
        $hasMovement = false;

        foreach ($segmentDistances as $distance) {
            $distance = (float)$distance;
            if ($distance <= 0.0) {
                continue;
            }

            $hasMovement = true;
            $duration = $this->calculateGameDuration($distance, $fleetSpeed, $speedFactor);
            $durationDenominator = round($duration, 0) * $gameSpeed - 10.0;
            if ($durationDenominator <= 0.0) {
                throw new \LogicException('Невозможно рассчитать расход топлива для MOVE-сегмента.');
            }

            foreach ($fleet->getShips() as $ship) {
                if ($ship->getCount() <= 0) {
                    continue;
                }

                $shipSpeed = $ship->getSpeed($accountData);
                if ($shipSpeed <= 0.0) {
                    continue;
                }

                $speed = 35000.0 / $durationDenominator
                    * sqrt($distance * 10.0 / $shipSpeed);
                $basicConsumption = $ship->getConsumptionAll($accountData);
                $consumption += $basicConsumption
                    * $distance / 35000.0
                    * (($speed / 10.0) + 1.0) ** 2;
            }
        }

        return $hasMovement ? (float)(round($consumption) + 1) : 0.0;
    }

    private function calculateJumpDuration(
        Coordinate $start,
        Coordinate $target,
        float $speed,
        ?float $speedFactor = null
    ): float {
        $startAddress = $this->getSystemAddress($start);
        $targetAddress = $this->getSystemAddress($target);

        $distance = $this->calculateTransitionDistance(
            $startAddress['galaxy'],
            $startAddress['system'],
            $targetAddress['galaxy'],
            $targetAddress['system']
        );

        return $this->calculateGameDuration($distance, $speed, $speedFactor);
    }

    private function calculateAddressAxisDistance(int $delta): float
    {
        return $delta === 0
            ? 0.0
            : $delta * self::ADDRESS_DISTANCE_STEP + self::ADDRESS_DISTANCE_BASE;
    }

    public function calculateGameDuration(
        float $distance,
        float $speed,
        ?float $speedFactor = null
    ): float
    {
        if ($distance <= 0.0) {
            return 0.0;
        }
        if ($speed <= 0.0) {
            throw new \InvalidArgumentException('Скорость флота должна быть больше нуля.');
        }

        $gameSpeed = (float)(Config::getValue('FleetGameSpeed') ?? 10.0);
        $speedFactor ??= (float)(Config::getValue('FleetSpeedFactor') ?? 1.0);
        if ($gameSpeed <= 0.0 || $speedFactor <= 0.0) {
            throw new \LogicException('Некорректные настройки скорости флота.');
        }

        $duration = (
            (3500.0 / ($speedFactor * 0.1)) * sqrt($distance * 10.0 / $speed)
            + 10.0
        ) / $gameSpeed;

        return (float)round(max(self::MIN_FLIGHT_DURATION, $duration));
    }

    private function resolveObjectPoint(Coordinate $coordinate, float $atTime): SystemCoordinate
    {
        $objectType = $coordinate->getObjectType();
        if ($objectType === null) {
            if ($coordinate->getAngle() === null) {
                throw new \LogicException('У неподвижного OBJECT не материализован angle Fleet.');
            }

            $rows = $this->getStationaryOrbitRow($coordinate);
            if ($rows === null) {
                throw new \LogicException('Неподвижный OBJECT не найден в GalaxyOrbits runtime state.');
            }

            return new SystemCoordinate(
                galaxy: $coordinate->getGalaxy(),
                system: $coordinate->getSystem(),
                radius: (float)$rows['distance'],
                angle: $coordinate->getAngle(),
                type: (int)$rows['type'] === GalaxyOrbits::SYSTEM_EDGE
                    ? 'system_edge'
                    : 'expedition',
                orbit: $coordinate->getOrbit(),
                rotationSpeed: 0.0
            );
        }

        if (!in_array($objectType, [ObjectType::PLANET, ObjectType::MOON, ObjectType::DEBRIS], true)) {
            throw new \LogicException('Физический resolver для данного ObjectType ещё не реализован.');
        }

        // Луна и обломки привязаны к планете той же орбиты и используют её
        // физическое положение. ObjectType остаётся identity цели, но не
        // создаёт отдельную траекторию вокруг звезды.
        $planet = Planets::findByIndex(
            'galaxy_system_planet_type',
            [
                $coordinate->getGalaxy(),
                $coordinate->getSystem(),
                $coordinate->getOrbit(),
                ObjectType::PLANET->value,
            ]
        );
        if (!$planet) {
            throw new \LogicException('Орбитальный anchor OBJECT не найден в Galaxy/Planets runtime state.');
        }
        $orbit = GalaxyOrbits::findByIndex(
            'galaxy_system_orbit',
            [$coordinate->getGalaxy(), $coordinate->getSystem(), $coordinate->getOrbit()]
        );
        if (!$orbit) {
            throw new \LogicException('Расстояние орбитального anchor не найдено в GalaxyOrbits runtime state.');
        }

        $elapsed = max(0.0, $atTime - (float)$planet['update_time']);
        $speedPlanets = (float)(Config::getValue('SpeedPlanets') ?? 24.0);
        $angle = (float)$planet['deg']
            + ($elapsed / 3600.0) * (float)$planet['speed'] * ($speedPlanets / 24.0);
        if ((int)$planet['rotation'] === 0) {
            $angle = (float)$planet['deg']
                - ($elapsed / 3600.0) * (float)$planet['speed'] * ($speedPlanets / 24.0);
        }

        $angle = fmod($angle, 360.0);
        if ($angle < 0.0) {
            $angle += 360.0;
        }

        return new SystemCoordinate(
            galaxy: (int)$planet['galaxy'],
            system: (int)$planet['system'],
            radius: (float)$orbit['distance'],
            angle: $angle,
            type: strtolower($objectType->name),
            orbit: (int)$planet['planet'],
            rotationSpeed: (float)$planet['speed']
        );
    }

    /** @return array{galaxy:int,system:int} */
    private function getSystemAddress(Coordinate $coordinate): array
    {
        if ($coordinate->getType() === CoordinateType::FLEET) {
            return $this->getSystemAddress($coordinate->getLastKnownPosition());
        }

        return [
            'galaxy' => $coordinate->getGalaxy(),
            'system' => $coordinate->getSystem(),
        ];
    }

    private function getSystemBoundaryDistance(int $galaxy, int $system): float
    {
        return (float)GalaxyGenerator::getSpecialOrbit(
            $galaxy,
            $system,
            GalaxyOrbits::SYSTEM_EDGE
        )['distance'];
    }

    private function withObjectAngle(Coordinate $coordinate, float $angle): Coordinate
    {
        $this->assertStationaryObject($coordinate, 'target', requireAngle: false);

        return Coordinate::object(
            galaxy: $coordinate->getGalaxy(),
            system: $coordinate->getSystem(),
            orbit: $coordinate->getOrbit(),
            objectType: null,
            angle: $angle
        );
    }

    private function assertStationaryObject(
        Coordinate $coordinate,
        string $field,
        ?int $expectedOrbitType = null,
        bool $requireAngle = true
    ): void {
        $orbit = $this->getStationaryOrbitRow($coordinate);
        if (
            $coordinate->getType() !== CoordinateType::OBJECT
            || $coordinate->getObjectType() !== null
            || $orbit === null
            || ($expectedOrbitType !== null && (int)$orbit['type'] !== $expectedOrbitType)
        ) {
            throw new \LogicException("{$field} должен быть неподвижным OBJECT.");
        }

        if ($requireAngle && $coordinate->getAngle() === null) {
            throw new \LogicException("{$field} не содержит материализованный angle Fleet.");
        }
    }

    public function isStationaryOrbitCoordinate(Coordinate $coordinate): bool
    {
        return $this->getStationaryOrbitRow($coordinate) !== null;
    }

    private function getStationaryOrbitRow(Coordinate $coordinate): ?array
    {
        if (
            $coordinate->getType() !== CoordinateType::OBJECT
            || $coordinate->getObjectType() !== null
        ) {
            return null;
        }

        $row = GalaxyOrbits::findByIndex(
            'galaxy_system_orbit',
            [$coordinate->getGalaxy(), $coordinate->getSystem(), $coordinate->getOrbit()]
        );
        if (!$row || !in_array(
            (int)$row['type'],
            [GalaxyOrbits::SYSTEM_EDGE, GalaxyOrbits::EXPEDITION],
            true
        )) {
            return null;
        }

        return $row;
    }

    /** @param array<string, mixed> $params */
    private function getTransitionDestination(Coordinate $target, array $params): array
    {
        if (isset($params['destination']) && is_array($params['destination'])) {
            $galaxy = (int)($params['destination']['galaxy'] ?? 0);
            $system = (int)($params['destination']['system'] ?? 0);
            if ($galaxy > 0 && $system > 0) {
                return ['galaxy' => $galaxy, 'system' => $system];
            }
            throw new \LogicException('Некорректный destination transition-команды.');
        }

        return $this->getSystemAddress($target);
    }
}
