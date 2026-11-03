<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;
use SPGame\Core\Message;

use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\OrbitalResourceSites;
use SPGame\Game\Repositories\DebrisFields;
use SPGame\Game\Repositories\Planets;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Repositories\Friends;
use SPGame\Game\Services\FriendsService;

use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Services\GalaxyGenerator;
use SPGame\Game\Services\CelestialNameGenerator;
use SPGame\Game\Services\QueuesServices;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Enums\FleetCommandType;

class GalaxyPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $language = (string)($AccountData['Account']['lang'] ?? 'ru');

        $galaxy = $this->Msg->getData('galaxy', (int)$Planet['galaxy']);
        $system = $this->Msg->getData('system', (int)$Planet['system']);

        //$PlanetsVisual = Planets::getSystemPlanetsVisual($Planet['galaxy'], $Planet['system']);
        $System  = Galaxy::getSystem($galaxy, $system);
        GalaxyGenerator::ensureResourceSitesForSystem($System);
        $Orbits  = GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]);
        $Planets = Planets::findByIndex('galaxy_system', [$galaxy, $system]);
        $ResourceSites = OrbitalResourceSites::findByIndex('galaxy_system', [$galaxy, $system]) ?? [];

        // Имена ресурсных объектов — представление сохранённой системы и орбит.
        $systemName = (string)($System['name'] ?? '');
        $beltOrbits = array_column(array_filter($Orbits, static fn(array $orbit): bool =>
            (int)$orbit['type'] === GalaxyOrbits::ASTEROID_BELT), 'orbit');
        sort($beltOrbits, SORT_NUMERIC);
        $beltOrdinals = array_flip($beltOrbits);
        $orbitNames = [];
        foreach ($Orbits as &$orbit) {
            $orbitNumber = (int)$orbit['orbit'];
            if ((int)$orbit['type'] === GalaxyOrbits::GAS_GIANT) {
                $orbit['name'] = CelestialNameGenerator::gasGiantName($systemName, $orbitNumber, $language);
            } elseif ((int)$orbit['type'] === GalaxyOrbits::ASTEROID_BELT) {
                $orbit['name'] = CelestialNameGenerator::asteroidBeltName(
                    $systemName, $beltOrdinals[$orbitNumber] + 1, $language
                );
            }
            if (isset($orbit['name'])) $orbitNames[$orbitNumber] = $orbit['name'];
        }
        unset($orbit);
        $System['name'] = CelestialNameGenerator::localize($systemName, $language);

        usort($ResourceSites, static function (array $left, array $right): int {
            return [(int)$left['orbit'], (int)$left['sector']]
                <=> [(int)$right['orbit'], (int)$right['sector']];
        });
        $PublicResourceSites = array_map(static fn(array $site): array => [
            'id' => (int)$site['id'],
            'name' => $orbitNames[(int)$site['orbit']] ?? '',
            'site_type' => (int)$site['site_type'],
            'orbit' => (int)$site['orbit'],
            'sector' => (int)$site['sector'],
            'anchor_angle' => (float)$site['anchor_angle'],
            'arc_width' => (float)$site['arc_width'],
            'speed' => (float)$site['speed'],
            'rotation' => (int)$site['rotation'],
            'position_time' => (float)$site['position_time'],
            'size' => (int)$site['size'],
            'visual_variant' => (int)$site['visual_variant'],
            'ring_variant' => (int)$site['ring_variant'],
            'survey' => \SPGame\Game\Services\FleetSurveyService::observation((int)$User['id'], (int)$site['id']),
            'control' => \SPGame\Game\Services\ResourceBaseService::publicControl((int)$site['id'], (int)$User['id']),
            'basesEnabled' => \SPGame\Game\Services\ResourceBaseService::isEnabled(),
        ], $ResourceSites);

        $PlanetUser = [];
        $friendships = [];
        $PlanetsList = [];
        $accountFleets = $AccountData->getFleetsData()->getForAccount((int)$User['id']);
        $phalanx = [
            'sourceId' => (int)$AccountData['WorkPlanet'],
            'level' => (int)($AccountData['Builds']['phalanx'] ?? 0),
            'cost' => \SPGame\Game\Services\PhalanxService::COST,
        ];
        $phalanx['range'] = \SPGame\Game\Services\PhalanxService::range($phalanx['level']);
        foreach ($Planets as &$Planet) {

            // Находим индекс орбиты по полю 'orbit'
            $orbitIndex = array_search($Planet['planet'], array_column($Orbits, 'orbit'));
            $orbitType = null;

            if ($orbitIndex !== false && isset($Orbits[$orbitIndex]['type'])) {
                $orbitType = $Orbits[$orbitIndex]['type'];
            }

            // === 🧭 ADMIN ACTION: Регенерация всех планет ===
            $regen = false;
            if ($regen) {
                $logger = Logger::getInstance();

                $logger->info('Начата полная регенерация всех планет администратором #' . $User['id']);

                $allPlanets = Planets::findAll();
                $total = count($allPlanets);
                $logger->info("Всего планет: {$total}");

                // Сбрасываем координаты
                foreach ($allPlanets as &$planet) {
                    $planet['galaxy'] = 0;
                    $planet['system'] = 0;
                    $planet['planet'] = 0;
                }

                $processed = 0;
                $errors = 0;

                foreach ($allPlanets as &$planet) {
                    $processed++;
                    try {
                        $planetId = (int)$planet['id'];
                        $ownerId  = (int)($planet['owner_id'] ?? 0);

                        // Проверяем — это домашняя планета?
                        $isHome = false;
                        if ($ownerId > 0) {
                            $user = Users::findById($ownerId);
                            if ($user && (int)$user['main_planet'] === $planetId) {
                                $isHome = true;
                            }
                        }

                        // Назначаем новые координаты
                        GalaxyGenerator::normalizeCoordinates($planet);
                        $g = (int)$planet['galaxy'];
                        $s = (int)$planet['system'];
                        $p = (int)$planet['planet'];

                        if ($g === 0 || $s === 0 || $p === 0) {
                            throw new \RuntimeException("Не удалось назначить координаты для #{$planetId}");
                        }

                        // Получаем систему
                        $system = Galaxy::getSystem($g, $s);
                        $starType = $system['star_type'] ?? 'G';

                        // Находим орбиту
                        $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$g, $s]);
                        $distance = null;
                        foreach ($orbits as $o) {
                            if ((int)$o['orbit'] === $p) {
                                $distance = (int)$o['distance'];
                                break;
                            }
                        }
                        if (!$distance) $distance = 1500;

                        // Генерация новой планеты
                        $newPhys = GalaxyGenerator::generatePlanet(
                            $starType,
                            $distance,
                            $isHome,
                            (int)($system['star_size'] ?? 16),
                            (int)($system['rotation'] ?? 1)
                        );

                        // Обновляем поля
                        $planet['type'] = $newPhys['type'];
                        $planet['image'] = $newPhys['image'];
                        $planet['size'] = $newPhys['size'];
                        $planet['fields'] = $newPhys['fields'];
                        $planet['temp_min'] = $newPhys['temp_min'];
                        $planet['temp_max'] = $newPhys['temp_max'];
                        $planet['distance'] = $distance;
                        $planet['speed'] = $newPhys['speed'];
                        $planet['rotation'] = $newPhys['rotation'];
                        $planet['gravity'] = $newPhys['gravity'];
                        $planet['atmosphere'] = $newPhys['atmosphere'];
                        $planet['habitability'] = $newPhys['habitability'];
                        
                        Planets::update($planet);

                        $logger->info("OK: #{$planetId} G{$g}:S{$s}:P{$p}" . ($isHome ? " [HOME]" : ""));
                    } catch (\Throwable $e) {
                        $errors++;
                        $logger->error("Ошибка у планеты #{$planet['id']}: " . $e->getMessage());
                    }
                }

                
                $logger->info("Регенерация завершена: {$processed} планет, ошибок {$errors}");
                /*return [
                    'page' => 'galaxy_admin_reassign',
                    'message' => "Регенерация завершена. Обработано {$processed} планет, ошибок {$errors}.",
                ];*/
            }

            $ownerId = (int)$Planet['owner_id'];
            if (!array_key_exists($ownerId, $PlanetUser)) {
                $PlanetUser[$ownerId] = Users::findById($ownerId);
            }

            $owner = $PlanetUser[$ownerId];
            $now = time();
            $activity = null;
            if ($owner && (int)($owner['online_time'] ?? 0) > 0) {
                $activityMinutes = max(0, (int)floor(($now - (int)$owner['online_time']) / 60));
                if ($activityMinutes < 60) {
                    $activity = [
                        'status' => $activityMinutes < 15 ? 'active' : 'recent',
                        'minutes' => $activityMinutes,
                    ];
                }
            }


            $target = FleetTarget::fromCoordinates([
                'galaxy' => (int)$Planet['galaxy'],
                'system' => (int)$Planet['system'],
                'orbit' => (int)$Planet['planet'],
                'type' => \SPGame\Game\Space\CoordinateType::OBJECT->value,
                'objectType' => (int)$Planet['planet_type'],
            ]);
            $availableMissions = [];
            foreach ($accountFleets as $fleet) {
                foreach ($fleet->getAvailableMissions($target, $AccountData)['missions'] as $missionId) {
                    $availableMissions[(int)$missionId] = true;
                }
            }
            $availableMissions = array_keys($availableMissions);
            sort($availableMissions);

            $PlanetsList[] = [
                'id'            => $Planet['id'],
                'name'          => CelestialNameGenerator::planetDisplayName($Planet, $language),
                'planet_type'   => $Planet['planet_type'],
                'image'         => $Planet['image'],
                'galaxy'        => $Planet['galaxy'],
                'system'        => $Planet['system'],
                'planet'        => $Planet['planet'],
                'update_time'   => $Planet['update_time'],
                'size'          => $Planet['size'],
                'fields'        => $Planet['fields'],
                'type'          => $Planet['type'],
                'temp_min'      => $Planet['temp_min'],
                'temp_max'      => $Planet['temp_max'],
                'distance'      => $orbitIndex !== false ? (int)$Orbits[$orbitIndex]['distance'] : (int)$Planet['distance'],
                'orbit_type'    => $orbitType,
                'deg'           => $Planet['deg'],
                'speed'         => $Planet['speed'],
                'rotation'      => $Planet['rotation'],
                'owner_id'      => $ownerId,
                'UserName'      => $owner['name'] ?? '-',
                'PlayerStatus'  => self::playerStatus($owner, $now),
                'OwnPlanet'     => $ownerId === (int)$User['id'],
                'Friendship'    => $friendships[$ownerId] ??= FriendsService::relationship((int)$User['id'], $ownerId),
                'Activity'      => $activity,
                'AvailableMissions' => $availableMissions,
                'PhalanxAvailable' => (int)$AccountData['Planet']['planet_type'] === 3 && $phalanx['level'] > 0
                    && (int)$Planet['planet_type'] === 1 && (int)$Planet['galaxy'] === (int)$AccountData['Planet']['galaxy']
                    && abs((int)$Planet['system'] - (int)$AccountData['Planet']['system']) <= $phalanx['range'],
                'Debris' => DebrisFields::at((int)$Planet['galaxy'], (int)$Planet['system'], (int)$Planet['planet']),

            ];
        }


        $System['color'] = Galaxy::$starTypes[$System['star_type']]['color'];
        $starPhysics = GalaxyGenerator::getStarPhysics(
            (string)$System['star_type'],
            (int)$System['star_size']
        );
        $orbitTypeCounts = array_count_values(array_map(
            static fn(array $orbit): int => (int)$orbit['type'],
            $Orbits
        ));
        $SystemInfo = [
            'star' => [
                'name' => CelestialNameGenerator::starName($systemName, $language),
                'type' => (string)$System['star_type'],
                'color' => (string)$System['color'],
                'temperature' => (int)round($starPhysics['temperature']),
                'mass' => round($starPhysics['mass'], 2),
                'radius' => round($starPhysics['radius'], 2),
                'luminosity' => round($starPhysics['real_luminosity'], 2),
            ],
            'orbits' => [
                'ordinary' => (int)$System['max_orbits'],
                'occupied' => count(array_filter($PlanetsList, static fn(array $p): bool => (int)$p['planet_type'] === 1)),
                'asteroid_belts' => (int)($orbitTypeCounts[GalaxyOrbits::ASTEROID_BELT] ?? 0),
                'gas_giants' => (int)($orbitTypeCounts[GalaxyOrbits::GAS_GIANT] ?? 0),
            ],
            'rotation' => (int)$System['rotation'],
        ];

        // На карте системы показываются собственные и направленные к игроку
        // чужие Fleet: внутрисистемные MOVE и стоянки/паузы в текущей позиции.
        // Межсистемные переходы не имеют непрерывной позиции внутри этой карты.
        $FlyingFleets = array_filter(
            (new FleetsPage($this->Msg))->buildVisibleFlyingFleetList($AccountData),
            static fn(array $fleet): bool => self::fleetIsVisibleInSystem($fleet, (int)$galaxy, (int)$system)
        );

        return [
            'page'          => 'galaxy',
            'System'        => $System,
            'SystemInfo'    => $SystemInfo,
            'Orbits'        => $Orbits,
            'ResourceSites' => $PublicResourceSites,
            'SpaceDebris'   => \SPGame\Game\Repositories\SpaceDebrisFields::publicInSystem((int)$galaxy, (int)$system),
            'Planets'       => $PlanetsList,
            'UserId'        => (int)$User['id'],
            'FriendRequestMaxLength' => Friends::REQUEST_MAX_LENGTH,
            'MaxGalaxy'     => (int)Config::getValue('MaxGalaxy'),
            'MaxSystem'     => (int)Config::getValue('MaxSystem'),
            'SpeedPlanets'  => (int)Config::getValue('SpeedPlanets', 24),
            'FleetMissions' => FleetMissionType::list(),
            'FlyingFleets'  => $FlyingFleets,
            'SensorContacts' => array_values(array_filter(
                \SPGame\Game\Services\FleetSensorService::contacts((int)$User['id']),
                static fn(array $c): bool => (int)$c['position']['galaxy'] === (int)$galaxy && (int)$c['position']['system'] === (int)$system
                    && !isset($FlyingFleets[(int)$c['id']])
            )),
            'Phalanx' => $phalanx,
            'PhalanxScan' => \SPGame\Game\Repositories\PhalanxScans::get((int)$User['id']),
        ];
    }

    /** Фильтр карты не расширяет видимость, уже проверенную FleetsPage. */
    private static function fleetIsVisibleInSystem(array $fleet, int $galaxy, int $system): bool
    {
        if ((int)($fleet['status'] ?? -1) === Fleet::STATUS_IDLE
            || (int)($fleet['command']['type'] ?? 0) === FleetCommandType::EXPEDITION->value) return false;
        $stationary = !empty($fleet['blocked_reason']) || in_array((int)($fleet['command']['type'] ?? 0), [
            FleetCommandType::OBSERVE->value, FleetCommandType::DEFEND->value, FleetCommandType::RESEARCH->value,
        ], true);
        if (!$stationary && (int)($fleet['command']['type'] ?? 0) !== FleetCommandType::MOVE_IN_SYSTEM->value) return false;
        $start = $fleet['segment_start'] ?? null;
        $end = $fleet['segment_end'] ?? null;
        if (!is_array($start) || !is_array($end)) return false;

        return (int)($start['galaxy'] ?? 0) === $galaxy && (int)($start['system'] ?? 0) === $system
            && (int)($end['galaxy'] ?? 0) === $galaxy && (int)($end['system'] ?? 0) === $system;
    }

    /** Публичные статусы неактивности: пороги Nova — 7 и 28 дней. */
    private static function playerStatus(?array $owner, int $now): array
    {
        $lastOnline = (int)($owner['online_time'] ?? 0);
        if ($lastOnline <= 0) {
            return [];
        }
        if ($lastOnline < $now - 28 * 86400) {
            return ['inactive', 'longinactive'];
        }
        return $lastOnline < $now - 7 * 86400 ? ['inactive'] : [];
    }
}
