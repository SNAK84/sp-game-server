<?php

namespace SPGame\Core;

class Defaults
{
    public const NONE = '__NONE__'; // новый тип

    public const TIME = '__TIME__';
    public const MICROTIME = '__MICROTIME__';
    public const EMPTY_STRING = '__EMPTY_STRING__';
    public const ZERO = '__ZERO__';
    public const RAND = '__RAND__';


    /**
     * Получить реальное значение по дефолту
     *
     * @param mixed $value
     * @return mixed
     */
    public static function resolve(mixed $value): mixed
    {
        // NONE — ничего не делать
        if ($value === self::NONE) {
            return null; // можно также выбросить исключение, если нужно явно
        }

        // callable
        if (is_callable($value)) {
            return $value();
        }

        // массив с параметрами [тип, ...args]
        if (is_array($value)) {
            $type = $value[0] ?? null;
            switch ($type) {
                case self::RAND:
                    $min = $value[1] ?? 0;
                    $max = $value[2] ?? 100;
                    return random_int($min, $max);
            }
        }

        // простые константы
        switch ($value) {
            case self::TIME:
                return time();
            case self::MICROTIME:
                return microtime(true);
            case self::EMPTY_STRING:
                return '';
            case self::ZERO:
                return 0;
            case self::RAND:
                return random_int(0, 100);
        }

        // обычное значение
        return $value;
    }
}
