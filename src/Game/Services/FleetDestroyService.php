<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Planets, Builds, Ships, Defenses, PlanetResources, Users, Queues,
    Fleets, FleetCommands, FleetAttackGroups};
use SPGame\Game\Space\ObjectType;

/** Nova: сначала победа в бою, затем независимые попытки уничтожения луны и гибели флота. */
final class FleetDestroyService
{
    public const DEATH_STAR = 214;

    public static function deathStars(Fleet $fleet): int
    {
        return ($fleet->getShips()[self::DEATH_STAR] ?? null)?->getCount() ?? 0;
    }

    public static function outcome(int $diameter, int $survivors, bool $won, string $seed): array
    {
        if ($diameter <= 0 || $survivors < 0) throw new \InvalidArgumentException('Некорректный размер луны или состав флота.');
        $result = ['attempted' => $won && $survivors > 0, 'moonChance' => 0.0, 'fleetChance' => 0.0,
            'moonDestroyed' => false, 'fleetDestroyed' => false];
        if (!$result['attempted']) return $result;
        $result['moonChance'] = max(0.0, min(100.0, round((100 - sqrt($diameter)) * sqrt($survivors), 1)));
        $result['fleetChance'] = max(0.0, min(100.0, round(sqrt($diameter) / 2)));
        $random = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(hash_hmac('sha256', 'moon-destruction', $seed, true)));
        $result['moonDestroyed'] = $random->getInt(1, 100) <= $result['moonChance'];
        $result['fleetDestroyed'] = $random->getInt(1, 100) <= $result['fleetChance'];
        return $result;
    }

    /** Только подготовка изменений; их запись/rollback выполняет общий commit результата боя. */
    public static function removalChanges(array $moon, float $time): array
    {
        $parent = Planets::findByIndex('galaxy_system_planet_type',
            [$moon['galaxy'], $moon['system'], $moon['planet'], ObjectType::PLANET->value]);
        if ($parent === null || (int)$parent['owner_id'] !== (int)$moon['owner_id']) throw new \LogicException('У уничтожаемой луны отсутствует родительская планета.');
        $id = (int)$moon['id'];
        $changes = [];
        foreach (['planets' => Planets::class, 'builds' => Builds::class, 'ships' => Ships::class,
            'defenses' => Defenses::class, 'resources' => PlanetResources::class] as $key => $class) {
            if ($class::findExistingById($id) !== null) $changes[] = ['repository' => $key, 'id' => $id, 'after' => null];
        }
        foreach (Users::getAll() ?? [] as $row) {
            $after = $row;
            foreach (['current_planet', 'main_planet'] as $field) if ((int)$row[$field] === $id) $after[$field] = (int)$parent['id'];
            if ($after !== $row) $changes[] = ['repository' => 'users', 'id' => (int)$row['id'], 'after' => $after];
        }
        foreach (Queues::getAll() ?? [] as $row) {
            if ((int)$row['planet_id'] !== $id) continue;
            // Исследование общее для аккаунта: переносим место финансирования, не теряем технологию/refund.
            $after = $row['type'] === QueuesServices::TECHS ? array_replace($row, ['planet_id' => (int)$parent['id']]) : null;
            $changes[] = ['repository' => 'queues', 'id' => (int)$row['id'], 'after' => $after];
        }
        foreach (Fleets::getAll() ?? [] as $row) {
            $after = $row;
            if ((int)$row['home_planet_id'] === $id) $after['home_planet_id'] = (int)$parent['id'];
            foreach (['start', 'current', 'target'] as $prefix) {
                if ((int)$row[$prefix . '_coordinate_type'] === 1 && (int)$row[$prefix . '_object_type'] === ObjectType::MOON->value
                    && (int)$row[$prefix . '_galaxy'] === (int)$moon['galaxy'] && (int)$row[$prefix . '_system'] === (int)$moon['system']
                    && (int)$row[$prefix . '_orbit'] === (int)$moon['planet']) $after[$prefix . '_object_type'] = ObjectType::PLANET->value;
            }
            if ($after !== $row) $changes[] = ['repository' => 'fleets', 'id' => (int)$row['id'], 'after' => $after];
        }
        foreach (FleetCommands::getAll() ?? [] as $row) {
            if ($row['executed']) continue;
            $params = json_decode($row['params'], true, 512, JSON_THROW_ON_ERROR);
            $changed = false;
            foreach (['target', 'at'] as $field) {
                $c = $params[$field] ?? null;
                if (!is_array($c) || (int)($c['type'] ?? 0) !== 1 || (int)($c['objectType'] ?? 0) !== ObjectType::MOON->value
                    || (int)$c['galaxy'] !== (int)$moon['galaxy'] || (int)$c['system'] !== (int)$moon['system']
                    || (int)$c['orbit'] !== (int)$moon['planet']) continue;
                $params[$field]['objectType'] = ObjectType::PLANET->value;
                $changed = true;
            }
            if (!$changed) continue;
            $after = $row;
            // Как в Nova: новая попытка DESTROY исчезнувшей луны становится обычной атакой планеты.
            if ((int)$row['command_type'] === Command::DESTROY->value && !isset($params['battleWait'])) {
                $after['command_type'] = Command::ATTACK->value;
                $fleet = Fleets::findExistingById((int)$row['fleet_id']);
                if ($fleet !== null) $changes[] = ['repository' => 'fleets', 'id' => (int)$fleet['id'],
                    'patch' => ['mission' => Mission::ATTACK->value]];
            }
            if (isset($params['hold'])) $params['hold']['until'] = min((float)$params['hold']['until'], $time);
            $after['params'] = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $changes[] = ['repository' => 'commands', 'id' => (int)$row['id'], 'after' => $after];
        }
        foreach (FleetAttackGroups::rows() as $row) {
            if ((int)$row['target_id'] === $id) $changes[] = ['repository' => 'attackGroups', 'id' => (int)$row['id'],
                'after' => array_replace($row, ['target_id' => (int)$parent['id']])];
        }
        return $changes;
    }
}
