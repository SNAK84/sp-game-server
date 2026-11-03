<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetShip;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\DebrisFields;
use SPGame\Game\Repositories\SpaceDebrisFields;

use SPGame\Game\Repositories\Vars;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

/**
 * Логика определения доступных миссий флота.
 *
 * Отвечает только за:
 * - определение цели;
 * - определение возможностей флота;
 * - определение доступных миссий.
 *
 * Построением цепочки команд занимается FleetMissionBuilder.
 */
class FleetMissionService
{
    /** Границы только пользовательской POINT-цели, не физических точек траектории. */
    public static function pointDistanceBounds(int $galaxy, int $system): ?array
    {
        $systems = Galaxy::findByIndex('galaxy_system', [$galaxy, $system]) ?? [];
        if (!$systems) return null;
        $edge = GalaxyOrbits::findByIndex('galaxy_system_orbit', [$galaxy, $system, (int)$systems[0]['max_orbits'] + 1]);
        if (!$edge || (int)$edge['type'] !== GalaxyOrbits::SYSTEM_EDGE) return null;
        $min = (float)$systems[0]['min_distance'];
        $max = (float)$edge['distance'];
        return is_finite($min) && $min >= 0 && is_finite($max) && $max > $min ? ['min' => $min, 'max' => $max] : null;
    }

