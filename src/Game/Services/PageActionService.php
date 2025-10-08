<?php

namespace SPGame\Game\Services;

use SPGame\Core\Message;
use SPGame\Game\Repositories\PlayerQueue;

class PageActionService
{
    public static function handle(Message $Msg, array &$AccountData, int $aid): void
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $action = $Msg->getAction();

        switch ($Msg->getMode()) {
            case 'buildings':
                if ($action === 'build') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueUpgarde, [
                        'Element' => $Msg->getData('id'),
                        'AddMode' => true
                    ]);
                } elseif ($action === 'dismantle') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueDismantle, [
                        'Element' => $Msg->getData('id'),
                        'AddMode' => false
                    ]);
                } elseif ($action === 'cancel') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueCancel, [
                        'QueueId' => $Msg->getData('id'),
                        'AddMode' => false
                    ]);
                }
                break;

            case 'researchs':
                if ($action === 'build') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueUpgarde, [
                        'Element' => $Msg->getData('id'),
                        'AddMode' => true
                    ]);
                } elseif ($action === 'cancel') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueCancel, [
                        'QueueId' => $Msg->getData('id'),
                        'AddMode' => false
                    ]);
                }
                break;
        }
    }
}
