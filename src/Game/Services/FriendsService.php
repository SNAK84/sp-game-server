<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\{Friends, Planets, PlayerQueue, Users};

final class FriendsService
{
    public static function change(int $userId, string $action, mixed $value, mixed $text = ''): void
    {
        if (!is_int($value) || $value <= 0) throw new \InvalidArgumentException('Некорректный ID игрока или заявки.');
        if ($action === 'request') {
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")
                || mb_strlen($text, 'UTF-8') > Friends::REQUEST_MAX_LENGTH) {
                throw new \InvalidArgumentException('Текст заявки должен содержать не более ' . Friends::REQUEST_MAX_LENGTH . ' символов.');
            }
            $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
            if ($value === $userId || Users::findById($value) === null) {
                throw new \InvalidArgumentException('Нельзя отправить заявку этому игроку.');
            }
            $row = Friends::pair($userId, $value);
            if ($row !== null && $row['status'] !== Friends::REMOVED) {
                throw new \InvalidArgumentException('Дружба или заявка уже существует. Входящую заявку нужно принять.');
            }
            $row = array_replace($row ?? ['id' => Friends::nextId()], [
                'user_low' => min($userId, $value), 'user_high' => max($userId, $value),
                'requester_id' => $userId, 'status' => Friends::PENDING, 'created_at' => time(),
                'request_text' => $text,
            ]);
            if (Friends::findExistingById((int)$row['id']) === null) Friends::add($row);
            else Friends::update($row);
        } else {
            $row = Friends::findExistingById($value);
            if ($row === null || !in_array($userId, [$row['user_low'], $row['user_high']], true)) {
                throw new \InvalidArgumentException('Заявка недоступна.');
            }
            if ($action === 'accept') {
                if ($row['status'] !== Friends::PENDING || $row['requester_id'] === $userId) {
                    throw new \InvalidArgumentException('Принять заявку может только её получатель.');
                }
                $row['status'] = Friends::ACCEPTED;
            } elseif ($action === 'remove') {
                if ($row['status'] === Friends::REMOVED) throw new \InvalidArgumentException('Связь уже удалена.');
                $event = $row['status'] === Friends::ACCEPTED ? 'removed'
                    : ($row['requester_id'] === $userId ? 'cancelled' : 'rejected');
                $row['status'] = Friends::REMOVED;
            } else throw new \InvalidArgumentException('Неизвестное действие с друзьями.');
            Friends::update($row);
        }
        if (Friends::findExistingById((int)$row['id']) !== $row) {
            throw new \RuntimeException('Не удалось сохранить изменение дружбы в runtime.');
        }
        $otherId = $row['user_low'] === $userId ? $row['user_high'] : $row['user_low'];
        try {
            Notification::sendFriendshipChange($userId, $otherId, $event ?? ($action === 'accept' ? 'accepted' : 'request'),
                (string)$row['request_text']);
        } catch (\Throwable $e) {
            // Связь уже сохранена: ошибка уведомления не должна предлагать повторную заявку.
            Logger::getInstance()->error('Не удалось отправить уведомление о дружбе',
                ['userId' => $userId, 'recipientId' => $otherId, 'message' => $e->getMessage()]);
        }
        foreach ([$row['user_low'], $row['user_high']] as $id) {
            $user = Users::findById($id);
            if ($user !== null) PlayerQueue::addQueue((int)$user['account_id'], $id,
                (int)$user['current_planet'], PlayerQueue::SendActualeData);
        }
    }

    /** Только состояние пары viewer/target, без чужих списков друзей и заявок. */
    public static function relationship(int $viewerId, int $targetId): array
    {
        if ($viewerId === $targetId) return ['state' => 'self'];
        $row = Friends::pair($viewerId, $targetId);
        if ($row === null || $row['status'] === Friends::REMOVED) return ['state' => 'none'];
        return ['id' => $row['id'], 'state' => $row['status'] === Friends::ACCEPTED ? 'friends'
            : ($row['requester_id'] === $viewerId ? 'outgoing' : 'incoming')];
    }

    public static function list(int $userId): array
    {
        $result = [];
        foreach (Friends::forUser($userId) as $row) {
            if ($row['status'] === Friends::REMOVED) continue;
            $otherId = $row['user_low'] === $userId ? $row['user_high'] : $row['user_low'];
            $other = Users::findById($otherId);
            if ($other === null) continue;
            $home = Planets::findExistingById((int)$other['main_planet']);
            $minutes = (int)$other['online_time'] > 0 ? max(0, (int)floor((time() - (int)$other['online_time']) / 60)) : null;
            $result[] = ['id' => $row['id'], 'userId' => $otherId, 'name' => $other['name'],
                'accepted' => $row['status'] === Friends::ACCEPTED, 'incoming' => $row['requester_id'] !== $userId,
                'text' => $row['request_text'], 'createdAt' => $row['created_at'],
                'coordinates' => $home === null || (int)$home['owner_id'] !== $otherId ? null : [
                    'galaxy' => (int)$home['galaxy'], 'system' => (int)$home['system'], 'orbit' => (int)$home['planet']],
                // Как в Nova: подробная активность доступна только подтверждённым друзьям.
                'online' => $row['status'] !== Friends::ACCEPTED || $minutes === null ? null
                    : ($minutes < 4 ? 'online' : ($minutes <= 15 ? 'recent' : 'offline'))];
        }
        usort($result, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
        return $result;
    }
}
