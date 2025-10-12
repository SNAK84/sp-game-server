<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Game\Repositories\PlayerQueue;

class PageActionService
{
    public static function handle(Message $Msg, array &$AccountData, int $aid): bool
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $action = $Msg->getAction();

        $handle = false;
        switch ($Msg->getMode()) {
            case 'buildings':
                if ($action === 'build') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueUpgarde, [
                        'Element' => $Msg->getData('id'),
                        'AddMode' => true
                    ]);
                    $handle = true;
                } elseif ($action === 'dismantle') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueDismantle, [
                        'Element' => $Msg->getData('id'),
                        'AddMode' => false
                    ]);
                    $handle = true;
                } elseif ($action === 'cancel') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueCancel, [
                        'QueueId' => $Msg->getData('id'),
                        'AddMode' => false
                    ]);
                    $handle = true;
                }
                break;

            case 'researchs':
                if ($action === 'build') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueUpgarde, [
                        'Element' => $Msg->getData('id'),
                        'AddMode' => true
                    ]);
                    $handle = true;
                } elseif ($action === 'cancel') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueCancel, [
                        'QueueId' => $Msg->getData('id'),
                        'AddMode' => false
                    ]);
                    $handle = true;
                }
                break;

            case 'messages':
                if ($action === 'read') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionMessagesRead, [
                        'ReadId' => $Msg->getData('ReadId',[])
                    ]);
                }
                //Logger::getInstance()->info("messages Data", $Msg->getData("ReadId",[]));
                break;
        }
        return $handle;
    }
}
