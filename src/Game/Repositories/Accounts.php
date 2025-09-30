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

use Swoole\Table;

class Accounts
{
    /** @var Table Массив всех аккаунтов по ID */
    protected static Table $accounts;

    protected static Table $indexLogin;
    protected static Table $indexEmail;
    protected static Table $indexToken;

    /** @var Logger */
    protected static Logger $logger;

    public static function init(): void
    {
        self::$logger = Logger::getInstance();

        $size = 10240;
        self::$accounts = new Table($size);

        self::$accounts->column('id', Table::TYPE_INT, 8);
        self::$accounts->column('login', Table::TYPE_STRING, 64);
        self::$accounts->column('email', Table::TYPE_STRING, 128);
        self::$accounts->column('password', Table::TYPE_STRING, 128);
        self::$accounts->column('token', Table::TYPE_STRING, 128);
        self::$accounts->column('verify_email', Table::TYPE_INT, 1);
        self::$accounts->column('last_send_verify_mail', Table::TYPE_INT, 8);
        self::$accounts->column('level', Table::TYPE_INT, 1);
        self::$accounts->column('reg_time', Table::TYPE_INT, 8);
        self::$accounts->column('last_time', Table::TYPE_INT, 8);
        self::$accounts->column('reg_ip', Table::TYPE_STRING, 45);
        self::$accounts->column('last_ip', Table::TYPE_STRING, 45);
        self::$accounts->column('credit', Table::TYPE_INT, 8);
        self::$accounts->column('lang', Table::TYPE_STRING, 4);
        self::$accounts->column('frame', Table::TYPE_INT, 8); // fd для Frame
        self::$accounts->column('email_pin', Table::TYPE_INT, 4); // 4 байта, подходит для PIN до 99999999


        self::$accounts->create();

        // индексы (ключ => id)
        self::$indexLogin = new Table($size);
        self::$indexLogin->column('id', Table::TYPE_INT);
        self::$indexLogin->create();

        self::$indexEmail = new Table($size);
        self::$indexEmail->column('id', Table::TYPE_INT);
        self::$indexEmail->create();

        self::$indexToken = new Table($size);
        self::$indexToken->column('id', Table::TYPE_INT);
        self::$indexToken->create();

        self::ensureTableExists();

        self::loadAll();
    }

