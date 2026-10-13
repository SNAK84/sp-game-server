<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Planets;

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
        AccountData $accountData
    ): array {
        $context = $this->buildContext($accountData, $fleet, $target);

        if (!$context['valid']) {
            return [
                'missions' => [],
                'messages' => $context['messages'],
                'disabled' => true,
            ];
        }

        $missions = $this->determineMissions($context);

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
        ?FleetMissionType $mission = null
    ): ?array {
        $context = $this->buildContext($accountData, $fleet, $target);

        if (!$context['valid']) {
            return null;
        }

        $homePlanet = Planets::findById($fleet->getHomePlanetId());
        if (!$homePlanet) {
            return null;
        }

        $requestedTarget = $target;

        $startGalaxy = (int)$homePlanet['galaxy'];
        $startSystem = (int)$homePlanet['system'];
        $startRadius = (float)$homePlanet['distance'];

        $target = $context['target'];
        $targetGalaxy = (int)$target['galaxy'];
        $targetSystem = (int)$target['system'];
        $targetRadius = (float)($target['distance'] ?? 0.0);

        $flight = new FleetFlightService();

        if ($startGalaxy === $targetGalaxy && $startSystem === $targetSystem) {
            $localTransferDistance = null;
            $startObjectType = ObjectType::tryFrom((int)($homePlanet['planet_type'] ?? 0));
            $targetObjectType = $requestedTarget->getObjectType();

            if ($startObjectType !== null && $targetObjectType !== null) {
                $localTransferDistance = $flight->getLocalObjectTransferDistance(
                    Coordinate::object(
                        $startGalaxy,
                        $startSystem,
                        (int)$homePlanet['planet'],
                        $startObjectType
                    ),
                    Coordinate::object(
                        $targetGalaxy,
                        $targetSystem,
                        $requestedTarget->getOrbit(),
                        $targetObjectType
                    )
                );
            }

            if ($localTransferDistance !== null) {
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
                $maxDistance = $startRadius + $targetRadius;
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
                $destinationMaxDistance = $entryRadius + $targetRadius;
            }

            $minSegments = [$sourceExitDistance, $jumpDistance, $destinationMinDistance];
            $maxSegments = [$sourceExitDistance, $jumpDistance, $destinationMaxDistance];
            $returnMinSegments = [
                abs($entryRadius - $targetRadius),
                $jumpDistance,
                abs($exitRadius - $startRadius),
            ];
            $returnMaxSegments = [
                abs($entryRadius - $targetRadius),
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
                $flight->calculateInSystemGameDistance(abs($entryRadius - $targetRadius)),
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
        if ($mission === FleetMissionType::TRANSPORT) {
            $fuelMinSegments = array_merge($fuelMinSegments, $returnMinGameSegments);
            $fuelMaxSegments = array_merge($fuelMaxSegments, $returnMaxGameSegments);
        }

        return [
            'distance' => [
                'min' => (int)round($minDistance),
                'max' => (int)round($maxDistance),
            ],
            'duration' => [
                'min' => (int)round($this->calculateSegmentsDuration($flight, $minGameSegments, $fleetStats['speed'], $speedFactor)),
                'max' => (int)round($this->calculateSegmentsDuration($flight, $maxGameSegments, $fleetStats['speed'], $speedFactor)),
            ],
            'fuel' => [
                // Командный pipeline списывает топливо по каждому MOVE, поэтому
                // округление выполняется отдельно для каждого участка.
                'min' => $this->calculateSegmentsFuel(
                    $flight,
                    $fleet,
                    $accountData,
                    $fuelMinSegments,
                    $speedFactor
                ),
                'max' => $this->calculateSegmentsFuel(
                    $flight,
                    $fleet,
                    $accountData,
                    $fuelMaxSegments,
                    $speedFactor
                ),
            ],
        ];
    }

    /** @param list<float> $segments */
    private function calculateSegmentsFuel(
        FleetFlightService $flight,
        Fleet $fleet,
        AccountData $accountData,
        array $segments,
        ?float $speedFactor
    ): float {
        $fuel = 0.0;
        foreach ($segments as $distance) {
            if ($distance > 0.0) {
                $fuel += $flight->calculateFuelConsumption(
                    $fleet,
                    $accountData,
                    [(float)$distance],
                    $speedFactor
                );
            }
        }

        return $fuel;
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
        FleetTarget $target
    ): array {
        $messages = [];

        $resolvedTarget = $this->resolveTarget($target);

        if (!$resolvedTarget['valid']) {
            return [
                'valid' => false,
                'messages' => $resolvedTarget['messages'],
            ];
        }

        if ((int)($resolvedTarget['orbit_type'] ?? -1) === GalaxyOrbits::EXPEDITION) {
            $current = $fleet->getCurrentCoordinate();
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

        return [
            'valid' => true,

            'owner_id' => $ownerId,

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
    protected function resolveTarget(FleetTarget $target): array
    {
        if ($target->getCoordinateType() === CoordinateType::FLEET) {
            return $this->invalidTarget(
                'Определение позиции целевого флота пока не реализовано.'
            );
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


        /*
        Тут будет поиск луны и обломков
        */

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

        if ($distance < 0) {
            return $this->invalidTarget(
                'Расстояние не может быть отрицательным.'
            );
        }

        /*
         * Нормализуем угол.
         */
        $deg = fmod($deg, 360.0);

        if ($deg < 0) {
            $deg += 360.0;
        }

        return [
            'valid' => true,

            'galaxy' => $galaxy,
            'system' => $system,

            'type' => 'point',

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

        if ($objectType === ObjectType::PLANET->value || $objectType === ObjectType::MOON->value) {
            $TargetId = (int)($context['target']['planet']['id'] ?? 0);
            $OwnerId = (int)($context['target']['planet']['owner_id'] ?? 0);
            $YourPlanet = ($TargetId > 0 && $context['owner_id'] === $OwnerId);
            $UsedPlanet = ($TargetId > 0);
        } else if ($objectType === ObjectType::DEBRIS->value) {
            $TargetId = (int)($context['target']['moon']['id'] ?? 0);
            $OwnerId = (int)($context['target']['moon']['owner_id'] ?? 0);
            $YourPlanet = ($TargetId > 0 && $context['owner_id'] === $OwnerId);
            $UsedPlanet = ($TargetId > 0);
        }



        //$combo = Vars::$CombatCaps;

        if ($objectType === ObjectType::DEBRIS->value) {
            if (isset($fleetMissions[FleetMissionType::RECYCLE->value])) {
                $missiontype[FleetMissionType::RECYCLE->value] = true;
            }
        }
        if ($objectType === ObjectType::PLANET->value || $objectType === ObjectType::MOON->value) {

            if (
                isset($fleetMissions[FleetMissionType::COLONIZE->value])
                && !$UsedPlanet && $objectType === ObjectType::PLANET->value
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

            if (
                isset($fleetMissions[FleetMissionType::DEFEND->value]) &&
                !$YourPlanet && $UsedPlanet
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
