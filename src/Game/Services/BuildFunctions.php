<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Techs;

use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;

use SPGame\Game\Repositories\Factors;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Vars;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\Resources;
use SPGame\Game\Services\Helpers;

class BuildFunctions
{

    public static function getElementPrice(int $Element, array $AccountData, bool $forDestroy = false, $forLevel = NULL)
    {

        if (isset($forLevel)) {
            $elementLevel = $forLevel;
        } else
            $elementLevel = Helpers::getElementLevel($Element, $AccountData);

        $price    = array();
        foreach (Vars::$reslist['ressources'] as $resType) {
            if (!isset(Vars::$pricelist[$Element][$resType])) {
                continue;
            }
            $ressourceAmount = Vars::$pricelist[$Element][$resType];

            if ($ressourceAmount == 0) {
                continue;
            }

            $price[$resType]    = $ressourceAmount;

            if (isset(Vars::$attributes[$Element]['factor']) && Vars::$attributes[$Element]['factor'] != 0 && Vars::$attributes[$Element]['factor'] != 1) {
                $price[$resType]    *= pow(Vars::$attributes[$Element]['factor'], $elementLevel - 1);
            }

            if ($forLevel && (in_array($Element, Vars::$reslist['fleet']) || in_array($Element, Vars::$reslist['defense']) || in_array($Element, Vars::$reslist['missile']))) {
                $price[$resType]    *= $elementLevel;
            }

            if ($forDestroy === true) {
                $price[$resType]    /= 2;
            }
        }

        return $price;
    }

    public static function getRestPrice(int $Element, array $AccountData, $elementPrice = NULL): array
    {
        $Resouce = $AccountData['Resources'];

        if (!isset($elementPrice)) {
            $elementPrice = self::getElementPrice($Element, $AccountData);
        }

        $overflow  = [];
        foreach ($elementPrice as $resType => $resPrice) {
            $available = (int)$Resouce[$resType]['count'];
            $overflow[$resType] = max($resPrice - floor($available), 0);
        }

        return $overflow;
    }

    public static function isTechnologieAccessible(int $Element, array $AccountData): bool
    {
        if (!isset(Vars::$requirement[$Element]))
            return true;

        foreach (Vars::$requirement[$Element] as $ReqElement => $EleLevel) {
            if (
                (isset($AccountData['Techs'][Vars::$resource[$ReqElement]]) && $AccountData['Techs'][Vars::$resource[$ReqElement]] < $EleLevel) ||
                (isset($AccountData['Builds'][Vars::$resource[$ReqElement]]) && $AccountData['Builds'][Vars::$resource[$ReqElement]] < $EleLevel)
            ) {
                return false;
            }
        }
        return true;
    }


    public static function getBuildingTime(int $Element, array $AccountData, $elementPrice = NULL, $forDestroy = false, $forLevel = NULL): float
    {
        $time   = 0;

        if (!isset($elementPrice)) {
            $elementPrice = self::getElementPrice($Element, $AccountData, $forDestroy, $forLevel);
        }

        $elementCost = 0;

        if (isset($elementPrice[901])) {
            $elementCost    += $elementPrice[901];
        }

        if (isset($elementPrice[902])) {
            $elementCost    += $elementPrice[902];
        }


        if (in_array($Element, Vars::$reslist['build'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $AccountData['Builds'][Vars::$resource[14]])) *
                pow(0.5, $AccountData['Builds'][Vars::$resource[15]]) *
                (1 + Factors::getFactor('BuildTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['fleet'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $AccountData['Builds'][Vars::$resource[21]])) *
                pow(0.5, $AccountData['Builds'][Vars::$resource[15]]) *
                (1 + Factors::getFactor('ShipTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['defense'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $AccountData['Builds'][Vars::$resource[21]])) *
                pow(0.5, $AccountData['Builds'][Vars::$resource[15]]) *
                (1 + Factors::getFactor('DefensiveTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['missile'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $AccountData['Builds'][Vars::$resource[21]])) *
                pow(0.5, $AccountData['Builds'][Vars::$resource[15]]) *
                (1 + Factors::getFactor('DefensiveTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            $NetworkLevels = Helpers::getNetworkLevels($AccountData);

            $Level = 0;
            foreach ($NetworkLevels as $Levels) {
                if (!isset(Vars::$requirement[$Element][31]) || $Levels >= Vars::$requirement[$Element][31])
                    $Level += $Levels;
            }

            $time    = $elementCost / (1000 * (1 + $Level)) /
                (Config::getValue('GameSpeed') / 2500) *
                pow(1 - Config::getValue('FactorsUniversity') / 100, $AccountData['Builds'][Vars::$resource[6]]) *
                (1 + Factors::getFactor('ResearchTime', $AccountData));
        }

        if ($forDestroy) {
            $time    = ($time * 1300);
        } else {
            $time    = ($time * 3600);
        }

        return max($time, Config::getValue('MinBuildTime'));
    }

    public static function isElementBuyable(int $Element, array $AccountData, $elementPrice = NULL, $forDestroy = false, $forLevel = NULL): bool
    {
        $rest    = self::getRestPrice($Element, $AccountData, $elementPrice, $forDestroy, $forLevel);
        $result = count(array_filter($rest)) === 0;

        return $result;
    }

    public static function setElementLevel(int $Element, array &$AccountData, int $BuildLevel): void
    {

        if (in_array($Element, Vars::$reslist['build'])) {
            $AccountData['Builds'][Vars::$resource[$Element]] = $BuildLevel;
        } elseif (in_array($Element, Vars::$reslist['fleet'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['defense'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            $AccountData['Techs'][Vars::$resource[$Element]] = $BuildLevel;
        } elseif (in_array($Element, Vars::$reslist['officier'])) {
            $BuildLevel = 0;
        }
    }
}
