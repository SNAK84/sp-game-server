<?php

namespace SPGame\Game\Pages;

use SPGame\Game\Services\Helpers;
use SPGame\Game\Services\AccountData;

class OverviewPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];

        return [
            'page' => 'overview',
            'UserName' => $User['name'],
            'PlanetImage' => $Planet['image'],
            'PlanetName' => $Planet['name'],
            'diameter' => $Planet['size'],
            'field_used' => Helpers::getCurrentFields($AccountData),
            'field_current' => Helpers::getMaxFields($AccountData),
            'TempMin' => $Planet['temp_min'],
            'TempMax' => $Planet['temp_max'],
            'galaxy' => $Planet['galaxy'],
            'system' => $Planet['system']
        ];
    }
}
