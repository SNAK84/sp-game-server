<?php

declare(strict_types=1);

namespace SPGame\Game\Pages;

use SPGame\Game\Repositories\{Config, EntitySettings, Factors, Resources, Vars};
use SPGame\Game\Services\{AccountData, BuildFunctions, CelestialNameGenerator, FleetControlService, FleetExpeditionService, FleetFunctions, FleetsServices};

/** Справочный snapshot: не меняет уровни, ресурсы, настройки и игровые очереди. */
class InformationPage extends AbstractPage
{
    public function render(AccountData &$accountData): array
    {
        $rawId = $this->Msg->source()['data']['id'] ?? null;
        if (!(is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)))) {
            throw new \InvalidArgumentException('Неизвестный игровой объект.');
        }
        $id = (int)$rawId;
        $kind = null;
        foreach (['build' => 'building', 'tech' => 'research', 'fleet' => 'ship', 'defense' => 'defense', 'missile' => 'defense'] as $group => $type) {
            if (in_array($id, Vars::$reslist[$group] ?? [], true)) {
                $kind = $type;
                break;
            }
        }
        if ($id <= 0 || $kind === null || !isset(Vars::$resource[$id], Vars::$attributes[$id])) {
            throw new \InvalidArgumentException('Неизвестный игровой объект.');
        }

        $levelled = $kind === 'building' || $kind === 'research';
        $current = BuildFunctions::getElementLevel($id, $accountData);
        $maxLevel = (int)(Vars::$attributes[$id]['max'] ?? 0);
        $targetLevel = $levelled ? $current + 1 : 1;
        $atMaximum = $levelled && $maxLevel > 0 && $current >= $maxLevel;
        $price = $atMaximum ? [] : BuildFunctions::getElementPrice($id, $accountData, false, $targetLevel);
        $duration = $atMaximum ? null : BuildFunctions::getBuildingTime($id, $accountData, $price, false, $targetLevel);

        $requirements = [];
        foreach (Vars::$requirement[$id] ?? [] as $requiredId => $level) {
            $own = BuildFunctions::getElementLevel((int)$requiredId, $accountData);
            $requirements[] = ['id' => (int)$requiredId, 'required' => (int)$level, 'current' => $own, 'met' => $own >= $level];
        }
        $unlocks = [];
        foreach (Vars::$requirement as $targetId => $dependencies) {
            if (isset($dependencies[$id])) {
                $unlocks[] = ['id' => (int)$targetId, 'required' => (int)$dependencies[$id], 'met' => $current >= $dependencies[$id]];
            }
        }

        $production = $this->formulas(Vars::$production[$id] ?? []);
        $storage = $this->formulas(Vars::$storage[$id] ?? []);
        $planetProductionResource = [113 => 911, 131 => 901, 132 => 902, 133 => 903][$id] ?? null;
        $bonuses = array_filter(Vars::$bonus[$id] ?? [], static fn(array $bonus): bool => (float)$bonus[0] !== 0.0);
        $effects = $levelled ? $this->levelEffects($id, $bonuses) : [];
        $rows = [];
        // Демонтаж предусмотрен только для построек. Исследования нельзя понизить.
        $start = $levelled ? max(0, $current - ($kind === 'building' ? 2 : 0)) : 1;
        $end = $levelled ? $start + 14 : 1;
        if ($levelled && $maxLevel > 0) $end = max($current, min($end, $maxLevel));
        $efficiency = EntitySettings::getEfficiency((int)$accountData['Planet']['id'], $id);
        // Только реальные показатели объекта: не цены и не время каждого уровня.
        foreach ($production || $storage || $effects || $planetProductionResource !== null ? range($start, $end) : [] as $level) {
            $row = ['level' => $level, 'production' => [], 'storage' => [], 'effects' => []];
            foreach ($production as $resourceId => $formula) {
                $multiplier = Config::getValue(in_array($resourceId, Vars::$reslist['resstype'][2], true) ? 'EnergySpeed' : 'ResourceMultiplier');
                $row['production'][$resourceId] = $this->evaluate($formula, $id, $level, $efficiency, $accountData) * $multiplier;
            }
            foreach ($storage as $resourceId => $formula) {
                $row['storage'][$resourceId] = round($this->evaluate($formula, $id, $level, $efficiency, $accountData))
                    * Config::getValue('StorageMultiplier') * (1 + Factors::getFactor('ResourceStorage', $accountData));
            }
            foreach ($effects as $effect) {
                $row['effects'][$effect['key']] = $this->effectValue($effect['key'], $id, $level, $bonuses);
            }
            if ($planetProductionResource !== null) {
                $row['production'] = [$planetProductionResource => $this->planetProduction($id, $level, $planetProductionResource, $accountData)];
            }
            $rows[] = $row;
        }

        $stats = [];
        $engine = null;
        if (!$levelled) {
            $stats = [
                'structure' => ['base' => (float)(Vars::$pricelist[$id][901] ?? 0) + (float)(Vars::$pricelist[$id][902] ?? 0)],
                'attack' => ['base' => (float)(Vars::$CombatCaps[$id]['attack'] ?? 0)],
                'shield' => ['base' => (float)(Vars::$CombatCaps[$id]['shield'] ?? 0)],
            ];
            // Те же Factors, что в боевом адаптере. Опыт конкретного Fleet
            // не относится к общей справке об одном типе кораблей/обороны.
            foreach (['structure' => 'Defensive', 'attack' => 'Attack', 'shield' => 'Shield'] as $stat => $bonus) {
                $stats[$stat]['current'] = $stats[$stat]['base'] * (1 + Factors::getFactor($bonus, $accountData));
            }
            if ($kind === 'ship') {
                $stats['capacity'] = ['base' => (float)(Vars::$attributes[$id]['capacity'] ?? 0)];
                $stats['speed'] = ['base' => (float)(Vars::$attributes[$id]['speed'] ?? 0), 'current' => FleetFunctions::GetShipSpeed($id, $accountData)];
                $stats['consumption'] = ['base' => (float)(Vars::$attributes[$id]['consumption'] ?? 0), 'current' => FleetFunctions::GetShipConsumption($id, $accountData)];
                $tech = (int)(Vars::$attributes[$id]['tech'] ?? 0);
                if ($tech === 4) $tech = (int)($accountData['Techs']['impulse_motor_tech'] ?? 0) >= 5 ? 2 : 1;
                if ($tech === 5) $tech = (int)($accountData['Techs']['hyperspace_motor_tech'] ?? 0) >= 8 ? 3 : 2;
                $engine = [1 => 115, 2 => 117, 3 => 118][$tech] ?? null;
            }
        }
        $rapidfire = ['to' => [], 'from' => []];
        foreach (Vars::$rapidfire[$id] ?? [] as $targetId => $shots) {
            if ($shots > 1) $rapidfire['to'][] = ['id' => (int)$targetId, 'value' => (float)$shots];
        }
        foreach (Vars::$rapidfire ?? [] as $attackerId => $targets) {
            if (($targets[$id] ?? 0) > 1) $rapidfire['from'][] = ['id' => (int)$attackerId, 'value' => (float)$targets[$id]];
        }

        return [
            'page' => 'information', 'id' => $id, 'name' => Vars::$resource[$id], 'kind' => $kind,
            'imageGroup' => ['building' => 'builds', 'research' => 'techs', 'ship' => 'fleets', 'defense' => 'defenses'][$kind],
            'current' => $current, 'maxLevel' => $levelled && $maxLevel > 0 ? $maxLevel : null,
            'planetId' => (int)$accountData['Planet']['id'],
            'planetName' => CelestialNameGenerator::planetDisplayName([
                'name' => (string)$accountData['Planet']['name'],
                'name_generated' => (bool)($accountData['Planet']['name_generated'] ?? false),
            ], (string)($accountData['Account']['lang'] ?? 'ru')),
            'targetLevel' => $targetLevel, 'atMaximum' => $atMaximum, 'price' => $price, 'duration' => $duration,
            'requirements' => $requirements, 'unlocks' => $unlocks, 'bonuses' => $bonuses,
            'stats' => $stats, 'engine' => $engine, 'rapidfire' => $rapidfire,
            'productionResources' => $planetProductionResource !== null ? [$planetProductionResource] : array_keys($production),
            'planetProduction' => $planetProductionResource !== null, 'storageResources' => array_keys($storage),
            'effects' => $effects, 'efficiency' => $efficiency, 'rows' => $rows,
        ];
    }

    private function levelEffects(int $id, array $bonuses): array
    {
        // Только зависимости, используемые текущим движком, а не ограничения из Nova.
        $key = match ($id) {
            108 => 'fleetMissionSlots', FleetsServices::LOGISTICS_TECH_ID => 'fleetBaseSlots',
            42 => 'phalanxRange',
            14 => 'buildingTime', 15 => 'naniteTime', 21 => 'productionTime', 31 => 'laboratoryTime',
            115, 117, 118 => 'engineSpeed', 123 => 'networkLabs', 124 => 'planets',
            6 => (float)Config::getValue('FactorUniversity') !== 0.0 ? 'researchTime' : null,
            default => null,
        };
        $effects = [];
        if ($key !== null) {
            $effects[] = ['key' => $key, 'unit' => in_array($key, ['phalanxRange', 'planets', 'networkLabs', 'fleetMissionSlots', 'fleetBaseSlots'], true) ? '' : '%',
                'lowerIsBetter' => in_array($key, ['buildingTime', 'naniteTime', 'productionTime', 'laboratoryTime', 'researchTime'], true)];
        }
        if ($id === 124) {
            $effects[] = ['key'=>'expeditionSlots','unit'=>'','lowerIsBetter'=>false];
            $effects[] = ['key'=>'expeditionHours','unit'=>'ч','lowerIsBetter'=>false,'difference'=>false];
        }
        if ($id === 108) {
            $effects[] = ['key'=>'fleetStages','unit'=>'','lowerIsBetter'=>false,'difference'=>false];
        }
        if ($id === 135) {
            $effects[] = ['key'=>'sensorScore','unit'=>'','lowerIsBetter'=>false];
            $effects[] = ['key'=>'sensorMode','unit'=>'','lowerIsBetter'=>false,'type'=>'boolean','difference'=>false];
        }
        if ($id === 136) {
            $effects[] = ['key'=>'miningYield','unit'=>'%','lowerIsBetter'=>false];
            $effects[] = ['key'=>'resourceBaseSlots','unit'=>'','lowerIsBetter'=>false,'difference'=>false];
        }
        foreach (match ($id) {
            108 => ['fleetPlan','fleetEdit','fleetInterceptStop'], FleetsServices::LOGISTICS_TECH_ID => ['fleetHold','fleetCargoRefuel'], default => [],
        } as $capability) {
            $effects[] = ['key'=>$capability,'unit'=>'','lowerIsBetter'=>false,'type'=>'boolean','difference'=>false];
        }
        // Общие бонусы Factors отделены от уже перечисленных игровых лимитов.
        foreach (array_intersect_key($bonuses, array_flip([
            'Attack', 'Defensive', 'Shield',
            'BuildTime', 'ResearchTime', 'ShipTime', 'DefensiveTime',
            'Resource', 'Energy', 'ResourceStorage', 'SpyPower',
        ])) as $name => $bonus) {
            if (in_array($id, [113, 131, 132, 133], true)) continue;
            $effects[] = ['key' => 'bonus:' . $name, 'unit' => (int)$bonus[1] === 0 ? '%' : '',
                'lowerIsBetter' => in_array($name, ['BuildTime', 'ResearchTime', 'ShipTime', 'DefensiveTime'], true)];
        }
        return $effects;
    }

    private function effectValue(string $key, int $id, int $level, array $bonuses): float|int|bool
    {
        if (str_starts_with($key, 'bonus:')) {
            $bonus = $bonuses[substr($key, 6)];
            return $level * (float)$bonus[0] * ((int)$bonus[1] === 0 ? 100 : 1);
        }
        $university = $key === 'researchTime' ? (float)Config::getValue('FactorUniversity') : 0.0;
        if ($university >= 100.0) $university = 99.9;
        if ($university >= 100) $university = 99.9;
        return match ($key) {
            'fleetMissionSlots' => FleetsServices::missionSlotLimit($level),
            'phalanxRange' => \SPGame\Game\Services\PhalanxService::range($level),
            'fleetBaseSlots' => FleetsServices::baseSlotLimit($level),
            'expeditionSlots' => FleetExpeditionService::limitsForLevel($level)['max'],
            'expeditionHours' => FleetExpeditionService::limitsForLevel($level)['maxHours'],
            'fleetPlan' => FleetControlService::capabilitiesForLevels($level,0)['plan'],
            'fleetEdit' => FleetControlService::capabilitiesForLevels($level,0)['edit'],
            'fleetInterceptStop' => FleetControlService::capabilitiesForLevels($level,0)['interceptStop'],
            'fleetStages' => FleetControlService::capabilitiesForLevels($level,0)['maxStages'],
            'fleetHold' => FleetControlService::capabilitiesForLevels(0,$level)['hold'],
            'fleetCargoRefuel' => FleetControlService::capabilitiesForLevels(0,$level)['cargoRefuel'],
            'sensorScore' => 2 * $level,
            'sensorMode' => $level >= 3,
            'miningYield' => $level * (require dirname(__DIR__, 3) . '/resources/fleet-mining.php')['yield_bonus_per_level'] * 100,
            'resourceBaseSlots' => \SPGame\Game\Services\ResourceBaseService::controlLimit($level),
            // FleetColonizationService::planetLimit, включая домашнюю планету.
            'planets' => 1 + intdiv($level + 1, 2),
            // Helpers::getNetworkLevels — верхний лимит, не число готовых лабораторий.
            'networkLabs' => $level + 1,
            // BuildFunctions::getBuildingTime — вклад объекта, прочие множители фиксированы.
            'buildingTime', 'productionTime', 'laboratoryTime' => 100 / (1 + $level),
            'naniteTime' => 100 * pow(0.5, $level),
            'researchTime' => 100 * pow(1 - $university / 100, $level),
            // FleetFunctions::GetShipSpeed / Resources::ReBuildRes.
            'engineSpeed' => $level * [115 => 10, 117 => 20, 118 => 30][$id],
        };
    }

    /** Прогноз ReBuildRes с заменой одного уровня, без записи в AccountData/repositories. */
    private function planetProduction(int $techId, int $techLevel, int $resourceId, AccountData $accountData): float
    {
        $plus = array_fill_keys([901, 902, 903, 911], 0.0);
        $minus = $plus;
        foreach (Vars::$reslist['prod'] as $producerId) {
            $level = $producerId === $techId ? $techLevel : BuildFunctions::getElementLevel($producerId, $accountData);
            $efficiency = EntitySettings::getEfficiency((int)$accountData['Planet']['id'], $producerId);
            foreach ($plus as $id => $_) {
                $formula = Vars::$production[$producerId][$id] ?? null;
                if ($formula === null || trim((string)$formula) === '') continue;
                $value = $this->evaluate((string)$formula, $producerId, $level, $efficiency, $accountData, [$techId => $techLevel]);
                if ($value > 0) {
                    $plus[$id] += $value;
                } elseif ($id === 911 || ($accountData['Resources'][$id]['count'] ?? 0) != 0) {
                    $minus[$id] += $value;
                }
            }
        }
        $factor = static function (string $name) use ($techId, $techLevel, $accountData): float {
            $ownBonus = in_array($techId, Vars::$reslist['bonus'], true) ? (float)(Vars::$bonus[$techId][$name][0] ?? 0) : 0.0;
            return Factors::getFactor($name, $accountData)
                + ($techLevel - BuildFunctions::getElementLevel($techId, $accountData)) * $ownBonus;
        };
        if ($resourceId === 911) {
            // Как в Resources::ReBuildRes: округление до общего энергетического бонуса.
            return round($plus[911] * Config::getValue('EnergySpeed')) * (1 + $factor('Energy'));
        }
        // Сохраняем действующую формулу энергообеспеченности, не меняя механику ресурсов.
        $productionLevel = $minus[911] == 0 ? 0 : min(1, $plus[911] / abs($minus[911]));
        $resourceTech = [901 => 131, 902 => 132, 903 => 133][$resourceId];
        $level = $resourceTech === $techId ? $techLevel : BuildFunctions::getElementLevel($resourceTech, $accountData);
        $incomeKey = [901 => 'metalBasicIncome', 902 => 'crystalBasicIncome', 903 => 'deuteriumBasicIncome'][$resourceId];
        $basicIncome = (int)$accountData['Planet']['planet_type'] === 3 ? 0 : Config::getValue($incomeKey);
        return ($plus[$resourceId] * (1 + $factor('Resource') + 0.02 * $level) * $productionLevel
            + $minus[$resourceId] + $basicIncome) * Config::getValue('ResourceMultiplier');
    }

    private function formulas(array $formulas): array
    {
        return array_filter($formulas, static fn($value): bool => $value !== null && trim((string)$value) !== ''
            && !(is_numeric($value) && (float)$value === 0.0));
    }

    private function evaluate(string $formula, int $id, int $level, int $efficiency, AccountData $accountData, array $techOverrides = []): float
    {
        // Те же переменные и подстановка формул, что в Resources/BuildingsPage.
        // Гипотетический уровень локален: AccountData и runtime не изменяются.
        $BuildLevel = $level;
        $BuildLevelFactor = $efficiency;
        $BuildTemp = $accountData['Planet']['temp_max'];
        $BuildEnergy = $techOverrides[113] ?? ($accountData['Techs'][Vars::$resource[113]] ?? 0);
        foreach ($techOverrides as $techId => $techLevel) {
            $formula = str_replace('__TECH__' . $techId . '__', (string)$techLevel, $formula);
        }
        $value = (float)eval(Resources::getProd($formula, $id, $accountData));
        if (!is_finite($value)) throw new \RuntimeException('Некорректный результат формулы объекта.');
        return $value;
    }
}
