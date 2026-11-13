<?php

namespace SPGame\Game\Services;

/** Полный публичный каталог v2. mode/action остаются только внутри игрового слоя. */
final class ProtocolOperations
{
    public const QUERIES = [
        'player.planets.get' => ['overview', '', []],
        'player.status.get' => ['overview', '', []],
        'localization.get' => ['lang', 'get', ['lang']],
        'overview.get' => ['overview', '', []],
        'buildings.get' => ['buildings', '', []],
        'research.get' => ['researchs', '', []],
        'shipyard.get' => ['shipyard', '', []],
        'defense.get' => ['defense', '', []],
        'galaxy.system.get' => ['galaxy', '', ['galaxy', 'system']],
        'fleets.get' => ['fleets', '', ['target', 'historyPages']],
        'fleet.missions.get' => ['fleets', 'GetAvailableMissions', ['fleetId', 'coordinates', 'speedFactor', 'missionType', 'forPlan', 'planReturn', 'origin', 'duration', 'mining']],
        'fleet.navigation.get' => ['fleets', 'GetSystemNavigationInfo', ['galaxy', 'system', 'fleetId', 'coordinates']],
        'fleet.commands.get' => ['fleets', 'GetFleetCommands', ['fleet_id', 'fleetId']],
        'fleet.missions.catalog.get' => ['fleets', 'GetMissions', []],
        'messages.get' => ['messages', '', ['page', 'category']],
        'friends.get' => ['friends', 'list', []],
        'information.get' => ['information', 'get', ['id']],
        'combat.report.get' => ['battlereport', 'get', ['id']],
        'combat.reports.get' => ['battlereport', 'list', ['page']],
    ];

    public const COMMANDS = [
        'planet.select' => ['overview', 'planetselect', []],
        'planet.rename' => ['overview', 'RenamePlanet', ['name']],
        'building.upgrade' => ['buildings', 'build', ['id']],
        'building.dismantle' => ['buildings', 'dismantle', ['id']],
        'building.cancel' => ['buildings', 'cancel', ['id']],
        'research.start' => ['researchs', 'build', ['id']],
        'research.cancel' => ['researchs', 'cancel', ['id']],
        'shipyard.build' => ['shipyard', 'build', ['items']],
        'shipyard.cancel' => ['shipyard', 'cancel', ['id']],
        'defense.build' => ['defense', 'build', ['items']],
        'defense.cancel' => ['defense', 'cancel', ['id']],
        'phalanx.scan' => ['galaxy', 'PhalanxScan', ['targetId', 'scanToken']],
        'fleet.create' => ['fleets', 'toOrbit', ['items']],
        'fleet.ships.transfer' => ['fleets', 'MoveShip', ['fromFleetId', 'toFleetId', 'shipId', 'count']],
        'fleet.disband' => ['fleets', 'DisbandFleet', ['FleetId']],
        'fleet.rename' => ['fleets', 'RenameFleet', ['FleetId', 'Name']],
        'fleet.send' => ['fleets', 'SendMission', ['fleetId', 'missionType', 'coordinates', 'speedFactor', 'fuel', 'resources', 'duration', 'group', 'refuel', 'contactLoss', 'mining']],
        'fleet.recall' => ['fleets', 'RecallFleet', ['fleetId']],
        'fleet.stop' => ['fleets', 'StopFleet', ['fleetId']],
        'fleet.resume' => ['fleets', 'ResumeFleet', ['fleetId', 'useCargoFuel', 'fuelMode']],
        'fleet.route.set' => ['fleets', 'SetFleetCommands', ['fleetId', 'plan']],
        'fleet.sensors.set' => ['fleets', 'SetFleetSensors', ['fleetId', 'enabled']],
        'fleet.group.invite' => ['fleets', 'InviteAttackGroup', ['groupId', 'username']],
        'messages.read' => ['messages', 'read', ['ReadId']],
        'messages.delete' => ['messages', 'delete', ['Ids']],
        'friend.request' => ['friends', 'request', ['id', 'text']],
        'friend.accept' => ['friends', 'accept', ['id']],
        'friend.remove' => ['friends', 'remove', ['id']],
        'combat.report.save' => ['battlereport', 'save', ['id']],
        'combat.report.unsave' => ['battlereport', 'unsave', ['id']],
    ];

    public const SUBSCRIPTIONS = ['player.status.get', 'player.planets.get', 'overview.get', 'buildings.get', 'research.get',
        'shipyard.get', 'defense.get', 'galaxy.system.get', 'fleets.get', 'messages.get', 'friends.get'];

