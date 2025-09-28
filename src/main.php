<?php

declare(strict_types=1);

namespace SPGame;

require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\Logger;
use SPGame\Core\Environment;
use SPGame\Core\WSocket;

try {
    Environment::init(__DIR__ . '/../.env');
    $logger = Logger::getInstance();

    if (Environment::getBool('DEBUG_MODE', false)) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', '0');
    }

    $logger->info('Starting SP-Game WebSocket Server');

    $server = new WSocket(); // Создаём и запускаем сервер
    
} catch (\Exception $e) {
    error_log('Failed to start SP-Game WebSocket Server: ' . $e->getMessage());
    exit(1);
}
