<?php

namespace SPGame\Core;

class Validator
{

    public const ERROR_PASSWORD_TOO_SHORT       = 1;
    public const ERROR_PASSWORD_NO_UPPERCASE    = 2;
    public const ERROR_PASSWORD_NO_LOWERCASE    = 3;
    public const ERROR_PASSWORD_NO_NUMBER       = 4;
    public const ERROR_PASSWORD_NO_SYMBOL       = 5;
    public const ERROR_PASSWORD_INVALID_CHARS   = 6;

    /**
     * Проверка корректности email
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Проверка логина: 4-20 символов, только буквы, цифры и _
     */
    public static function validateLogin(string $login): bool
    {
        $lengthOk = strlen($login) >= 4 && strlen($login) <= 20;
        $patternOk = preg_match('/^[a-zA-Z0-9_]+$/', $login) === 1;

        return $lengthOk && $patternOk;
    }

    /**
     * Проверка пароля: минимум 8 символов, хотя бы одна буква и одна цифра
     */
    public static function validatePassword(string $password): int
    {
        if (strlen($password) < 6) {
            return self::ERROR_PASSWORD_TOO_SHORT;
        }

        if (!preg_match('/[A-ZА-Я]/', $password)) {
            return self::ERROR_PASSWORD_NO_UPPERCASE;
        }

        if (!preg_match('/[a-zа-я]/', $password)) {
            return self::ERROR_PASSWORD_NO_LOWERCASE;
        }

        if (!preg_match('/\d/', $password)) {
            return self::ERROR_PASSWORD_NO_NUMBER;
        }

        if (!preg_match('/[!@#$%^&*-_]/', $password)) {
            return self::ERROR_PASSWORD_NO_SYMBOL;
        }

        if (!preg_match('/^[A-Za-zА-Яа-я0-9!@#$%^&*-_]+$/', $password)) {
            return self::ERROR_PASSWORD_INVALID_CHARS;
        }

        return 0; // Пароль валиден
    }


    /**
     * Проверка токена SHA512 (128 символов hex)
     */
    public static function validateToken(string $token): bool
    {
        return strlen($token) === 128 && ctype_xdigit($token);
    }

    /**
     * Проверка целого числа с опциональным диапазоном
     */
    public static function validateInteger(mixed $value, int $min = null, int $max = null): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        $intValue = (int)$value;

        if ($min !== null && $intValue < $min) {
            return false;
        }

        if ($max !== null && $intValue > $max) {
            return false;
        }

        return true;
    }

    /**
     * Проверка числа с плавающей точкой с опциональным диапазоном
     */
    public static function validateFloat(mixed $value, float $min = null, float $max = null): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        $floatValue = (float)$value;

        if ($min !== null && $floatValue < $min) {
            return false;
        }

        if ($max !== null && $floatValue > $max) {
            return false;
        }

        return true;
    }

    /**
     * Очистка строки: обрезка, экранирование HTML
     */
    public static function sanitizeString(string $input, int $maxLength = 255): string
    {
        $sanitized = trim($input);
        $sanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');

        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }

    /**
     * Проверка режима игры
     */
    public static function validateGameMode(string $mode): bool
    {
        $allowedModes = ['login', 'token', 'register'];
        return in_array($mode, $allowedModes, true);
    }

    /**
     * Проверка координат планеты
     */
    public static function validatePlanetCoordinates(int $galaxy, int $system, int $position): bool
    {
        return $galaxy >= 1 && $galaxy <= 9 &&
            $system >= 1 && $system <= 499 &&
            $position >= 1 && $position <= 15;
    }

    /**
     * Проверка идентификатора ресурса
     */
    public static function validateResourceId(int $resourceId): bool
    {
        $validResources = [901, 902, 903, 911, 921, 922];
        return in_array($resourceId, $validResources, true);
    }

    /**
     * Проверка идентификатора здания
     */
    public static function validateBuildingId(int $buildingId): bool
    {
        return $buildingId > 0 && $buildingId < 1000;
    }

    /**
     * Проверка наличия обязательных полей в массиве
     */
    public static function validateArray(array $data, array $requiredFields): array
    {
        $errors = [];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }

        return $errors;
    }

    /**
     * Проверка валидности JSON
     */
    public static function validateJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