    public static function definition(string $name, bool $command = false): array
    {
        return ($command ? self::COMMANDS : self::QUERIES)[$name]
            ?? throw new \DomainException($command ? 'command.unknown' : 'query.unknown');
    }

    public static function validateData(array $data, array $definition): void
    {
        if (array_diff(array_keys($data), $definition[2])) throw new \DomainException('protocol.unknown_field');
        if (array_key_exists('historyPages', $data)) {
            $pages = $data['historyPages'];
            if ($pages instanceof \stdClass) $pages = (array)$pages;
            if (!is_array($pages) || count($pages) > 32) throw new \DomainException('query.invalid_input');
            foreach ($pages as $id => $page) {
                if (!preg_match('/^[1-9][0-9]{0,18}$/D', (string)$id) || !is_int($page) || $page < 1 || $page > 1000000) {
                    throw new \DomainException('query.invalid_input');
                }
            }
        }
        if ($definition[0] === 'messages' && $definition[1] === '') {
            if (array_key_exists('page', $data) && (!is_int($data['page']) || $data['page'] < 1 || $data['page'] > 1000000)) {
                throw new \DomainException('query.invalid_input');
            }
            if (array_key_exists('category', $data) && (!is_int($data['category'])
                || \SPGame\Game\Repositories\MessageType::tryFrom($data['category']) === null)) {
                throw new \DomainException('query.invalid_input');
            }
        }
    }

    public static function validateCommand(string $name, array $data): void
    {
        $definition = self::definition($name, true);
        self::validateData($data, $definition);
        $required = match ($name) {
            'fleet.send' => ['fleetId', 'missionType', 'coordinates', 'speedFactor', 'fuel', 'resources'],
            'fleet.resume' => ['fleetId'], 'friend.request' => ['id'],
            default => $definition[2],
        };
        if (array_diff($required, array_keys($data))) throw new \DomainException('command.invalid_input');
        foreach ($data as $key => $value) {
            if (in_array($key, ['items', 'resources', 'coordinates', 'group', 'mining'], true)
                && $value !== null && !is_array($value) && !$value instanceof \stdClass) throw new \DomainException('command.invalid_input');
            if (in_array($key, ['enabled', 'refuel', 'useCargoFuel'], true) && !is_bool($value)) throw new \DomainException('command.invalid_input');
            if ($key === 'plan') {
                if ($value instanceof \stdClass) $value = (array)$value;
                if (!is_array($value) || array_is_list($value)
                    || array_diff(array_keys($value), ['version', 'steps', 'fuel', 'resources'])) throw new \DomainException('command.invalid_input');
            }
            if (in_array($key, ['ReadId', 'Ids'], true) && (!is_array($value) || !array_is_list($value))) throw new \DomainException('command.invalid_input');
            if (in_array($key, ['id', 'FleetId', 'fleetId', 'shipId', 'fromFleetId', 'toFleetId', 'targetId', 'groupId'], true)) {
                $pattern = str_starts_with($name, 'combat.report.') ? '/^[a-f0-9]{32}$/D' : '/^(0|[1-9][0-9]{0,18})$/D';
                if (!is_string($value) || !preg_match($pattern, $value)) throw new \DomainException('command.invalid_input');
            }
            if (in_array($key, ['count', 'missionType', 'speedFactor', 'fuel'], true)
                && (!is_numeric($value) || is_bool($value))) throw new \DomainException('command.invalid_input');
        }
    }

    /** JSON entity references — decimal strings; игровой слой пока использует PHP int. */
    public static function ids(mixed $value, bool $outgoing, string $key = ''): mixed
    {
        if (is_array($value) || $value instanceof \stdClass) {
            $result = [];
            foreach ((array)$value as $k => $item) $result[$k] = self::ids($item, $outgoing,
                is_int($k) ? $key : (string)$k);
            return $result;
        }
        $isId = preg_match('/^(id|qid|[A-Za-z_]*_id|[A-Za-z_]*Id|Ids|ReadId|current_planet|main_planet)$/D', $key);
        if ($outgoing && $isId && is_int($value) && $value >= 0) return (string)$value;
        if (!$outgoing && $isId && is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value)) {
            if (strlen($value) === 19 && strcmp($value, (string)PHP_INT_MAX) > 0) throw new \DomainException('resource.unavailable');
            return (int)$value;
        }
        return $value;
    }
}
