<?php

// Лёгкий bootstrap для запуска юнит-тестов без composer autoload и без PHPUnit.
// Подключает PSR-4 автозагрузчик SPGame\ -> src/ .

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'SPGame\\')) {
        $rel = substr($class, strlen('SPGame\\'));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// Не даём fixture-ошибкам попадать в log работающей игры. Повторный require
// и дочерние CLI могут использовать уже выбранный артефакт своего запуска.
if (PHP_SAPI === 'cli' && !getenv('SP_GAME_TEST_LOG_FILE')) {
    $testLog = tempnam(sys_get_temp_dir(), 'sp-game-test-');
    if ($testLog === false || !putenv('SP_GAME_TEST_LOG_FILE=' . $testLog)) {
        throw new RuntimeException('Не удалось выделить отдельный log для тестового процесса.');
    }
}
