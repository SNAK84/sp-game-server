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