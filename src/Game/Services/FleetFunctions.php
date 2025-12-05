<?php

namespace SPGame\Game\Services;


use SPGame\Game\Repositories\Vars;

class FleetFunctions
{
    public static function GetShipConsumption($Ship, AccountData $AccountData)
    {
        return (
            ($AccountData['Techs']['impulse_motor_tech'] >= 5 && $Ship == 202) ||
            ($AccountData['Techs']['hyperspace_motor_tech'] >= 8 && $Ship == 211)) ?
            Vars::$attributes[$Ship]['consumption2'] :
            Vars::$attributes[$Ship]['consumption'];
    }

    public static function GetShipSpeed($Ship, AccountData $AccountData)
    {

        $techSpeed    = Vars::$attributes[$Ship]['tech'];

        if ($techSpeed == 4) {
            $techSpeed = $AccountData['Techs']['impulse_motor_tech'] >= 5 ? 2 : 1;
        }
        if ($techSpeed == 5) {
            $techSpeed = $AccountData['Techs']['hyperspace_motor_tech'] >= 8 ? 3 : 2;
        }


        switch ($techSpeed) {
            case 1:
                $speed    = Vars::$attributes[$Ship]['speed'] * (1 + (0.1 * $AccountData['Techs']['combustion_tech']));
                break;
            case 2:
                $speed    = Vars::$attributes[$Ship]['speed'] * (1 + (0.2 * $AccountData['Techs']['impulse_motor_tech']));
                break;
            case 3:
                $speed    = Vars::$attributes[$Ship]['speed'] * (1 + (0.3 * $AccountData['Techs']['hyperspace_motor_tech']));
                break;
            default:
                $speed    = 0;
                break;
        }

        return $speed;
    }
}
