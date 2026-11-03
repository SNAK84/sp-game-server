<?php

declare(strict_types=1);

namespace SPGame\Game\Pages;

use SPGame\Game\Services\{AccountData, FriendsService};
use SPGame\Game\Repositories\Friends;

class FriendsPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {
        $userId = (int)$AccountData['User']['id'];
        $action = $this->Msg->getAction();
        if (in_array($action, ['request', 'accept', 'remove'], true)) {
            $data = $this->Msg->source()['data'];
            FriendsService::change($userId, $action, $data['id'] ?? null, $data['text'] ?? '');
        } elseif (!in_array($action, ['', 'list', 'ActualData', 'reconnect', null], true)) {
            throw new \InvalidArgumentException('Неизвестное действие с друзьями.');
        }
        return ['page' => 'friends', 'userId' => $userId, 'Friends' => FriendsService::list($userId),
            'FriendRequestMaxLength' => Friends::REQUEST_MAX_LENGTH];
    }
}
