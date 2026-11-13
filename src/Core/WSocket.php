<?php

namespace SPGame\Core;

use SPGame\Game\Services\EventLoop;
use SPGame\Game\Services\FleetBattleService;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use Swoole\Timer;

class WSocket
{
    private ?Server $ws = null;
    private Logger $logger;
    private ?\Throwable $shutdownError = null;

    /** Синглтон-инстанс (удобно дергать из других частей) */
    private static ?WSocket $instance = null;

    public function __construct()
    {
        $this->logger = Logger::getInstance();

        // регистрируем синглтон до старта
        self::$instance = $this;
        RuntimeMetrics::init();
        GameProtocolV2::initialize();

        $this->startServer();
    }

    /** Получить текущий инстанс (может вернуть null если ещё не создан) */
    public static function getInstance(): ?WSocket
    {
        return self::$instance;
    }

    private function startServer(): void
    {
        try {
            $host = Environment::get('WS_HOST', '0.0.0.0');
            $port = Environment::getInt('WS_PORT', 9501);

            // Явно сохраняем проверенный single-authority BASE, не default версии расширения.
            $this->ws = new Server($host, $port, SWOOLE_BASE);
            $combatWorkers = Environment::getInt('COMBAT_WORKERS', 2);
            FleetBattleService::initializeWorkers(fn(array $task): bool => $this->ws->task($task) !== false, $combatWorkers);
            \SPGame\Game\Services\FleetExpeditionService::initializeWorkers(fn(array $task): bool => $this->ws->task($task) !== false);
            $this->ws->set(['worker_num' => 1, 'max_connection' => 4096, 'enable_coroutine' => false,
                'task_worker_num' => $combatWorkers, 'task_enable_coroutine' => false]);
            $this->ws->on('Task', static function (Server $server, int $taskId, int $workerId, mixed $task): void {
                try {
                    if (($task['kind'] ?? '') === 'expedition_round') {
                        \SPGame\Game\Services\FleetExpeditionService::executeTask($task);
                    } else FleetBattleService::executeTask($task);
                } catch (\Throwable $error) {
                    Logger::getInstance()->error('Ошибка боевого TaskWorker', ['taskId' => $taskId, 'message' => $error->getMessage()]);
                }
            });
            // Результаты забирает игровой цикл из общего mailbox, не callback другого процесса.
            $this->ws->on('Finish', static function (): void {});
            $this->logger->info('WebSocket server created', ['host' => $host, 'port' => $port]);

            $this->ws->on('Start', [$this, 'onStart']);
            $this->ws->on('Shutdown', [$this, 'onShutdown']);
            $this->ws->on('open', [$this, 'onOpen']);
            $this->ws->on('message', [$this, 'onMessage']);
            $this->ws->on('close', [$this, 'onClose']);
            $this->ws->on('WorkerStart',function(Server $server,int $workerId): void {
                Database::forbidRuntimeSql();
                if (!$server->taskworker && $workerId === 0) Timer::tick(1000, function (): void {
                    foreach (Connect::expiredUnauthenticated(time()) as $fd) {
                        if ($this->ws?->exist($fd)) $this->ws->disconnect($fd, 1008, 'auth_timeout');
                    }
                });
                if (!$server->taskworker && $workerId===0 && \SPGame\Game\Services\CabinetWorldService::enabled()) {
                    Timer::tick(1000,fn()=>\SPGame\Game\Services\CabinetWorldService::poll($this));
                }
            });

            global $saver;
            if (!$saver instanceof \SPGame\Game\Services\RepositorySaver) throw new \LogicException('RepositorySaver недоступен при запуске.');
            $saver->attach($this->ws);
            Database::beforeFork();
            $this->ws->start();
            if ($this->shutdownError !== null) {
                throw new \RuntimeException('Финальное сохранение сервера не завершено.', 0, $this->shutdownError);
            }
        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to start WebSocket server');
            throw $e;
        }
    }

