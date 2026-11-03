<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\Fleet;
use SPGame\Core\Logger;
use SPGame\Game\Repositories\{Config, FleetAttackGroups as Groups, FleetCommands, Planets, Users};
use SPGame\Game\Space\Coordinate;

/** САБ: неизменный срок, явное приглашение и одна общая сторона боя. */
final class FleetGroupAttackService
{
    public static function prepare(Fleet $fleet, array $target, mixed $options, float $now, array $travel): array
    {
        if (!is_array($options)) throw new \InvalidArgumentException('Не указаны параметры групповой атаки.');
        $id = $options['id'] ?? 0;
        if (!is_int($id) || $id < 0) throw new \InvalidArgumentException('Некорректный ID групповой атаки.');
        $maximum = (int)(Config::getValue('GroupAttackMaxFleets') ?? 16);
        if ($id > 0) {
            $row = Groups::findExistingById($id);
            if ($row === null || !in_array($fleet->getOwnerId(), json_decode($row['users'], true), true)) {
                throw new \InvalidArgumentException('Групповая атака недоступна.');
            }
            if ((int)$row['target_id'] !== (int)$target['id']) throw new \InvalidArgumentException('Цель не совпадает с целью групповой атаки.');
            $members = self::members($row);
            if ($members === []) throw new \LogicException('В группе больше нет атакующих флотов.');
            if (count($members) >= $maximum && !isset($members[$fleet->getId()])) throw new \LogicException('Достигнут лимит флотов групповой атаки.');
            $row['fleets'] = json_encode(array_values(array_unique([...array_keys($members), $fleet->getId()])), JSON_THROW_ON_ERROR);
        } else {
            $invited = $options['users'] ?? [];
            $names = $options['usernames'] ?? [];
            if (!is_array($invited) || !is_array($names)
                || count($invited) + count($names) + 1 > (int)(Config::getValue('GroupAttackMaxUsers') ?? 5)) {
                throw new \InvalidArgumentException('Превышен лимит приглашённых игроков.');
            }
            $users = [$fleet->getOwnerId()];
            foreach ($names as $name) $invited[] = (int)self::userByName($name)['id'];
            foreach ($invited as $userId) {
                if (!is_int($userId) || $userId <= 0 || Users::findExistingById($userId) === null
                    || $userId === (int)$target['owner_id']) {
                    throw new \InvalidArgumentException('Этого игрока нельзя пригласить в атаку.');
                }
                if (in_array($userId, $users, true)) throw new \InvalidArgumentException('Игрок уже приглашён в группу.');
                $users[] = $userId;
            }
            $at = $options['executeAt'] ?? ($now + (float)$travel['duration']['max'] + 30);
            if ((!is_int($at) && !is_float($at)) || !is_finite((float)$at)) throw new \InvalidArgumentException('Некорректное время общей атаки.');
            $row = ['id' => 0, 'owner_id' => $fleet->getOwnerId(), 'target_id' => (int)$target['id'],
                'execute_time' => (float)$at, 'users' => json_encode(array_values(array_unique($users)), JSON_THROW_ON_ERROR),
                'fleets' => json_encode([$fleet->getId()], JSON_THROW_ON_ERROR)];
        }
        if ((float)$row['execute_time'] <= $now + 5 || (float)$row['execute_time'] > $now + 7 * 86400) {
            throw new \InvalidArgumentException('Срок групповой атаки истёк или слишком далёк.');
        }
        return ['before' => $id > 0 ? Groups::findExistingById($id) : null, 'after' => $row];
    }

