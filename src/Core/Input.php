<?php

namespace SPGame\Core;


class Input
{
    /**
     * Получает значение по ключу из массива данных и преобразует к нужному типу.
     *
     * @param array $data Данные (например, $_POST, $_GET или декодированный JSON)
     * @param string $key Имя поля
     * @param mixed $default Значение по умолчанию, если ключ отсутствует
     * @param string $type Тип значения: 'string', 'int', 'float', 'bool', 'array', 'json'
     * @param bool $multibyte Разрешить ли multibyte символы для строк
     * @return mixed Значение в нужном типе или $default
     */
    public static function get(array $data, string $key, mixed $default = null, string $type = null, bool $multibyte = true): mixed
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        // Определяем тип, если не указан
        if (!$type) {
            $type = $default !== null ? gettype($default) : 'string';
        }

        $value = $data[$key];

        return match ($type) {
            'int'   => is_numeric($value) ? (int)$value : $default,
            'float' => is_numeric($value) ? (float)$value : $default,
            'bool' => match (true) {
                is_bool($value) => $value,
                is_numeric($value) => (bool)$value,
                is_string($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
                default => $default
            },
            'array' => is_array($value) ? $value : $default,
            'json' => is_string($value) ? (json_decode($value, true) ?: $default) : $default,
            'string' => is_scalar($value) ? self::sanitizeString((string)$value, $multibyte) : $default,
            default => $value
        };
    }

    /**
     * Преобразует строку в безопасный формат для HTML или ASCII
     *
     * @param string $str Строка для очистки
     * @param bool $multibyte Разрешить ли multibyte символы
     * @return string Очищенная строка
     */
    public static function sanitizeString(string $str, bool $multibyte = true): string
    {
        // Нормализуем переносы строк и удаляем нулевые байты
        $str = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $str);
        $str = trim($str);
        $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');

        if (!$multibyte) {
            // Ограничение только ASCII
            $str = preg_replace('/[\x80-\xFF]/', '?', $str);
        }

        return $str;
    }

    /**
     * Удобная проверка на "истинное" значение (true/1/on/yes)
     *
     * @param array $data Данные
     * @param string $key Ключ
     * @param bool $default Значение по умолчанию
     * @return bool
     */
    public static function getBool(array $data, string $key, bool $default = false): bool
    {
        return self::get($data, $key, $default, 'bool');
    }
}
