<?php

namespace SPGame\Core;

class Errors
{
    private static ?Logger $logger = null;

    // ==========================
    // Общие системные ошибки
    // ==========================
    public const UNKNOWN_ERROR = 1;
    public const INVALID_REQUEST = 2;
    public const DB_CONNECTION_FAILED = 10;
    public const DB_QUERY_FAILED = 11;
    public const CACHE_ERROR = 12;

    // ==========================
    // Аккаунт / регистрация / логин
    // ==========================
    public const LOGIN_ALREADY_EXISTS = 100;
    public const EMAIL_ALREADY_REGISTERED = 101;
    public const ACCOUNT_NOT_FOUND = 102;
    public const LOGIN_NOT_FOUND = 103;
    public const INCORRECT_PASSWORD = 104;
    public const ACCOUNT_INACTIVE = 105;
    public const INVALID_TOKEN = 106;
    public const ACCOUNT_ALREADY_LOGGED_IN = 107;
    public const EMAIL_NOT_VERIFIED = 108;
    public const ACCOUNT_BANNED = 109;
    public const TOKEN_EXPIRED = 110;
    public const NOT_AUTHORIZED = 111;
    public const INVALID_PIN = 112;
    public const PIN_RESEND_COOLDOWN = 113;

    // ==========================
    // Валидация пароля / логина / email
    // ==========================
    public const PASSWORD_TOO_SHORT = 200;
    public const PASSWORD_NO_UPPERCASE = 201;
    public const PASSWORD_NO_LOWERCASE = 202;
    public const PASSWORD_NO_NUMBER = 203;
    public const PASSWORD_NO_SYMBOL = 204;
    public const PASSWORD_INVALID_CHARS = 205;
    public const PASSWORD_TOO_COMMON = 206;

    public const INVALID_LOGIN = 300;
    public const LOGIN_TOO_SHORT = 301;
    public const LOGIN_TOO_LONG = 302;
    public const INVALID_EMAIL = 303;
    public const EMAIL_INVALID_DOMAIN = 304;

    // ==========================
    // WebSocket / соединение
    // ==========================
    public const CONNECTION_NOT_FOUND = 400;
    public const INVALID_FRAME_DATA = 401;
    public const SEND_FAILED = 402;

    // ==========================
    // Сообщения для игрока
    // ==========================
    private static array $messages = [
        self::UNKNOWN_ERROR => 'Unknown error occurred',
        self::INVALID_REQUEST => 'Invalid request',
        self::DB_CONNECTION_FAILED => 'Database connection failed',
        self::DB_QUERY_FAILED => 'Database query failed',
        self::CACHE_ERROR => 'Cache error',

        self::LOGIN_ALREADY_EXISTS => 'Login already exists',
        self::EMAIL_ALREADY_REGISTERED => 'Email already registered',
        self::ACCOUNT_NOT_FOUND => 'Account not found',
        self::LOGIN_NOT_FOUND => 'Login not found',
        self::INCORRECT_PASSWORD => 'Incorrect password',
        self::ACCOUNT_INACTIVE => 'Account is inactive',
        self::INVALID_TOKEN => 'Invalid token',
        self::ACCOUNT_ALREADY_LOGGED_IN => 'Account already logged in',
        self::EMAIL_NOT_VERIFIED => 'Email not verified',
        self::ACCOUNT_BANNED => 'Account is banned',
        self::TOKEN_EXPIRED => 'Token has expired',
        self::NOT_AUTHORIZED => 'Not authorized',
        self::INVALID_PIN => 'Invalid PIN code',
        self::PIN_RESEND_COOLDOWN => 'PIN code already sent recently. Please wait before requesting again',

        self::PASSWORD_TOO_SHORT => 'Password too short',
        self::PASSWORD_NO_UPPERCASE => 'Password must contain at least one uppercase letter',
        self::PASSWORD_NO_LOWERCASE => 'Password must contain at least one lowercase letter',
        self::PASSWORD_NO_NUMBER => 'Password must contain at least one number',
        self::PASSWORD_NO_SYMBOL => 'Password must contain at least one symbol (!@#$%^&*-_)',
        self::PASSWORD_INVALID_CHARS => 'Password contains invalid characters',
        self::PASSWORD_TOO_COMMON => 'Password is too common',

        self::INVALID_LOGIN => 'Invalid login format',
        self::LOGIN_TOO_SHORT => 'Login is too short',
        self::LOGIN_TOO_LONG => 'Login is too long',
        self::INVALID_EMAIL => 'Invalid email format',
        self::EMAIL_INVALID_DOMAIN => 'Email domain is not allowed',

        self::CONNECTION_NOT_FOUND => 'WebSocket connection not found',
        self::INVALID_FRAME_DATA => 'Invalid WebSocket frame data',
        self::SEND_FAILED => 'Failed to send WebSocket message',
    ];

    // ==========================
    // Методы
    // ==========================
    public static function getArrayMessage(int $code): array
    {
        return [
            'code'   => $code,
            'message' => self::$messages[$code] ?? 'Unknown error'
        ];
    }

    public static function getMessage(int $code): string
    {
        return self::$messages[$code] ?? 'Unknown error';
    }

    public static function log(int $code, string|array $context = ''): void
    {
        if (!self::$logger) {
            self::$logger = Logger::getInstance();
        }

        $msg = self::getMessage($code);

        $contextData = [];
        if (is_string($context) && $context !== '') {
            $contextData['context'] = $context;
        } elseif (is_array($context)) {
            $contextData = $context;
        }

        $contextData['code'] = $code;

        self::$logger->error($msg, $contextData);
    }

    public static function logMessage(string $message, array $context = []): void
    {
        if (!self::$logger) {
            self::$logger = Logger::getInstance();
        }
        self::$logger->error($message, $context);
    }
}
