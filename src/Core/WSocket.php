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

    //private array $connections = [];

    public function __construct()
    {
        $this->logger = Logger::getInstance();

        $this->startServer();
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
                'authorized' => \SPGame\Core\Connect::getAuthorizedCount()
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
        }, 60000);

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
}
