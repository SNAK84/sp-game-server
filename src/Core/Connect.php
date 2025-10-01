<?php

namespace SPGame\Core;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Table;


use SPGame\Game\Repositories\Accounts;
use SPGame\Core\Message;
use SPGame\Core\Input;
use SPGame\Core\Environment;
use SPGame\Core\Logger;



/*

{
  "status": "ok|error",  // только для ответов
  "requestId": "string", // уникальный идентификатор запроса
  "token": "string (128 chars)",
  "mode": "connect | build | research | fleet | chat | system",
  "action": "list | start | cancel | move | send | message",
  "data": {
    "...": "зависит от mode/action"
  }
  "error": {             // если status = "error"
    "code": "string",
    "message": "string"
  }
}

*/



class Connect
{
    protected Server $server;
    // removed instance logger, use Logger::getInstance() statically

    protected static Table $connections;

    public static function init(int $size = 1024): void
    {
        self::$connections = new Table($size);

        // Определяем колонки
        self::$connections->column('fd', Table::TYPE_INT, 4);
        self::$connections->column('ip', Table::TYPE_STRING, 45); // IPv6
        self::$connections->column('port', Table::TYPE_INT, 4);
        self::$connections->column('account', Table::TYPE_INT, 11); // JSON или ID

        self::$connections->create();
    }

    public static function set(int $fd, $ip, $port, $account)
    {
        self::$connections->set($fd, [
            'fd'      => $fd,
            'ip'      => $ip,
            'port'    => $port,
            'account' => $account ?? null,
        ]);
        /*
        self::$connections[$fd] = [
            'headers' => $headers,
            'ip'      => $ip,
            'port'    => $port,
            'account' => $account,
        ];*/
    }
    public static function unset(int $fd)
    {
        self::$connections->del($fd);
    }

    public static function getCount(): int
    {
        return self::$connections->count();
    }

    public static function getAuthorizedCount()
    {
        $count = 0;
        foreach (self::$connections as $row) {
            if (!empty($row['account'])) {
                $count++;
            }
        }
        return $count;
    }

    public static function getAuthorizedFds(): array
    {
        $fds = [];
        foreach (self::$connections as $row) {
            if (!empty($row['account'])) {
                $fds[] = $row['fd'];
            }
        }
        return $fds;
    }

    public static function setAccount(int $fd, int $account): void
    {
        if ($row = self::$connections->get($fd)) {
            $row['account'] = $account ?? null;
            self::$connections->set($fd, $row);
        }
    }

    public static function getAccount(int $fd): ?int
    {
        if ($row = self::$connections->get($fd)) {
            return $row['account'] !== '' ? $row['account'] : null;
        }
        return null;
    }

    public static function getIp(int $fd): string
    {
        return self::$connections->get($fd)['ip'] ?? '0.0.0.0';
    }

    public static function getPort(int $fd): int
    {
        return self::$connections->get($fd)['port'] ?? 0;
    }

    /**
     * Прямой доступ к таблице (для итераций в Timer и других случаях)
     */
    public function getTable(): Table
    {
        return self::$connections;
    }

    /**
     * Обработка ошибки EMAIL_NOT_VERIFIED
     */
    private static function handleEmailNotVerified(array $result, Message $response, int $fd): array
    {
        $aid = self::getAccount($fd);
        $accaunt = Accounts::findById($aid);

        $response->setToken($accaunt['token'] ?? '');
        $response->setData("cooldown",  Accounts::getResendCooldownEmail($aid));
        $response->setData("PinLeght", Environment::getInt('PIN_LENGTH', 6));
        $response->setMode("login")->setAction("verify_email");

        return $response->source();
    }

