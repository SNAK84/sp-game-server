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
    private static int $capacity;

    public static function init(int $size = 2048): void
    {
        self::$capacity = $size;
        self::$connections = new Table($size, 1.0);

        // Определяем колонки
        self::$connections->column('fd', Table::TYPE_INT, 4);
        self::$connections->column('ip', Table::TYPE_STRING, 45); // IPv6
        self::$connections->column('port', Table::TYPE_INT, 4);
        self::$connections->column('account', Table::TYPE_INT, 11); // JSON или ID
        self::$connections->column('galaxy', Table::TYPE_INT, 4);
        self::$connections->column('system', Table::TYPE_INT, 4);
        self::$connections->column('cabinet_checked_at', Table::TYPE_INT, 8);
        self::$connections->column('protocol_version', Table::TYPE_INT, 1);
        self::$connections->column('connection_id', Table::TYPE_STRING, 32);
        self::$connections->column('cabinet_session_id', Table::TYPE_STRING, 36);
        self::$connections->column('v2_sequence', Table::TYPE_INT, 8);
        self::$connections->column('opened_at', Table::TYPE_INT, 8);
        self::$connections->column('rate_second', Table::TYPE_INT, 8);
        self::$connections->column('rate_count', Table::TYPE_INT, 8);

        if (!self::$connections->create()) throw new \RuntimeException('Не создан реестр подключений.');
    }

    public static function set(int $fd, $ip, $port, $account): bool
    {
        if (!self::$connections->exists((string)$fd) && self::$connections->count() >= self::$capacity) return false;
        return self::$connections->set($fd, [
            'fd'      => $fd,
            'ip'      => $ip,
            'port'    => $port,
            'account' => $account ?? null,
            'galaxy'  => 0,
            'system'  => 0,
            'protocol_version' => 0,
            'connection_id' => bin2hex(random_bytes(16)),
            'cabinet_session_id' => '',
            'v2_sequence' => 0,
            'opened_at' => time(), 'rate_second' => time(), 'rate_count' => 0,
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

    /** Один network worker; счётчик меняет только поля своего окна. */
    public static function allowRequest(int $fd, int $now): bool
    {
        $row = self::$connections->get((string)$fd);
        if (!$row) return false;
        if ((int)$row['rate_second'] !== $now) {
            return self::$connections->set((string)$fd, ['rate_second' => $now, 'rate_count' => 1]);
        }
        $count = self::$connections->incr((string)$fd, 'rate_count');
        return $count !== false && $count <= ((int)$row['account'] > 0 ? 240 : 20);
    }

    public static function expiredUnauthenticated(int $now): array
    {
        $fds = [];
        foreach (self::$connections as $row) if (!(int)$row['account'] && (int)$row['opened_at'] < $now - 20) $fds[] = (int)$row['fd'];
        return $fds;
    }

    public static function getProtocolVersion(int $fd): int
    {
        return (int)(self::$connections->get((string)$fd, 'protocol_version') ?: 0);
    }

    public static function setProtocolVersion(int $fd, int $version): void
    {
        if ($version !== 2 || (self::getProtocolVersion($fd) !== 0 && self::getProtocolVersion($fd) !== $version)) {
            throw new \LogicException('Версия соединения уже выбрана.');
        }
        if (!self::$connections->set((string)$fd, ['protocol_version' => $version])) throw new \RuntimeException('Не сохранена версия соединения.');
    }

    public static function getConnectionId(int $fd): string
    {
        return (string)(self::$connections->get((string)$fd, 'connection_id') ?: '');
    }

    public static function setCabinetSessionId(int $fd, string $sessionId): void
    {
        if (!self::$connections->set((string)$fd, ['cabinet_session_id' => $sessionId])) throw new \RuntimeException('Не сохранён контекст сессии.');
    }

    public static function getCabinetSessionId(int $fd): string
    {
        return (string)(self::$connections->get((string)$fd, 'cabinet_session_id') ?: '');
    }

    public static function nextV2Sequence(int $fd): string
    {
        $sequence = self::$connections->incr((string)$fd, 'v2_sequence');
        if ($sequence === false) throw new \RuntimeException('Не обновлён номер снимка.');
        return (string)$sequence;
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

    public static function getAllFds(): array
    {
        $fds = [];
        foreach (self::$connections as $row) {
            $fds[] = $row['fd'];
        }
        return $fds;
    }

    public static function setAccount(int $fd, ?int $account): void
    {
        if ($row = self::$connections->get($fd)) {
            self::$connections->set($fd, ['account' => $account ?? 0]);
        }
    }

    public static function getAccount(int $fd): ?int
    {
        $row=self::$connections->get($fd);
        if (\SPGame\Game\Services\CabinetWorldService::enabled() && (int)($row['cabinet_checked_at']??0)<time()-5) return null;
        return self::getRawAccount($fd);
    }

    public static function markCabinetSession(int $fd): void
    {
        if ($row=self::$connections->get($fd)) {
            self::$connections->set($fd,['cabinet_checked_at' => time()]);
        }
    }

    public static function getRawAccount(int $fd): ?int
    {
        if ($row = self::$connections->get($fd)) {
            return $row['account'] !== '' ? $row['account'] : null;
        }
        return null;
    }

    public static function setGalaxyView(int $fd, int $galaxy, int $system): void
    {
        if ($row = self::$connections->get((string)$fd)) {
            self::$connections->set((string)$fd, ['galaxy' => $galaxy, 'system' => $system]);
        }
    }

    /** @return array{galaxy:int, system:int}|null */
    public static function getGalaxyView(int $fd): ?array
    {
        $row = self::$connections->get((string)$fd);
        $galaxy = (int)($row['galaxy'] ?? 0);
        $system = (int)($row['system'] ?? 0);
        return $galaxy > 0 && $system > 0 ? compact('galaxy', 'system') : null;
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
}
