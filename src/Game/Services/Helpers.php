<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Techs;

use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;

use SPGame\Game\Repositories\Vars;

use SPGame\Core\Logger;

class Helpers
{

    public static function getElementLevel(int $Element, int $userId, int $planetId): int
    {

        $BuildLevel = 0;
        if (in_array($Element, Vars::$reslist['build'])) {
            $BuildLevel = Builds::findById($planetId)[Vars::$resource[$Element]] ?? 0;
        } elseif (in_array($Element, Vars::$reslist['fleet'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['defense'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            $BuildLevel = Techs::findById($userId)[Vars::$resource[$Element]] ?? 0;
        } elseif (in_array($Element, Vars::$reslist['officier'])) {
            $BuildLevel = 0;
        }

        return $BuildLevel;
    }
}
