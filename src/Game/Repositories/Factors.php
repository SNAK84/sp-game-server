<?php

namespace SPGame\Game\Repositories;


use SPGame\Game\Services\Helpers;

class Factors
{

    public static function getFactor(string $bonusKey, int $userId, int $planetId)
    {
        $factor = 0;

        foreach (Vars::$reslist['bonus'] as $elementID) {
            $bonus = Vars::$bonus[$elementID];

            $elementLevel = Helpers::getElementLevel($elementID, $userId, $planetId);

            if (!$elementLevel) continue;

            $factor += $elementLevel * $bonus[$bonusKey][0];
        }

        return $factor;
    }
}
