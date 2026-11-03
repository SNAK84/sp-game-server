<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Core\WSocket;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Techs;

use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Resources;

use SPGame\Game\Repositories\Queues;

use SPGame\Game\Repositories\Vars;

use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

use Swoole\Timer;

class EventLoop
{
    protected Logger $logger;

    private array $tasks = [];
    private float $nextBattleReportCleanup = 0.0;
    private float $nextPayloadCleanup = 0.0;
    private float $nextFleetFuelNotice = 0.0;

    public function __construct()
    {
        $this->logger = Logger::getInstance();
    }


    public function register(callable $callback, int $intervalMs): void
    {
        $this->tasks[] = [
            'interval' => $intervalMs,
            'callback' => $callback
        ];
    }

    /**
     * Запуск игрового цикла через Swoole Timer
     * @param int $interval Интервал в миллисекундах
     */
    public function start(): void
    {

        foreach ($this->tasks as $task) {
            $this->logger->info("Event loop started with interval {$task['interval']}ms");
            Timer::tick($task['interval'], function (int $timerId) use ($task): void {
                try {
                    ($task['callback'])($timerId);
                } catch (\Throwable $error) {
                    // Исключение не должно попасть в shutdown PHP/Swoole из Timer callback.
                    try {
                        $this->logger->error('Ошибка задачи игрового цикла перехвачена', [
                            'timerId' => $timerId, 'intervalMs' => $task['interval'],
                            'exception' => get_class($error), 'message' => $error->getMessage(),
                            'file' => $error->getFile(), 'line' => $error->getLine(),
                        ]);
                    } catch (\Throwable $loggingError) {
                        error_log('CRITICAL: не удалось записать ошибку игрового цикла: '
                            . get_class($error) . ': ' . $error->getMessage()
                            . '; ошибка логирования: ' . $loggingError->getMessage());
                    }
                }
            });
        }
    }

    /**
     * Основная логика обработки
     */
    public function process(): void
    {
        try { FleetMiningCheckpointService::world(fn() => $this->processWorld()); }
        catch (\RuntimeException $error) {
            // Занятый checkpoint — штатный retry на следующем tick, не поломка мира.
            if ($error->getCode() !== \SPGame\Game\Repositories\MiningCheckpoints::LOCK_BUSY) throw $error;
        }
    }

    private function processWorld(): void
    {
        $now = microtime(true);

        if ($now >= $this->nextPayloadCleanup) {
            $this->nextPayloadCleanup = $now + 5.0;
            try { PlayerQueue::deleteExpiredPayloads($now); }
            catch (\Throwable $error) {
                $this->logger->error('Очистка временного буфера PlayerQueue отложена', ['message' => $error->getMessage()]);
            }
        }

        if ($now >= $this->nextBattleReportCleanup) {
            $this->nextBattleReportCleanup = $now + 60.0;
            try { \SPGame\Game\Repositories\BattleReports::deleteExpired($now); }
            catch (\Throwable $error) {
                $this->logger->error('Удаление просроченных боевых докладов отложено', ['message' => $error->getMessage()]);
            }
            FleetGroupAttackService::cleanup($now);
        }

        // Fleet events являются временными событиями мира и обрабатываются
        // до обычного прохода online Accounts.
        FleetDefendService::rescheduleSupplies($now);
        $interceptionAccounts = FleetInterceptionFlightService::process($now);
        if (!$this->processFleetEvents($now)) {
            return;
        }
        ResourceBaseService::settleWorld($now);

        // Прибытия обрабатываются перед фиксацией состава очередного раунда.
        $roundAccounts = FleetBattleService::tick($now);
        $battleAccounts = FleetAttackService::process($now, function (int $planetId, float $time): void {
            $planet = Planets::findExistingById($planetId);
            $user = Users::findExistingById((int)($planet['owner_id'] ?? 0));
            if ($user === null) throw new \LogicException('Владелец цели боя недоступен.');
            $account = new AccountData((int)$user['account_id']);
            $account['WorkPlanet'] = $planetId;
            $this->processPlayerStateAtTime($time, $account, $planetId);
            $account->save();
        });
        $battleAccounts = array_unique(array_merge($roundAccounts, $battleAccounts, FleetSpaceBattleService::process($now), $interceptionAccounts));
        $onlineAccounts = Accounts::getOnline();
        foreach ($battleAccounts as $accountId) {
            if (isset($onlineAccounts[$accountId])) $this->sendActualData((int)$accountId);
        }

        // Принятая команда принадлежит игровому миру, а не открытому сокету.
        // Offline игроки без команд не добавляются к обычному online-проходу.
        foreach (self::playerAccountIds(Accounts::getOnline(), PlayerQueue::getAll()) as $accountId) {
            $Account = Accounts::findExistingById($accountId);
            if ($Account === null) continue;

            if (!$Account['id']) continue;

            $sendMsg = false;


            $AccountData = new AccountData($Account['id']);

            if (!$AccountData['User']) continue;

            // 🧩 Приводим состояние всех планет и технологий игрока к текущему времени
            $sendMsg = $this->processPlayerStateAtTime($now, $AccountData);

            $AccountData->save();

            // ⚙️ Обработка действий из PlayerQueue (запросы на постройки, отмены и т.д.)
            $sendMsg = ($this->processPlayerEvents($Account['id'])) ? true : $sendMsg;

            if ($sendMsg && isset(Accounts::getOnline()[$accountId])) {
                self::sendActualData($Account['id']);
            }
        }
        if ($now >= $this->nextFleetFuelNotice) {
            $this->nextFleetFuelNotice = $now + 1.0;
            FleetControlService::notifyFuelStops($now);
            FleetInterceptionFlightService::notifyStops($now);
        }
        $online = Accounts::getOnline();
        foreach (FleetSensorService::tick($now) as $accountId) {
            if (isset($online[$accountId])) $this->sendActualData((int)$accountId);
        }
        // Можно логировать, если нужно
        $duration = round(microtime(true) - $now, 3);
        $this->logger->debug("Event process time: {$duration}s");
    }

