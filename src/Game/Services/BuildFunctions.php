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

        $price    = [];
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

            if (
                $forLevel &&
                (in_array($Element, Vars::$reslist['fleet']) ||
                    in_array($Element, Vars::$reslist['defense']) ||
                    in_array($Element, Vars::$reslist['missile']))
            ) {
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
        $time = 0.0;

        // --- 1. Получаем цену, если не передана ---
        if ($elementPrice === null) {
            $elementPrice = self::getElementPrice($Element, $AccountData, $forDestroy, $forLevel);
        }

        // --- 2. Общая стоимость металла + кристалла ---
        $elementCost = 0.0;
        $elementCost += (float)($elementPrice[901] ?? 0);
        $elementCost += (float)($elementPrice[902] ?? 0);

        // --- 3. Безопасное извлечение уровней через Helpers ---
        $roboticLevel   = (int)Helpers::getElementLevel(14, $AccountData); // Робототехника
        $naniteLevel    = (int)Helpers::getElementLevel(15, $AccountData); // Наниты
        $shipyardLevel  = (int)Helpers::getElementLevel(21, $AccountData); // Верфь
        $labLevel       = (int)Helpers::getElementLevel(6,  $AccountData); // Лаборатория

        // --- 4. Общие параметры конфигурации ---
        $gameSpeed = (float)Config::getValue('GameSpeed');
        if ($gameSpeed <= 0) {
            $gameSpeed = 2500.0; // безопасное значение
        }

        $factorUniversity = (float)Config::getValue('FactorsUniversity');
        if ($factorUniversity >= 100) {
            $factorUniversity = 99.9; // защита от pow(<=0)
        }

        // --- 5. Расчёт по типу элемента ---
        if (in_array($Element, Vars::$reslist['build'], true)) {
            $time = $elementCost /
                ($gameSpeed * (1 + $roboticLevel)) *
                pow(0.5, $naniteLevel) *
                (1 + (float)Factors::getFactor('BuildTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['fleet'], true)) {
            $time = $elementCost /
                ($gameSpeed * (1 + $shipyardLevel)) *
                pow(0.5, $naniteLevel) *
                (1 + (float)Factors::getFactor('ShipTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['defense'], true) || in_array($Element, Vars::$reslist['missile'], true)) {
            $time = $elementCost /
                ($gameSpeed * (1 + $shipyardLevel)) *
                pow(0.5, $naniteLevel) *
                (1 + (float)Factors::getFactor('DefensiveTime', $AccountData));
        } elseif (in_array($Element, Vars::$reslist['tech'], true)) {
            // --- 6. Исследования ---
            $NetworkLevels = Helpers::getNetworkLevels($AccountData);
            $Level = 0;
            foreach ($NetworkLevels as $Levels) {
                if (!isset(Vars::$requirement[$Element][31]) || $Levels >= Vars::$requirement[$Element][31]) {
                    $Level += $Levels;
                }
            }

            $time = $elementCost /
                (1000.0 * (1.0 + $Level)) /
                ($gameSpeed / 2500.0) *
                pow(1.0 - ($factorUniversity / 100.0), $labLevel) *
                (1.0 + (float)Factors::getFactor('ResearchTime', $AccountData));
        }

        // --- 7. Коэффициент для разрушения / строительства ---
        $time *= $forDestroy ? 1300.0 : 3600.0;

        // --- 8. Минимальное время ---
        return max($time, (float)Config::getValue('MinBuildTime'));
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
