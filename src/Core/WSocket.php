<?php

namespace SPGame\Core;

use SPGame\Game\Services\EventLoop;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use Swoole\Timer;

class WSocket
{
    private ?Server $ws = null;
    private Logger $logger;

    /** Синглтон-инстанс (удобно дергать из других частей) */
    private static ?WSocket $instance = null;

    public function __construct()
    {
        $this->logger = Logger::getInstance();

        // регистрируем синглтон до старта
        self::$instance = $this;

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

            $this->ws = new Server($host, $port);
            $this->logger->info('WebSocket server created', ['host' => $host, 'port' => $port]);

            $this->ws->on('Start', [$this, 'onStart']);
            $this->ws->on('open', [$this, 'onOpen']);
            $this->ws->on('message', [$this, 'onMessage']);
            $this->ws->on('close', [$this, 'onClose']);

            $this->ws->start();
        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to start WebSocket server');
            throw $e;
        }
    }

    public function onStart(Server $server)
    {

        $this->logger->info('Swoole WebSocket Server started', [
            'host' => $server->host,
            'port' => $server->port
        ]);

        Time::Start();

        $loop = new EventLoop();
        //$loop->start(1000); // Tick каждую 1 секунду

        // Раз в секунду
        $loop->register([$loop, 'process'], 1000);

        $loop->register(function () {
            $this->logger->info('Server statistics', [
                'uptime' => Time::WorkTime(true, true),
                'memory_usage' => Helpers::formatNumberShort(memory_get_usage()),
                'Accaunts' => \SPGame\Game\Repositories\Accounts::count(),
                'connections' => \SPGame\Core\Connect::getCount(),
                'authorized' => \SPGame\Core\Connect::getAuthorizedCount(),
                'PlayerQueue' => \SPGame\Game\Repositories\PlayerQueue::count()
            ]);
        }, 5000);

        // Раз в минуту – сохранение всех репозиториев
        $loop->register(function () {
            try {
                global $saver; // берём из main.php
                if ($saver) {
                    $saver->saveAll();
                    Logger::getInstance()->info("Repositories flushed to MySQL");
                }
            } catch (\Throwable $e) {
                Logger::getInstance()->error("Repository flush failed: " . $e->getMessage());
            }
        }, 30000);

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

        Connect::set(
            $fd,
            $ip,
            $info['remote_port'] ?? 0,
            null
        );

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
        try {
            $this->logger->debug('Received WebSocket message', ['fd' => $frame->fd]);


            $data = json_decode($frame->data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Invalid JSON received', [
                    'fd' => $frame->fd,
                    'raw' => $frame->data,
                ]);
                return;
            }
            $frame->data = $data;
            $response = Connect::handle($frame, $this);

            $this->ws->push($frame->fd, json_encode($response));
        } catch (\Exception $e) {
            $this->logger->logException($e, 'Error processing WebSocket message');
        }
    }

    public function onClose(Server $server, int $fd)
    {


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
        } elseif (is_string($payload, JSON_UNESCAPED_UNICODE)) {
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
