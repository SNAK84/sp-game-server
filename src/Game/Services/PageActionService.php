<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Messages;

class PageActionService
{
    public static function handle(
        Message $Msg,
        AccountData &$AccountData,
        int $aid,
        ?Message $response = null
    ): bool
    {
        $User = &$AccountData['User'];
        $Planet = &$AccountData['Planet'];
        $action = $Msg->getAction();

        if (in_array($Msg->getMode(), ['buildings', 'researchs', 'shipyard', 'defense'], true)
            && in_array($action, ['build', 'dismantle', 'cancel'], true)) {
            try {
                FleetBattleService::assertQueueAction((int)$Planet['id'],
                    $action === 'cancel' ? (int)$Msg->getData('id', 0) : null);
            } catch (\LogicException $error) {
                $response?->setError('planet_in_battle', $error->getMessage());
                return true;
            }
        }
        if ($Msg->getMode() === 'fleets' && in_array($action, ['toOrbit', 'MoveShip', 'DisbandFleet', 'SendMission'], true)) {
            try {
                FleetBattleService::assertPlanetAvailable((int)$Planet['id']);
                foreach (['FleetId', 'fleetId', 'fromFleetId', 'toFleetId'] as $key) {
                    $fleetId = (int)$Msg->getData($key, 0);
                    if ($fleetId > 0) FleetBattleService::assertFleetAvailable($fleetId);
                }
            } catch (\LogicException $error) {
                $response?->setError('planet_in_battle', $error->getMessage());
                return true;
            }
        }

        $handle = false;
        switch ($Msg->getMode()) {

            case 'galaxy':
                if ($action === 'PhalanxScan') {
                    try {
                        $input = $Msg->source()['data'];
                        $targetId = $input['targetId'] ?? null;
                        $token = $input['scanToken'] ?? null;
                        if (!is_string($token) || !preg_match('/\A[a-f0-9-]{36}\z/D', $token)) {
                            throw new \InvalidArgumentException('Некорректный запрос сканирования.');
                        }
                        PhalanxService::validate($AccountData, $targetId);
                        PlayerQueue::addQueue($aid, (int)$User['id'], (int)$Planet['id'], PlayerQueue::ActionPhalanxScan,
                            ['targetId' => $targetId, 'token' => $token]);
                    } catch (\InvalidArgumentException|\LogicException $error) {
                        $response?->setError('phalanx_unavailable', $error->getMessage());
                    }
                    return true;
                }
                break;

            case 'overview':
                if ($action === 'RenamePlanet') {
                    $name = trim($Msg->getData("Name"));
                    $length = mb_strlen($name);
                    // 1. Проверка длины
                    if ($length < 2 || $length > 20 || strlen($name) > 32) {
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
                        'Name' => $name
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
                if ($action === 'SetFleetSensors') {
                    try {
                        $data = $Msg->source()['data'];
                        $id = $data['fleetId'] ?? null;
                        if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Флот недоступен.');
                        $fleet = FleetsServices::getManageableFleet($id, $AccountData);
                        FleetSensorService::validateMode($fleet, $AccountData, $data['enabled'] ?? null);
                        PlayerQueue::addQueue($aid, (int)$User['id'], $fleet->getHomePlanetId(),
                            PlayerQueue::ActionFleetControl, ['action' => $action, 'fleetId' => $id, 'enabled' => $data['enabled']]);
                    } catch (\InvalidArgumentException|\LogicException $error) {
                        $response?->setError('fleet_sensor_unavailable', $error->getMessage());
                    }
                    return true;
                }
                if (in_array($action, ['StopFleet', 'ResumeFleet', 'SetFleetCommands'], true)) {
                    try {
                        $data = $Msg->source()['data'];
                        $id = $data['fleetId'] ?? null;
                        if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Флот недоступен.');
                        $fleet = FleetsServices::getManageableFleet($id, $AccountData);
                        FleetControlService::assertAction($fleet, $AccountData, $action);
                        $useCargoFuel = false;
                        if ($action === 'ResumeFleet') {
                            $useCargoFuel = array_key_exists('useCargoFuel', $data) ? $data['useCargoFuel'] : false;
                            if (!is_bool($useCargoFuel)) throw new \InvalidArgumentException('Некорректный режим использования дейтерия из трюма.');
                            if (array_key_exists('fuelMode', $data)) {
                                FleetControlService::prepareResume($fleet, $AccountData, $data['fuelMode'], microtime(true));
                            } elseif ($useCargoFuel) {
                                FleetControlService::assertCargoRefuel($AccountData);
                            }
                        }
                        $plan = $data['plan'] ?? [];
                        if ($action === 'SetFleetCommands') {
                            if (!is_array($plan)) throw new \InvalidArgumentException('Некорректный план.');
                            FleetControlService::preparePlan($fleet, $AccountData, $plan);
                        }
                        $queuePlanetId = $fleet->getHomeSiteId() > 0
                            ? (int)ResourceBaseService::fallbackPlanet($fleet)['id'] : $fleet->getHomePlanetId();
                        PlayerQueue::addQueue($aid, (int)$User['id'], $queuePlanetId,
                            PlayerQueue::ActionFleetControl, ['action' => $action, 'fleetId' => $id, 'plan' => $plan]
                                + ($action === 'ResumeFleet' ? ['useCargoFuel' => $useCargoFuel]
                                    + (array_key_exists('fuelMode', $data) ? ['fuelMode' => $data['fuelMode']] : []) : []));
                    } catch (\InvalidArgumentException|\LogicException $error) {
                        $response?->setError('fleet_control_unavailable', $error->getMessage());
                    }
                    return true;
                }
                if ($action === 'InviteAttackGroup') {
                    try {
                        $data = $Msg->source()['data'];
                        FleetGroupAttackService::prepareInvitation((int)$User['id'], $data['groupId'] ?? null,
                            $data['username'] ?? null, microtime(true));
                        PlayerQueue::addQueue($aid, (int)$User['id'], (int)$Planet['id'],
                            PlayerQueue::ActionFleetGroupInvite, ['groupId' => $data['groupId'], 'username' => $data['username']]);
                    } catch (\InvalidArgumentException|\LogicException $exception) {
                        $response?->setError('group_invitation_unavailable', $exception->getMessage());
                    }
                    $handle = true;
                }
                if (in_array($action, ['RecallFleet', 'RecallDefend'], true)) {
                    try {
                        $id = $Msg->source()['data']['fleetId'] ?? null;
                        if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Флот недоступен.');
                        $fleet = $AccountData->getFleetsData()->get($id);
                        if ($fleet === null) throw new \InvalidArgumentException('Флот недоступен.');
                        FleetCommandsService::assertRecall($fleet, $AccountData);
                        PlayerQueue::addQueue($aid, (int)$User['id'], (int)$Planet['id'],
                            PlayerQueue::ActionFleetRecall, ['fleetId' => $id]);
                    } catch (\InvalidArgumentException|\LogicException $exception) {
                        $response?->setError('fleet_recall_unavailable', $exception->getMessage());
                    }
                    $handle = true;
                }
                if ($action === 'toOrbit') {
                    try {
                        FleetsServices::assertBaseSlot($AccountData);
                        PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetToOrbit, [
                            'items' => $Msg->getData('items', [])
                        ]);
                    } catch (\LogicException $exception) {
                        $response?->setError('fleet_base_limit', $exception->getMessage());
                    }
                    $handle = true;
                }
                if ($action == "MoveShip") {
                    PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionFleetMoveShip, [
                        'fromFleetId' => $Msg->getData('fromFleetId', 0),
                        'toFleetId' => $Msg->getData('toFleetId', 0),
                        'shipId' => $Msg->getData('shipId', 0),
                        'count' => $Msg->getData('count', 0),
                    ]);
                }
                if ($action === 'DisbandFleet' || $action === 'RenameFleet') {
                    try {
                        // getData() со значением default=null приводит scalar к строке.
                        // Здесь нужны исходные типы, в том числе для отклонения bool.
                        $requestData = $Msg->source()['data'];
                        $rawId = $requestData['FleetId'] ?? null;
                        if (!(is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) || (int)$rawId <= 0) {
                            throw new \InvalidArgumentException('Флот недоступен.');
                        }
                        $fleetId = (int)$rawId;
                        FleetsServices::getManageableFleet($fleetId, $AccountData, $action === 'DisbandFleet');
                        $data = ['FleetId' => $fleetId];
                        if ($action === 'RenameFleet') {
                            $data['Name'] = \SPGame\Game\Fleet\Fleet::validateName($requestData['Name'] ?? null);
                        }
                        PlayerQueue::addQueue($aid, $User['id'], $Planet['id'],
                            $action === 'DisbandFleet' ? PlayerQueue::ActionFleetDisband : PlayerQueue::ActionFleetRename,
                            $data
                        );
                    } catch (\InvalidArgumentException|\LogicException $exception) {
                        $response?->setError('fleet_action_unavailable', $exception->getMessage());
                    }
                    $handle = true;
                }
                if ($action === 'SendMission') {
                    $fleetId = (int)$Msg->getData('fleetId', 0);
                    $missionType = (int)$Msg->getData('missionType', 0);
                    $coordinates = $Msg->getData('coordinates', []);
                    $resources = $Msg->getData('resources', []);

                    try {
                        if (!is_array($coordinates) || !is_array($resources)) {
                            throw new \InvalidArgumentException('Некорректные данные миссии флота.');
                        }

                        $fleet = $AccountData->getFleetsData()->get($fleetId);
                        if ($fleet === null) {
                            throw new \InvalidArgumentException('Флот недоступен.');
                        }

                        foreach (PlayerQueue::getByAccount($aid) ?? [] as $queuedEvent) {
                            if (
                                ($queuedEvent['action'] ?? '') === PlayerQueue::ActionFleetSendMission
                                && (int)($queuedEvent['data']['fleetId'] ?? 0) === $fleetId
                            ) {
                                throw new \LogicException('Отправка этого флота уже ожидает обработки.');
                            }
                        }

                        $prepared = FleetCommandsService::prepareMissionRequest(
                            $fleet,
                            $AccountData,
                            $missionType,
                            $coordinates,
                            $Msg->getData('speedFactor'),
                            $Msg->getData('fuel'),
                            $resources,
                            $Msg->source()['data']['duration'] ?? null,
                            $Msg->source()['data']['group'] ?? null,
                            null,
                            $Msg->source()['data']['refuel'] ?? false,
                            $Msg->source()['data']['contactLoss'] ?? null,
                            $Msg->source()['data']['mining'] ?? null
                        );

                        PlayerQueue::addQueue(
                            $aid,
                            (int)$User['id'],
                            $prepared['queuePlanetId'],
                            PlayerQueue::ActionFleetSendMission,
                            [
                                'fleetId' => $fleetId,
                                'missionType' => $missionType,
                                'coordinates' => $prepared['targetCoordinate']->toArray(),
                                'speedFactor' => $prepared['speedFactor'],
                                'fuel' => $prepared['fuel'],
                                'resources' => $prepared['resources'],
                                'duration' => $prepared['duration'],
                                'group' => $Msg->source()['data']['group'] ?? null,
                                'refuel' => $prepared['refuel'],
                                'contactLoss' => $prepared['interception']['policy'] ?? null,
                                'mining' => $prepared['mining'],
                            ]
                        );
                    } catch (\InvalidArgumentException|\LogicException $exception) {
                        $response?->setError('fleet_mission_unavailable', $exception->getMessage());
                    } catch (\Throwable $exception) {
                        Logger::getInstance()->error('SendMission validation failed', [
                            'fleetId' => $fleetId,
                            'message' => $exception->getMessage(),
                        ]);
                        $response?->setError('fleet_mission_failed', 'Не удалось принять миссию флота.');
                    }

                    $handle = true;
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
                } elseif ($action === 'delete') {
                    try {
                        $ids = Notification::normalizeMessageIds($Msg->source()['data']['Ids'] ?? null);
                        foreach ($ids as $id) {
                            $message = Messages::findById($id);
                            if (!$message || (int)$message['to_id'] !== (int)$User['id']) {
                                throw new \InvalidArgumentException('Сообщения недоступны.');
                            }
                        }
                        PlayerQueue::addQueue($aid, $User['id'], $Planet['id'], PlayerQueue::ActionMessagesDelete, [
                            'Ids' => $ids,
                        ]);
                    } catch (\InvalidArgumentException $exception) {
                        $response?->setError('messages_unavailable', $exception->getMessage());
                    }
                    $handle = true;
                }
                //Logger::getInstance()->info("messages Data", $Msg->getData("ReadId",[]));
                break;
        }
        return $handle;
    }
}
