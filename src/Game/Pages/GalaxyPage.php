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

            // Проверка всех условий, включая тип орбиты
            if (
                $Planet['planet'] == 0 ||
                $Planet['galaxy'] == 0 ||
                $Planet['system'] == 0 ||
                $Planet['planet'] > $System['max_orbits'] ||
                $Planet['galaxy'] > (int)Config::getValue('MaxGalaxy') ||
                $Planet['system'] > (int)Config::getValue('MaxSystem') ||
                ($orbitType !== null && $orbitType != 0 && $orbitType != 5)
            ) {
                $Planet = GalaxyGenerator::regeneratePlanet($Planet);
                //$NewPlanet = GalaxyGenerator::generatePlanet($System['star_type'],$Orbits[$orbitIndex]['distance']);

                /*Logger::getInstance()->info("Regen Planet id " . $Planet['id'], [
                    $Planet,
                    $orbitType !== null ? $Orbits[$orbitIndex] : 'orbit not found',
                ]);*/
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