    /** Online + владельцы опубликованных событий; pending ready=0 ещё не принят. */
    private static function playerAccountIds(array $online, array $events): array
    {
        $ids = array_fill_keys(array_keys($online), true);
        foreach ($events as $event) {
            $id = (int)$event['account_id'];
            if ($id > 0 && (int)($event['ready'] ?? 1) === 1) $ids[$id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Обработать due Fleet events в глобальном chronological порядке.
     */
    protected function processFleetEvents(float $now): bool
    {
        $scheduler = new FleetEventScheduler();
        $failedFleets = [];

        // Ошибка одного флота не останавливает остальные события мира.
        // Исключение сохраняет запись команды; retry — на следующем tick.
        while (true) {
            $currentCommand = null;
            try {
                $scheduler->process(
                    $now,
                    static function (float $eventNow) use (&$currentCommand, &$failedFleets): ?array {
                        $baseEvent = ResourceBaseService::nextEvent($eventNow, $failedFleets);
                        foreach (FleetCommands::getPendingTimeCommands($eventNow) as $command) {
                            if (isset($failedFleets[(int)$command['fleet_id']])) continue;
                            $currentCommand = $command;
                            if (!FleetAttackService::mustWait($command)) {
                                if ($baseEvent !== null && (float)$baseEvent['execute_time'] <= (float)$command['execute_time']) {
                                    return $currentCommand = $baseEvent;
                                }
                                return $command;
                            }
                            $currentCommand = null;
                        }
                        return $currentCommand = $baseEvent;
                    },
                    function (array $command) use (&$currentCommand): bool {
                        if (isset($command['resource_base_id'])) {
                            $accounts = ResourceBaseService::processEvent((int)$command['resource_base_id'], (float)$command['execute_time']);
                            $currentCommand = null;
                            $online = Accounts::getOnline();
                            foreach ($accounts as $accountId) if (isset($online[$accountId])) {
                                try { $this->sendActualData($accountId); }
                                catch (\Throwable $error) {
                                    $this->logger->error('Не удалось отправить состояние ресурсной базы',
                                        ['accountId' => $accountId, 'error' => $error->getMessage()]);
                                }
                            }
                            return true;
                        }
                        $fleetId = (int)$command['fleet_id'];
                        $fleet = FleetsData::getInstance()->get($fleetId);

                        if ($fleet === null) {
                            // Fleet уже уничтожен. Его временные события больше не
                            // принадлежат игровому миру и не должны блокировать
                            // глобальный scheduler.
                            FleetCommands::clearFleetQueue($fleetId);
                            $currentCommand = null;
                            return true;
                        }

                        $eventTime = (float)$command['execute_time'];
                        $this->processFleetObjectStateAtTime($command, $fleet, $eventTime);

                        $ownerUser = Users::findById($fleet->getOwnerId());
                        $ownerAccountId = (int)($ownerUser['account_id'] ?? 0);
                        if ($ownerAccountId <= 0) {
                            throw new \LogicException("Аккаунт владельца Fleet #{$fleetId} не найден.");
                        }

                        $affectedAccountIds = [$ownerAccountId => true];
                        $targetAccountId = $this->getFleetTargetAccountId($fleet);
                        if ($targetAccountId !== null) {
                            $affectedAccountIds[$targetAccountId] = true;
                        }

                        $ownerAccountData = new AccountData($ownerAccountId);
                        $positionBefore = [$fleet->getCurrentPositionTime(), $fleet->getCurrentCoordinate()->toArray()];
                        $completionError = null;
                        try {
                            $completed = FleetCommandsService::completeCommand($command, $fleet, $ownerAccountData);
                        } catch (\Throwable $exception) {
                            $completed = false;
                            $completionError = $exception;
                        }
                        $stateChanged = $completionError !== null && (
                            FleetCommands::getCommand((int)$command['id']) !== $command
                            || $positionBefore !== [$fleet->getCurrentPositionTime(), $fleet->getCurrentCoordinate()->toArray()]
                        );
                        if ($completed || $stateChanged) {
                            // После активации нового участка обновляем получателя
                            // даже на межсистемном переходе. Старая цель в списке
                            // позволяет удалить входящее событие при начале возврата.
                            // Даже если следующий участок не активировался, клиент
                            // должен убрать уже завершённый участок с карты.
                            try {
                                $targetAccountId = $this->getFleetTargetAccountId($fleet);
                                if ($targetAccountId !== null) {
                                    $affectedAccountIds[$targetAccountId] = true;
                                }
                                // Общая оборона ресурса включает гостей: обновляем
                                // всех участников, а не только атакующего и хозяина.
                                $spaceBattle = \SPGame\Game\Repositories\SpaceBattles::forFleet($fleetId);
                                foreach ($spaceBattle['state']['mission']['participants'] ?? [] as $participant) {
                                    $user = Users::findExistingById((int)$participant['ownerId']);
                                    if ($user !== null) $affectedAccountIds[(int)$user['account_id']] = true;
                                }
                                $onlineAccounts = Accounts::getOnline();
                                foreach (array_keys($affectedAccountIds) as $accountId) {
                                    if (isset($onlineAccounts[$accountId])) {
                                        $this->sendActualData((int)$accountId);
                                    }
                                }
                            } catch (\Throwable $snapshotError) {
                                if ($completionError === null) throw $snapshotError;
                                $this->logger->error('Не удалось обновить Fleet после ошибки команды', [
                                    'fleetId' => $fleetId, 'message' => $snapshotError->getMessage(),
                                ]);
                            }
                        }

                        if ($completionError !== null) throw $completionError;
                        if ($completed) $currentCommand = null;
                        return $completed;
                    }
                );
                return true;
            } catch (\Throwable $exception) {
                // Не отмечаем проблемную команду выполненной: состояние можно
                // диагностировать и повторить после исправления причины.
                $this->logger->error($currentCommand === null ? 'Fleet event processing stopped' : 'Ошибка события флота: повтор отложен', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'fleetId' => $currentCommand['fleet_id'] ?? null,
                    'commandId' => $currentCommand['id'] ?? null,
                ]);
                if ($currentCommand === null) return false;
                $failedFleets[(int)$currentCommand['fleet_id']] = true;
            }
        }
    }

    /** Владелец логической цели миссии, независимо от текущего MOVE-участка. */
    protected function getFleetTargetAccountId(Fleet $fleet): ?int
    {
        if ($fleet->isReturn()) {
            return null;
        }
        $target = $fleet->getTargetCoordinate();
        $targetOwnerId = 0;
        if ($target !== null && FleetSurveyService::isResource($target)) {
            $targetOwnerId = (int)(\SPGame\Game\Repositories\ResourceBases::findExistingById((int)$target->getSiteId())['owner_id'] ?? 0);
        } elseif ($target?->getType() === CoordinateType::OBJECT && $target->getObjectType() !== null) {
            $planet = Planets::findByIndex('galaxy_system_planet_type', [
                $target->getGalaxy(),
                $target->getSystem(),
                $target->getOrbit(),
                // Обломки не имеют владельца: snapshot обновляет игрока планеты-anchor.
                $target->getObjectType() === ObjectType::DEBRIS
                    ? ObjectType::PLANET->value : $target->getObjectType()->value,
            ]);
            $targetOwnerId = (int)($planet['owner_id'] ?? 0);
        } elseif ($target?->getType() === CoordinateType::FLEET) {
            $targetRow = \SPGame\Game\Repositories\Fleets::getById((int)$target->getFleetId());
            $targetOwnerId = (int)($targetRow['owner_id'] ?? 0);
        }

        if ($targetOwnerId <= 0) {
            return null;
        }
        $user = Users::findById($targetOwnerId);
        $accountId = (int)($user['account_id'] ?? 0);
        return $accountId > 0 ? $accountId : null;
    }

    /**
     * Привести только затронутый OBJECT к event time.
     */
    protected function processFleetObjectStateAtTime(
        array $command,
        Fleet $fleet,
        float $eventTime
    ): void
    {
        $target = FleetCommandsService::getCommandTarget($command);
        if ($target === null || $target->getType() !== CoordinateType::OBJECT) {
            return;
        }

        $commandType = FleetCommandType::from((int)$command['command_type']);
        if (
            $commandType === FleetCommandType::MOVE_BETWEEN_SYSTEMS
            || $commandType === FleetCommandType::MOVE_BETWEEN_GALAXIES
        ) {
            return;
        }

        if ($commandType === FleetCommandType::MOVE_IN_SYSTEM) {
            $current = $fleet->getCurrentCoordinate();
            $currentPosition = $current->getType() === CoordinateType::FLEET
                ? $current->getLastKnownPosition()
                : $current;
            $targetPosition = $target;

            if (
                $currentPosition->getGalaxy() !== $targetPosition->getGalaxy()
                || $currentPosition->getSystem() !== $targetPosition->getSystem()
            ) {
                // Это сегмент выхода к границе исходной системы, а не
                // взаимодействие с конечным OBJECT.
                return;
            }
        }

        $objectType = $target->getObjectType();
        if (FleetSurveyService::isResource($target)
            && ($commandType === FleetCommandType::SPY
                || $fleet->getMission() === \SPGame\Game\Enums\FleetMissionType::SPY->value)) {
            $base = \SPGame\Game\Repositories\ResourceBases::findExistingById((int)$target->getSiteId());
            $user = $base === null ? null : Users::findExistingById((int)$base['owner_id']);
            if ($user !== null) {
                // У базы нет фиктивного Planet ID. Актуализируем настоящий аккаунт,
                // включая завершённые к прибытию исследования, прежним pipeline.
                $accountData = new AccountData((int)$user['account_id']);
                $this->processPlayerStateAtTime($eventTime, $accountData);
                $accountData->save();
            }
            return;
        }
        if ($objectType === null) {
            // Служебные орбиты не являются объектами Planets и не требуют
            // актуализации чужого AccountData.
            return;
        }

        $object = Planets::findByIndex(
            'galaxy_system_planet_type',
            [
                $target->getGalaxy(),
                $target->getSystem(),
                $target->getOrbit(),
                $objectType->value,
            ]
        );
        if (!$object || (int)($object['owner_id'] ?? 0) <= 0) {
            return;
        }

        $objectOwner = Users::findById((int)$object['owner_id']);
        $objectOwnerAccountId = (int)($objectOwner['account_id'] ?? 0);
        if ($objectOwnerAccountId <= 0) {
            throw new \LogicException('Аккаунт владельца Fleet target не найден.');
        }

        $accountData = new AccountData($objectOwnerAccountId);
        $accountData['WorkPlanet'] = (int)$object['id'];
        $this->processPlayerStateAtTime(
            $eventTime,
            $accountData,
            (int)$object['id']
        );
        $accountData->save();
    }



    /**
     * Приводит состояние всех планет игрока и его очередей к заданному моменту времени.
     */
    protected function processPlayerStateAtTime(
        float $targetTime,
        AccountData &$AccountData,
        ?int $onlyPlanetId = null
    ): bool
    {
        $sendMsg = false;
        $userId = $AccountData['User']['id'];

        // Обрабатываем все очереди аккаунта (в порядке end_time) до targetTime
        $sendMsg = $this->processAccountQueues($targetTime, $AccountData) ? true : $sendMsg;


        // После обработки очередей — доводим ресурсы всех планет до targetTime
        $Planets = $onlyPlanetId !== null
            ? [
                $onlyPlanetId => Planets::findById($onlyPlanetId),
            ]
            : Planets::getAllPlanets($userId);
        foreach ($Planets as $pid => $Planet) {
            if (!$Planet) {
                continue;
            }
            $AccountData['WorkPlanet'] = $Planet['id'];
            $sendMsg = Helpers::processPlanet($targetTime, $AccountData) ? true : $sendMsg;
        }
        return $sendMsg;
    }

    protected function processAccountQueues(float $StatTimeTick, AccountData &$AccountData): bool
    {
        $sendMsg = false;
        $maxIterations = 500; // safety
        $lastProcessedId = 0;
        while ($maxIterations-- > 0) {
            // НАДО: репозиторий должен вернуть самую раннюю активную очередь для данного user
            $Queue = Queues::getActiveMinEndTimeByUser($AccountData['User']['id']);
            if (!$Queue) break;
            if ($Queue['end_time'] > $StatTimeTick) break;

            if ($Queue && $Queue['id'] === $lastProcessedId) {
                $this->logger->error("Queue service returned same queue id {$lastProcessedId} after CompleteQueue. Breaking to avoid loop.");
                break;
            }
            $lastProcessedId = $Queue['id'];

            $this->logger->info(
                sprintf(
                    "Queue complete: id=%d, type=%s, planet=%d, end=%.3f <= %.3f",
                    $Queue['id'],
                    $Queue['type'] ?? '?',
                    $Queue['planet_id'],
                    $Queue['end_time'],
                    $StatTimeTick
                )
            );

            $planetId = $Queue['planet_id'];

            $AccountData['WorkPlanet'] = $planetId;
            // 1) Пересчитать ресурсы планеты до конца этой очереди
            Helpers::processPlanet($Queue['end_time'], $AccountData);

            // 2) Завершить очередь (важно: CompleteQueue должна корректно работать с переданными данными)
            // Хорошая практика: внутри CompleteQueue выполнять DB-транзакцию,
            // чтобы изменения очередей и пересчеты были атомарными.
            QueuesServices::CompleteQueue($Queue['id'], $AccountData, $Queue['end_time']);

            //            $AccountData->save();

            // 5) Решаем, нужно ли отправлять данные игроку (если это текущая планета или техи)
            if (
                $planetId == $AccountData['User']['current_planet'] ||
                ($Queue['type'] == QueuesServices::TECHS && $Queue['user_id'] == $AccountData['User']['id']) ||
                in_array((int)$Queue['object_id'], [6, 31], true)
            ) {
                $sendMsg = true;
            }

            // После CompleteQueue возможен ReCalc других очередей => повторно запрашиваем следующую earliest очередь
        }

        if ($maxIterations <= 0) {
            $this->logger->warning("processAccountQueues: reached max iterations for user " . $AccountData['User']['id']);
        }

        return $sendMsg;
    }

    /**
     * Обработка очереди событий игроков
     */
    protected function processPlayerEvents(int $accountId): bool
    {
        $sendMsg = false;
        foreach (PlayerQueue::getByAccount($accountId) ?? [] as $Event) {
            try {
                $sendMsg = $this->processPlayerEvent($Event, $accountId) || $sendMsg;
            } catch (\Throwable $error) {
                // Не повторяем произвольно уже частично исполненное действие.
                // Его атомарность остаётся обязанностью конкретного обработчика.
                $pending = PlayerQueue::findExistingById((int)$Event['id']);
                $this->logger->error('Ошибка обработки события PlayerQueue перехвачена', [
                    'queueId' => (int)$Event['id'], 'accountId' => $accountId,
                    'action' => $Event['action'], 'planetId' => (int)$Event['planet_id'],
                    'discarded' => $pending !== null, 'exception' => get_class($error),
                    'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine(),
                ]);
                if ($pending !== null) PlayerQueue::delete($pending);
                $sendMsg = true;
            }
        }
        return $sendMsg;
    }

    /** Одна команда: исключение из неё перехватывается до перехода к соседней. */
    protected function processPlayerEvent(array $Event, int $accountId): bool
    {
        $sendMsg = false;
        $affectedAccountIds = [];

        try {
            FleetBattleService::assertPlayerMutation($Event['action'], (int)$Event['planet_id'], $Event['data']);
        } catch (\LogicException $error) {
            $this->logger->warning('Игровая команда отклонена из-за боя', [
                'planetId' => $Event['planet_id'], 'action' => $Event['action'],
            ]);
            PlayerQueue::delete($Event);
            return true;
        }

        $AccountData = new AccountData($Event['account_id']);
        $eventPlanet = Planets::findExistingById((int)$Event['planet_id']);
        if ($eventPlanet === null && in_array($Event['action'], [
            PlayerQueue::ActionRenamePlanet, PlayerQueue::ActionQueueUpgarde, PlayerQueue::ActionQueueDismantle,
            PlayerQueue::ActionQueueHangarAdd, PlayerQueue::ActionQueueHangarCancel, PlayerQueue::ActionQueueCancel,
            PlayerQueue::ActionFleetToOrbit, PlayerQueue::ActionFleetMoveShip, PlayerQueue::ActionFleetDisband,
            PlayerQueue::ActionFleetSendMission,
            PlayerQueue::ActionPhalanxScan,
        ], true)) {
            // Луна могла исчезнуть после приёма request: не восстанавливаем её через AccountData defaults.
            $this->logger->warning('Команда отклонена: исходный объект больше не существует',
                ['planetId' => (int)$Event['planet_id'], 'action' => $Event['action']]);
            PlayerQueue::delete($Event);
            return true;
        }
        $AccountData['WorkPlanet'] = $eventPlanet !== null ? $Event['planet_id'] : $AccountData['User']['current_planet'];

        switch ($Event['action']) {
            case PlayerQueue::ActionPhalanxScan:
                try {
                    PhalanxService::scan($AccountData, $Event['data']['targetId'], (int)$Event['id'], $Event['data']['token']);
                } catch (\Throwable $error) {
                    $this->logger->error('Ошибка сканирования фаланги', ['queueId' => (int)$Event['id'],
                        'planetId' => (int)$Event['planet_id'], 'exception' => get_class($error),
                        'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()]);
                    $failure = ['eventId' => (int)$Event['id'], 'token' => $Event['data']['token'],
                        'sourceId' => (int)$Event['planet_id'], 'error' => $error->getMessage()];
                    \SPGame\Game\Repositories\PhalanxScans::put((int)$Event['user_id'],
                        \SPGame\Game\Repositories\PhalanxScans::pack($failure, microtime(true)));
                }
                $sendMsg = true;
                break;
            case PlayerQueue::ActionSelectPlanet:
                $planetId = (int)($Event['planet_id'] ?? 0);
                $planet = $planetId > 0 ? Planets::findById($planetId) : null;

                if (
                    !$planet
                    || (int)$planet['owner_id'] !== (int)$AccountData['User']['id']
                ) {
                    $this->logger->warning('Rejected invalid queued planet selection', [
                        'accountId' => $accountId,
                        'planetId' => $planetId,
                    ]);
                    // Удаляем некорректное событие ниже и отправляем актуальный
                    // snapshot, не меняя current_planet.
                    $sendMsg = true;
                    break;
                }

                $AccountData['User']['current_planet'] = $planetId;
                $sendMsg  = true;
                break;
            case PlayerQueue::ActionRenamePlanet:
                $AccountData['Planet']['name'] = $Event['data']['Name'];
                $AccountData['Planet']['name_generated'] = 0;
                $sendMsg  = true;
                break;
            case PlayerQueue::ActionQueueUpgarde:
                QueuesServices::AddToQueue($Event['data']['Element'], $AccountData, $Event['added_at'], true);
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionQueueDismantle:
                QueuesServices::AddToQueue($Event['data']['Element'], $AccountData, $Event['added_at'], false);
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionQueueHangarAdd:
                QueuesServices::AddToQueueHangar($Event['data']['items'], $AccountData, $Event['added_at']);
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionQueueHangarCancel:
                QueuesServices::CancelToQueueHangar($Event['data']['QueueId'], $AccountData, $Event['added_at']);
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionQueueCancel:
                QueuesServices::CancelToQueue($Event['data']['QueueId'], $AccountData, $Event['added_at']);
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionQueueReCalcTech:
                QueuesServices::ReCalcTimeQueue(QueuesServices::TECHS, $AccountData, $Event['added_at']);
                $sendMsg  = true;
                break;


            case PlayerQueue::ActionFleetToOrbit:
                try {
                    // Повторная проверка: другая принятая команда могла занять последний слот.
                    FleetsServices::AddToOrbit($Event['data']['items'], $AccountData, $Event['added_at']);
                } catch (\LogicException $exception) {
                    $this->logger->warning('Создание флота отклонено', [
                        'userId' => $AccountData['User']['id'],
                        'message' => $exception->getMessage(),
                    ]);
                }
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionFleetMoveShip:
                FleetsServices::MoveShip($Event['data']['toFleetId'], $Event['data']['fromFleetId'], $Event['data']['shipId'], $Event['data']['count'], $AccountData, $Event['added_at']);
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionFleetDisband:
            case PlayerQueue::ActionFleetRename:
                try {
                    if ($Event['action'] === PlayerQueue::ActionFleetDisband) {
                        FleetsServices::DisbandFleet((int)$Event['data']['FleetId'], $AccountData, $Event['added_at']);
                    } else {
                        FleetsServices::RenameFleet((int)$Event['data']['FleetId'], $Event['data']['Name'] ?? null, $AccountData);
                    }
                } catch (\InvalidArgumentException|\LogicException $exception) {
                    $this->logger->warning('Команда управления флотом отклонена', [
                        'fleetId' => $Event['data']['FleetId'] ?? null,
                        'action' => $Event['action'],
                        'message' => $exception->getMessage(),
                    ]);
                }
                $sendMsg  = true;
                break;

            case PlayerQueue::ActionFleetRecallDefend:
            case PlayerQueue::ActionFleetRecall:
                try {
                    $fleet = $AccountData->getFleetsData()->get((int)($Event['data']['fleetId'] ?? 0));
                    if ($fleet === null) throw new \InvalidArgumentException('Флот недоступен.');
                    $targetAccountId = $this->getFleetTargetAccountId($fleet);
                    FleetCommandsService::recall($fleet, $AccountData, (float)$Event['added_at']);
                    if ($targetAccountId !== null && $targetAccountId !== $accountId) {
                        $affectedAccountIds[$targetAccountId] = true;
                    }
                } catch (\InvalidArgumentException|\LogicException $exception) {
                    $this->logger->warning('Отзыв флота отклонён', ['message' => $exception->getMessage()]);
                }
                $sendMsg = true;
                break;

            case PlayerQueue::ActionFleetControl:
                try {
                    $fleet = FleetsServices::getManageableFleet((int)$Event['data']['fleetId'], $AccountData);
                    $previousTargetAccount = $this->getFleetTargetAccountId($fleet);
                    $time = max((float)$Event['added_at'], $fleet->getCurrentPositionTime());
                    match ($Event['data']['action']) {
                        'StopFleet' => FleetControlService::stop($fleet, $AccountData, $time),
                        'ResumeFleet' => FleetControlService::resume($fleet, $AccountData, $time,
                            $Event['data']['useCargoFuel'] ?? false, $Event['data']['fuelMode'] ?? null),
                        'SetFleetCommands' => FleetControlService::savePlan($fleet, $AccountData, $Event['data']['plan'], $time),
                        'SetFleetSensors' => FleetSensorService::setMode($fleet, $AccountData, $Event['data']['enabled'], microtime(true)),
                        default => throw new \InvalidArgumentException('Неизвестное управление флотом.'),
                    };
                    foreach ([$previousTargetAccount, $this->getFleetTargetAccountId($fleet)] as $affected) {
                        if ($affected !== null) $affectedAccountIds[$affected] = true;
                    }
                } catch (\Throwable $error) {
                    $this->logger->warning('Команда управления маршрутом отклонена',
                        ['fleetId' => $Event['data']['fleetId'] ?? null, 'message' => $error->getMessage()]);
                }
                $sendMsg = true;
                break;

            case PlayerQueue::ActionFleetGroupInvite:
                try {
                    $group = FleetGroupAttackService::invite((int)$AccountData['User']['id'],
                        $Event['data']['groupId'] ?? null, $Event['data']['username'] ?? null, microtime(true));
                    foreach (json_decode($group['users'], true) as $invitedId) {
                        $invited = Users::findExistingById($invitedId);
                        if ($invited !== null && (int)$invited['account_id'] !== $accountId) {
                            $affectedAccountIds[(int)$invited['account_id']] = true;
                        }
                    }
                } catch (\Throwable $exception) {
                    $this->logger->warning('Приглашение в групповую атаку отклонено',
                        ['groupId' => $Event['data']['groupId'] ?? null, 'message' => $exception->getMessage()]);
                }
                $sendMsg = true;
                break;

            case PlayerQueue::ActionFleetSendMission:
                $fleetId = (int)($Event['data']['fleetId'] ?? 0);
                $fleet = $AccountData->getFleetsData()->get($fleetId);
                try {
                    if ($fleet === null) {
                        throw new \InvalidArgumentException('Флот недоступен.');
                    }

                    FleetCommandsService::startMission(
                        $fleet,
                        $AccountData,
                        (int)($Event['data']['missionType'] ?? 0),
                        is_array($Event['data']['coordinates'] ?? null)
                            ? $Event['data']['coordinates']
                            : [],
                        $Event['data']['speedFactor'] ?? null,
                        $Event['data']['fuel'] ?? null,
                        is_array($Event['data']['resources'] ?? null)
                            ? $Event['data']['resources']
                            : [],
                        ((int)($Event['data']['missionType'] ?? 0) === \SPGame\Game\Enums\FleetMissionType::GROUP_ATTACK->value
                            || ((int)($Event['data']['missionType'] ?? 0) === \SPGame\Game\Enums\FleetMissionType::ATTACK->value
                                && (int)($Event['data']['coordinates']['type'] ?? 0) === CoordinateType::FLEET->value))
                            ? max((float)$Event['added_at'], microtime(true)) : (float)$Event['added_at'],
                        $Event['data']['duration'] ?? null,
                        $Event['data']['group'] ?? null,
                        $Event['data']['refuel'] ?? false,
                        $Event['data']['contactLoss'] ?? null,
                        $Event['data']['mining'] ?? null
                    );
                    if ($fleet->getMission() === \SPGame\Game\Enums\FleetMissionType::GROUP_ATTACK->value) {
                        $active = FleetCommands::getActiveCommand($fleet->getId());
                        $params = json_decode($active['params'] ?? '{}', true);
                        $group = \SPGame\Game\Repositories\FleetAttackGroups::findExistingById((int)($params['groupId'] ?? 0));
                        foreach (json_decode($group['users'] ?? '[]', true) as $invitedId) {
                            $invited = Users::findExistingById($invitedId);
                            if ($invited !== null) $affectedAccountIds[(int)$invited['account_id']] = true;
                        }
                    }
                    $targetAccountId = $this->getFleetTargetAccountId($fleet);
                    if ($targetAccountId !== null && $targetAccountId !== $accountId) {
                        $affectedAccountIds[$targetAccountId] = true;
                    }
                } catch (\Throwable $exception) {
                    $this->logger->warning('Queued SendMission отклонён без изменения gameplay state', [
                        'accountId' => $accountId,
                        'fleetId' => $fleetId,
                        'message' => $exception->getMessage(),
                    ]);
                }
                $sendMsg = true;
                break;

                /*
            case PlayerQueue::ActionFleetSendMission:
                // Отправка флота с миссией
                $fleetId = $Event['data']['fleet_id'] ?? 0;
                $missionType = $Event['data']['mission_type'] ?? 0;
                $target = $Event['data']['target'] ?? [];
                $startTime = $Event['data']['start_time'] ?? 0;

                if ($fleetId > 0) {
                    $fleetsPage = new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
                    $fleetsPage->sendMission($fleetId, $missionType, $target, $startTime, $AccountData, $Event['added_at']);
                    $sendMsg  = true;
                }
                break;

            case PlayerQueue::ActionFleetSetCommands:
                // Установка команд флота
                $fleetId = $Event['data']['fleet_id'] ?? 0;
                $startTime = $Event['data']['start_time'] ?? 0;
                $commands = $Event['data']['commands'] ?? [];

                if ($fleetId > 0) {
                    $fleetsPage = new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
                    $fleetsPage->setFleetCommands($fleetId, $startTime, $commands, $AccountData, $Event['added_at']);
                    $sendMsg  = true;
                }
                break;

            case PlayerQueue::ActionFleetDeleteCommand:
                // Удаление команды флота
                $fleetId = $Event['data']['fleet_id'] ?? 0;
                $commandIndex = $Event['data']['command_index'] ?? 0;

                if ($fleetId > 0) {
                    $fleetsPage = new \SPGame\Game\Pages\FleetsPage(new \SPGame\Core\Message());
                    $fleetsPage->deleteCommand($fleetId, $commandIndex, $AccountData, $Event['added_at']);
                    $sendMsg  = true;
                }
                break;
*/
            /*case PlayerQueue::ActionFleetGetAvailableMissions:
                $fleetId = (int)($Event['data']['fleet_id'] ?? 0);
                $target = $Event['data']['target'] ?? [];
                FleetsServices::GetAvailableMissions(
                    $fleetId,
                    is_array($target) ? $target : [],
                    $AccountData, $Event['added_at']
                );

                $sendMsg = true;
                break;
*/


            case PlayerQueue::ActionMessagesRead:
                Notification::setReadMessages($Event['user_id'], $Event['data']['ReadId']);
                $sendMsg  = true;
                break;
            case PlayerQueue::ActionMessagesDelete:
                Notification::deleteMessages($Event['user_id'], $Event['data']['Ids']);
                $sendMsg  = true;
                break;
            case PlayerQueue::SendActualeData:
            case PlayerQueue::ActionMessagesNew:
                $sendMsg  = true;
                break;
                // можно добавить другие действия
        }

        $AccountData->save();

        Logger::getInstance()->info("ProcessPlayerEvents " . $Event['action']);

        PlayerQueue::delete($Event);

        // Используем обычный snapshot открытой страницы сразу после
        // успешного старта миссии, не дожидаясь события прибытия.
        if ($affectedAccountIds !== []) {
            $onlineAccounts = Accounts::getOnline();
            foreach (array_keys($affectedAccountIds) as $affectedAccountId) {
                if (isset($onlineAccounts[$affectedAccountId])) {
                    $this->sendActualData((int)$affectedAccountId);
                }
            }
        }

        return $sendMsg;
    }

    protected function sendActualData(int $accountId): void
    {
        $Account = Accounts::findById($accountId);
        if (!$Account) {
            $this->logger->warning("sendActualData: account not found", ['accountId' => $accountId]);
            return;
        }

        $frame = (int)$Account['frame'];
        if ($frame < 1) {
            $this->logger->warning("sendActualData: invalid frame for account", ['accountId' => $accountId, 'frame' => $frame]);
            return;
        }

        $ws = WSocket::getInstance();
        if (!$ws) {
            $this->logger->error("sendActualData: WebSocket instance not available", ['accountId' => $accountId]);
            return;
        }

        $response = new Message();
        $response->setMode($Account['mode']);
        $response->setAction("ActualData");

        //$this->logger->info("sendActualData: sending to account", ['accountId' => $accountId, 'mode' => $Account['mode'], 'frame' => $frame]);

        $pageBuilder = new \SPGame\Game\PageBuilder($response, $frame);
        $response = $pageBuilder->build($response);

        $ws->Send($frame, $response);
    }
}
