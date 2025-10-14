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


        // Загружаем технологии один раз (нужны для расчёта ресурсов)
        //$AccountData['Techs'] = Techs::findById($userId);

        // Загружаем все планеты игрока и готовим кеш PlanetsData
        /*$Planets = Planets::getAllPlanets($userId);
        $PlanetsData = [];
        foreach ($Planets as $pid => $Planet) {
            // Для корректного вызова Resources::get() нужен полный $tmpAccountData
            $tmpAccountData = $AccountData;
            $tmpAccountData['Planet'] = $Planet;
            $tmpAccountData['Builds'] = Builds::findById($pid);

            // Now resources are built using Planet + Builds + Techs + User + Account
            $PlanetsData[$pid] = [
                'Planet'    => $Planet,
                'Builds'    => $tmpAccountData['Builds'],
                'Resources' => Resources::get($tmpAccountData)
            ];
        }*/

        // Обрабатываем все очереди аккаунта (в порядке end_time) до targetTime
        $sendMsg = $this->processAccountQueues($targetTime, $AccountData) ? true : $sendMsg;


        // После обработки очередей — доводим ресурсы всех планет до targetTime
        $Planets = Planets::getAllPlanets($userId);
        foreach ($Planets as $pid => $Planet) {
            $AccountData['WorkPlanet'] = $Planet['id'];
            Resources::processResources($targetTime, $AccountData);
        }
        /*foreach ($PlanetsData as $pid => $pd) {
            $tmp = $AccountData;
            $tmp['Planet'] = $pd['Planet'];
            $tmp['Builds'] = $pd['Builds'];
            $tmp['Resources'] = $pd['Resources'];
        */
        // processResources ожидает array &$AccountData (он обновит $tmp['Resources'] и $tmp['Planet']['update_time'])

        /*
            // Сохраняем актуальные значения в БД
            Resources::updateByPlanetId($pid, $tmp['Resources']);
            Planets::update($tmp['Planet']);
            Builds::update($tmp['Builds']);

            // Обновляем кеш на случай, если ещё что-то будет использовать PlanetsData после этого
            $PlanetsData[$pid]['Planet'] = $tmp['Planet'];
            $PlanetsData[$pid]['Resources'] = $tmp['Resources'];
        }

        Techs::update($AccountData['Techs']);
        Users::update($AccountData['User']);*/

        return $sendMsg;
    }

    protected function processAccountQueues(float $StatTimeTick, AccountData &$AccountData): bool
    {
        $sendMsg = false;
        $maxIterations = 500; // safety

        while ($maxIterations-- > 0) {
            // НАДО: репозиторий должен вернуть самую раннюю активную очередь для данного user
            $Queue = Queues::getActiveMinEndTimeByUser($AccountData['User']['id']);
            if (!$Queue) break;
            if ($Queue['end_time'] > $StatTimeTick) break;

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
            /*
            // Если для планеты ещё нет кеша — подготовим его (не забыв Techs)
            if (!isset($PlanetsData[$planetId])) {
                $Planet = Planets::findById($planetId);
                $Builds = Builds::findById($planetId);

                $tmpAccountData = $AccountData;
                $tmpAccountData['Planet'] = $Planet;
                $tmpAccountData['Builds'] = $Builds;
                // Techs уже должно быть в $AccountData (см. вызов выше)
                $ResourcesForPlanet = Resources::get($tmpAccountData);

                $PlanetsData[$planetId] = [
                    'Planet'    => $Planet,
                    'Builds'    => $Builds,
                    'Resources' => $ResourcesForPlanet
                ];
            }

            // Собираем локальную копию AccountData для обработки этой очереди (ссылки на кеш)
            $QueueAccountData = $AccountData;
            $QueueAccountData['Planet'] = $PlanetsData[$planetId]['Planet'];
            $QueueAccountData['Builds'] = $PlanetsData[$planetId]['Builds'];
            $QueueAccountData['Resources'] = $PlanetsData[$planetId]['Resources'];
            */

            $AccountData['WorkPlanet'] = $planetId;
            // 1) Пересчитать ресурсы планеты до конца этой очереди
            Resources::processResources($Queue['end_time'], $AccountData);

            // 2) Завершить очередь (важно: CompleteQueue должна корректно работать с переданными данными)
            // Хорошая практика: внутри CompleteQueue выполнять DB-транзакцию,
            // чтобы изменения очередей и пересчеты были атомарными.
            QueuesServices::CompleteQueue($Queue['id'], $AccountData, $Queue['end_time']);

            $AccountData->save();

            /*
            // 3) Применяем изменения обратно в кеш
            $AccountData['Techs'] = $QueueAccountData['Techs'];
            $AccountData['User'] = $QueueAccountData['User'];

            $PlanetsData[$planetId]['Planet'] = $QueueAccountData['Planet'];
            $PlanetsData[$planetId]['Builds'] = $QueueAccountData['Builds'];
            $PlanetsData[$planetId]['Resources'] = $QueueAccountData['Resources'];

            // 4) Сохраняем изменения в БД (чтобы при следующем запросе всё было консистентно)
            Resources::updateByPlanetId($planetId, $QueueAccountData['Resources']);
            Planets::update($QueueAccountData['Planet']);
            Builds::update($QueueAccountData['Builds']);
            Techs::update($AccountData['Techs']);
            Users::update($AccountData['User']);
            */
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
        $Queues = PlayerQueue::getByAccaunt($accountId) ?? [];

        foreach ($Queues as $Event) {

            $AccountData = new AccountData($Event['account_id']);
            $AccountData['WorkPlanet'] = $Event['planet_id'];

            /*
            $AccountData = [
                'Account'   => Accounts::findById($Event['account_id']),
                'User'      => Users::findById($Event['user_id']),
                'Planet'    => Planets::findById($Event['planet_id']),
                'Builds'    => Builds::findById($Event['planet_id']),
                'Techs'     => Techs::findById($Event['user_id'])
            ];
            $AccountData['Resources']   = Resources::get($AccountData);
            

            $PlanetsData = [];

            $PlanetsData[$Event['planet_id']]['Planet'] = &$AccountData['Planet'];
            $PlanetsData[$Event['planet_id']]['Builds'] = &$AccountData['Builds'];
            $PlanetsData[$Event['planet_id']]['Resources'] = &$AccountData['Resources'];
            */
            switch ($Event['action']) {
                case PlayerQueue::ActionQueueUpgarde:
                    QueuesServices::AddToQueue($Event['data']['Element'], $AccountData, $Event['added_at'], true);
                    $sendMsg  = true;
                    break;

                case PlayerQueue::ActionQueueDismantle:
                    QueuesServices::AddToQueue($Event['data']['Element'], $AccountData, $Event['added_at'], false);
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
                case PlayerQueue::ActionSelectPlanet:
                    $AccountData['User']['current_planet'] = $Event['planet_id'];
                    $sendMsg  = true;
                    break;
                case PlayerQueue::ActionMessagesRead:
                    Notification::setReadMessages($Event['user_id'], $Event['data']['ReadId']);
                    $sendMsg  = true;
                    break;
                case PlayerQueue::ActionMessagesNew:
                    $sendMsg  = true;
                    break;
                    // можно добавить другие действия
            }

            $AccountData->save();
            /*
            foreach ($PlanetsData as $pid => $pd) {
                Resources::updateByPlanetId($pid, $pd['Resources']);
                Planets::update($pd['Planet']);
                Builds::update($pd['Builds']);
            }*/

            /*Resources::updateByPlanetId($AccountData['Planet']['id'], $AccountData['Resources']);
            Planets::update($AccountData['Planet']);
            Builds::update($AccountData['Builds']);*/
            //Techs::update($AccountData['Techs']);
            //Users::update($AccountData['User']);

            Logger::getInstance()->info("ProcessPlayerEvents " . $Event['action']);

            PlayerQueue::delete($Event);
        }

        return $sendMsg;
        /*if ($sendMsg) {
            self::sendActualData($accountId);
        }*/
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
