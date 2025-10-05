<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Core\Message;
use SPGame\Core\WSocket;

use SPGame\Game\Repositories\Accounts;
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

        /*Timer::tick($interval, function () {
            $this->process();
        });*/
    }

    /**
     * Основная логика обработки
     */
    public function process(): void
    {
        $StatTimeTick = microtime(true);

        foreach (Accounts::getOnline() as $Account) {

            $sendMsg = false;

            $User = Users::findByAccount($Account['id']);
            if (!$User) continue;
            $Planets = Planets::getAllPlanets($User['id']);

            foreach ($Planets as $planetId => $Planet) {
                // 1. Обновление ресурсов на планетах
                $this->processResources($StatTimeTick, $User, $Planet);

                // 2. Обновление очереди строек/технологий
                $sendMsg = ($this->processQueues($User, $Planet)) ? true : $sendMsg;

                Planets::update($Planet);
                Users::update($User);
            }


            // 3. Обработка событий игроков из очереди
            $sendMsg = ($this->processPlayerEvents($Account['id'])) ? true : $sendMsg;

            if ($sendMsg) {
                self::sendActualData($Account['id']);
            }
            //Users::update($User);
        }
        // Можно логировать, если нужно
        $duration = round(microtime(true) - $StatTimeTick, 3);
        $this->logger->debug("Event process time: {$duration}s");
    }

    protected function processResources(float $StatTimeTick, &$User, &$Planet): void
    {
        if ($Planet['update_time'] < $Planet['create_time']) {
            $Planet['update_time'] = $Planet['create_time'];
        }

        $ProductionTime = ($StatTimeTick - $Planet['update_time']);

        if ($ProductionTime > 0) {
            $Planet['update_time'] = $StatTimeTick;
            $Resources = Resources::getByPlanetId($Planet['id']);

            //if ($Planets[$PID]['PlanetType'] == 3)
            //    return;

            foreach (Vars::$reslist['resstype'][1] as $ResID) {
                $Theoretical = $ProductionTime * ($Resources[$ResID]['perhour']) / 3600;
                if ($Theoretical < 0) {
                    $Resources[$ResID]['count'] = max($Resources[$ResID]['count'] + $Theoretical, 0);
                } elseif ($Resources[$ResID]['count'] <= $Resources[$ResID]['max']) {
                    $Resources[$ResID]['count'] = min($Resources[$ResID]['count'] + $Theoretical, $Resources[$ResID]['max']);
                }
                $Resources[$ResID]['count'] = max($Resources[$ResID]['count'], 0);
            }

            Resources::updateByPlanetId($Planet['id'], $Resources);
        }
    }

    protected function processQueues(&$User, &$Planet): bool
    {

        $now = microtime(true);
        $sendMsg = false;

        // 1️⃣ Обрабатываем постройки
        while ($Queue = Queues::getActiveMinEndTime($User['id'])) {
            if ($Queue['end_time'] > $now) {
                // Активная очередь ещё не завершена
                break;
            }

            // 2️⃣ Завершаем очередь
            QueuesServices::CompleteQueue(
                $Queue['id'],
                $User['id'],
                $Queue['planet_id'], // используем планету из записи
                $Queue['end_time']
            );

            if ($Queue['planet_id'] == $User['current_planet'] || $Queue['type'] == QueuesServices::TECHS) {
                $sendMsg = true;
            }
        }


        return $sendMsg;
        // Пример: обработка очередей строек или технологий
        // QueueWorker::tick();
    }

    /**
     * Обработка очереди событий игроков
     */
    protected function processPlayerEvents(int $accountId): bool
    {

        $sendMsg  = false;

        while ($Event = PlayerQueue::popByAccaunt($accountId)) {
            switch ($Event['action']) {
                case PlayerQueue::ActionQueueUpgarde:
                    QueuesServices::AddToQueue($Event['data']['Element'], $Event['user_id'], $Event['planet_id'], $Event['added_at'], true);
                    $sendMsg  = true;
                    break;

                case PlayerQueue::ActionQueueDismantle:
                    QueuesServices::AddToQueue($Event['data']['Element'], $Event['user_id'], $Event['planet_id'], $Event['added_at'], false);
                    $sendMsg  = true;
                    break;

                case PlayerQueue::ActionQueueCancel:
                    QueuesServices::CancelToQueue($Event['data']['QueueId'], $Event['user_id'], $Event['planet_id'], $Event['added_at']);
                    $sendMsg  = true;
                    break;

                case PlayerQueue::ActionQueueReCalcTech:
                    Logger::getInstance()->info("PlayerQueue::ActionQueueReCalcTech");
                    QueuesServices::ReCalcTimeQueue(QueuesServices::TECHS, $Event['user_id'], $Event['planet_id'], $Event['added_at']);
                    $sendMsg  = true;
                    break;

                    // можно добавить другие действия
            }
        }

        return $sendMsg;
        if ($sendMsg) {
            self::sendActualData($accountId);
        }
    }

    protected function sendActualData(int $accountId): void
    {
        $Account = Accounts::findById($accountId);
        if (!$Account) return;

        $response = new Message();
        $response->setMode($Account['mode']);


        $pageBuilder = new \SPGame\Game\PageBuilder($response, $Account['frame']);
        $response = $pageBuilder->build($response);

        $ws = WSocket::getInstance();
        if ($ws !== null) {
            $ws->Send($Account['frame'], $response); // $fd — числовой идентификатор соединения
        }
    }
}