    public function onStart(Server $server)
    {
        Database::forbidRuntimeSql();

        $this->logger->info('Swoole WebSocket Server started', [
            'host' => $server->host,
            'port' => $server->port
        ]);

        Time::Start();

        $loop = new EventLoop();
        //$loop->start(1000); // Tick каждую 1 секунду

        // Раз в секунду
        $loop->register([$loop, 'process'], 1000, 'world.lag');
        // Отправка DTO имеет отдельный бюджет и не удлиняет критическую секцию tick мира.
        $loop->register([GameProtocolV2::class, 'flushPending'], GameProtocolV2::PUSH_INTERVAL_MS);

        $loop->register(function () {
            $this->logger->info('Server statistics', [
                'uptime' => Time::WorkTime(true, true),
                'memory_usage' => Helpers::formatNumberShort(memory_get_usage()),
                'Accaunts' => \SPGame\Game\Repositories\Accounts::count(),
                'connections' => \SPGame\Core\Connect::getCount(),
                'authorized' => \SPGame\Core\Connect::getAuthorizedCount(),
                'PlayerQueue' => \SPGame\Game\Repositories\PlayerQueue::count()
                , 'timings' => RuntimeMetrics::snapshot(), 'storage' => RuntimeMetrics::gauges()
            ]);
        }, 5000);

        $loop->start();

        /*
        Timer::tick(5000, function () {

            $this->logger->info('Server statistics', [
                'uptime' => Time::WorkTime(true, true),
                'memory_usage' => Helpers::formatNumberShort(memory_get_usage()),
                'Accaunts' => \SPGame\Game\Repositories\Accounts::count(),
                'connections' => \SPGame\Core\Connect::getCount(),
                'authorized' => \SPGame\Core\Connect::getAuthorizedCount()
                //'online_users' => count($OnLines)
            ]);
        });*/
    }

    /** Master callback после остановки workers/таймеров. Только синхронное сохранение. */
    public function onShutdown(Server $server): void
    {
        global $saver;
        try {
            if (!$saver instanceof \SPGame\Game\Services\RepositorySaver) {
                throw new \LogicException('RepositorySaver недоступен при остановке.');
            }
            $saver->saveAll(strict: true);
            $this->logger->info('Финальное сохранение сервера завершено');
        } catch (\Throwable $error) {
            $this->shutdownError = $error;
            $this->logger->critical('Финальное сохранение сервера не выполнено', ['message' => $error->getMessage()]);
        }
    }

    public function onOpen(Server $server, Request $request): void
    {
        $fd = $request->fd;

        // сохраняем заголовки и IP
        $info = $server->getClientInfo($fd);

        $ip = $request->header['x-forwarded-for'] ?? $request->header['x-real-ip'] ?? null;

        if ($ip) {
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
        } else {
            $ip = $info['remote_ip'] ?? '0.0.0.0';
        }

        $registered = Connect::set(
            $fd,
            $ip,
            $info['remote_port'] ?? 0,
            null
        );
        if (!$registered) {
            $this->logger->warning('Отклонено подключение: заполнен реестр соединений', ['fd' => $fd]);
            $server->disconnect($fd, 1013, 'server_busy');
            return;
        }

        /* $this->connections[$fd] = [
            'headers' => $request->header ?? [],
            'ip'      => $info['remote_ip'] ?? '0.0.0.0',
            'port'    => $info['remote_port'] ?? 0,
            'account' => null,
        ];*/

        $this->logger->info("Client {$fd} connected. IP: " . Connect::getIp($fd) . ":" . Connect::getPort($fd));
    }