    /** Как в Nova: игровое имя, а не login аккаунта; дружба/альянс не обязательны. */
    private static function userByName(mixed $name): array
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")
            || trim($name) === '' || mb_strlen($name, 'UTF-8') > 32) {
            throw new \InvalidArgumentException('Укажите игровое имя игрока (до 32 символов).');
        }
        $name = mb_strtolower(trim($name), 'UTF-8');
        foreach (Users::getAll() ?? [] as $user) {
            if (mb_strtolower($user['name'], 'UTF-8') === $name) return $user;
        }
        throw new \InvalidArgumentException('Игрок с таким именем не найден.');
    }

    /** Проверяется и до PlayerQueue, и при исполнении: приглашать может только создатель живой группы. */
    public static function prepareInvitation(int $actorId, mixed $groupId, mixed $name, float $now): array
    {
        if (!is_int($groupId) || $groupId <= 0) throw new \InvalidArgumentException('Групповая атака недоступна.');
        $row = Groups::findExistingById($groupId);
        if ($row === null || (int)$row['owner_id'] !== $actorId) {
            throw new \InvalidArgumentException('Групповая атака недоступна.');
        }
        if ((float)$row['execute_time'] <= $now || self::members($row) === []) {
            throw new \LogicException('Приглашения доступны только до прибытия атакующих флотов.');
        }
        $target = Planets::findExistingById((int)$row['target_id']);
        if ($target === null || (int)$target['owner_id'] === $actorId) throw new \LogicException('Цель атаки недоступна.');
        $user = self::userByName($name);
        $users = json_decode($row['users'], true, 512, JSON_THROW_ON_ERROR);
        if (in_array((int)$user['id'], $users, true)) throw new \InvalidArgumentException('Игрок уже приглашён в группу.');
        if ((int)$user['id'] === (int)$target['owner_id']) throw new \InvalidArgumentException('Нельзя пригласить владельца цели.');
        if (count($users) >= (int)(Config::getValue('GroupAttackMaxUsers') ?? 5)) {
            throw new \LogicException('Превышен лимит приглашённых игроков.');
        }
        $after = $row;
        $after['users'] = json_encode([...$users, (int)$user['id']], JSON_THROW_ON_ERROR);
        return ['before' => $row, 'after' => $after, 'recipientId' => (int)$user['id']];
    }

    public static function invite(int $actorId, mixed $groupId, mixed $name, float $now): array
    {
        $plan = self::prepareInvitation($actorId, $groupId, $name, $now);
        try {
            Groups::store($plan['after']);
        } catch (\Throwable $error) {
            try { Groups::store($plan['before']); }
            catch (\Throwable $rollback) {
                Logger::getInstance()->error('CRITICAL: не удалось восстановить приглашения группы',
                    ['groupId' => $groupId, 'message' => $rollback->getMessage()]);
            }
            throw $error;
        }
        // Группа уже сохранена: сбой уведомления не должен добавлять участника повторно.
        try { Notification::sendGroupAttackInvitation($plan['after'], $plan['recipientId'], $actorId); }
        catch (\Throwable $error) {
            Logger::getInstance()->error('Не удалось отправить приглашение в групповую атаку',
                ['groupId' => $groupId, 'recipientId' => $plan['recipientId'], 'message' => $error->getMessage()]);
        }
        return $plan['after'];
    }

    /** Только невыполненные боевые команды; отзыв освобождает место без вмешательства в PlayerQueue. */
    public static function members(array $row): array
    {
        $members = [];
        foreach (json_decode($row['fleets'], true, 512, JSON_THROW_ON_ERROR) as $fleetId) {
            foreach (FleetCommands::getFleetQueue((int)$fleetId) as $command) {
                if ((int)$command['command_type'] !== Command::GROUP_ATTACK->value || $command['executed']) continue;
                $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
                if ((int)($params['groupId'] ?? 0) === (int)$row['id']) $members[(int)$fleetId] = $command;
            }
        }
        return $members;
    }

    public static function ready(int $id): bool
    {
        $row = Groups::findExistingById($id);
        if ($row === null) return true;
        foreach (self::members($row) as $fleetId => $_) {
            $active = FleetCommands::getActiveCommand($fleetId);
            if ($active !== null && in_array((int)$active['command_type'], [10, 11, 12], true)
                && (float)$active['execute_time'] <= (float)$row['execute_time'] + .00001) return false;
        }
        return true;
    }

    public static function arrivals(int $id): array
    {
        $row = Groups::findExistingById($id);
        if ($row === null) return [];
        return array_filter(self::members($row), static function (array $command): bool {
            $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
            return isset($params['groupRendezvous']) && (float)$command['execute_time'] > 0;
        });
    }

    public static function listFor(int $userId, float $now): array
    {
        $result = [];
        foreach (Groups::rows() as $row) {
            if ((float)$row['execute_time'] <= $now || !in_array($userId, json_decode($row['users'], true), true)) continue;
            $planet = Planets::findExistingById((int)$row['target_id']);
            if ($planet === null || (int)$planet['owner_id'] === $userId) continue;
            $members = self::members($row);
            if ($members === []) continue;
            $invited = [];
            foreach (json_decode($row['users'], true) as $id) {
                $user = Users::findExistingById($id);
                if ($user !== null) $invited[] = ['id' => $id, 'name' => (string)$user['name']];
            }
            $result[] = ['id' => (int)$row['id'], 'ownerName' => (string)(Users::findExistingById((int)$row['owner_id'])['name'] ?? ''),
                'canInvite' => (int)$row['owner_id'] === $userId,
                'executeAt' => (float)$row['execute_time'], 'fleets' => count($members),
                'fleetIds' => array_keys($members), 'invited' => $invited,
                'target' => Coordinate::object((int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'],
                    \SPGame\Game\Space\ObjectType::from((int)$planet['planet_type']))->toArray()];
        }
        return $result;
    }

    /** Подбор точной скорости каждого MOVE; случайный вход не выбирается заранее. */
    public static function schedule(Command $type, Coordinate $start, Coordinate $target, array $params,
        float $time, float $speed, float $executeAt, float $cap): array
    {
        $flight = new FleetFlightService();
        $remaining = $executeAt - $time;
        if ($remaining <= 0) throw new \LogicException('Флот уже не успевает к началу групповой атаки.');
        $same = $start->getGalaxy() === $target->getGalaxy() && $start->getSystem() === $target->getSystem();
        $final = $type === Command::MOVE_IN_SYSTEM && $same;
        if ($final) {
            $duration = $remaining;
            $distance = $flight->calculateCommandGameDistance($type, $start, $target, $params, $time, $executeAt);
        } else {
            $distance = $flight->calculateCommandGameDistance($type, $start, $target, $params, $time, $time + 1);
            $orbit = \SPGame\Game\Repositories\GalaxyOrbits::findByIndex('galaxy_system_orbit',
                [$target->getGalaxy(), $target->getSystem(), $target->getOrbit()]);
            $edge = GalaxyGenerator::getSpecialOrbit($target->getGalaxy(), $target->getSystem(),
                \SPGame\Game\Repositories\GalaxyOrbits::SYSTEM_EDGE);
            $reserve = $flight->calculateGameDuration($flight->calculateInSystemGameDistance(
                (float)$edge['distance'] + (float)$orbit['distance']), $speed, $cap) + 1;
            if ($type === Command::MOVE_IN_SYSTEM) {
                $reserve += $flight->calculateGameDuration($flight->calculateTransitionDistance(
                    $start->getGalaxy(), $start->getSystem(), $target->getGalaxy(), $target->getSystem()), $speed, $cap) + 1;
            }
            $fast = $flight->calculateGameDuration($distance, $speed, $cap) + 1;
            if ($remaining < $fast + $reserve) throw new \LogicException('Флот не успевает к общему сроку без превышения выбранной скорости.');
            $duration = min($flight->calculateGameDuration($distance, $speed, 1) - .5,
                $remaining * $fast / ($fast + $reserve));
        }
        $gameSpeed = (float)(Config::getValue('FleetGameSpeed') ?? 10);
        if ($distance <= 0) throw new \LogicException('Нулевая дистанция участка групповой атаки.');
        $factor = 35000 * sqrt($distance * 10 / $speed) / ($duration * $gameSpeed - 10);
        if (!is_finite($factor) || $duration < 5 || $factor < 1 - 1e-8 || $factor > $cap + 1e-8) {
            throw new \LogicException('Нельзя достичь общего срока в допустимом диапазоне скорости 10–100%.');
        }
        return ['duration' => $duration, 'speedFactor' => max(1.0, min($cap, $factor))];
    }

    public static function cleanup(float $now): void
    {
        foreach (Groups::rows() as $row) {
            if ((float)$row['execute_time'] < $now - 86400 && self::members($row) === []) Groups::delete(['id' => (int)$row['id']]);
        }
    }
}
