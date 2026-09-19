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

use Swoole\Timer;

class EventLoop
{
    protected Logger $logger;

    private array $tasks = [];

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
            Timer::tick($task['interval'], $task['callback']);
        }
    }

    /**
     * Основная логика обработки
     */
    public function process(): void
    {
        $StatTimeTick = microtime(true);

        foreach (Accounts::getOnline() as $Account) {

            if (!$Account['id']) continue;

            $sendMsg = false;


            $AccountData = new AccountData($Account['id']);

            if (!$AccountData['User']) continue;

            // 🧩 Приводим состояние всех планет и технологий игрока к текущему времени
            $sendMsg = $this->processPlayerStateAtTime($StatTimeTick, $AccountData);

            $AccountData->save();

            // ⚙️ Обработка действий из PlayerQueue (запросы на постройки, отмены и т.д.)
            $sendMsg = ($this->processPlayerEvents($Account['id'])) ? true : $sendMsg;

            if ($sendMsg) {
                self::sendActualData($Account['id']);
            }
        }
        // Можно логировать, если нужно
        $duration = round(microtime(true) - $StatTimeTick, 3);
        $this->logger->debug("Event process time: {$duration}s");
    }



    /**
     * Приводит состояние всех планет игрока и его очередей к заданному моменту времени.
     */
    protected function processPlayerStateAtTime(float $targetTime, AccountData &$AccountData): bool
    {
        $sendMsg = false;
        $userId = $AccountData['User']['id'];

        // Обрабатываем все очереди аккаунта (в порядке end_time) до targetTime
        $sendMsg = $this->processAccountQueues($targetTime, $AccountData) ? true : $sendMsg;


        // После обработки очередей — доводим ресурсы всех планет до targetTime
        $Planets = Planets::getAllPlanets($userId);
        foreach ($Planets as $pid => $Planet) {
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
                ($Queue['type'] == QueuesServices::TECHS && $Queue['user_id'] == $AccountData['User']['id'])
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

        $sendMsg  = false;
        $Queues = PlayerQueue::getByAccount($accountId) ?? [];

        foreach ($Queues as $Event) {

            $AccountData = new AccountData($Event['account_id']);
            $AccountData['WorkPlanet'] = $Event['planet_id'];

            switch ($Event['action']) {
                case PlayerQueue::ActionSelectPlanet:
                    $AccountData['User']['current_planet'] = $Event['planet_id'];
                    $sendMsg  = true;
                    break;
                case PlayerQueue::ActionRenamePlanet:
                    $AccountData['Planet']['name'] = $Event['data']['Name'];
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
                    FleetsServices::AddToOrbit($Event['data']['items'], $AccountData, $Event['added_at']);
                    $sendMsg  = true;
                    break;

                case PlayerQueue::ActionFleetMoveShip:
                    FleetsServices::MoveShip($Event['data']['toFleetId'], $Event['data']['fromFleetId'], $Event['data']['shipId'], $Event['data']['count'], $AccountData, $Event['added_at']);
                    $sendMsg  = true;
                    break;

                case PlayerQueue::ActionFleetDisband:
                    FleetsServices::DisbandFleet($Event['data']['FleetId'], $AccountData, $Event['added_at']);
                    $sendMsg  = true;
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
                    $sendMsg  = false;
                    break;
                case PlayerQueue::ActionMessagesNew:
                    $sendMsg  = true;
                    break;
                    // можно добавить другие действия
            }

            $AccountData->save();

            Logger::getInstance()->info("ProcessPlayerEvents " . $Event['action']);

            PlayerQueue::delete($Event);
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