    protected static function ensureTableExists(): void
    {
        $db = Database::getInstance();
        $tableName = 'accounts';

        // Определяем все столбцы и их типы один раз
        $columnsDefinition = [
            'id' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'login' => 'VARCHAR(64) NOT NULL',
            'email' => 'VARCHAR(128) NOT NULL',
            'password' => 'VARCHAR(128) NOT NULL',
            'token' => 'VARCHAR(128) DEFAULT NULL',
            'verify_email' => 'TINYINT(1) DEFAULT 0',
            'last_send_verify_mail' => 'INT(11) DEFAULT NULL',
            'level' => 'TINYINT(1) DEFAULT 5',
            'reg_time' => 'INT(11) NOT NULL',
            'last_time' => 'INT(11) NOT NULL',
            'reg_ip' => 'VARCHAR(45) DEFAULT NULL',
            'last_ip' => 'VARCHAR(45) DEFAULT NULL',
            'credit' => 'INT(8) DEFAULT 0',
            'lang' => "VARCHAR(4) DEFAULT 'ru'"
        ];

        $exists = $db->fetchOne("SHOW TABLES LIKE '$tableName'");
        if (!$exists) {
            // Собираем строку для CREATE TABLE из определения столбцов
            $columnsSql = [];
            foreach ($columnsDefinition as $col => $def) {
                $columnsSql[] = "`$col` $def";
            }
            $db->query("CREATE TABLE `$tableName` (" . implode(", ", $columnsSql) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            self::$logger->info("Create table MySQL `$tableName`");
        }

        // Проверяем и добавляем недостающие столбцы
        $existingColumns = $db->fetchAll("SHOW COLUMNS FROM `$tableName`");
        $existingColumns = array_column($existingColumns, 'Field');

        foreach ($columnsDefinition as $col => $def) {
            if (!in_array($col, $existingColumns)) {
                $db->query("ALTER TABLE `$tableName` ADD COLUMN `$col` $def");
                self::$logger->info("Add column `$col` to table MySQL `$tableName`");
            }
        }

        // Добавляем уникальные индексы и индексы при их отсутствии
        $indexes = $db->fetchAll("SHOW INDEX FROM `$tableName`");
        $existingIndexNames = array_map(function ($row) {
            return $row['Key_name'];
        }, $indexes);

        $ensureIndex = function (string $sqlCreate, string $indexName) use ($db, $existingIndexNames, $tableName) {
            if (!in_array($indexName, $existingIndexNames, true)) {
                $db->query($sqlCreate);
                self::$logger->info("Add index `$indexName` on MySQL `$tableName`");
            }
        };

        $ensureIndex("ALTER TABLE `$tableName` ADD UNIQUE `uniq_login` (`login`)", 'uniq_login');
        $ensureIndex("ALTER TABLE `$tableName` ADD UNIQUE `uniq_email` (`email`)", 'uniq_email');
        $ensureIndex("ALTER TABLE `$tableName` ADD UNIQUE `uniq_token` (`token`)", 'uniq_token');
    }


    // ==============================
    // Работа с массивом аккаунтов
    // ==============================
    public static function loadAll(): void
    {
        $db = Database::getInstance();
        $start = microtime(true);
        $rows = $db->fetchAll("SELECT * FROM accounts");

        foreach ($rows as $row) {
            //$account = new Account($row);
            self::add($row);
        }
        $duration = round(microtime(true) - $start, 3);
        self::$logger->info("Accounts loaded", [
            'count' => count(self::$accounts),
            'duration' => $duration . 's'
        ]);
    }

    public static function add(array $data): void
    {
        // нормализуем ключи для индексов
        $normalizedLogin = isset($data['login']) ? mb_strtolower(trim((string)$data['login'])) : '';
        $normalizedEmail = isset($data['email']) ? mb_strtolower(trim((string)$data['email'])) : '';
        $tokenValue = isset($data['token']) ? trim((string)$data['token']) : '';

        self::$accounts->set(
            (int)$data['id'],
            [
                'id' => $data['id'] ?? 0,
                'login' => $normalizedLogin,
                'email' => $normalizedEmail,
                'password' => $data['password'] ?? '',
                'token' => $tokenValue,
                'verify_email' => !empty($data['verify_email']) ? 1 : 0,
                'last_send_verify_mail' => $data['last_send_verify_mail'] ?? 0,
                'level' => $data['level'] ?? 5,
                'reg_time' => $data['reg_time'] ?? time(),
                'last_time' => $data['last_time'] ?? time(),
                'reg_ip' => $data['reg_ip'] ?? '',
                'last_ip' => $data['last_ip'] ?? '',
                'credit' => $data['credit'] ?? 0,
                'lang' => $data['lang'] ?? 'ru',
                'frame' => 0,
            ]
        );

        // индексируем только непустые значения
        if ($normalizedLogin !== '') {
            self::$indexLogin->set($normalizedLogin, ['id' => $data['id']]);
        }
        if ($normalizedEmail !== '') {
            self::$indexEmail->set($normalizedEmail, ['id' => $data['id']]);
        }
        if ($tokenValue !== '') {
            self::$indexToken->set($tokenValue, ['id' => $data['id']]);
        }
    }

    /*
    public static function get(int $id): ?Account
    {
        return self::$accounts[$id] ?? null;
    }*/

    public static function findByLogin(string $login): ?array
    {
        $row = self::$indexLogin->get($login);
        return $row ? self::$accounts->get($row['id']) : null;
    }


    public static function findByEMail(string $email): ?array
    {
        $row = self::$indexEmail->get($email);
        return $row ? self::$accounts->get($row['id']) : null;
    }

    public static function findByToken(string $token): ?array
    {
        $row = self::$indexToken->get($token);
        return $row ? self::$accounts->get($row['id']) : null;
    }

    public static function getAccount(int $id): array
    {
        return self::$accounts->get($id);
    }

    public static function all(): array
    {
        $result = [];
        foreach (self::$accounts as $row) {
            $result[] = $row;
        }
        return $result;
    }

    public static function count(): int
    {
        return self::$accounts->count();
    }

    /** Генерация нового токена для аккаунта */
    public static function generateToken(int $id): string
    {

        $row = self::$accounts->get($id);

        if (!$row) {
            throw new \RuntimeException("Account with id {$id} not found");
        }

        $old = self::$accounts->get($id)['token'] ?? '';
        if ($old) {
            self::$indexToken->del($old);
        }

        $row['token'] = hash('sha512', $id . bin2hex(random_bytes(6)) . microtime(true));
        self::$accounts->set($id, $row);

        self::$indexToken->set($row['token'], ['id' => $id]);

        return $row['token'];
    }

    public static function getResendCooldownEmail(int $id): int
    {
        $account = self::$accounts->get($id);
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

        self::$accounts->set($account['id'], $account);

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

        self::$logger->info("authByToken", $account);


        $ip = Connect::getIp($fd); // Берём IP из WSocket

        Connect::setAccount($fd, $account['id']);

        $account['last_time'] = time();
        $account['last_ip'] = $ip;
        $account['frame'] = $fd;

        self::$accounts->set((int)$account['id'], $account);

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
        $db->query(
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

        $id = $db->lastInsertId();
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

        $token = self::generateToken($account['id']);
        $db->query(
            "UPDATE accounts SET token = :token WHERE id = :id",
            [
                ':token' => $token,
                ':id' => $id
            ]
        );

        self::sendEmailConfirmation($id);

        return [
            'success' => true,
            'id' => $id,
            'login' => $login,
            'token' => $token
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
        self::$accounts->set($accountId, $account);

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
        $account = self::$accounts->get($id);
        if (!$account) {
            throw new \RuntimeException("Account with id {$id} not found");
        }

        $pin = Helpers::generatePin();

        // Сохраняем PIN в аккаунт (в таблице Swoole)
        $account['email_pin'] = $pin;
        self::$accounts->set($id, $account);

        self::$logger->info("Generated e-mail PIN for account {$account['login']}");

        return $pin;
    }

    /** Отправка e-mail для подтверждения */
    public static function sendEmailConfirmation(int $id): bool
    {
        $account = self::$accounts->get($id);
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
        self::$accounts->set($accountId, $account);

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
}
