<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Game\Repositories\PlayerQueue;

class PageActionService
{
    public static function handle(Message $Msg, AccountData &$AccountData, int $aid): bool
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $action = $Msg->getAction();

        $handle = false;
        switch ($Msg->getMode()) {

            case 'overview':
                if ($action === 'RenamePlanet') {
                    $name = trim($Msg->getData("Name"));
                    $length = mb_strlen($name);
                    // 1. Проверка длины
                    if ($length < 2 || $length > 20) {
                        break;
                    }
                    // 2. Проверка разрешенных символов (буквы всех языков, цифры, пробел, дефис, подчёркивание)
                    if (!preg_match('/^[\p{L}\p{N} _-]+$/u', $name)) {
                        break;
                    }
                    // 3. Проверка начала/конца на спецсимволы
                    if (preg_match('/^[-_ ]|[-_ ]$/u', $name)) {
                        break;
                    }

                    // 4. Проверка на подряд идущие спецсимволы
                    if (preg_match('/[-_ ]{2,}/u', $name)) {
                        break;
                    }

                    // 5. Проверка количества спецсимволов (не более 3)
                    preg_match_all('/[-_ ]/u', $name, $matches);
                    if (count($matches[0]) > 3) {
                        break;
                    }

                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionRenamePlanet, [
                        'Name' => $Msg->getData('Name')
                    ]);
                    $handle = true;
                }
                //Logger::getInstance()->info("RenamePlanet Name:" . $Msg->getData("Name"));
                break;

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

            case 'shipyard':
            case 'defense':
                if ($action === 'build') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueHangarAdd, [
                        'items' => $Msg->getData('items', [])
                    ]);
                } elseif ($action === 'cancel') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionQueueHangarCancel, [
                        'QueueId' => $Msg->getData('id')
                    ]);
                    $handle = true;
                }
                //Logger::getInstance()->info("messages Data", $Msg->source());
                break;

            case 'messages':
                if ($action === 'read') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionMessagesRead, [
                        'ReadId' => $Msg->getData('ReadId', [])
                    ]);
                }
                //Logger::getInstance()->info("messages Data", $Msg->getData("ReadId",[]));
                break;
        }
        return $handle;
    }
}
