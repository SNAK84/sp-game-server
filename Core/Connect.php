<?php

namespace Core;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;

use Input;

use Game\Repositories\Accounts;
use Game\Account;
use Core\Database;

class Connect
{
    protected Server $server;
    protected Logger $logger;

    /*public function __construct(Server $server)
    {
        $this->server = $server;
        $this->logger = Logger::getInstance();
    }*/

    /**
     * Обработка входящего сообщения
     */
    public static function handle(Frame $frame)
    {

        $mode = Input::get((array)$frame->data, 'mode', '');
        $action = Input::get((array)$frame->data, 'action', '');

        if ($mode == "login") {
            // Авторизация
            switch ($action) {
                case 'register':
                    return $this->register((array)$frame->data);
                default:
                    return $this->login((array)$frame->data);
            }
        } else {
            $Token = Input::get((array)$frame->data, 'Token', '');
            if (empty($Token)) {
                Logger::getInstance()->warning('Missing token for action', ['fd' => $frame->fd, 'action' => $action]);
                return null;
            }

        }

    }

    // =============================
    // Регистрация
    // =============================
    protected function register(array $data): array
    {
        $login = $data['login'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $ip = $data['ip'] ?? '0.0.0.0';

        if (empty($login) || empty($email) || empty($password)) {
            return ['error' => 'Missing registration fields'];
        }

        // Валидация email
        if (!\Core\Validator::validateEmail($email)) {
            return ['error' => 'Invalid email format'];
        }

        // Валидация логина (3-20 символов, буквы, цифры, _)
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $login)) {
            return ['error' => 'Invalid login format'];
        }

        // Проверка пароля
        if (!\Core\Validator::validatePassword($password)) {
            return ['error' => 'Password must be at least 8 characters and contain letters and numbers'];
        }

        // Проверка на существующий логин
        if (Accounts::findByLogin($login)) {
            return ['error' => 'Login already exists'];
        }

        // Проверка на существующий email
        foreach (Accounts::all() as $acc) {
            if ($acc->getEmail() === $email) {
                return ['error' => 'Email already registered'];
            }
        }

        // Хешируем пароль
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $time = time();

        $db = Database::getInstance();
        $db->query(
            "INSERT INTO accounts (login, email, password, reg_time, last_time, reg_ip, last_ip) 
         VALUES (:login, :email, :password, :reg_time, :last_time, :reg_ip, :last_ip)",
            [
                ':login' => $login,
                ':email' => $email,
                ':password' => $hash,
                ':reg_time' => $time,
                ':last_time' => $time,
                ':reg_ip' => $ip,
                ':last_ip' => $ip
            ]
        );

        $accountId = (int)$db->lastInsertId();

        $account = new \Game\Account([
            'id' => $accountId,
            'login' => $login,
            'email' => $email,
            'password' => $hash,
            'reg_time' => $time,
            'last_time' => $time,
            'reg_ip' => $ip,
            'last_ip' => $ip,
            'level' => 5,
            'credit' => 0,
            'lang' => 'ru'
        ]);

        Accounts::add($account);

        return ['success' => true, 'id' => $accountId];
    }

    // =============================
    // Логин
    // =============================
    protected function login(array $data): array
    {
        $login = $data['login'] ?? '';
        $password = $data['password'] ?? '';
        $ip = $data['ip'] ?? '0.0.0.0';

        if (empty($login) || empty($password)) {
            return ['error' => 'Missing login fields'];
        }

        // Валидация логина (можно повторно проверить формат)
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $login)) {
            return ['error' => 'Invalid login format'];
        }

        $account = Accounts::findByLogin($login);
        if (!$account) {
            return ['error' => 'Account not found'];
        }

        if (!password_verify($password, $account->getPassword())) {
            return ['error' => 'Incorrect password'];
        }

        // Генерация нового токена
        $token = hash('sha512', $account->getId() . bin2hex(random_bytes(6)) . microtime(true));
        $account->setToken($token);

        $db = Database::getInstance();
        $db->query(
            "UPDATE accounts SET token = :token, last_time = :last_time, last_ip = :last_ip WHERE id = :id",
            [
                ':token' => $token,
                ':last_time' => time(),
                ':last_ip' => $ip,
                ':id' => $account->getId()
            ]
        );

        return ['success' => true, 'token' => $token];
    }


    // =============================
    // Авторизация по токену
    // =============================
    protected function authByToken(string $token): array
    {
        $account = Accounts::findByToken($token);
        if (!$account) {
            return ['error' => 'Invalid token'];
        }

        $db = Database::getInstance();

        // Обновление last_time при каждом запросе
        $db->query(
            "UPDATE accounts SET last_time = :last_time WHERE id = :id",
            [
                ':last_time' => time(),
                ':id' => $account->getId()
            ]
        );

        return [
            'success' => true,
            'id' => $account->getId(),
            'login' => $account->getLogin()
        ];
    }
}
