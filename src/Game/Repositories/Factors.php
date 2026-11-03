<?php

namespace SPGame\Game\Repositories;


use SPGame\Game\Services\BuildFunctions;
use SPGame\Game\Services\AccountData;

class Factors
{

    public static function getFactor(string $bonusKey, AccountData $AccountData)
    {
        $factor = 0;

        foreach (Vars::$reslist['bonus'] as $elementID) {
            $bonus = Vars::$bonus[$elementID];

            $elementLevel = BuildFunctions::getElementLevel($elementID, $AccountData);

            if (!$elementLevel) continue;

            $factor += $elementLevel * $bonus[$bonusKey][0];
        }

        return $factor;
    }
}
