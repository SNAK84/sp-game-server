<?php

namespace SPGame\Game\Repositories;

use Swoole\Table;
use SPGame\Core\Defaults;
use SPGame\Core\Database;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Services\QueuesServices;


enum MessageType: int
{
    case Build          = 1;    // Постройки
    case System         = 2;    // Системные
    case News           = 3;    // Новости
    case Players        = 11;   // Игроки
    case Alliance       = 12;   // Альянс
    case Spy            = 21;   // Шпионаж
    case Fights         = 22;   // Битвы
    case Transport      = 23;   // Транспорт
    case Expeditions    = 24;   // Экспедиции
    case All            = 99;   // Все сообщения

    public function toString(): string
    {
        return match ($this) {
            self::Build         => 'Build',
            self::System        => 'System',
            self::News          => 'News',
            self::Players       => 'Players',
            self::Alliance      => 'Alliance',
            self::Spy           => 'Spy',
            self::Fights        => 'Fights',
            self::Transport     => 'Transport',
            self::Expeditions   => 'Expeditions',
            self::All           => 'All',
        };
    }
}

class Messages extends BaseRepository
{
    public const Type = MessageType::class;
    public const RETENTION_SECONDS = 7 * 86400;
    private const INLINE_BYTES = 256;
    private const BODY_FIELDS = ['from', 'subject', 'text', 'sample', 'data'];
    private static ?SharedBlobStore $bodies = null;
    private static ?Table $packedTable = null;
    private static ?\Swoole\Atomic\Long $expiryCursor = null;
    
    protected static string $className = 'Messages';
    protected static string $tableName = 'messages';
    protected static int $tableSize = 1024 * 8;

    /** @var Table Основная таблица */
    protected static Table $table;

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** @var Table */
    protected static Table $syncTable;

