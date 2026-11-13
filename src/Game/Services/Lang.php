<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;

class Lang
{
    protected static array $cache = [];

    public static function load(string $lang): array
    {
        if (!isset(self::$cache[$lang])) {
            $file = __DIR__ . "/../../../resources/lang/{$lang}.json";

            if (!file_exists($file)) {
                Logger::getInstance()->warning("Lang file not found: {$lang}, fallback to en");
                $file = __DIR__ . "/../../../resources/lang/en.json";
            }

            self::$cache[$lang] = json_decode(file_get_contents($file), true) ?? [];
        }

        return self::$cache[$lang];
    }

    public static function get(string $lang, string $key): string
    {
        $data = self::load($lang);
        return $data[$key] ?? $key;
    }
}
