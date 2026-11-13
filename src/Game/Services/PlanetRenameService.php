<?php

namespace SPGame\Game\Services;

/** Общие правила имени для старого API и команд v2. */
final class PlanetRenameService
{
    public static function validate(mixed $input): string
    {
        if (!is_string($input)) throw new \InvalidArgumentException('Нужно имя планеты.');
        $name = trim($input);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 20 || strlen($name) > 32
            || !preg_match('/^[\p{L}\p{N} _-]+$/u', $name)
            || preg_match('/^[-_ ]|[-_ ]$/u', $name) || preg_match('/[-_ ]{2,}/u', $name)
            || preg_match_all('/[-_ ]/u', $name) > 3) {
            throw new \InvalidArgumentException('Некорректное имя планеты.');
        }
        return $name;
    }
}