    /**
     * Схема таблицы сообщений
     */
    protected static array $tableSchema = [
        'columns' => [
            'id'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'from_id'  => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'from'     => ['swoole' => [Table::TYPE_STRING, 64], 'sql' => 'VARCHAR(64) NOT NULL', 'default' => ''],
            'to_id'    => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'type'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => "INT UNSIGNED NOT NULL", 'default' => 0],
            'subject'  => ['swoole' => [Table::TYPE_STRING, 128], 'sql' => "VARCHAR(128) NOT NULL", 'default' => ''],
            'text'     => ['swoole' => [Table::TYPE_STRING, 1024], 'sql' => "TEXT NOT NULL", 'default' => ''],
            'sample'   => ['swoole' => [Table::TYPE_STRING, 64], 'sql' => "VARCHAR(64) NOT NULL", 'default' => ''],
            'data'     => ['swoole' => [Table::TYPE_STRING, 8192], 'sql' => "TEXT NOT NULL", 'default' => ''],
            'time'     => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => Defaults::MICROTIME],
            'read'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) DEFAULT 0', 'default' => 0], // прочитано или нет
        ],
        'indexes' => [
            ['type' => 'PRIMARY', 'fields' => ['id']],
            ['type' => 'INDEX', 'name' => 'idx_from', 'fields' => ['from_id']],
            ['type' => 'INDEX', 'name' => 'idx_to', 'fields' => ['to_id']],
            ['type' => 'INDEX', 'name' => 'idx_type', 'fields' => ['type']],
            ['type' => 'INDEX', 'name' => 'idx_time', 'fields' => ['time']],
            ['type' => 'INDEX', 'name' => 'idx_read', 'fields' => ['read']],
        ],
    ];

    /** Индексы Swoole */
    protected static array $indexes = [
        'to'            => ['key' => ['to_id'], 'Unique' => false],               // сообщения к пользователю
        'to_read'       => ['key' => ['to_id', 'read'], 'Unique' => false],       // выборка непрочитанных сообщений
        'to_type'       => ['key' => ['to_id', 'type'], 'Unique' => false],       // сообщения к пользователю по типу
        'expiry_minute' => ['key' => ['expiry_minute'], 'Unique' => false],
    ];

    public static function runtimeColumns(): array
    {
        $columns = parent::runtimeColumns();
        foreach (self::BODY_FIELDS as $field) unset($columns[$field]);
        return $columns + ['body_inline' => [Table::TYPE_STRING, self::INLINE_BYTES],
            'body_id' => [Table::TYPE_INT, 8], 'body_size' => [Table::TYPE_INT, 8],
            'expiry_minute' => [Table::TYPE_INT, 8]];
    }

    public static function overflowBuckets(): int { return max(1024, intdiv(static::runtimeLayout()['mainBuckets'], 8)); }

    public static function init(?RepositorySaver $saver = null): void
    {
        self::$packedTable = null;
        self::$bodies = new SharedBlobStore(self::overflowBuckets());
        self::$expiryCursor = new \Swoole\Atomic\Long((int)floor((microtime(true) - self::RETENTION_SECONDS) / 60));
        parent::init($saver);
        self::$packedTable = static::$table;
    }

    protected static function loadingFilter(): array
    {
        return ['`time` > ?', [microtime(true) - self::RETENTION_SECONDS]];
    }

    private static function packed(): bool
    {
        // Старые диагностические Table без init остаются логическими копиями.
        return self::$bodies !== null && (self::$packedTable === static::$table || self::$packedTable === null);
    }

    private static function unpackRow(array $row): array
    {
        if (!array_key_exists('body_inline', $row)) return $row;
        $body = $row['body_id'] === 0 ? $row['body_inline'] : self::$bodies->get($row['body_id'], $row['body_size']);
        if (strlen($body) !== $row['body_size'] || $body === '') throw new \RuntimeException('Повреждено тело сообщения.');
        $json = $body[0] === "\x01" ? gzinflate(substr($body, 1), 65536) : substr($body, 1);
        if (!is_string($json)) throw new \RuntimeException('Не распаковано тело сообщения.');
        $strings = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($strings) || count($strings) !== count(self::BODY_FIELDS)) throw new \RuntimeException('Повреждены поля сообщения.');
        return static::castRowToSchema($row + array_combine(self::BODY_FIELDS, $strings));
    }

    public static function add(array $data, bool $sync = true): ?int
    {
        return static::guarded(fn() => parent::add($data, $sync));
    }

    public static function update(array $data): void
    {
        if (!self::packed()) { parent::update($data); return; }
        static::guarded(static function () use ($data): void {
            $id = (int)($data['id'] ?? 0);
            MiningCheckpoints::assertMutable(static::$tableName, $id);
            $before = self::findExistingById($id);
            if ($before === null) return;
            $after = array_merge($before, static::castRowToSchema($data));
            if ($after !== $before) static::writeRuntimeRow($after, $before, true, false);
        });
    }

    protected static function writeRuntimeRow(array $row, ?array $before, bool $sync, bool $adding, ?int $runtimeId = null, ?array $syncMarker = null): void
    {
        if (!self::packed()) { parent::writeRuntimeRow($row, $before, $sync, $adding, $runtimeId, $syncMarker); return; }
        static::guarded(static function () use ($row, $before, $sync, $adding, $runtimeId, $syncMarker): void {
            static::assertCheckpointValues($row, false);
            $old = static::$table->get((string)$row['id']) ?: null;
            if (($old === null ? null : self::unpackRow($old)) !== $before) throw new \RuntimeException('Сообщение изменилось до записи.');
            $physical = array_diff_key($row, array_flip(self::BODY_FIELDS));
            $strings = array_map(static fn(string $field): string => $row[$field], self::BODY_FIELDS);
            $reuse = $before !== null && $strings === array_map(static fn(string $field): string => $before[$field], self::BODY_FIELDS);
            if ($reuse) $body = array_intersect_key($old, array_flip(['body_inline', 'body_id', 'body_size']));
            else {
                $json = json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $compressed = gzdeflate($json, 1);
                $payload = is_string($compressed) && strlen($compressed) < strlen($json) ? "\x01" . $compressed : "\x00" . $json;
                $inline = strlen($payload) <= self::INLINE_BYTES;
                $body = ['body_inline' => $inline ? $payload : '', 'body_id' => $inline ? 0 : self::$bodies->put($payload), 'body_size' => strlen($payload)];
            }
            $physical += $body + ['expiry_minute' => (int)floor($row['time'] / 60)];
            try { parent::writeRuntimeRow($physical, $old, $sync, $adding, $runtimeId, $syncMarker); }
            catch (\Throwable $error) {
                if (!$reuse) self::$bodies->release($body['body_id'], $body['body_size']);
                throw $error;
            }
            if (!$reuse && $old !== null) self::$bodies->release($old['body_id'], $old['body_size']);
            $minute = $physical['expiry_minute'];
            while (($cursor = self::$expiryCursor->get()) > $minute && !self::$expiryCursor->cmpset($cursor, $minute)) { }
        });
    }

    public static function findExistingById(int $id): ?array
    {
        return static::guarded(static function () use ($id): ?array {
            $row = parent::findExistingById($id);
            return $row === null ? null : self::unpackRow($row);
        });
    }

    public static function findById(int $id): ?array { return self::findExistingById($id); }

    public static function findByIndex(string $indexKey, array|string $val): ?array
    {
        return static::guarded(static function () use ($indexKey, $val): ?array {
            $rows = parent::findByIndex($indexKey, $val);
            return $rows === null ? null : array_map(self::unpackRow(...), $rows);
        });
    }

    public static function getAll(): ?array
    {
        return static::guarded(static function (): ?array {
            $rows = parent::getAll();
            return $rows === null ? null : array_map(self::unpackRow(...), $rows);
        });
    }

    public static function delete(array $data): void
    {
        static::guarded(static function () use ($data): void {
            $old = static::$table->get((string)($data['id'] ?? 0));
            parent::delete($data);
            if ($old && isset($old['body_id'])) self::$bodies->release($old['body_id'], $old['body_size']);
        });
    }

    public static function discardUncommittedRow(array $row): void
    {
        static::guarded(static function () use ($row): void {
            if (static::$table->exists((string)$row['id'])) self::delete($row);
            else parent::discardUncommittedRow($row + ['expiry_minute' => (int)floor(($row['time'] ?? 0) / 60)]);
        });
    }

    public static function restoreDeletedRow(array $row): void
    {
        static::guarded(fn() => parent::restoreDeletedRow($row));
    }

    public static function unreadCount(int $userId): int
    {
        return static::guarded(fn() => count(parent::findByIndex('to_read', [$userId, 0]) ?? []));
    }

    /** Сортируем компактные заголовки; распаковываем только 20 показанных тел. */
    public static function pageForUser(int $userId, int $page, MessageType $category): array
    {
        return static::guarded(static function () use ($userId, $page, $category): array {
            $counts = [];
            foreach (MessageType::cases() as $type) $counts[$type->value] = ['total' => 0, 'unread' => 0];
            $filtered = [];
            foreach (parent::findByIndex('to', [$userId]) ?? [] as $row) {
                $unread = (int)$row['read'] === 0 ? 1 : 0;
                $counts[99]['total']++;
                $counts[99]['unread'] += $unread;
                if ((int)$row['type'] !== 99 && isset($counts[$row['type']])) {
                    $counts[$row['type']]['total']++;
                    $counts[$row['type']]['unread'] += $unread;
                }
                if ($category === MessageType::All || (int)$row['type'] === $category->value) $filtered[] = $row;
            }
            usort($filtered, static fn(array $a, array $b): int => ($b['time'] <=> $a['time']) ?: ($b['id'] <=> $a['id']));
            $total = count($filtered);
            $pages = max(1, (int)ceil($total / 20));
            $page = min(max(1, $page), $pages);
            return ['allMessages' => array_map(self::unpackRow(...), array_slice($filtered, ($page - 1) * 20, 20)),
                'messageCounts' => $counts, 'pagination' => ['page' => $page, 'size' => 20, 'pages' => $pages, 'total' => $total, 'category' => $category->value]];
        });
    }

    /** Не более заданной пачки и 20мс; минутные корзины дают естественный порядок TTL. */
    public static function purgeExpired(float $now, int $limit = 256): array
    {
        if (!self::packed()) return [];
        return static::guarded(static function () use ($now, $limit): array {
            $started = hrtime(true);
            $last = (int)floor(($now - self::RETENTION_SECONDS) / 60) - 1;
            $owners = []; $deleted = 0; $buckets = 0;
            while (($minute = self::$expiryCursor->get()) <= $last && $deleted < $limit && ++$buckets <= 64 && hrtime(true) - $started < 20000000) {
                $key = static::buildIndexKey([$minute]);
                $head = static::$indexTables['expiry_minute']->get($key);
                if ($head === false) { self::$expiryCursor->set($minute + 1); continue; }
                $ids = array_values(unpack('J*', substr($head['ids'], 2)));
                foreach ($ids as $id) {
                    $row = static::$table->get((string)$id);
                    if ($row === false) throw new \RuntimeException('Повреждён индекс срока сообщения.');
                    $owners[$row['to_id']] = true;
                    self::delete(['id' => $id]);
                    if (++$deleted >= $limit || hrtime(true) - $started >= 20000000) break;
                }
            }
            return array_keys($owners);
        });
    }

    public static function purgeExpiredSql(float $now): void
    {
        RepositoryGuard::run(static::$className . ':save', fn() => Database::getInstance()->query(
            'DELETE FROM messages WHERE `time` <= ? ORDER BY `time` LIMIT 1024', [$now - self::RETENTION_SECONDS]));
    }
}
