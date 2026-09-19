<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Planets;

use SPGame\Game\Repositories\Vars;

/**
 * Логика определения доступных миссий флота.
 *
 * Отвечает только за:
 * - определение флота;
 * - определение цели;
 * - определение возможностей флота;
 * - определение доступных миссий.
 *
 * Построением цепочки команд занимается FleetMissionBuilder.
 */
class FleetMissionService
{


    /**
     * Типы навигационных целей, которые сейчас передаёт клиент.
     *
     * 1..3 — объект на орбите.
     * 4    — свободная точка в пространстве.
     */
    public const NAVIGATION_POINT = 4;

    /**
     * Получить доступные миссии для флота.
     *
     * @param AccountData $accountData Данные текущего игрока.
     * @param int $fleetId             ID флота.
     * @param array $coordinates       [
     *                                    'galaxy' => 1,
     *                                    'system' => 2,
     *                                    'type'   => 1,
     *                                    'orbit'  => 1,
     *                                ]
     *
     * @return array{
     *     missions: array,
     *     messages: array,
     *     disabled: bool
     * }
     */
    public function getAvailableMissions(
        AccountData $accountData,
        int $fleetId,
        array $coordinates
    ): array {
        $context = $this->buildContext(
            $accountData,
            $fleetId,
            $coordinates
        );

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

    public function getAvailableMissionsForTarget(
        AccountData $accountData,
        array $coordinates
    ): array {
        $fleets = $accountData['Fleets'];
        if (!is_array($fleets)) {
            return [];
        }

        $missions = [];
        foreach (array_keys($fleets) as $fleetId) {
            $available = $this->getAvailableMissions($accountData, (int)$fleetId, $coordinates);
            foreach ($available['missions'] as $missionId) {
                $missions[(int)$missionId] = true;
            }
        }

        $missionIds = array_keys($missions);
        sort($missionIds);

        return $missionIds;
    }

    /**
     * Рассчитать диапазон расстояния, времени полёта и расхода топлива до цели.
     *
     * Флот стартует со своей домашней планеты. Внутри одной системы расстояние
     * зависит от неизвестного угла планет на орбите, поэтому считается диапазоном
     * [min; max] по правилу треугольника (|R1-R2| .. R1+R2).
     *
     * При перелёте в другую систему/галактику флот покидает систему на радиусе
     * "дальняя орбита системы + 500" и входит в целевую систему на таком же
     * радиусе в случайной точке, поэтому итоговый диапазон складывается из
     * диапазона выхода, фиксированного "прыжка" (Config::InterSystemJumpDistance /
     * InterGalaxyJumpDistance) и диапазона входа к цели.
     *
     * @return array{distance: array{min:int,max:int}, duration: array{min:int,max:int}, fuel: array{min:float,max:float}}|null
     */
    public function getTravelInfo(
        AccountData $accountData,
        int $fleetId,
        array $coordinates
    ): ?array {
        $context = $this->buildContext($accountData, $fleetId, $coordinates);

        if (!$context['valid']) {
            return null;
        }

        $homePlanet = Planets::findById((int)($context['fleet']['home_planet_id'] ?? 0));
        if (!$homePlanet) {
            return null;
        }

        $startGalaxy = (int)$homePlanet['galaxy'];
        $startSystem = (int)$homePlanet['system'];
        $startRadius = (float)$homePlanet['distance'];

        $target = $context['target'];
        $targetGalaxy = (int)$target['galaxy'];
        $targetSystem = (int)$target['system'];
        $targetRadius = (float)($target['distance'] ?? 0.0);

        if ($startGalaxy === $targetGalaxy && $startSystem === $targetSystem) {
            $minDistance = abs($startRadius - $targetRadius);
            $maxDistance = $startRadius + $targetRadius;
        } else {
            $startSystemInfo = Galaxy::getSystem($startGalaxy, $startSystem);
            $targetSystemInfo = Galaxy::getSystem($targetGalaxy, $targetSystem);

            $exitRadius = (float)($startSystemInfo['max_distance'] ?? 0) + 500.0;
            $entryRadius = (float)($targetSystemInfo['max_distance'] ?? 0) + 500.0;

            $jumpDistance = abs($startSystem - $targetSystem) * (float)Config::getValue('InterSystemJumpDistance');
            if ($startGalaxy !== $targetGalaxy) {
                $jumpDistance += abs($startGalaxy - $targetGalaxy) * (float)Config::getValue('InterGalaxyJumpDistance');
            }

            $minDistance = abs($startRadius - $exitRadius) + $jumpDistance + abs($entryRadius - $targetRadius);
            $maxDistance = ($startRadius + $exitRadius) + $jumpDistance + ($entryRadius + $targetRadius);
        }

        $fleetStats = $this->calcFleetStats($context['fleet'], $accountData);

        if ($fleetStats['speed'] <= 0) {
            return null;
        }

        $gameSpeed = (float)Config::getValue('FleetGameSpeed');
        $speedFactor = (float)Config::getValue('FleetSpeedFactor');

        return [
            'distance' => [
                'min' => (int)round($minDistance),
                'max' => (int)round($maxDistance),
            ],
            'duration' => [
                'min' => $this->calcDuration($minDistance, $fleetStats['speed'], $gameSpeed, $speedFactor),
                'max' => $this->calcDuration($maxDistance, $fleetStats['speed'], $gameSpeed, $speedFactor),
            ],
            'fuel' => [
                'min' => $this->calcFuel($fleetStats['consumption'], $minDistance),
                'max' => $this->calcFuel($fleetStats['consumption'], $maxDistance),
            ],
        ];
    }

    /**
     * Суммарное потребление топлива и минимальная скорость флота (по составу кораблей).
     */
    protected function calcFleetStats(array|\SPGame\Game\Fleet\Fleet $fleet, AccountData $accountData): array
    {
        $ships = $fleet['Ships'] ?? [];
        if ($ships instanceof \SPGame\Game\Fleet\FleetShipsView) {
            $ships = $ships->toArray();
        }

        $consumption = 0.0;
        $speeds = [];

        foreach (Vars::$reslist['fleet'] as $shipId) {
            $name = Vars::$resource[$shipId];
            $count = (int)($ships[$name . '_count'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $consumption += FleetFunctions::GetShipConsumption($shipId, $accountData) * $count;
            $speeds[] = FleetFunctions::GetShipSpeed($shipId, $accountData);
        }

        return [
            'consumption' => $consumption,
            'speed' => $speeds ? min($speeds) : 0.0,
        ];
    }

    /**
     * Классическая формула времени полёта (см. старую версию игры).
     */
    protected function calcDuration(float $distance, float $speed, float $gameSpeed, float $speedFactor): int
    {
        if ($speed <= 0 || $gameSpeed <= 0 || $speedFactor <= 0) {
            return 0;
        }

        return (int)round(((35000 / $gameSpeed) * sqrt($distance * 10 / $speed) + 10) / $speedFactor);
    }

    /**
     * Расход топлива пропорционален суммарному потреблению кораблей и расстоянию.
     */
    protected function calcFuel(float $consumption, float $distance): float
    {
        return round($consumption * $distance / 10000, 2);
    }

    /**
     * Построить полный контекст флота и цели.
     *
     * Это основной подготовительный этап перед определением миссий.
     */
    protected function buildContext(
        AccountData $accountData,
        int $fleetId,
        array $coordinates
    ): array {
        $messages = [];

        $fleet = $this->getFleet($accountData, $fleetId);

        if (!$fleet) {
            return [
                'valid' => false,
                'messages' => ['Флот не найден.'],
            ];
        }

        $target = $this->resolveTarget($coordinates);

        if (!$target['valid']) {
            return [
                'valid' => false,
                'messages' => $target['messages'],
            ];
        }

        Logger::getInstance()->info("target",  $target);



        $ownerId = (int)($accountData['User']['id'] ?? 0);
        $targetOwnerId = (int)($target['owner_id'] ?? 0);

        return [
            'valid' => true,

            'owner_id' => $ownerId,

            // Флот
            'fleet_id' => $fleetId,
            'fleet' => $fleet,

            // Цель
            'coordinates' => $coordinates,
            'target' => $target,


            'messages' => $messages,
        ];
    }

    protected function getFleet(
        AccountData $accountData,
        int $fleetId
    ): ?\SPGame\Game\Fleet\Fleet {
        if ($fleetId <= 0) {
            return null;
        }

        return $accountData->getFleetsData()->get($fleetId);
    }

    /**
     * Определить объект по координатам.
     *
     * coordinates:
     * {
     *     galaxy: 1,
     *     system: 2,
     *     type: 1,
     *     orbit: 1
     * }
     *
     * type здесь является типом объекта GalaxyOrbits:
     *
     * GalaxyOrbits::EMPTY
     * GalaxyOrbits::ASTEROID_BELT
     * GalaxyOrbits::GAS_GIANT
     * GalaxyOrbits::PLANET
     */
    protected function resolveTarget(array $coordinates): array
    {
        $galaxy = (int)($coordinates['galaxy'] ?? 0);
        $system = (int)($coordinates['system'] ?? 0);
        $type   = (int)($coordinates['type'] ?? 0);

        if ($galaxy <= 0 || $system <= 0) {
            return $this->invalidTarget(
                'Некорректные координаты цели.'
            );
        }

        /*
        * Свободная точка.
        */
        if ($type === self::NAVIGATION_POINT) {
            return $this->resolvePointTarget($coordinates);
        }


        $orbit = (int)($coordinates['orbit'] ?? 0);

        if ($orbit <= 0) {
            return $this->invalidTarget(
                'Не указана орбита цели.'
            );
        }

        /*
        * Получаем данные орбиты.
        */
        $GalaxyOrbit = GalaxyOrbits::findByIndex(
            'galaxy_system_orbit',
            [$galaxy, $system, $orbit]
        );

        if (!$GalaxyOrbit) {
            return $this->invalidTarget(
                "Орбита {$galaxy}:{$system}:{$orbit} не найдена."
            );
        }


        if ($GalaxyOrbit['type'] === GalaxyOrbits::PLANET) {
            // На орбите есть планета, определяем её

            $planet = Planets::findByIndex(
                'galaxy_system_planet_type',
                [$galaxy, $system, $orbit, '1']
            );

            /*
            Тут будет поиск луны и обломков
            */
        }

        return [
            'valid' => true,

            'galaxy' => $galaxy,
            'system' => $system,
            'orbit' => $orbit,

            'type' => 'object',

            'planet' => $planet ?? null,
            'moon' => $moon ?? null,
            'debris' => $debris ?? null,

            'distance' => (float)($GalaxyOrbit['distance'] ?? 0),
        ];
    }

    /**
     * Цель является свободной точкой пространства.
     */
    protected function resolvePointTarget(array $coordinates): array
    {

        if (!isset($coordinates['distance'])) {
            return $this->invalidTarget(
                'Не указано расстояние до точки.'
            );
        }

        if (!isset($coordinates['deg'])) {
            return $this->invalidTarget(
                'Не указан угол точки.'
            );
        }

        $galaxy = (int)($coordinates['galaxy']);
        $system = (int)($coordinates['system']);
        $distance = (float)$coordinates['distance'];
        $deg = (float)$coordinates['deg'];

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

        $coordinates = $context['coordinates'];
        $coordinates['type'] = (int)$coordinates['type'];

        $OwnerId = 0;
        $TargetId = 0;

        $missiontype = [];
        $fleetMissions = array_fill_keys(
            $this->fleetCanPerform($context['fleet']),
            true
        );

        if ($coordinates['type'] === 1 || $coordinates['type'] === 3) {
            $TargetId = (int)($context['target']['planet']['id'] ?? 0);
            $OwnerId = (int)($context['target']['planet']['owner_id'] ?? 0);
            $YourPlanet = ($TargetId > 0 && $context['owner_id'] === $OwnerId);
            $UsedPlanet = ($TargetId > 0);
        } else if ($coordinates['type'] === 2) {
            $TargetId = (int)($context['target']['moon']['id'] ?? 0);
            $OwnerId = (int)($context['target']['moon']['owner_id'] ?? 0);
            $YourPlanet = ($TargetId > 0 && $context['owner_id'] === $OwnerId);
            $UsedPlanet = ($TargetId > 0);
        }



        //$combo = Vars::$CombatCaps;

        if ($coordinates['type'] === 2) {
            if (isset($fleetMissions[FleetMissionType::RECYCLE->value])) {
                $missiontype[FleetMissionType::RECYCLE->value] = true;
            }
        }
        if ($coordinates['type'] === 1 || $coordinates['type'] === 3) {

            if (
                isset($fleetMissions[FleetMissionType::COLONIZE->value])
                && !$UsedPlanet && $coordinates['type'] === 1
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

        

        $missions = array_keys($missiontype);

        sort($missions);

        return $missions;
    }

    /**
     * Возвращает все миссии, которые может выполнять состав флота.
     *
     * Результат: массив ID миссий (int) без дубликатов.
     */
    protected function fleetCanPerform(array|\SPGame\Game\Fleet\Fleet $fleet): array
    {
        $ships = $fleet['Ships'] ?? [];

        if ($ships instanceof \SPGame\Game\Fleet\FleetShipsView) $ships = $ships->toArray();

        if (!is_array($ships) || !$ships) {
            return [];
        }

        $missions = [];
        $seen = [];

        foreach (Vars::$reslist['fleet'] as $elementId) {
            $shipName = Vars::$resource[$elementId] ?? null;

            if (!$shipName) {
                continue;
            }

            $count = (int)($ships[$shipName . '_count'] ?? 0);

            if ($count <= 0) {
                continue;
            }

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
