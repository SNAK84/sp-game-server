<?php

namespace Core;

class Validator
{
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validatePassword(string $password): bool
    {
        // Minimum 8 characters, at least one letter and one number
        return strlen($password) >= 8 && 
               preg_match('/[A-Za-z]/', $password) && 
               preg_match('/[0-9]/', $password);
    }

    public static function validateToken(string $token): bool
    {
        // SHA512 hash should be 128 characters long
        return strlen($token) === 128 && ctype_xdigit($token);
    }

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

    public static function sanitizeString(string $input, int $maxLength = 255): string
    {
        $sanitized = trim($input);
        $sanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
        
        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }
        
        return $sanitized;
    }

    public static function validateGameMode(string $mode): bool
    {
        $allowedModes = ['login', 'token', 'register'];
        return in_array($mode, $allowedModes, true);
    }

    public static function validatePlanetCoordinates(int $galaxy, int $system, int $position): bool
    {
        // Basic coordinate validation - adjust ranges based on game config
        return $galaxy >= 1 && $galaxy <= 9 &&
               $system >= 1 && $system <= 499 &&
               $position >= 1 && $position <= 15;
    }

    public static function validateResourceId(int $resourceId): bool
    {
        $validResources = [901, 902, 903, 911, 921, 922];
        return in_array($resourceId, $validResources, true);
    }

    public static function validateBuildingId(int $buildingId): bool
    {
        // This should be validated against the actual building list from vars
        return $buildingId > 0 && $buildingId < 1000;
    }

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

    public static function validateJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