    public function onMessage(Server $server, Frame $frame)
    {
        RuntimeMetrics::gauge('ws.authorityPid', (float)getmypid());
        if (!Connect::allowRequest($frame->fd, time())) {
            $server->disconnect($frame->fd, 1008, 'rate_limit');
            return;
        }
        $started = hrtime(true);
        try {
            $this->logger->debug('Received WebSocket message', ['fd' => $frame->fd]);

            {
                if (strlen($frame->data) > 16384) {
                    $response = GameProtocolV2::error($frame->fd, null, 'protocol.packet_too_large', 'Слишком большой пакет.', true);
                } else {
                    $response = GameProtocolV2::handle($frame->fd, $frame->data, $this);
                }
                $this->ws->push($frame->fd, json_encode($response, JSON_THROW_ON_ERROR));
                if (($response['error']['fatal'] ?? false) || ($response['type'] ?? '') === 'session') $this->closeSession($frame->fd);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Ошибка обработки WebSocket-сообщения', [
                'fd' => $frame->fd, 'exception' => get_class($e),
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
        } finally { RuntimeMetrics::observe('ws.request', (hrtime(true) - $started) / 1000000); }
    }

    public function closeSession(int $fd): void
    {
        if ($this->ws && $this->ws->exist($fd)) $this->ws->disconnect($fd,4001,'session_ended');
    }

    public function onClose(Server $server, int $fd)
    {
        \SPGame\Game\Repositories\ProtocolSubscriptions::remove(Connect::getConnectionId($fd));
        if (\SPGame\Game\Services\CabinetWorldService::enabled()) {
            $accountId = Connect::getRawAccount($fd);
            Connect::unset($fd);
            if ($accountId) \SPGame\Game\Services\CabinetWorldService::clearClosedFrame($accountId, $fd);
        }
        Connect::unset($fd);
        $this->logger->info("Client {$fd} disconnected");

        /*global $OnLines;
        try {
            $LID = $OnLines->GetIdByFd($fd);
            if ($LID) {
                unset($OnLines[$LID]);
                $this->logger->info('WebSocket connection closed', [
                    'fd' => $fd,
                    'login_id' => $LID
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->logException($e, 'Error handling WebSocket close');
        }*/
    }

    /**
     * Безопасная отправка сообщения клиенту.
     * $payload - массив/объект/Message/string. Метод сериализует в JSON.
     * Возвращает true если push вернул true, false - иначе.
     */
    public function Send(int $frameId, $payload)
    {
        if (Connect::getProtocolVersion($frameId) === 2) {
            $source = $payload instanceof Message ? $payload->source() : $payload;
            if (is_array($source) && ($source['mode'] ?? '') === 'connect'
                && in_array($source['action'] ?? '', ['sessionReplaced', 'gameLoggedOut'], true)) {
                $payload = GameProtocolV2::sessionEnded($frameId, null, $source['action'] === 'gameLoggedOut');
            } elseif (!is_array($source) || ($source['v'] ?? null) !== 2) {
                // v2 без подписок не получает устаревшие Page/ActualData из v1.
                return false;
            }
        }
        if (\SPGame\Game\Services\CabinetWorldService::enabled() && Connect::getRawAccount($frameId)
            && !Connect::getAccount($frameId)) return false;
        if ($this->ws === null) {
            $this->logger->warning("WSocket::Send: server not initialized");
            return false;
        }

        // проверяем, существует ли соединение (swoole::server->exist)
        try {
            if (!$this->ws->exist($frameId)) {
                $this->logger->debug("WSocket::Send: fd {$frameId} not exist");
                // если у тебя есть класс Connect, можно сразу почистить запись:
                Connect::unset($frameId);
                return false;
            }
        } catch (\Throwable $e) {
            // на некоторых версиях Swoole метод exist может бросать - логируем и пробуем отправить
            $this->logger->debug("WSocket::Send: exist check failed for fd {$frameId}: " . $e->getMessage());
        }

        // подготовка payload
        if (is_object($payload) && method_exists($payload, 'source')) {
            $data = json_encode($payload->source());
        } elseif (is_string($payload)) {
            $data = $payload;
        } else {
            // массив/объект -> json
            $data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        try {
            $ok = $this->ws->push($frameId, $data);
            if ($ok === false) {
                $this->logger->warning("WSocket::Send: push returned false for fd {$frameId}");
            }
            return (bool)$ok;
        } catch (\Throwable $e) {
            $this->logger->error("WSocket::Send error for fd {$frameId}: " . $e->getMessage(), ['fd' => $frameId]);
            // при ошибке соединение вероятно уже закрыто
            Connect::unset($frameId);
            return false;
        }
    }

    /**
     * Broadcast / отправка списку FDs. Если $fds === null — постим всем соединениям,
     * но обычно лучше отдавать список авторизованных FDs.
     */
    public function broadcast($payload, ?array $fds = null): void
    {
        if ($fds === null) {
            // предполагаем, что Connect умеет отдавать список текущих FDs или авторизованных
            if (method_exists(\SPGame\Core\Connect::class, 'getAllFds')) {
                $fds = Connect::getAllFds();
            } elseif (method_exists(\SPGame\Core\Connect::class, 'getAuthorizedFds')) {
                $fds = Connect::getAuthorizedFds();
            } else {
                $this->logger->warning("WSocket::broadcast: no Connect::getAllFds / getAuthorizedFds method found");
                return;
            }
        }

        foreach ($fds as $fd) {
            $this->Send((int)$fd, $payload);
        }
    }
}
