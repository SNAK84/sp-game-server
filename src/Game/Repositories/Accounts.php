<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Connect;
use SPGame\Core\Database;
use SPGame\Core\Errors;
use SPGame\Core\Validator;
use SPGame\Core\Logger;
use SPGame\Core\Helpers;
use SPGame\Core\Message;
use SPGame\Core\WSocket;
use SPGame\Core\Mailer;
use SPGame\Core\Environment;
use SPGame\Core\Defaults;

use Swoole\Table;

class Accounts extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    protected static string $className = 'Accounts';

    protected static string $tableName = 'accounts';

    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => Defaults::NONE],
            'login' => ['swoole' => [Table::TYPE_STRING, 64], 'sql' => 'VARCHAR(64) NOT NULL', 'default' => ''],
            'email' => ['swoole' => [Table::TYPE_STRING, 128], 'sql' => 'VARCHAR(128) NOT NULL', 'default' => ''],
            'password' => ['swoole' => [Table::TYPE_STRING, 128], 'sql' => 'VARCHAR(128) NOT NULL', 'default' => ''],
            'token' => ['swoole' => [Table::TYPE_STRING, 128], 'sql' => 'VARCHAR(128) DEFAULT NULL', 'default' => ''],
            'verify_email' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) DEFAULT 0', 'default' => 0],
            'last_send_verify_mail' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT(11) DEFAULT 0', 'default' => 0],
            'level' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) DEFAULT 5', 'default' => 5],
            'reg_time' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT(11) NOT NULL', 'default' => Defaults::TIME],
            'last_time' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT(11) NOT NULL', 'default' => Defaults::TIME],
            'reg_ip' => ['swoole' => [Table::TYPE_STRING, 45], 'sql' => 'VARCHAR(45) DEFAULT NULL', 'default' => ''],
            'last_ip' => ['swoole' => [Table::TYPE_STRING, 45], 'sql' => 'VARCHAR(45) DEFAULT NULL', 'default' => ''],
            'credit' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT(8) DEFAULT 0', 'default' => 0],
            'lang' => ['swoole' => [Table::TYPE_STRING, 4], 'sql' => "VARCHAR(4) DEFAULT 'ru'", 'default' => 'ru'],
            'frame' => ['swoole' => [Table::TYPE_INT, 8], 'default' => 0],
            'email_pin' => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'mode' => ['swoole' => [Table::TYPE_STRING, 32], 'default' => ''],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_login', 'type' => 'UNIQUE', 'fields' => ['login']],
            ['name' => 'uniq_email', 'type' => 'UNIQUE', 'fields' => ['email']],
            ['name' => 'uniq_token', 'type' => 'UNIQUE', 'fields' => ['token']],
        ]
    ];

    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsTable;
    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsDelTable;

    // Индексы Swoole Table
    protected static array $indexes = [
        'login' => ['key' => 'login', 'Unique' => true],
        'email' => ['key' => 'email', 'Unique' => true],
        'token' => ['key' => 'token', 'Unique' => true]
    ];

    // ==============================
    // Поиск
    // ==============================
    public static function findByLogin(string $login): ?array
    {
        return self::findByIndex('login', mb_strtolower(trim($login)));
    }

    public static function findByEmail(string $email): ?array
    {
        return self::findByIndex('email', mb_strtolower(trim($email)));
    }

    public static function findByToken(string $token): ?array
    {
        return self::findByIndex('token', $token);
    }

    /** Генерация нового токена для аккаунта */
    public static function generateToken(int $id): string
    {

        $account  = self::findById($id);

        if (!$account) {
            throw new \RuntimeException("Account with id {$id} not found");
        }

        $account['token'] = hash('sha512', $id . bin2hex(random_bytes(6)) . microtime(true));

        self::update($account);

        return $account['token'];
    }

    public static function getResendCooldownEmail(int $id): int
    {
        $account = self::findById($id);
        if (!$account) {
            return 0;
        }

        $cooldown = Environment::getInt('EMAIL_RESEND_COOLDOWN', 60);
        $lastSend = (int)($account['last_send_verify_mail'] ?? 0);

        $remaining = $cooldown - (time() - $lastSend);
        return max(0, $remaining);
    }
    // ==============================
    // Авторизация
    // ==============================
    public static function login(Message $Msg, int $fd, WSocket $wsocket): array
    {

        if (!isset(self::$logger)) {
            self::$logger = Logger::getInstance();
        }


        $login = $Msg->getData('login', '');
        $password = $Msg->getData('password', '');

        $ip = Connect::getIp($fd); // Берём IP из WSocket

        if (empty($login) || empty($password)) {
            self::$logger->warning("Missing login fields | IP: $ip");

            return ['error' => Errors::getArrayMessage(Errors::INVALID_REQUEST)];
        }

        if (!Validator::validateLogin($login) && !Validator::validateEmail($login)) {
            self::$logger->warning("Invalid login format: $login | IP: $ip");
            return ['error' => Errors::getArrayMessage(Errors::INVALID_LOGIN)];
        }

        if (Validator::validateEmail($login))
            $account = self::findByEMail($login);
        else
            $account = self::findByLogin($login);
        if (!$account) {
            self::$logger->warning("Account not found: $login | IP: $ip");
            return ['error' => Errors::getArrayMessage(Errors::ACCOUNT_NOT_FOUND)];
        }

        if (!password_verify($password, $account['password'])) {
            self::$logger->warning("Incorrect password: $login | IP: $ip");
            return ['error' => Errors::getArrayMessage(Errors::INCORRECT_PASSWORD)];
        }

        $token = self::generateToken($account['id']);

        $account['last_time'] = time();
        $account['last_ip'] = $ip;
        $account['frame'] = $fd;
        $account['token'] = $token;

        self::update($account);

        $db = Database::getInstance();
        $db->query(
            "UPDATE accounts SET token = :token, last_time = :last_time, last_ip = :last_ip WHERE id = :id",
            [
                ':token' => $token,
                ':last_time' => $account['last_time'],
                ':last_ip' => $account['last_ip'],
                ':id' => $account['id']
            ]
        );

        Connect::setAccount($fd, $account['id']);

        self::$logger->info("User logged in: $login | IP: $ip");

        $verify_email = false;

        if (!$account['verify_email']) {
            self::$logger->warning("Account not verify email: $login");
            $verify_email = true;
        }

        return [
            'success' => true,
            'id' => $account['id'],
            'login' => $account['login'],
            'token' => $token,
            'verify_email' => $verify_email
        ];
    }

    // ==============================
    // Авторизация по Token
    // ==============================
    public static function authByToken(string $token, int $fd, WSocket $wsocket): array
    {
        if (!isset(self::$logger)) {
            self::$logger = Logger::getInstance();
        }

        $account = Accounts::findByToken($token);
        if (!$account) {
            return ['error' => 'Invalid token'];
        }

        self::$logger->debug("authByToken", $account);


        $ip = Connect::getIp($fd); // Берём IP из WSocket

        Connect::setAccount($fd, $account['id']);

        $account['last_time'] = time();
        $account['last_ip'] = $ip;
        $account['frame'] = $fd;

        self::update($account);

        $verify_email = false;
        // Блокируем доступ для не верифицированных аккаунтов
        if (empty($account['verify_email'])) {
            self::$logger->warning("Token auth denied: email not verified", ['id' => $account['id'], 'login' => $account['login']]);
            $verify_email = true;
        }



        $db = Database::getInstance();

        // Обновление last_time при каждом запросе
        $db->query(
            "UPDATE accounts SET last_time = :last_time, last_ip = :last_ip WHERE id = :id",
            [
                ':last_time' => $account['last_time'],
                ':last_ip' => $account['last_ip'],
                ':id' => $account['id']
            ]
        );

        return [
            'success' => true,
            'id' => $account['id'],
            'login' => $account['login'],
            'token' => $token,
            'verify_email' => $verify_email
        ];
    }

    // ==============================
    // Регистрация
    // ==============================
    public static function register(Message $Msg, int $fd, WSocket $wsocket): array
    {
        if (!isset(self::$logger)) {
            self::$logger = Logger::getInstance();
        }

        self::$logger->info("register", $Msg->source());

        $login = $Msg->getData('login', '');
        $password = $Msg->getData('password', '');
        $email = $Msg->getData('email', '');

        $ip = Connect::getIp($fd); // Берём IP из WSocket

        // Валидация
        if (!Validator::validateLogin($login)) {
            return ['error' => Errors::getArrayMessage(Errors::INVALID_LOGIN)];
        }
        if ($error = Validator::validatePassword($password)) {
            return ['error' => Errors::getArrayMessage($error)];
        }
        if (!Validator::validateEmail($email)) {
            return ['error' => Errors::getArrayMessage(Errors::INVALID_EMAIL)];
        }

        if (self::findByLogin($login)) {
            return ['error' => Errors::getArrayMessage(Errors::LOGIN_ALREADY_EXISTS)];
        }

        if (self::findByEMail($email)) {
            return ['error' => Errors::getArrayMessage(Errors::EMAIL_ALREADY_REGISTERED)];
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $db = Database::getInstance();
        $id = $db->insert(
            "INSERT INTO accounts (login, email, password, reg_time, last_time, reg_ip, last_ip)
             VALUES (:login, :email, :password, :reg_time, :last_time, :reg_ip, :last_ip)",
            [
                ':login' => $login,
                ':email' => $email,
                ':password' => $hashedPassword,
                ':reg_time' => time(),
                ':last_time' => time(),
                ':reg_ip' => $ip,
                ':last_ip' => $ip
            ]
        );

        $account = [
            'id' => $id,
            'login' => $login,
            'email' => $email,
            'password' => $hashedPassword,
            'last_send_verify_mail' => time(),
            'frame' => $fd
        ];

        self::add($account);

        Connect::setAccount($fd, $account['id']);

        self::$logger->info("New account registered: $login | IP: $ip");

        $account['token'] = self::generateToken($account['id']);

        $db->query(
            "UPDATE accounts SET token = :token WHERE id = :id",
            [
                ':token' => $account['token'],
                ':id' => $id
            ]
        );

        self::update($account);

        self::sendEmailConfirmation($id);

        return [
            'success' => true,
            'id' => $id,
            'login' => $login,
            'token' => $account['token']
        ];
    }

    public static function verifyEmail(Message $Msg, int $fd, WSocket $wsocket): array
    {
        $pin = $Msg->getData('PinCode', '');

        if (empty($pin)) {
            return ['error' => Errors::getArrayMessage(Errors::INVALID_REQUEST)];
        }

        $accountId = Connect::getAccount($fd);
        if (!$accountId) {
            return ['error' => Errors::getArrayMessage(Errors::NOT_AUTHORIZED)];
        }

        $account = Accounts::findByToken($Msg->getToken());
        if (!$account) {
            return ['error' => Errors::getArrayMessage(Errors::INVALID_TOKEN)];
        }

        if ((string)$account['email_pin'] !== (string)$pin) {
            self::$logger->warning("Invalid email PIN for account ID {$accountId} | FD: $fd");
            return ['error' => Errors::getArrayMessage(Errors::INVALID_PIN)];
        }

        // Верификация успешна
        //$account['verify_email'] = 1;
        //$account['email_pin'] = 0; // или NULL

        // Обновляем время последней отправки
        self::update($account);

        /*
        self::$logger->info("Resending verification PIN for account {$account['login']} | ID: $accountId");
        $sent = self::sendEmailConfirmation($accountId);
        if (!$sent) {
            self::$logger->error("Failed to send verification PIN email for account {$account['login']} | ID: $accountId");
            return ['error' => Errors::getArrayMessage(Errors::SEND_FAILED)];
        }*/

        // Обновляем базу
        $db = Database::getInstance();
        $db->query(
            "UPDATE accounts SET verify_email = 1 WHERE id = :id",
            [':id' => $accountId]
        );

        self::$logger->info("Email verified for account ID {$accountId} | FD: $fd");

        return [
            'success' => true,
            'id' => $accountId,
            'verify_email' => false
        ];
    }



    // ==============================
    // Проверка токена
    // ==============================
    public static function validateToken(string $token): ?array
    {
        $account = self::findByToken($token);
        if (!$account) {
            $mask = strlen($token) > 16 ? (substr($token, 0, 8) . '...' . substr($token, -8)) : $token;
            self::$logger->warning("Invalid token: {$mask}");
        }
        return $account;
    }

    /** Генерация PIN для подтверждения e-mail */
    public static function generateEmailPin(int $id): string
    {
        $account = self::findById($id);
        if (!$account) {
            throw new \RuntimeException("Account with id {$id} not found");
        }

        $pin = Helpers::generatePin();

        // Сохраняем PIN в аккаунт (в таблице Swoole)
        $account['email_pin'] = $pin;
        self::update($account);

        self::$logger->info("Generated e-mail PIN for account {$account['login']}");

        return $pin;
    }

    /** Отправка e-mail для подтверждения */
    public static function sendEmailConfirmation(int $id): bool
    {
        $account = self::findById($id);
        if (!$account || empty($account['email'])) {
            self::$logger->error("Cannot send email confirmation: account not found or email empty | ID: $id");
            return false;
        }

        self::$logger->info("Sending email confirmation to {$account['email']} for account {$account['login']} | ID: $id");

        $pin = self::generateEmailPin($id);

        $mailer = new Mailer();
        $result = $mailer->sendVerificationPin($account['email'], $account['login'], $pin);

        if ($result) {
            self::$logger->info("Email confirmation sent successfully to {$account['email']} | ID: $id");
        } else {
            self::$logger->error("Failed to send email confirmation to {$account['email']} | ID: $id");
        }

        return $result;
    }

    public static function resendVerificationPin(Message $Msg, int $fd, WSocket $wsocket): array
    {

        $accountId = Connect::getAccount($fd);
        if (!$accountId) {
            return ['error' => Errors::getArrayMessage(Errors::NOT_AUTHORIZED)];
        }

        $account = Accounts::findByToken($Msg->getToken());
        if (!$account) {
            return ['error' => Errors::getArrayMessage(Errors::INVALID_TOKEN)];
        }

        // Проверяем, когда последний раз отправлялся PIN
        $remaining = self::getResendCooldownEmail($accountId);
        if ($remaining > 0) {
            return [
                'error' => Errors::getArrayMessage(Errors::PIN_RESEND_COOLDOWN),
                'cooldown' => $remaining
            ];
        }

        // Обновляем время последней отправки
        $account['last_send_verify_mail'] = time();
        self::update($account);

        // Обновляем в базе данных
        /*$db = Database::getInstance();
        $db->query(
            "UPDATE accounts SET last_send_verify_mail = :last_send_verify_mail WHERE id = :id",
            [
                ':last_send_verify_mail' => $account['last_send_verify_mail'],
                ':id' => $accountId
            ]
        );*/

        self::$logger->info("Resending verification PIN for account {$account['login']} | ID: $accountId");

        $sent = self::sendEmailConfirmation($accountId);

        if (!$sent) {
            self::$logger->error("Failed to send verification PIN email for account {$account['login']} | ID: $accountId");
            return ['error' => Errors::getArrayMessage(Errors::SEND_FAILED)];
        }

        self::$logger->info("Verification PIN email sent successfully for account {$account['login']} | ID: $accountId");

        return [
            'success' => true,
            'message' => 'Verification PIN sent'
        ];
    }

    /**
     * Получить список аккаунтов, которые сейчас онлайн
     *
     * @return array<int, array>  [accountId => данные аккаунта]
     */
    public static function getOnline(): array
    {
        $result = [];

        // идём по таблице соединений
        foreach (Connect::getAuthorizedFds() as $fd) {
            $aid = Connect::getAccount($fd);

            if ($aid !== null) {
                $account = static::findById($aid);
                if ($account) {
                    $result[$aid] = $account;
                }
            }
        }

        return $result;
    }


    public static function getAccountData(int $accountId, int $planetId = null): array
    {
        $AccountData = [
            'Account' => self::findById($accountId),
            'User' => Users::findByAccount($accountId)
        ];

        if ($planetId) {
            $AccountData['Planet'] = Planets::findById($planetId);
            $AccountData['Builds'] = Builds::findById($planetId);
            $AccountData['Techs'] = Techs::findById($AccountData['User']['id']);
        }

        return $AccountData;
    }
}
