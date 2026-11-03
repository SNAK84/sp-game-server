<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Factors;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\ResourceBases;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;

/** Разведданные и контршпионаж; перемещение выполняют существующие команды. */
final class FleetSpyService
{
    public const PROBE = 210;
    public const TECHNOLOGY = 106;

    /**
     * Правила раскрытия разделов и две проверки контршпионажа адаптированы
     * из Nova. Бонус влияет на разведку, но не на стоимость потерянных кораблей.
     * $roll используется только изолированными тестами, не WebSocket API.
     */
    public static function inspect(
        Fleet $fleet, AccountData $owner, AccountData $target, ?callable $roll = null
    ): array {
        [$strength, $threshold, $senderTech, $targetTech] = self::power($fleet, $owner, $target);
        $sections = ['resources' => []];
        foreach ([901, 902, 903, 911] as $id) {
            $sections['resources'][$id] = floor((float)($target['Resources'][$id]['count'] ?? 0));
        }
        foreach (['ships' => ['fleet', 'Ships', 0, ''],
            'defense' => ['defense', 'Defenses', 1, ''],
            'buildings' => ['build', 'Builds', 3, ''],
            'research' => ['tech', 'Techs', 5, '']] as $section => [$list, $key, $offset, $suffix]) {
            if ($strength < $threshold + $offset) {
                continue;
            }
            $sections[$section] = [];
            $ids = $list === 'defense'
                ? array_merge(Vars::$reslist[$list], Vars::$reslist['missile']) : Vars::$reslist[$list];
            foreach ($ids as $id) {
                $sections[$section][$id] = max(0, (int)($target[$key][Vars::$resource[$id] . $suffix] ?? 0));
            }
        }

        // Корабли, находящиеся в собственных неподвижных Fleet, тоже видны.
        // Активный MOVE означает полёт: такие корабли не приплюсовываются.
        if (isset($sections['ships'])) {
            $planet = $target['Planet'];
            $holdingIds = FleetDefendService::holdingFleets((int)$planet['id']);
            foreach (Fleets::getAll() ?? [] as $row) {
                $holding = in_array((int)$row['id'], $holdingIds, true);
                if (!$holding && (int)$row['owner_id'] !== (int)$target['User']['id']) continue;
                if ((int)$row['current_coordinate_type'] !== CoordinateType::OBJECT->value
                    || (int)$row['current_galaxy'] !== (int)$planet['galaxy']
                    || (int)$row['current_system'] !== (int)$planet['system']
                    || (int)$row['current_orbit'] !== (int)$planet['planet']
                    || (int)$row['current_object_type'] !== (int)$planet['planet_type']
                    || (!$holding && \SPGame\Game\Repositories\FleetCommands::getActiveCommands((int)$row['id']) !== [])) {
                    continue;
                }
                $ships = (FleetsShips::findByIndex('fleet_id', [(int)$row['id']]) ?? [])[0] ?? [];
                foreach ($sections['ships'] as $id => &$count) {
                    $count += max(0, (int)($ships[Vars::$resource[$id] . '_count'] ?? 0));
                }
                unset($count);
            }
        }
        return self::report($sections, $strength, $senderTech, $targetTech, $roll);
    }

    public static function resourceReason(Fleet $fleet, AccountData $owner, Coordinate $coordinate): ?string
    {
        $site = FleetSurveyService::site($coordinate);
        $base = ResourceBases::findExistingById((int)$site['id']);
        if (!ResourceBaseService::isEnabled() || $base === null) return 'Для шпионажа нужна занятая ресурсная база.';
        if ((int)$base['owner_id'] === (int)$owner['User']['id']) return 'Собственная ресурсная база не является целью шпионажа.';
        if (($fleet->getShips()[self::PROBE] ?? null)?->getCount() <= 0) return 'Для шпионажа необходимы шпионские зонды.';
        if (ResourceBaseService::battleAt((int)$site['id']) !== null) return 'Ресурсный объект участвует в бою.';
        return null;
    }

    /** Снимок общей защиты: без Fleet ID, координат перехвата, запасов G/K и чужого склада. */
    public static function inspectResource(Fleet $fleet, AccountData $owner, AccountData $target, array $site,
        float $time, ?callable $roll = null): array
    {
        $base = ResourceBases::findExistingById((int)$site['id']);
        if ($base === null || (int)$base['owner_id'] !== (int)$target['User']['id']) throw new \LogicException('Владелец ресурсной базы изменился.');
        [$strength, $threshold, $senderTech, $targetTech] = self::power($fleet, $owner, $target);
        $sections = [];
        if ($strength >= $threshold) {
            $sections['ships'] = array_fill_keys(Vars::$reslist['fleet'], 0);
            foreach (ResourceBaseService::defendingFleets($base, FleetSurveyService::coordinate($site), $time) as $defender) {
                foreach ($defender->getShips() as $id => $ship) $sections['ships'][$id] += $ship->getCount();
            }
        }
        if ($strength >= $threshold + 5) {
            $sections['research'] = [];
            foreach (Vars::$reslist['tech'] as $id) $sections['research'][$id] = max(0, (int)($target['Techs'][Vars::$resource[$id]] ?? 0));
        }
        return self::report($sections, $strength, $senderTech, $targetTech, $roll)
            + ['targetKind' => 'resource_base', 'snapshotAt' => $time];
    }

    /** Общие Nova-пороги и Factors без изменения прежнего шпионажа планет. */
    private static function power(Fleet $fleet, AccountData $owner, AccountData $target): array
    {
        $probes = $fleet->getShips()[self::PROBE] ?? null;
        if ($probes === null || $probes->getCount() <= 0) {
            throw new \LogicException('Для шпионажа необходимы шпионские зонды.');
        }
        $techName = Vars::$resource[self::TECHNOLOGY];
        $senderTech = max(1, (int)($owner['Techs'][$techName] ?? 0));
        $targetTech = max(1, (int)($target['Techs'][$techName] ?? 0));
        $strength = $probes->getCount() * max(0.0, 1.0 + Factors::getFactor('SpyPower', $owner));
        $threshold = ($senderTech > $targetTech ? -1 : 1) * ($senderTech - $targetTech) ** 2;
        return [$strength, $threshold, $senderTech, $targetTech];
    }

    private static function report(array $sections, float $strength, int $senderTech, int $targetTech, ?callable $roll): array
    {
        $counterLimit = min(100, max(0, (int)(($strength / 4) * ($targetTech / $senderTech))));
        $roll ??= static fn(int $min, int $max): int => random_int($min, $max);
        return [
            'sections' => $sections,
            'counterChance' => $counterLimit,
            'destroyed' => $roll(0, $counterLimit) >= $roll(0, 100),
        ];
    }

    /** Только металл/кристалл реальных кораблей, без топлива и бонуса SpyPower. */
    public static function debrisFor(Fleet $fleet): array
    {
        $percent = min(100.0, max(0.0, (float)(Config::getValue('FleetDebrisPercent') ?? 30)));
        $resources = [901 => 0.0, 902 => 0.0];
        foreach ($fleet->getShips() as $ship) {
            foreach ($resources as $id => &$amount) {
                $amount += max(0, $ship->getCount())
                    * (float)(Vars::$pricelist[$ship->getShipTypeId()][$id] ?? 0) * $percent / 100;
            }
            unset($amount);
        }
        return $resources;
    }
}
