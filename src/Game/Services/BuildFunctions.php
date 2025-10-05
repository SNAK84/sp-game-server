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

class BuildFunctions
{

    public static function getElementPrice(int $Element, int $userId, int $planetId, bool $forDestroy = false, $forLevel = NULL)
    {

        if (isset($forLevel)) {
            $elementLevel = $forLevel;
        } else
            $elementLevel = Helpers::getElementLevel($Element, $userId, $planetId);

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

    public static function getRestPrice(int $Element, int $userId, int $planetId, $elementPrice = NULL): array
    {
        $Resouce = Resources::getByPlanetId($planetId);

        if (!isset($elementPrice)) {
            $elementPrice = self::getElementPrice($Element, $userId, $planetId);
        }

        $overflow  = [];
        foreach ($elementPrice as $resType => $resPrice) {
            $available = (int)$Resouce[$resType]['count'];
            $overflow[$resType] = max($resPrice - floor($available), 0);
        }

        return $overflow;
    }

    public static function isTechnologieAccessible(int $Element, int $userId, int $planetId): bool
    {
        if (!isset(Vars::$requirement[$Element]))
            return true;

        $Builds = Builds::findById($planetId);
        $Techs = Techs::findById($userId);

        foreach (Vars::$requirement[$Element] as $ReqElement => $EleLevel) {
            if (
                (isset($Techs[Vars::$resource[$ReqElement]]) && $Techs[Vars::$resource[$ReqElement]] < $EleLevel) ||
                (isset($Builds[Vars::$resource[$ReqElement]]) && $Builds[Vars::$resource[$ReqElement]] < $EleLevel)
            ) {
                return false;
            }
        }
        return true;
    }


    public static function getBuildingTime(int $Element, int $userId, int $planetId, $elementPrice = NULL, $forDestroy = false, $forLevel = NULL): float
    {
        $time   = 0;

        if (!isset($elementPrice)) {
            $elementPrice = self::getElementPrice($Element, $userId, $planetId, $forDestroy, $forLevel);
        }

        $elementCost = 0;

        if (isset($elementPrice[901])) {
            $elementCost    += $elementPrice[901];
        }

        if (isset($elementPrice[902])) {
            $elementCost    += $elementPrice[902];
        }


        $Builds = Builds::findById($planetId);

        if (in_array($Element, Vars::$reslist['build'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $Builds[Vars::$resource[14]])) *
                pow(0.5, $Builds[Vars::$resource[15]]) *
                (1 + Factors::getFactor('BuildTime', $userId, $planetId));
        } elseif (in_array($Element, Vars::$reslist['fleet'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $Builds[Vars::$resource[21]])) *
                pow(0.5, $Builds[Vars::$resource[15]]) *
                (1 + Factors::getFactor('ShipTime', $userId, $planetId));
        } elseif (in_array($Element, Vars::$reslist['defense'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $Builds[Vars::$resource[21]])) *
                pow(0.5, $Builds[Vars::$resource[15]]) *
                (1 + Factors::getFactor('DefensiveTime', $userId, $planetId));
        } elseif (in_array($Element, Vars::$reslist['missile'])) {
            $time    = $elementCost / (Config::getValue('GameSpeed') *
                (1 + $Builds[Vars::$resource[21]])) *
                pow(0.5, $Builds[Vars::$resource[15]]) *
                (1 + Factors::getFactor('DefensiveTime', $userId, $planetId));
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            $NetworkLevels = Techs::getNetworkLevels($userId, $planetId);

            $Level = 0;
            foreach ($NetworkLevels as $Levels) {
                if (!isset(Vars::$requirement[$Element][31]) || $Levels >= Vars::$requirement[$Element][31])
                    $Level += $Levels;
            }


            $time    = $elementCost / (1000 * (1 + $Level)) /
                (Config::getValue('GameSpeed') / 2500) *
                pow(1 - Config::getValue('FactorsUniversity') / 100, $Builds[Vars::$resource[6]]) *
                (1 + Factors::getFactor('ResearchTime', $userId, $planetId));
        }

        if ($forDestroy) {
            $time    = ($time * 1300);
        } else {
            $time    = ($time * 3600);
        }

        return max($time, Config::getValue('MinBuildTime'));
    }

    public static function isElementBuyable(int $Element, int $userId, int $planetId, $elementPrice = NULL, $forDestroy = false, $forLevel = NULL): bool
    {
        $rest    = self::getRestPrice($Element, $userId, $planetId, $elementPrice, $forDestroy, $forLevel);
        $result = count(array_filter($rest)) === 0;

        return $result;
    }

    public static function setElementLevel(int $Element, int $userId, int $planetId, int $BuildLevel): void
    {

        if (in_array($Element, Vars::$reslist['build'])) {
            $row = Builds::findById($planetId);
            $row[Vars::$resource[$Element]] = $BuildLevel;
            Techs::update($row);
        } elseif (in_array($Element, Vars::$reslist['fleet'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['defense'])) {
            $BuildLevel = 0;
        } elseif (in_array($Element, Vars::$reslist['tech'])) {
            $row = Techs::findById($userId);
            $row[Vars::$resource[$Element]] = $BuildLevel;
            Techs::update($row);
        } elseif (in_array($Element, Vars::$reslist['officier'])) {
            $BuildLevel = 0;
        }
    }
}
