<?php

namespace SPGame\Game\Pages;


use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\MessageType;

use SPGame\Core\Logger;
use SPGame\Game\Services\Notification;
use SPGame\Game\Services\AccountData;

class MessagesPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {

        $allMessages = Notification::getAllMessages($AccountData['User']['id']);

        foreach ($allMessages as $key => $value) {
            if ($value['from_id'] === 0) {
            } else {
                $User = Users::findById($value['from_id']);
                $allMessages[$key]['from'] = $User['name'];
            }
            $User = Users::findById($value['to_id']);
            $allMessages[$key]['to'] = $User['name'];
        }

        // Все возможные типы сообщений
        $messageTypes = [];
        foreach (MessageType::cases() as $case) {
            $messageTypes[] = [
                'id'    => $case->value,
                'key'   => $case->toString(),
                'label' => "notifications.types." . $case->toString(), // клиент сам возьмёт перевод
            ];
        }

        return [
            'page' => 'messages',
            'allMessages' => $allMessages,
            'messageTypes'  => $messageTypes,
        ];
    }
}
