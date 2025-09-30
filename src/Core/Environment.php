<?php


namespace SPGame\Core;

use RuntimeException;

class Environment
{
    private static ?array $env = null;
    private static string $envFile = '';

    public static function init(string $envFile = ''): void
    {
        if (empty($envFile)) {
            $envFile = dirname(__DIR__, 2) . '/.env';
        }

        self::$envFile = $envFile;
        self::loadEnv();
    }

    private static function loadEnv(): void
    {
        if (!file_exists(self::$envFile)) {
            throw new RuntimeException("Environment file not found: " . self::$envFile);
        }

        $lines = file(self::$envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::$env = [];

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // Skip comments
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Если есть inline-комментарий и значение не в кавычках
                if ((substr($value, 0, 1) !== '"' && substr($value, 0, 1) !== "'")) {
                    $pos = strpos($value, '#');
                    if ($pos !== false) {
                        $value = trim(substr($value, 0, $pos));
                    }
                }

                // Убираем кавычки, если есть
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")
                ) {
                    $value = substr($value, 1, -1);
                }

                self::$env[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$env === null) {
            self::init();
        }

        return self::$env[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== null ? (int)$value : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Required environment variable not set: {$key}");
        }

        return $value;
    }

    public static function all(): array
    {
        if (self::$env === null) {
            self::init();
        }

        return self::$env ?? [];
    }
}
