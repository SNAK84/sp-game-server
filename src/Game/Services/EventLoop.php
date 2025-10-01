<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Config;
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
    protected function process(): void
    {
        // 1. Обновление ресурсов на планетах
        $this->processResources();

        // 2. Обновление строек/технологий
        $this->processQueues();

        // 3. События или задания игрока
        $this->processPlayerEvents();

        // Можно логировать, если нужно
        // $this->logger->info("Event tick processed");
    }

    protected function processResources(): void
    {
        //foreach (Planets::$planets as $planet) {
        // Пример: увеличение ресурсов на планете
        // ResourceWorker::tick($planet);
        //}
    }

    protected function processQueues(): void
    {
        // Пример: обработка очередей строек или технологий
        // QueueWorker::tick();
    }

    protected function processPlayerEvents(): void
    {
        // Пример: обработка действий игроков
        // EventWorker::tick();
    }
}