    /**
     * Обработка входящего сообщения
     */
    public static function handle(Frame $frame, WSocket $wsocket)
    {

        // Logger is accessed via Logger::getInstance()

        $Msg = new Message((array)$frame->data);

        /*Logger::getInstance()->info(
            'Input Message',
            $Msg->source()
        );*/

        // Преобразуем данные фрейма в удобный массив
        //$payload = (array)$frame->data;

        $mode      = $Msg->getMode();
        $action    = $Msg->getAction();
        $requestId = $Msg->getRequestId();
        $token     = $Msg->getToken();

        $response = new Message();
        $response->setMode($mode)->setAction($action)->setRequestId($requestId);

        try {
            // --- special case: handshake / connect (токен не обязателен) ---
            if ($mode === 'connect' || $mode === 'handshake') {
                if ($action === 'ping') {
                    $response->setData('message', 'Connected');
                    return $response->source();
                }
            }

            $result  = [];

            // --- логин / регистрация (обработка до проверки токена) ---
            if ($mode === 'login') {
                switch ($action) {
                    case 'register':
                        // Accounts::register должен вернуть массив в формате ответа
                        $result  = Accounts::register($Msg, $frame->fd, $wsocket);
                        break;
                    case 'verify_email':
                        $result  = Accounts::verifyEmail($Msg, $frame->fd, $wsocket);
                        break;
                    case 'send_verify_email':
                        $result  = Accounts::resendVerificationPin($Msg, $frame->fd, $wsocket);
                        Logger::getInstance()->info("Verification PIN Connect", $result);
                        break;
                    case 'login':
                    default:
                        $result  = Accounts::login($Msg, $frame->fd, $wsocket);
                        break;
                }

                if ($result['verify_email']) {
                    return self::handleEmailNotVerified($result, $response, $frame->fd);
                }

                if (isset($result['success']) && $result['success'] === true && isset($result['id'])) {
                    // при успешной регистрации/логине добавляем токен в Message
                    $response->setToken($result['token'] ?? '');
                    $response->setData('id', $result['id']);
                    $response->setData('login', $result['login']);
                } elseif (isset($result['success']) && $result['success'] === true) {

                    if ($action == 'send_verify_email') {
                        $response->setData('message', $result['message']);
                        $response->setMode("overview");
                        $response->setAction("");
                        return $response->source();
                    }
                    /*
                    $aid = self::getAccount($frame->fd);
                    $accaunt = Accounts::getAccount($aid);

                    $response->setToken($accaunt['token'] ?? '');
                    $response->setData("cooldown",  Accounts::getResendCooldownEmail($aid));
                    $response->setData("PinLeght", Environment::getInt('PIN_LENGTH', 6));
*/

                    /*return self::handleEmailNotVerified($result, $response, $frame->fd);*/
                } else {


                    $response->setError($result['error']['code'] ?? 'unknown', $result['error']['message'] ?? 'Error');

                    if ($result['error']['code'] == Errors::PIN_RESEND_COOLDOWN) {
                        $response->setToken($result['token'] ?? '');
                        $response->setData("cooldown", $result['cooldown']);
                        $response->setData("PinLeght", Environment::getInt('PIN_LENGTH', 6));
                    }
                }

                return $response->source();
            }

            // --- для всех остальных режимов требуется токен ---
            if (empty($token)) {
                Logger::getInstance()->warning('Missing token for action', [
                    'fd' => $frame->fd,
                    'mode' => $mode,
                    'action' => $action
                ]);

                $response->setError('missing_token', 'Token is required')->setMode("login")->setAction("login");
                return $response->source();
            }

            // Проверяем токен — authByToken должен возвращать массив с success
            $authResult = Accounts::authByToken($token, $frame->fd, $wsocket);
            if (!isset($authResult['success']) || $authResult['success'] !== true) {
                Logger::getInstance()->warning('Invalid token', [
                    'fd' => $frame->fd,
                    'token' => $token,
                    'mode' => $mode,
                    'action' => $action,
                    'authResult' => $authResult
                ]);

                $response->setError('invalid_token', 'Invalid token')->setMode("login")->setAction("login");
                return $response->source();
            }

            if ($authResult['verify_email']) {
                return self::handleEmailNotVerified($authResult, $response, $frame->fd);
            }

            $pageBuilder = new \SPGame\Game\PageBuilder($Msg, $frame->fd);

            // --- Роутинг по режимам (game logic) ---
            switch ($mode) {
                /*case 'build':
                    // BuildModule::handle должен вернуть массив-ответ
                    $result = BuildModule::handle($action, $payload, $authResult, $frame, $wsocket);
                    break;

                case 'research':
                    $result = ResearchModule::handle($action, $payload, $authResult, $frame, $wsocket);
                    break;

                case 'fleet':
                    $result = FleetModule::handle($action, $payload, $authResult, $frame, $wsocket);
                    break;

                case 'chat':
                    $result = Chat::handle($action, $payload, $authResult, $frame, $wsocket);
                    break;

                case 'system':
                    $result = SystemModule::handle($action, $payload, $authResult, $frame, $wsocket);
                    break;*/

                case 'overview':
                default:
                    $result = $pageBuilder->build('overview');
                    $response->setData('Page',$result);
                    $response->setError('unknown_mode', 'Страница по умолчанию overview');
                    return $response->source();
            }

            // Гарантируем стандартную структуру ответа
            // --- объединяем результат с Message ---
            if (is_array($result)) {
                foreach ($result as $key => $val) {
                    if (!in_array($key, ['mode', 'action', 'requestId'])) {
                        $response->setData($key, $val);
                    }
                }
            } else {
                $response->setError('invalid_response', 'Handler returned invalid response');
            }

            return $response->source();
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Unhandled exception in WS handler', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'Trace' => $e->getTraceAsString(),
                'fd' => $frame->fd,
                'payload' => (array)$frame->data
            ]);

            $response->setError('exception', "Server error");
            return $response->source();
        }
    }
}
