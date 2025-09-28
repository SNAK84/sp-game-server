<?php

declare(strict_types=1);

define('ROOT_DIR', str_replace('\\', '/', dirname(__FILE__)) . "/");
set_include_path(ROOT_DIR);


require_once "Core/Time.php";
require_once "Core/Environment.php";
require_once "Core/Validator.php";
require_once "Core/Logger.php";
require_once "Core/Helpers.php";
require_once "Core/Input.php";


use Core\Logger;
use Core\Environment;

try {
    Environment::init(dirname(__FILE__) . '/config.env');
    $logger = Logger::getInstance();

    // Set error reporting based on environment
    if (Environment::getBool('DEBUG_MODE', false)) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', '0');
    }

    $logger->info('Starting SP-Game WebSocket Server');
} catch (Exception $e) {
    error_log('Failed to initialize environment: ' . $e->getMessage());
    exit(1);
}


require_once "Core/WSocket.php";