    /**
     * Получить доступные миссии для флота.
     *
    * @param Fleet $fleet             Конкретный флот.
    * @param FleetTarget $target      Цель назначения.
    * @param AccountData $accountData Данные текущего игрока.
     *
     * @return array{
     *     missions: array,
     *     messages: array,
     *     disabled: bool
     * }
     */
    public function getAvailableMissions(
        Fleet $fleet,
        FleetTarget $target,
        AccountData $accountData,
        ?Coordinate $origin = null,
        ?float $at = null
    ): array {
        $context = $this->buildContext($accountData, $fleet, $target, $origin, $at);

        if (!$context['valid']) {
            return [
                'missions' => [],
                'messages' => $context['messages'],
                'disabled' => true,
            ];
        }

        $missions = $this->determineMissions($context);
        if ($fleet->getHomeSiteId() > 0) {
            $missions = ResourceBaseService::isEnabled()
                ? array_values(array_intersect($missions, ResourceBaseService::departureMissions())) : [];
        }
        if (!empty($context['target']['resource_site'])
            && !in_array(FleetMissionType::CAPTURE->value, $missions, true)
            && !in_array(FleetMissionType::RELOCATE->value, $missions, true)
            && !in_array(FleetMissionType::MINING->value, $missions, true)
            && !in_array(FleetMissionType::DEFEND->value, $missions, true)
            && !in_array(FleetMissionType::SPY->value, $missions, true)) {
            $reason = FleetSurveyService::reason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates()));
            if ($reason !== null) $context['messages'][] = $reason;
        }
        if ((int)($context['target']['orbit_type'] ?? -1) === GalaxyOrbits::EMPTY
            && (int)($context['target']['object_type'] ?? 0) === ObjectType::PLANET->value) {
            $reason = FleetColonizationService::availabilityReason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates()));
            if ($reason !== null) $context['messages'][] = $reason;
        }

        return [
            'missions' => $missions,
            'messages' => $context['messages'],
            'disabled' => false,
        ];
    }

    /**
     * Рассчитать диапазон расстояния, времени полёта и расхода топлива до цели.
     *
     * Флот стартует со своей домашней планеты. Внутри одной системы расстояние
     * зависит от неизвестного угла планет на орбите, поэтому считается диапазоном
     * [min; max] по правилу треугольника (|R1-R2| .. R1+R2).
     *
     * При перелёте в другую систему/галактику флот покидает систему на
     * служебной границе, рассчитанной из масштаба звезды, по текущему углу и
     * входит в целевую систему на её служебной границе в случайной точке.
     * Поэтому выход из исходной
     * системы имеет точную длину, а диапазон остаётся только на участке входа
     * к цели.
     *
     * Для миссий с возвратом fuel содержит расход полного маршрута, тогда как
     * distance и duration описывают время прибытия к цели.
     *
     * @return array{distance: array{min:int,max:int}, duration: array{min:int,max:int}, fuel: array{min:float,max:float}}|null
     */
    public function getTravelInfo(
        Fleet $fleet,
        FleetTarget $target,
        AccountData $accountData,
        ?float $speedFactor = null,
        ?FleetMissionType $mission = null,
        ?Coordinate $origin = null,
        ?float $at = null
    ): ?array {
        $context = $this->buildContext($accountData, $fleet, $target, $origin, $at);

        if (!$context['valid']) {
            return null;
        }
        if ($mission === FleetMissionType::SPY && !empty($context['target']['resource_site'])
            && empty($context['can_resource_spy'])) return null;
        if ($target->getCoordinateType() === CoordinateType::FLEET && $mission === FleetMissionType::ATTACK
            && empty($context['can_intercept'])) return null;
        if ($mission === FleetMissionType::RESEARCH && empty($context['can_survey'])) return null;
        if ($mission === FleetMissionType::CAPTURE && empty($context['can_capture'])) return null;
        if ($mission === FleetMissionType::MINING && empty($context['can_mine'])) return null;
        if ($mission === FleetMissionType::RELOCATE && !empty($context['target']['resource_site'])
            && empty($context['can_resource_relocate'])) return null;

        $siteHome = $fleet->getHomeSiteId() > 0;
        if ($siteHome && (!ResourceBaseService::isEnabled()
            || ($mission !== null && !in_array($mission->value, ResourceBaseService::departureMissions(), true)))) return null;
        if ($siteHome) {
            try { $homeCoordinate = ResourceBaseService::homeCoordinate($fleet); }
            catch (\LogicException|\InvalidArgumentException) { return null; }
            $homePlanet = null;
        } else {
            $homePlanet = Planets::findById($fleet->getHomePlanetId());
            if (!$homePlanet) return null;
            $homeCoordinate = Coordinate::object((int)$homePlanet['galaxy'], (int)$homePlanet['system'],
                (int)$homePlanet['planet'], ObjectType::from((int)$homePlanet['planet_type']));
        }

        $requestedTarget = $target;

        $startGalaxy = (int)$homeCoordinate->getGalaxy();
        $startSystem = (int)$homeCoordinate->getSystem();
        $startRadius = $siteHome
            ? (float)(GalaxyOrbits::findByIndex('galaxy_system_orbit', [$startGalaxy, $startSystem, $homeCoordinate->getOrbit()])['distance'] ?? 0)
            : (float)$homePlanet['distance'];
        if ($origin !== null) {
            $startGalaxy = (int)$origin->getGalaxy(); $startSystem = (int)$origin->getSystem();
            $startRadius = $origin->getType() === CoordinateType::OBJECT
                ? (float)(GalaxyOrbits::findByIndex('galaxy_system_orbit', [$startGalaxy, $startSystem, $origin->getOrbit()])['distance'] ?? 0)
                : (float)$origin->getDistance();
        }

        $target = $context['target'];
        $targetGalaxy = (int)$target['galaxy'];
        $targetSystem = (int)$target['system'];
        $targetRadius = (float)($target['distance'] ?? 0.0);
        $fleetTarget = $requestedTarget->getCoordinateType() === CoordinateType::FLEET;

        $flight = new FleetFlightService();

        if ($startGalaxy === $targetGalaxy && $startSystem === $targetSystem) {
            $targetMaximumRadius = $this->maximumTargetRadius($target);
            $localTransferDistance = null;
            $startObjectType = $origin === null ? $homeCoordinate->getObjectType() : $origin->getObjectType();
            $targetObjectType = $requestedTarget->getObjectType();

            if ($startObjectType !== null && $targetObjectType !== null) {
                $localTransferDistance = $flight->getLocalObjectTransferDistance(
                    Coordinate::object(
                        $startGalaxy,
                        $startSystem,
                        $origin === null ? (int)$homeCoordinate->getOrbit() : (int)$origin->getOrbit(),
                        $startObjectType, siteId: ($origin ?? $homeCoordinate)->getSiteId()
                    ),
                    Coordinate::object(
                        $targetGalaxy,
                        $targetSystem,
                        $requestedTarget->getOrbit(),
                        $targetObjectType, siteId: $requestedTarget->getSiteId()
                    )
                );
            }

            if ($mission === FleetMissionType::MINING && ($origin ?? $homeCoordinate)->toArray() === $requestedTarget->toCoordinates()) {
                $minDistance = $maxDistance = 0.0;
            } elseif ($localTransferDistance !== null) {
                $minDistance = $localTransferDistance;
                $maxDistance = $localTransferDistance;
            } elseif ((int)($target['orbit_type'] ?? -1) === GalaxyOrbits::EXPEDITION) {
                // Служебный OBJECT не вращается: его угол будет
                // материализован из угла Fleet, поэтому preview точный.
                $minDistance = abs($startRadius - $targetRadius);
                $maxDistance = $minDistance;
            } else {
                // Обычный внутрисистемный preview остаётся диапазоном: точное
                // положение будет рассчитано при фактической активации MOVE.
                $minDistance = abs($startRadius - $targetRadius);
                $maxDistance = $startRadius + $targetMaximumRadius;
            }
            $minSegments = [$minDistance];
            $maxSegments = [$maxDistance];
            $returnMinSegments = $minSegments;
            $returnMaxSegments = $maxSegments;
            if ($localTransferDistance !== null) {
                $minGameSegments = $minSegments;
                $maxGameSegments = $maxSegments;
            } else {
                $minGameSegments = [$flight->calculateInSystemGameDistance($minDistance)];
                $maxGameSegments = [$flight->calculateInSystemGameDistance($maxDistance)];
            }
            $returnMinGameSegments = $minGameSegments;
            $returnMaxGameSegments = $maxGameSegments;
            if ((int)($target['orbit_type'] ?? -1) === GalaxyOrbits::EXPEDITION) {
                // Экспедиционная точка неподвижна, но база вращается во время выдержки.
                $returnMaxSegments = [$startRadius + $targetRadius];
                $returnMaxGameSegments = [$flight->calculateInSystemGameDistance($startRadius + $targetRadius)];
            }
        } else {
            $exitRadius = (float)GalaxyGenerator::getSpecialOrbit(
                $startGalaxy,
                $startSystem,
                GalaxyOrbits::SYSTEM_EDGE
            )['distance'];
            $entryRadius = (float)GalaxyGenerator::getSpecialOrbit(
                $targetGalaxy,
                $targetSystem,
                GalaxyOrbits::SYSTEM_EDGE
            )['distance'];
            // getSpecialOrbit может актуализировать служебные орбиты: читаем предел после него.
            $targetMaximumRadius = $this->maximumTargetRadius($target);

            $sourceExitDistance = abs($exitRadius - $startRadius);
            $jumpDistance = $flight->calculateTransitionDistance(
                $startGalaxy,
                $startSystem,
                $targetGalaxy,
                $targetSystem
            );
            if (in_array(
                (int)($target['orbit_type'] ?? -1),
                [GalaxyOrbits::SYSTEM_EDGE, GalaxyOrbits::EXPEDITION],
                true
            )) {
                $destinationMinDistance = abs($entryRadius - $targetRadius);
                $destinationMaxDistance = $destinationMinDistance;
            } else {
                $destinationMinDistance = abs($entryRadius - $targetRadius);
                $destinationMaxDistance = $entryRadius + $targetMaximumRadius;
            }

            // После встречи Fleet может оказаться в любой точке этой системы.
            $returnExitMaximum = $fleetTarget
                ? max($entryRadius, abs($entryRadius - $targetMaximumRadius))
                : abs($entryRadius - $targetRadius);

            $minSegments = [$sourceExitDistance, $jumpDistance, $destinationMinDistance];
            $maxSegments = [$sourceExitDistance, $jumpDistance, $destinationMaxDistance];
            $returnMinSegments = [
                abs($entryRadius - $targetRadius),
                $jumpDistance,
                abs($exitRadius - $startRadius),
            ];
            $returnMaxSegments = [
                $returnExitMaximum,
                $jumpDistance,
                $exitRadius + $startRadius,
            ];
            $minGameSegments = [
                $flight->calculateInSystemGameDistance($sourceExitDistance),
                $jumpDistance,
                $flight->calculateInSystemGameDistance($destinationMinDistance),
            ];
            $maxGameSegments = [
                $flight->calculateInSystemGameDistance($sourceExitDistance),
                $jumpDistance,
                $flight->calculateInSystemGameDistance($destinationMaxDistance),
            ];
            $returnMinGameSegments = [
                $flight->calculateInSystemGameDistance(abs($entryRadius - $targetRadius)),
                $jumpDistance,
                $flight->calculateInSystemGameDistance(abs($exitRadius - $startRadius)),
            ];
            $returnMaxGameSegments = [
                $flight->calculateInSystemGameDistance($returnExitMaximum),
                $jumpDistance,
                $flight->calculateInSystemGameDistance($exitRadius + $startRadius),
            ];
            $minDistance = array_sum($minSegments);
            $maxDistance = array_sum($maxSegments);
        }

        $fleetStats = $this->calcFleetStats($fleet, $accountData);

        if ($fleetStats['speed'] <= 0) {
            return null;
        }

        $fuelMinSegments = $minGameSegments;
        $fuelMaxSegments = $maxGameSegments;
        // На базе нет склада: при занятии/передислокации оставляем резерв возврата в баке.
        $resourceBaseAction = $mission === FleetMissionType::CAPTURE
            || ($mission === FleetMissionType::RELOCATE && !empty($context['target']['resource_site']));
        // Последняя охрана не сохраняет пустую базу. Не обещаем возвращение на
        // прежний объект: резерв считаем до собственной запасной планеты/луны.
        if ($siteHome && ($resourceBaseAction || $mission === FleetMissionType::MINING || ($mission === FleetMissionType::TRANSPORT
            && ResourceBaseService::willReleaseOnDeparture($fleet, $at ?? microtime(true))))) {
            try { $fallback = ResourceBaseService::fallbackCoordinate($fleet); }
            catch (\LogicException|\InvalidArgumentException) { return null; }
            [$returnMinGameSegments, $returnMaxGameSegments] = $this->returnToFallbackSegments($flight, $target, $fallback);
        }
        if ($resourceBaseAction || in_array($mission, [FleetMissionType::ATTACK, FleetMissionType::GROUP_ATTACK, FleetMissionType::DESTROY, FleetMissionType::DEFEND, FleetMissionType::TRANSPORT, FleetMissionType::SPY, FleetMissionType::RECYCLE, FleetMissionType::COLONIZE, FleetMissionType::OBSERVE, FleetMissionType::EXPEDITION, FleetMissionType::RESEARCH, FleetMissionType::MINING], true)) {
            $fuelMinSegments = array_merge($fuelMinSegments, $returnMinGameSegments);
            $fuelMaxSegments = array_merge($fuelMaxSegments, $returnMaxGameSegments);
        }

        $colonyReturn = null;
        $colonyFuel = null;
        if ($mission === FleetMissionType::COLONIZE) {
            $ships = FleetColonizationService::returnShips($fleet);
            $colonyReturn = [
                'min' => $this->calculateSegmentsFuel($flight, $fleet, $accountData, $returnMinGameSegments, $speedFactor, $ships),
                'max' => $this->calculateSegmentsFuel($flight, $fleet, $accountData, $returnMaxGameSegments, $speedFactor, $ships),
            ];
            $colonyFuel = [
                'min' => $this->calculateSegmentsFuel($flight, $fleet, $accountData, $minGameSegments, $speedFactor)
                    + max($colonyReturn['min'], $this->calculateSegmentsFuel($flight, $fleet, $accountData, $returnMinGameSegments, $speedFactor)),
                'max' => $this->calculateSegmentsFuel($flight, $fleet, $accountData, $maxGameSegments, $speedFactor)
                    + max($colonyReturn['max'], $this->calculateSegmentsFuel($flight, $fleet, $accountData, $returnMaxGameSegments, $speedFactor)),
            ];
        }
        $combatFuelMaximum = null;
        if (in_array($mission, [FleetMissionType::ATTACK, FleetMissionType::GROUP_ATTACK,
            FleetMissionType::DESTROY, FleetMissionType::DEFEND, FleetMissionType::EXPEDITION], true)) {
            // После боя топливо остаётся пропорционально уцелевшей вместимости.
            // Округление каждого MOVE и исчезновение медленных кораблей не линейны
            // по численности: обычного расчёта для исходного состава недостаточно.
            $combatFuelMaximum = $this->calculateSegmentsFuel(
                $flight, $fleet, $accountData, $maxGameSegments, $speedFactor
            ) + $this->calculateCombatReturnReserve(
                $flight, $fleet, $accountData, $returnMaxGameSegments, $speedFactor
            );
        }
        $survey = $mission === FleetMissionType::RESEARCH
            ? FleetSurveyService::estimate($fleet, $accountData, Coordinate::fromArray($requestedTarget->toCoordinates())) : null;
        $holdFuel = (float)($survey['fuel'] ?? 0);
        return [
            'survey' => $survey,
            'distance' => [
                'min' => (int)round($minDistance),
                'max' => (int)round($maxDistance),
            ],
            'duration' => [
                'min' => (int)round($this->calculateSegmentsDuration($flight, $minGameSegments, $fleetStats['speed'], $speedFactor)),
                'max' => (int)round($this->calculateSegmentsDuration($flight, $maxGameSegments, $fleetStats['speed'], $speedFactor)),
            ],
            'colonizationReturnFuel' => $colonyReturn,
            'fuel' => $colonyFuel ?? [
                // Командный pipeline списывает топливо по каждому MOVE, поэтому
                // округление выполняется отдельно для каждого участка.
                'min' => $holdFuel + $this->calculateSegmentsFuel(
                    $flight,
                    $fleet,
                    $accountData,
                    $fuelMinSegments,
                    $speedFactor
                ),
                'max' => $combatFuelMaximum ?? ($holdFuel + $this->calculateSegmentsFuel(
                    $flight,
                    $fleet,
                    $accountData,
                    $fuelMaxSegments,
                    $speedFactor
                )),
            ],
        ];
    }

    /** Отдельные MOVE до резервной базы, с прежней нормализацией и округлением. */
    private function returnToFallbackSegments(FleetFlightService $flight, array $target, Coordinate $home): array
    {
        $galaxy = (int)$target['galaxy']; $system = (int)$target['system'];
        $radius = (float)$target['distance'];
        $maximum = $this->maximumTargetRadius($target);
        $homeRadius = (float)(GalaxyOrbits::findByIndex('galaxy_system_orbit',
            [$home->getGalaxy(), $home->getSystem(), $home->getOrbit()])['distance'] ?? 0);
        if ($galaxy === $home->getGalaxy() && $system === $home->getSystem()) {
            return [[$flight->calculateInSystemGameDistance(abs($radius - $homeRadius))],
                [$flight->calculateInSystemGameDistance($maximum + $homeRadius)]];
        }
        $exit = (float)GalaxyGenerator::getSpecialOrbit($galaxy, $system, GalaxyOrbits::SYSTEM_EDGE)['distance'];
        $entry = (float)GalaxyGenerator::getSpecialOrbit((int)$home->getGalaxy(), (int)$home->getSystem(), GalaxyOrbits::SYSTEM_EDGE)['distance'];
        $jump = $flight->calculateTransitionDistance($galaxy, $system, (int)$home->getGalaxy(), (int)$home->getSystem());
        $maximum = $this->maximumTargetRadius($target);
        $exitMax = ($target['type'] ?? '') === 'fleet' ? max($exit, abs($exit - $maximum)) : abs($exit - $radius);
        return [[$flight->calculateInSystemGameDistance(abs($exit - $radius)), $jump,
            $flight->calculateInSystemGameDistance(abs($entry - $homeRadius))],
            [$flight->calculateInSystemGameDistance($exitMax), $jump,
            $flight->calculateInSystemGameDistance($entry + $homeRadius)]];
    }

    /** Верхний резерв встречи устойчив к движению Fleet между preview, ACK и PlayerQueue. */
    private function maximumTargetRadius(array $target): float
    {
        $radius = (float)($target['distance'] ?? 0.0);
        if (($target['type'] ?? '') === 'fleet') {
            // Все орбиты системы, включая служебные; фактическая встреча рассчитывается отдельно.
            foreach (GalaxyOrbits::findByIndex('galaxy_system', [$target['galaxy'], $target['system']]) ?? [] as $orbit) {
                $radius = max($radius, (float)$orbit['distance']);
            }
        }
        return $radius;
    }

    /** @param list<float> $segments */
    private function calculateSegmentsFuel(
        FleetFlightService $flight,
        Fleet $fleet,
        AccountData $accountData,
        array $segments,
        ?float $speedFactor,
        ?array $ships = null
    ): float {
        $fuel = 0.0;
        foreach ($segments as $distance) {
            if ($distance > 0.0) {
                $fuel += $flight->calculateFuelConsumption(
                    $fleet,
                    $accountData,
                    [(float)$distance],
                    $speedFactor,
                    $ships
                );
            }
        }

        return $fuel;
    }

    /** Резерв по вместимости покрывает любую оставшуюся комбинацию текущих типов кораблей. */
    private function calculateCombatReturnReserve(
        FleetFlightService $flight,
        Fleet $fleet,
        AccountData $accountData,
        array $segments,
        ?float $speedFactor
    ): float {
        $fuelPerCapacity = 0.0;
        foreach ($fleet->getShips() as $ship) {
            if ($ship->getCount() <= 0 || $ship->getCapacity() <= 0) continue;
            // Новый объект без callback: подготовка не меняет исходный FleetShip.
            $single = new FleetShip($ship->getShipTypeId(), $ship->getShipTypeName(), 1);
            $fuel = $this->calculateSegmentsFuel($flight, $fleet, $accountData, $segments, $speedFactor, [$single]);
            $fuelPerCapacity = max($fuelPerCapacity, $fuel / $single->getCapacity());
        }
        // round(sum) + 1 <= sum(round(unit) + 1): стоимость каждой уцелевшей
        // группы ограничена суммой цен отдельных кораблей на тех же участках.
        // После потерь тот же коэффициент сохраняется вместе с долей вместимости.
        return $fleet->getCapacity() * $fuelPerCapacity;
    }

    /**
     * @param list<float> $segments
     */
    private function calculateSegmentsDuration(
        FleetFlightService $flight,
        array $segments,
        float $speed,
        ?float $speedFactor
    ): float {
        $duration = 0.0;
        foreach ($segments as $distance) {
            if ($distance > 0.0) {
                $duration += $flight->calculateGameDuration($distance, $speed, $speedFactor);
            }
        }

        return $duration;
    }

    /**
     * @return array{min: float, max: float}
     */
    protected function calculateInterSystemDistanceRange(
        float $startRadius,
        float $exitRadius,
        float $targetRadius,
        float $entryRadius,
        float $jumpDistance
    ): array {
        // Выход выполняется радиально по текущему углу и не имеет диапазона.
        $sourceExitDistance = abs($exitRadius - $startRadius);

        // Угол появления в целевой системе случаен: от одной стороны до
        // противоположной относительно цели.
        $destinationMinDistance = abs($entryRadius - $targetRadius);
        $destinationMaxDistance = $entryRadius + $targetRadius;

        return [
            'min' => $sourceExitDistance + $jumpDistance + $destinationMinDistance,
            'max' => $sourceExitDistance + $jumpDistance + $destinationMaxDistance,
        ];
    }

    /**
     * Суммарное потребление топлива и минимальная скорость флота (по составу кораблей).
     */
    protected function calcFleetStats(Fleet $fleet, AccountData $accountData): array
    {
        $consumption = 0.0;
        $speeds = [];

        foreach ($fleet->getShips() as $ship) {
            if ($ship->getCount() <= 0) {
                continue;
            }

            $consumption += $ship->getConsumptionAll($accountData);
            $speeds[] = $ship->getSpeed($accountData);
        }

        return [
            'consumption' => $consumption,
            'speed' => $speeds ? min($speeds) : 0.0,
        ];
    }

    /**
     * Построить полный контекст флота и цели.
     *
     * Это основной подготовительный этап перед определением миссий.
     */
    protected function buildContext(
        AccountData $accountData,
        Fleet $fleet,
        FleetTarget $target,
        ?Coordinate $origin = null,
        ?float $at = null
    ): array {
        $messages = [];

        $at ??= microtime(true);
        $resolvedTarget = $this->resolveTarget($target, $accountData, $fleet, $at);

        if (!$resolvedTarget['valid']) {
            return [
                'valid' => false,
                'messages' => $resolvedTarget['messages'],
            ];
        }

        if ((int)($resolvedTarget['orbit_type'] ?? -1) === GalaxyOrbits::EXPEDITION) {
            $current = $origin ?? $fleet->getCurrentCoordinate();
            if ($current->getType() === CoordinateType::FLEET) {
                $current = $current->getLastKnownPosition();
            }

            if (
                $current === null
                || $current->getGalaxy() !== (int)$resolvedTarget['galaxy']
                || $current->getSystem() !== (int)$resolvedTarget['system']
            ) {
                return [
                    'valid' => false,
                    'messages' => ['Экспедиция доступна только в текущей системе флота.'],
                ];
            }
        }

        Logger::getInstance()->info("target",  $resolvedTarget);



        $ownerId = (int)($accountData['User']['id'] ?? 0);
        $targetOwnerId = (int)($resolvedTarget['owner_id'] ?? 0);
        $canIntercept = false;
        $interceptReason = null;
        if ($target->getCoordinateType() === CoordinateType::FLEET && $targetOwnerId !== $ownerId) {
            try {
                FleetInterceptionService::targetObservation($fleet, (int)$target->getFleetId(), $at, $origin);
                $canIntercept = true;
            } catch (\InvalidArgumentException|\LogicException $error) { $interceptReason = $error->getMessage(); }
        }
        if ($target->getCoordinateType() === CoordinateType::FLEET && empty($resolvedTarget['delivery']) && !$canIntercept) {
            return ['valid' => false, 'messages' => [$interceptReason ?? 'Нет актуального контакта для перехвата.']];
        }

        return [
            'valid' => true,

            'owner_id' => $ownerId,
            'can_intercept' => $canIntercept,
            'can_survey' => !empty($resolvedTarget['resource_site'])
                && FleetSurveyService::reason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates())) === null,
            'can_mine' => !empty($resolvedTarget['resource_site'])
                && FleetMiningRuntimeService::reason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates())) === null,
            'can_resource_spy' => !empty($resolvedTarget['resource_site'])
                && FleetSpyService::resourceReason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates())) === null,
            'can_resource_defend' => !empty($resolvedTarget['resource_site']) && ResourceBaseService::isEnabled()
                && FleetResourceDefendService::reason($fleet, Coordinate::fromArray($target->toCoordinates())) === null,
            'can_capture' => $origin === null && !empty($resolvedTarget['resource_site']) && ResourceBaseService::isEnabled()
                && ResourceBaseService::destinationReason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates()), true) === null,
            'can_resource_relocate' => $origin === null && !empty($resolvedTarget['resource_site']) && ResourceBaseService::isEnabled()
                && ResourceBaseService::destinationReason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates()), false) === null,
            'can_hold' => FleetControlService::capabilities($accountData)['hold'] && ($resolvedTarget['point_allowed'] ?? true),
            'can_expedition' => (int)($resolvedTarget['orbit_type'] ?? -1) === GalaxyOrbits::EXPEDITION
                && FleetExpeditionService::reason($fleet,$accountData,Coordinate::fromArray($target->toCoordinates()), $origin) === null,
            'can_colonize' => (int)($resolvedTarget['orbit_type'] ?? -1) === GalaxyOrbits::EMPTY
                && FleetColonizationService::availabilityReason($fleet, $accountData, Coordinate::fromArray($target->toCoordinates())) === null,

            // Флот
            'fleet' => $fleet,

            // Цель
            'coordinates' => $target->toCoordinates(),
            'target' => $resolvedTarget,


            'messages' => $messages,
        ];
    }

    /**
     * Определить объект по координатам.
     *
     * coordinates:
     * {
     *     galaxy: 1,
     *     system: 2,
     *     type: 1,
     *     orbit: 1,
     *     objectType: 1
     * }
     *
     * type — CoordinateType, objectType — ObjectType. GalaxyOrbits является
     * server-side source of truth для радиуса и назначения орбиты.
     */
    protected function resolveTarget(FleetTarget $target, ?AccountData $owner = null, ?Fleet $source = null, ?float $at = null): array
    {
        if ($target->getCoordinateType() === CoordinateType::FLEET) {
            try {
                if ($owner === null) throw new \InvalidArgumentException('Целевой флот недоступен.');
                $fleet = FleetRendezvousService::target((int)$owner['User']['id'], (int)$target->getFleetId(), $source?->getId());
                $point = FleetRendezvousService::position($fleet, $at ?? microtime(true));
                return ['valid' => true, 'messages' => [], 'type' => 'fleet', 'fleetId' => $fleet->getId(), 'delivery' => true,
                    'owner_id' => $fleet->getOwnerId(), 'galaxy' => $point->getGalaxy(), 'system' => $point->getSystem(),
                    'distance' => $point->getDistance(), 'deg' => $point->getAngle(), 'position' => $point->toArray()];
            } catch (\InvalidArgumentException|\LogicException $error) {
                if ($owner !== null && $source !== null) try {
                    $observation = FleetInterceptionService::observation((int)$owner['User']['id'], (int)$target->getFleetId(), $at ?? microtime(true));
                    $point = Coordinate::fromArray($observation['position']);
                    return ['valid' => true, 'messages' => [], 'type' => 'fleet', 'fleetId' => (int)$target->getFleetId(), 'delivery' => false,
                        'owner_id' => (int)\SPGame\Game\Repositories\Fleets::getById((int)$target->getFleetId())['owner_id'],
                        'galaxy' => $point->getGalaxy(), 'system' => $point->getSystem(), 'distance' => $point->getDistance(),
                        'deg' => $point->getAngle(), 'position' => $point->toArray()];
                } catch (\InvalidArgumentException|\LogicException) { /* ID без разрешённого снимка не раскрывает цель. */ }
                return $this->invalidTarget($error->getMessage());
            }
        }

        $galaxy = $target->getGalaxy();
        $system = $target->getSystem();
        $objectType = $target->getObjectType();

        if ($galaxy <= 0 || $system <= 0) {
            return $this->invalidTarget(
                'Некорректные координаты цели.'
            );
        }

        /*
        * Свободная точка.
        */
        if ($target->getCoordinateType() === CoordinateType::POINT) {
            return $this->resolvePointTarget($target);
        }

        $orbit = $target->getOrbit();

        if ($orbit <= 0) {
            return $this->invalidTarget(
                'Не указана орбита цели.'
            );
        }

        /*
        * Получаем данные орбиты.
        */
        $GalaxyOrbitRows = GalaxyOrbits::findByIndex(
            'galaxy_system_orbit',
            [$galaxy, $system, $orbit]
        );

        if (!$GalaxyOrbitRows) {
            return $this->invalidTarget(
                "Орбита {$galaxy}:{$system}:{$orbit} не найдена."
            );
        }

        $GalaxyOrbit = $GalaxyOrbitRows;

        $orbitType = (int)$GalaxyOrbit['type'];
        if ($orbitType === GalaxyOrbits::SYSTEM_EDGE) {
            return $this->invalidTarget('Граница системы недоступна как пользовательская цель.');
        }

        if ($orbitType === GalaxyOrbits::EXPEDITION) {
            if ($objectType !== null) {
                return $this->invalidTarget('Экспедиционная орбита не является типом объекта.');
            }

            return [
                'valid' => true,
                'galaxy' => $galaxy,
                'system' => $system,
                'orbit' => $orbit,
                'type' => 'object',
                'object_type' => null,
                'orbit_type' => $orbitType,
                'planet' => null,
                'moon' => null,
                'debris' => null,
                'distance' => (float)($GalaxyOrbit['distance'] ?? 0),
            ];
        }

        if (in_array($objectType, [ObjectType::GAS_GIANT, ObjectType::ASTEROID_SECTOR], true)) {
            try { $site = FleetSurveyService::site(Coordinate::fromArray($target->toCoordinates())); }
            catch (\InvalidArgumentException $error) { return $this->invalidTarget($error->getMessage()); }
            return ['valid' => true, 'galaxy' => $galaxy, 'system' => $system, 'orbit' => $orbit,
                'type' => 'object', 'object_type' => $objectType->value, 'orbit_type' => $orbitType,
                'resource_site' => $site, 'planet' => null, 'moon' => null, 'debris' => null,
                'distance' => (float)$GalaxyOrbit['distance']];
        }
        $systemRows = Galaxy::findByIndex('galaxy_system', [$galaxy, $system]);
        $systemData = is_array($systemRows) && isset($systemRows[0])
            ? $systemRows[0]
            : null;
        if (
            $systemData !== null
            && $orbit > (int)($systemData['max_orbits'] ?? 0)
        ) {
            return $this->invalidTarget('Орбита выходит за пределы выбранной системы.');
        }

        // Фактическая запись Planets подтверждает занятость орбиты. Это также
        // позволяет корректно обработать старые планеты, созданные до починки
        // маркировки GalaxyOrbits::PLANET.
        $planet = Planets::findByIndex(
            'galaxy_system_planet_type',
            [$galaxy, $system, $orbit, ObjectType::PLANET->value]
        );

        // Защитный fallback для уже работающего runtime: если точечный
        // unique-индекс рассинхронизирован, координатный индекс всё ещё
        // позволяет однозначно найти планету нужного типа.
        if ($planet === null) {
            $coordinateRows = Planets::findByIndex(
                'galaxy_system_planet',
                [$galaxy, $system, $orbit]
            ) ?? [];

            foreach ($coordinateRows as $coordinateRow) {
                if ((int)($coordinateRow['planet_type'] ?? 0) === ObjectType::PLANET->value) {
                    $planet = $coordinateRow;
                    Logger::getInstance()->warning(
                        "FleetMissionService: восстановлена планета {$galaxy}:{$system}:{$orbit} через координатный индекс"
                    );
                    break;
                }
            }
        }

        if ($planet !== null && $orbitType === GalaxyOrbits::EMPTY) {
            Logger::getInstance()->warning(
                "FleetMissionService: планета {$galaxy}:{$system}:{$orbit} находится на орбите с типом EMPTY"
            );
            $orbitType = GalaxyOrbits::PLANET;
        }

        if ($objectType === null) {
            // Совместимость со старой записью PLANET, где тип объекта лежал в type.
            if ($orbitType !== GalaxyOrbits::PLANET) {
                return $this->invalidTarget('Неизвестный тип объекта.');
            }
            $objectType = ObjectType::PLANET;
        }


        $moon = Planets::findByIndex(
            'galaxy_system_planet_type',
            [$galaxy, $system, $orbit, ObjectType::MOON->value]
        );
        $debris = DebrisFields::at($galaxy, $system, $orbit);

        return [
            'valid' => true,

            'galaxy' => $galaxy,
            'system' => $system,
            'orbit' => $orbit,

            'type' => 'object',
            'object_type' => $objectType->value,
            'orbit_type' => $orbitType,

            'planet' => $planet ?? null,
            'moon' => $moon ?? null,
            'debris' => $debris ?? null,

            'distance' => (float)($GalaxyOrbit['distance'] ?? 0),
        ];
    }

    /**
     * Цель является свободной точкой пространства.
     */
    protected function resolvePointTarget(FleetTarget $target): array
    {

        if ($target->getDistance() === null) {
            return $this->invalidTarget(
                'Не указано расстояние до точки.'
            );
        }

        if ($target->getDeg() === null) {
            return $this->invalidTarget(
                'Не указан угол точки.'
            );
        }

        $galaxy = $target->getGalaxy();
        $system = $target->getSystem();
        $distance = $target->getDistance();
        $deg = $target->getDeg();

        $systems = Galaxy::findByIndex('galaxy_system', [$galaxy, $system]) ?? [];
        if (!is_finite($distance) || !is_finite($deg) || !$systems) {
            return $this->invalidTarget('Свободная точка недоступна.');
        }
        $bounds = self::pointDistanceBounds($galaxy, $system);
        if ($bounds === null) return $this->invalidTarget('Границы системы недоступны.');
        $deg = fmod(fmod($deg, 360.0) + 360.0, 360.0);
        $pointAllowed = $distance >= $bounds['min'] && $distance <= $bounds['max'];
        $fields = $distance < 0 ? [] : SpaceDebrisFields::atPoint(Coordinate::point($galaxy, $system, $distance, $deg));
        if (!$pointAllowed && $fields === []) {
            return $this->invalidTarget('Расстояние до свободной точки должно быть от ' . $bounds['min'] . ' до ' . $bounds['max'] . '.');
        }

        return [
            'valid' => true,

            'galaxy' => $galaxy,
            'system' => $system,

            'type' => 'point',

            // Физическая точка боя может оказаться вне области свободного удержания.
            // Это разрешает сбор существующих обломков, но не произвольный OBSERVE.
            'point_allowed' => $pointAllowed,
            'space_debris' => $fields !== [],

            'distance' => $distance,
            'deg' => $deg
        ];
    }


    /**
     * Определить доступные миссии.
     *
     * Пока это только каркас.
     *
     * Правила будем добавлять постепенно, чтобы не повторить
     * огромный монолит старого GetMissionType().
     */
    protected function determineMissions(array $context): array
    {
        $missions = [];

        $YourPlanet = false;
        $UsedPlanet = false;

        $objectType = (int)($context['target']['object_type'] ?? 0);
        $orbitType = (int)($context['target']['orbit_type'] ?? -1);

        $OwnerId = 0;
        $TargetId = 0;

        $missiontype = [];
        $fleetMissions = array_fill_keys(
            $this->fleetCanPerform($context['fleet']),
            true
        );
        if (!empty($context['can_survey']) && isset($fleetMissions[FleetMissionType::RESEARCH->value])) {
            $missiontype[FleetMissionType::RESEARCH->value] = true;
        }
        if (!empty($context['can_resource_spy']) && isset($fleetMissions[FleetMissionType::SPY->value])) {
            $missiontype[FleetMissionType::SPY->value] = true;
        }
        if (!empty($context['can_resource_defend']) && isset($fleetMissions[FleetMissionType::DEFEND->value])) {
            $missiontype[FleetMissionType::DEFEND->value] = true;
        }
        // Контроль определяется реальным составом и технологией, не миграцией missions всех старых корпусов.
        if (!empty($context['can_capture'])) $missiontype[FleetMissionType::CAPTURE->value] = true;
        if (!empty($context['can_mine'])) $missiontype[FleetMissionType::MINING->value] = true;
        if (!empty($context['can_resource_relocate']) && isset($fleetMissions[FleetMissionType::RELOCATE->value])) {
            $missiontype[FleetMissionType::RELOCATE->value] = true;
        }

        if (!empty($context['target']['space_debris']) && isset($fleetMissions[FleetMissionType::RECYCLE->value])) {
            $missiontype[FleetMissionType::RECYCLE->value] = true;
        }

        if (($context['target']['type'] ?? '') === 'point' && !empty($context['can_hold'])) {
            $missiontype[FleetMissionType::OBSERVE->value] = true;
        }
        if (($context['target']['type'] ?? '') === 'fleet' && !empty($context['target']['delivery'])
            && isset($fleetMissions[FleetMissionType::TRANSPORT->value])) {
            $missiontype[FleetMissionType::TRANSPORT->value] = true;
        }
        if (!empty($context['can_intercept']) && isset($fleetMissions[FleetMissionType::ATTACK->value])) {
            $missiontype[FleetMissionType::ATTACK->value] = true;
        }
        if (($context['target']['type'] ?? '') === 'fleet'
            && FleetMergeService::reason($context['fleet'], (int)$context['target']['fleetId']) === null) {
            $missiontype[FleetMissionType::MERGE->value] = true;
        }

        if ($objectType === ObjectType::PLANET->value || $objectType === ObjectType::MOON->value) {
            $object = $context['target'][$objectType === ObjectType::MOON->value ? 'moon' : 'planet'];
            $TargetId = (int)($object['id'] ?? 0);
            $OwnerId = (int)($object['owner_id'] ?? 0);
            $YourPlanet = ($TargetId > 0 && $context['owner_id'] === $OwnerId);
            $UsedPlanet = ($TargetId > 0 && $OwnerId > 0);
        }



        //$combo = Vars::$CombatCaps;

        if ($objectType === ObjectType::DEBRIS->value) {
            if (isset($fleetMissions[FleetMissionType::RECYCLE->value])
                && (float)($context['target']['debris']['metal'] ?? 0)
                    + (float)($context['target']['debris']['crystal'] ?? 0) > 0) {
                $missiontype[FleetMissionType::RECYCLE->value] = true;
            }
        }
        if ($objectType === ObjectType::PLANET->value || $objectType === ObjectType::MOON->value) {

            if (
                isset($fleetMissions[FleetMissionType::COLONIZE->value])
                && !$UsedPlanet && $objectType === ObjectType::PLANET->value && ($context['can_colonize'] ?? false)
            ) {
                $missiontype[FleetMissionType::COLONIZE->value] = true;
            }

            if (
                isset($fleetMissions[FleetMissionType::SPY->value]) &&
                !$YourPlanet && $UsedPlanet
            ) {
                $missiontype[FleetMissionType::SPY->value] = true;
            }

            if (
                isset($fleetMissions[FleetMissionType::ATTACK->value]) &&
                !$YourPlanet && $UsedPlanet
            ) {
                $missiontype[FleetMissionType::ATTACK->value] = true;
            }
            if (isset($fleetMissions[FleetMissionType::GROUP_ATTACK->value]) && !$YourPlanet && $UsedPlanet) {
                $missiontype[FleetMissionType::GROUP_ATTACK->value] = true;
            }
            // Звезда смерти — специальное требование Nova; старые vars могут не содержать destroy.
            if ($objectType === ObjectType::MOON->value && !$YourPlanet && $UsedPlanet
                && FleetDestroyService::deathStars($context['fleet']) > 0) {
                $missiontype[FleetMissionType::DESTROY->value] = true;
            }

            if (
                isset($fleetMissions[FleetMissionType::DEFEND->value]) &&
                !$YourPlanet && $UsedPlanet && FleetDefendService::reason($context['fleet'], $object) === null
            ) {
                $missiontype[FleetMissionType::DEFEND->value] = true;
            }

            if (
                isset($fleetMissions[FleetMissionType::TRANSPORT->value]) &&
                $UsedPlanet
            ) {
                $missiontype[FleetMissionType::TRANSPORT->value] = true;
            }
        }

        if ($YourPlanet && isset($fleetMissions[FleetMissionType::RELOCATE->value])) {
            $missiontype[FleetMissionType::RELOCATE->value] = true;
        }

        if (
            $orbitType === GalaxyOrbits::EXPEDITION
            && isset($fleetMissions[FleetMissionType::EXPEDITION->value])
            && ($context['can_expedition'] ?? false)
        ) {
            $missiontype[FleetMissionType::EXPEDITION->value] = true;
        }

        

        $missions = array_keys($missiontype);

        sort($missions);

        return $missions;
    }

    /**
     * Возвращает все миссии, которые может выполнять состав флота.
     *
     * Результат: массив ID миссий (int) без дубликатов.
     */
    protected function fleetCanPerform(Fleet $fleet): array
    {
        $ships = $fleet->getShips();
        if (!$ships) {
            return [];
        }

        $missions = [];
        $seen = [];

        foreach ($ships as $ship) {
            if ($ship->getCount() <= 0) {
                continue;
            }

            $elementId = $ship->getShipTypeId();

            // Vars::loadAll() преобразует fleetMissions в массив ID FleetMissionType.
            $availableMissions = Vars::$attributes[$elementId]['missions'] ?? null;

            if (!is_array($availableMissions)) {
                continue;
            }

            foreach ($availableMissions as $missionId) {
                if (!is_int($missionId) || isset($seen[$missionId])) {
                    continue;
                }

                $seen[$missionId] = true;
                $missions[] = $missionId;
            }
        }

        return $missions;
    }

    /**
     * Создать невалидный контекст.
     */
    protected function invalidContext(
        string|array $messages
    ): array {
        return [
            'valid' => false,
            'messages' => is_array($messages)
                ? $messages
                : [$messages],
        ];
    }

    /**
     * Создать невалидную цель.
     */
    protected function invalidTarget(
        string|array $messages
    ): array {
        return [
            'valid' => false,
            'messages' => is_array($messages)
                ? $messages
                : [$messages],
        ];
    }
}
