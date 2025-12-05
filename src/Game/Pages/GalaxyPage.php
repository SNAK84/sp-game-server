<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;
use SPGame\Core\Message;

use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Planets;

use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\GalaxyGenerator;
use SPGame\Game\Services\QueuesServices;

class GalaxyPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];

        $galaxy = $this->Msg->getData('galaxy', (int)$Planet['galaxy']);
        $system = $this->Msg->getData('system', (int)$Planet['system']);

        //$PlanetsVisual = Planets::getSystemPlanetsVisual($Planet['galaxy'], $Planet['system']);
        $System  = Galaxy::getSystem($galaxy, $system);
        $Orbits  = GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]);
        $Planets = Planets::findByIndex('galaxy_system', [$galaxy, $system]);

        $PlanetUser = [];
        $PlanetsList = [];
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
                        $newPhys = GalaxyGenerator::generatePlanet($starType, $distance, $isHome);

                        // Обновляем поля
                        $planet['type'] = $newPhys['type'];
                        $planet['image'] = $newPhys['image'];
                        $planet['size'] = $newPhys['size'];
                        $planet['fields'] = $newPhys['fields'];
                        $planet['temp_min'] = $newPhys['temp_min'];
                        $planet['temp_max'] = $newPhys['temp_max'];
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

            if (!isset($PlanetUser[$Planet['owner_id']])) {
                $PlanetUser[$Planet['owner_id']] = Users::findById($Planet['owner_id']);
            }


            $PlanetsList[] = [
                'id'            => $Planet['id'],
                'name'          => $Planet['name'],
                'planet_type'   => $Planet['planet_type'],
                'image'         => $Planet['image'],
                'galaxy'        => $Planet['galaxy'],
                'system'        => $Planet['system'],
                'planet'        => $Planet['planet'],
                'update_time'   => $Planet['update_time'],
                'size'          => $Planet['size'],
                'deg'           => $Planet['deg'],
                'speed'         => $Planet['speed'],
                'rotation'      => $Planet['rotation'],
                'UserName'      => $PlanetUser[$Planet['owner_id']]['name'],

            ];
        }


        $System['color'] = Galaxy::$starTypes[$System['star_type']]['color'];

        return [
            'page'          => 'galaxy',
            'System'        => $System,
            'Orbits'        => $Orbits,
            'Planets'       => $PlanetsList,
            'MaxGalaxy'     => (int)Config::getValue('MaxGalaxy'),
            'MaxSystem'     => (int)Config::getValue('MaxSystem'),
            'SpeedPlanets'  => (int)Config::getValue('SpeedPlanets', 24)
        ];
    }
}
