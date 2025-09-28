<?php

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Timer;

use Core\Connect;
use Core\Logger;
use Core\Environment;

class WSocket
{
    private ?Server $ws = null;
    private Logger $logger;

    public function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->Start();
    }

    public function onStart(Server $server)
    {
        $this->logger->info('Swoole WebSocket Server started', [
            'host' => $server->host,
            'port' => $server->port
        ]);

        // Resource update timer (every second)
        Timer::tick(1000, function () {
            //Tick();
        });

        // Statistics timer (every 5 seconds)
        Timer::tick(5000, function () {
            global $OnLines;
            $this->logger->info('Server statistics', [
                'uptime' => Time::WorkTime(true, false),
                'memory_usage' => format_number_short(memory_get_usage()),
                //'online_users' => count($OnLines)
            ]);
        });
    }

    public function onMessage(Server $server, Frame $frame)
    {
        try {
            $this->logger->debug('Received WebSocket message', ['fd' => $frame->fd]);

            $frame->data = json_decode($frame->data, true);
            
            $response = Connect::handle($frame); // Передаем текущий Frame
            //$connected = Connect($server, $frame);

            $this->Send($frame);
        } catch (Exception $e) {
            $this->logger->logException($e, 'Error processing WebSocket message');
        }
    }

    public function onClose($server, $fd)
    {
        global $OnLines;
        try {
            $LID = $OnLines->GetIdByFd($fd);
            if ($LID) {
                unset($OnLines[$LID]);
                $this->logger->info('WebSocket connection closed', [
                    'fd' => $fd,
                    'login_id' => $LID
                ]);
            }
        } catch (Exception $e) {
            $this->logger->logException($e, 'Error handling WebSocket close');
        }
    }

    private function Start(): void
    {
        try {

            $host = Environment::get('WS_HOST', '0.0.0.0');
            $port = Environment::getInt('WS_PORT', 9501);

            $this->ws = new Server($host, $port);
            $this->logger->info('WebSocket server created', ['host' => $host, 'port' => $port]);

            $this->ws->on("Start", [$this, 'onStart']);

            $this->ws->on('open', function ($server, $req) {
                $this->logger->info('New WebSocket connection', ['fd' => $req->fd]);
            });

            // websocket message handler
            $this->ws->on('message', [$this, 'onMessage']);

            $this->ws->on('close', [$this, 'onClose']);

            $this->ws->start();
        } catch (Exception $e) {
            $this->logger->logException($e, 'Failed to start WebSocket server');
            throw $e;
        }
    }



    private function Send(Frame $frame, array $Data = []): void
    {
        global $OnLines;

        try {
            $LID = $OnLines->GetIdByFd($frame->fd);
            if ($LID > 0) {
                $Data['Token'] = $OnLines[$LID]['token'];
                $Data["Resource"] = $OnLines[$LID]['User']['Planet']['Resouce'];
            }

            $jsonData = json_encode($Data);
            if ($jsonData === false) {
                $this->logger->error('Failed to encode JSON data', ['fd' => $frame->fd]);
                return;
            }

            $this->ws->push($frame->fd, $jsonData);
            $this->logger->debug('Sent WebSocket message', ['fd' => $frame->fd]);
        } catch (Exception $e) {
            $this->logger->logException($e, 'Error sending WebSocket message');
        }
    }
}

try {
    $WS = new WSocket();
} catch (Exception $e) {
    $logger = Logger::getInstance();
    $logger->logException($e, 'Failed to start WebSocket server');
    exit(1);
}
