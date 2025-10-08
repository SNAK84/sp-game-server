<?php

namespace SPGame\Game\Repositories;


use SPGame\Game\Services\Helpers;

class Factors
{

    public static function getFactor(string $bonusKey, array $AccountData)
    {
        $factor = 0;

        foreach (Vars::$reslist['bonus'] as $elementID) {
            $bonus = Vars::$bonus[$elementID];

            $elementLevel = Helpers::getElementLevel($elementID, $AccountData);

            if (!$elementLevel) continue;

            $factor += $elementLevel * $bonus[$bonusKey][0];
        }

        return $factor;
    }
}
