<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Game\Pages\FleetsPage;
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
            case 'fleets':
                if ($action === 'toOrbit') {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetToOrbit, [
                        'items' => $Msg->getData('items', [])
                    ]);
                }
                if ($action == "MoveShip") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetMoveShip, [
                        'fromFleetId' => $Msg->getData('fromFleetId', 0),
                        'toFleetId' => $Msg->getData('toFleetId', 0),
                        'shipId' => $Msg->getData('shipId', 0),
                        'count' => $Msg->getData('count', 0),
                    ]);
                }if ($action == "DisbandFleet") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetDisband, [
                        'FleetId' => $Msg->getData('FleetId', 0)
                    ]);
                }
                /*
                if ($action == "SendMission") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetSendMission, [
                        'fleet_id' => $Msg->getData('fleet_id', 0),
                        'mission_type' => $Msg->getData('mission_type', 0),
                        'target' => $Msg->getData('target', []),
                        'start_time' => $Msg->getData('start_time', 0)
                    ]);
                    $handle = true;
                }
                if ($action == "SetFleetCommands") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetSetCommands, [
                        'fleet_id' => $Msg->getData('fleet_id', 0),
                        'start_time' => $Msg->getData('start_time', 0),
                        'commands' => $Msg->getData('commands', [])
                    ]);
                    $handle = true;
                }
                if ($action == "DeleteCommand") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetDeleteCommand, [
                        'fleet_id' => $Msg->getData('fleet_id', 0),
                        'command_index' => $Msg->getData('command_index', 0)
                    ]);
                    $handle = true;
                }
                /*if ($action == "GetAvailableMissions") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetGetAvailableMissions, [
                        'fleet_id' => $Msg->getData('fleet_id', 0),
                        'target' => $Msg->getData('target', []),
                        'requestId' => $Msg->getRequestId(),
                    ]);
                    $handle = true;
                }
                if ($action == "GetFleetCommands") {
                    // Возвращаем текущие команды флота
                    $fleetId = $Msg->getData('fleet_id', 0);
                    
                    // Получаем данные о флоте
                    $fleetsRepo = $AccountData["Fleets"];
                    $fleet = $fleetsRepo->findById($fleetId);
                    
                    if (!$fleet) {
                        $Msg->setError('fleet_not_found', 'Fleet not found');
                        $Msg->setData('commands', []);
                    } else {
                        // Возвращаем команды
                        $commands = $fleet['commands'] ?? [];
                        $Msg->setData('commands', $commands);
                    }
                    $handle = true;
                }*/
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
