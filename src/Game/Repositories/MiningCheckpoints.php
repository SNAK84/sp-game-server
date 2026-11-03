<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\{Database, Environment};

/**
 * Долговечный журнал добычи, НЕ второе runtime-хранилище экономики.
 * Одна текущая операция на siteId, отдельная ограниченная запись на сущность.
 * Публикуется только полный manifest; незавершённая подготовка не даёт эффектов.
 */
final class MiningCheckpoints
{
    public const MAX_ENTRIES = 1024;
    public const MAX_ENTRY_BYTES = 8192;
    public const MAX_METADATA_BYTES = 2048;
    public const LOCK_BUSY = 423;
    public const FIELDS = [
        'site' => ['metal_stock', 'crystal_stock', 'deuterium_stock', 'resource_update_time'],
        'knowledge' => ['reserve_time', 'state', 'coverage', 'observed_at'],
        'survey' => ['state'],
        'fleet' => ['fuel_current', 'fuel_total', 'resource_metal', 'resource_crystal', 'resource_deuterium',
            'resource_credit', 'resource_doubloon', 'experience', 'current_position_time',
            'home_planet_id', 'home_site_id', 'fallback_planet_id',
            'base_fuel_time', 'base_fuel_rate', 'base_fuel_reserve', 'base_fuel_speed'],
        'command' => ['execute_time', 'executed', 'params', 'command_type'],
        'ships' => [], // Одна ограниченная часть на группу, не весь широкий fleets_ships.
    ];
    /** @var array<int,string> Владелец блокировки: PID и coroutine ID. */
    private static array $held = [];
    /** @var array<string,array{handle:resource,exclusive:bool}> Барьер только для checkpoint добычи. */
    private static array $worldLocks = [];
    /** Защита участников после ошибки в том же tick (scheduler может поймать исключение). */
    private static array $protectedRows = [];
    private static array $protectedSites = [];
    private static ?string $writingContext = null;
    private const TABLES = ['site' => 'orbital_resource_sites', 'knowledge' => 'resource_surveys',
        'fleet' => 'fleets', 'command' => 'fleet_commands', 'ships' => 'fleets_ships', 'survey' => 'survey_results'];

    public static function protect(int $siteId, array $entries): void
    {
        self::assertLocked($siteId);
        self::$protectedSites[$siteId] = $entries;
        foreach ($entries as $entry) self::$protectedRows[self::TABLES[$entry['kind']]][$entry['id']] = true;
    }

    public static function unprotect(int $siteId): void
    {
        self::assertLocked($siteId);
        foreach (self::$protectedSites[$siteId] ?? [] as $entry) unset(self::$protectedRows[self::TABLES[$entry['kind']]][$entry['id']]);
        unset(self::$protectedSites[$siteId]);
    }

    public static function protectedSites(): array { return array_keys(self::$protectedSites); }

    public static function isProtected(string $table, int $id): bool
    {
        return isset(self::$protectedRows[$table][$id]) && self::$writingContext !== self::context();
    }

    public static function assertMutable(string $table, int $id): void
    {
        if (self::isProtected($table, $id)) throw new \LogicException('Сущность ожидает восстановления checkpoint добычи.');
    }

    /** Узкий допуск самого roll-forward, не разрешение обычным командам менять prepared. */
    public static function writing(callable $operation): mixed
    {
        if (empty(self::$worldLocks[self::context()]['exclusive'])) {
            throw new \LogicException('Применение checkpoint требует exclusive-барьера.');
        }
        $previous = self::$writingContext;
        self::$writingContext = self::context();
        try { return $operation(); }
        finally { self::$writingContext = $previous; }
    }

    /**
     * Обычные обработчики держат SH и не блокируют друг друга. Применение
     * checkpoint требует EX: ни страница, ни saver, ни tick не увидят полурезультат.
     * Вложенный вызов того же coroutine не создаёт второй flock-дескриптор.
     */
    public static function worldLocked(callable $operation, bool $exclusive = false): mixed
    {
        $context = self::context();
        if (isset(self::$worldLocks[$context])) {
            $lock = &self::$worldLocks[$context];
            $upgrade = $exclusive && !$lock['exclusive'];
            if ($upgrade && !flock($lock['handle'], LOCK_EX | LOCK_NB)) {
                // flock upgrade на Linux может отпустить прежний SH даже
                // при отказе EX. Внешний обработчик должен сохранить барьер.
                flock($lock['handle'], LOCK_SH);
                throw new \RuntimeException('Checkpoint добычи ожидает завершения текущих обработчиков.', self::LOCK_BUSY);
            }
            if ($upgrade) $lock['exclusive'] = true;
            try { return $operation(); }
            finally {
                if ($upgrade) {
                    flock($lock['handle'], LOCK_SH);
                    $lock['exclusive'] = false;
                }
            }
        }
        $namespace = substr(hash('sha256', Environment::require('DB_NAME')), 0, 16);
        $handle = fopen(dirname(__DIR__, 3) . '/logs/.mining-' . $namespace . '-world.lock', 'c');
        if ($handle === false) throw new \RuntimeException('Не удалось открыть барьер checkpoint добычи.');
        if (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Применяется checkpoint добычи. Повторите действие.', self::LOCK_BUSY);
        }
        self::$worldLocks[$context] = ['handle' => $handle, 'exclusive' => $exclusive];
        try { return $operation(); }
        finally { unset(self::$worldLocks[$context]); flock($handle, LOCK_UN); fclose($handle); }
    }

    public static function init(): void
    {
        $db = Database::getInstance();
        $db->query("CREATE TABLE IF NOT EXISTS mining_checkpoints (
            site_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            phase VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'applied',
            checkpoint_time DOUBLE NOT NULL DEFAULT 0,
            entry_count INT UNSIGNED NOT NULL DEFAULT 0,
            manifest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            metadata TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS mining_checkpoint_rows (
            site_id BIGINT UNSIGNED NOT NULL,
            token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entry_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload TEXT NOT NULL,
            PRIMARY KEY (site_id, token, entry_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Блокировка между процессами на общем bind-mount приложения, не static bool.
     * Неблокирующая: занятый объект должен быть повторён игровым scheduler.
     * Все контейнеры/процессы записи обязаны видеть тот же каталог logs.
     */
    public static function locked(int $siteId, callable $operation): mixed
    {
        self::positive($siteId);
        $shard = $siteId % 64;
        $namespace = substr(hash('sha256', Environment::require('DB_NAME')), 0, 16);
        $path = dirname(__DIR__, 3) . '/logs/.mining-' . $namespace . '-' . $shard . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) throw new \RuntimeException('Не удалось открыть блокировку добычи.');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Ресурсный объект уже обрабатывается.');
        }
        self::$held[$siteId] = self::context();
        try { return $operation(); }
        finally { unset(self::$held[$siteId]); flock($handle, LOCK_UN); fclose($handle); }
    }

    /** Диагностический заголовок; изменения требуют locked() и проверки revision. */
    public static function head(int $siteId): ?array
    {
        self::positive($siteId);
        return Database::getInstance()->fetchOne('SELECT * FROM mining_checkpoints WHERE site_id=?', [$siteId]);
    }

    public static function pending(): array
    {
        return Database::getInstance()->fetchAll("SELECT site_id FROM mining_checkpoints WHERE phase='prepared' ORDER BY site_id");
    }

    /**
     * Сохранить намерение ДО изменения runtime. Повтор того же token/payload
     * возвращает прежнюю операцию. Новый token требует прежнюю revision.
     */
    public static function prepare(int $siteId, int $ownerId, int $expectedRevision, string $token,
        float $time, array $entries, array $metadata = []): array
    {
        self::assertLocked($siteId);
        self::positive($ownerId); self::token($token);
        if ($expectedRevision < 0 || $expectedRevision >= PHP_INT_MAX || !is_finite($time) || $time < 0) {
            throw new \InvalidArgumentException('Некорректная версия или время checkpoint добычи.');
        }
        $encoded = self::encodeEntries($entries);
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'site' && $entry['id'] !== $siteId
                || $entry['kind'] === 'knowledge' && (($entry['guard']['site_id'] ?? null) !== $siteId
                    || ($entry['guard']['owner_id'] ?? null) !== $ownerId)
                || in_array($entry['kind'], ['fleet', 'ships'], true) && ($entry['guard']['owner_id'] ?? null) !== $ownerId) {
                throw new \InvalidArgumentException('Сущность checkpoint принадлежит другому объекту/владельцу.');
            }
        }
        $meta = self::json($metadata);
        if (strlen($meta) > self::MAX_METADATA_BYTES) throw new \OverflowException('Metadata checkpoint добычи слишком велика.');
        $next = ['site_id' => $siteId, 'revision' => $expectedRevision + 1, 'owner_id' => $ownerId,
            'token' => $token, 'phase' => 'prepared', 'checkpoint_time' => $time,
            'entry_count' => count($encoded), 'metadata' => $meta];
        $next['manifest'] = self::manifest($next, $encoded);
        $old = self::head($siteId);
        if ($old !== null && $old['token'] === $token) {
            foreach (['revision', 'owner_id', 'checkpoint_time', 'entry_count', 'metadata', 'manifest'] as $key) {
                if ($old[$key] !== $next[$key]) throw new \LogicException('Повтор checkpoint изменяет уже подготовленный результат.');
            }
            return self::load($siteId);
        }
        if ($old !== null && ($old['phase'] !== 'applied' || (int)$old['revision'] !== $expectedRevision)) {
            throw new \LogicException('Есть незавершённый или более новый checkpoint добычи.');
        }
        if ($old === null && $expectedRevision !== 0 || $time < (float)($old['checkpoint_time'] ?? 0)) {
            throw new \LogicException('Checkpoint добычи устарел.');
        }
        $db = Database::getInstance();
        if ($old === null) {
            $db->query("INSERT INTO mining_checkpoints (site_id, metadata) VALUES (?, '{}')", [$siteId]);
            $old = self::head($siteId);
        }
        // Старый applied-заголовок остаётся действующим до полной записи новых
        // частей. После сбоя здесь остаётся только неиспользуемая подготовка.
        $db->query('DELETE FROM mining_checkpoint_rows WHERE site_id=? AND token<>?', [$siteId, $old['token']]);
        foreach ($encoded as $key => $row) {
            $db->query('INSERT INTO mining_checkpoint_rows (site_id,token,entry_key,digest,payload) VALUES (?,?,?,?,?)',
                [$siteId, $token, $key, $row['digest'], $row['payload']]);
        }
        $stored = self::readParts($siteId, $token);
        if ($stored !== $encoded) throw new \RuntimeException('Не подтверждена полная запись частей checkpoint добычи.');
        $db->query("UPDATE mining_checkpoints SET revision=?,owner_id=?,token=?,phase='prepared',
            checkpoint_time=?,entry_count=?,manifest=?,metadata=? WHERE site_id=? AND revision=? AND phase='applied'",
            // PDO::execute иначе приводит float через precision=14 и теряет
            // микросекунды раньше MySQL. JSON хранит round-trip представление.
            [$next['revision'], $ownerId, $token, json_encode($time, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                $next['entry_count'], $next['manifest'], $meta, $siteId, $expectedRevision]);
        $published = self::load($siteId);
        if ($published['head']['token'] !== $token || $published['head']['manifest'] !== $next['manifest']) {
            throw new \RuntimeException('Не подтверждена публикация checkpoint добычи.');
        }
        return $published;
    }

    /** Полный снимок, проверенный по числу частей, каждому digest и общему manifest. */
    public static function load(int $siteId): array
    {
        self::assertLocked($siteId);
        $head = self::head($siteId);
        if ($head === null || !in_array($head['phase'], ['prepared', 'applied'], true)) {
            throw new \LogicException('Checkpoint добычи отсутствует или повреждён.');
        }
        if ((int)$head['revision'] === 0) {
            if ($head['phase'] !== 'applied' || $head['token'] !== '' || (int)$head['entry_count'] !== 0
                || $head['manifest'] !== '' || (int)$head['owner_id'] !== 0) {
                throw new \LogicException('Повреждён исходный заголовок checkpoint добычи.');
            }
            return ['head' => $head, 'entries' => []];
        }
        if ((int)$head['entry_count'] < 1 || (int)$head['entry_count'] > self::MAX_ENTRIES
            || strlen($head['metadata']) > self::MAX_METADATA_BYTES || (int)$head['owner_id'] <= 0
            || !is_finite((float)$head['checkpoint_time']) || (float)$head['checkpoint_time'] < 0) {
            throw new \LogicException('Некорректный заголовок checkpoint добычи.');
        }
        self::token($head['token']);
        $parts = self::readParts($siteId, $head['token']);
        if (count($parts) !== (int)$head['entry_count'] || self::manifest($head, $parts) !== $head['manifest']) {
            throw new \LogicException('Неполный или повреждённый manifest добычи.');
        }
        return ['head' => $head, 'entries' => self::decodeEntries($parts)];
    }

    /** Только ПОСЛЕ проверки durable after всех игровых строк, не после одного runtime write. */
    public static function acknowledge(int $siteId, string $token): array
    {
        self::assertLocked($siteId); self::token($token);
        $batch = self::load($siteId);
        if ($batch['head']['token'] !== $token) throw new \LogicException('Подтверждается другая операция добычи.');
        $db = Database::getInstance();
        if ($batch['head']['phase'] !== 'applied') {
            $db->query("UPDATE mining_checkpoints SET phase='applied' WHERE site_id=? AND token=? AND phase='prepared'", [$siteId, $token]);
        }
        $result = self::load($siteId);
        if ($result['head']['phase'] !== 'applied') throw new \RuntimeException('Не подтверждён checkpoint добычи.');
        // История тиков не накапливается. Текущие parts нужны следующему checkpoint/retry.
        $db->query('DELETE FROM mining_checkpoint_rows WHERE site_id=? AND token<>?', [$siteId, $token]);
        return $result;
    }

    /** Чистый codec: ограничения проверяются ДО первой записи журнала. */
    public static function encodeEntries(array $entries): array
    {
        if ($entries === [] || count($entries) > self::MAX_ENTRIES) throw new \OverflowException('Недопустимое число сущностей checkpoint добычи.');
        $parts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) throw new \InvalidArgumentException('Ожидалась сущность checkpoint добычи.');
            $kind = $entry['kind'] ?? '';
            if (!is_string($kind) || !isset(self::FIELDS[$kind])
                || array_diff(array_keys($entry), $kind === 'ships'
                    ? ['kind', 'id', 'before', 'after', 'guard', 'group'] : ['kind', 'id', 'before', 'after', 'guard'])
                || !is_int($entry['id'] ?? null) || $entry['id'] <= 0
                || !is_array($entry['before'] ?? null) || !is_array($entry['after'] ?? null)
                || !is_array($entry['guard'] ?? null) || $entry['guard'] === []) {
                throw new \InvalidArgumentException('Некорректная сущность checkpoint добычи.');
            }
            $keys = array_keys($entry['before']); $afterKeys = array_keys($entry['after']);
            sort($keys); sort($afterKeys);
            $allowed = self::FIELDS[$kind];
            if ($kind === 'ships') {
                if (!is_string($entry['group'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $entry['group'])
                    || ($entry['guard']['fleet_id'] ?? null) !== $entry['id']) {
                    throw new \InvalidArgumentException('Некорректная группа кораблей checkpoint.');
                }
                $allowed = array_map(static fn($suffix) => $entry['group'] . '_' . $suffix,
                    ['count', 'experience', 'damage', 'morale', 'status']);
                if (count($keys) !== count($allowed)) throw new \InvalidArgumentException('Неполная группа кораблей checkpoint.');
            }
            if ($keys === [] || $keys !== $afterKeys || array_diff($keys, $allowed)
                || array_intersect($keys, array_keys($entry['guard']))) {
                throw new \InvalidArgumentException('Недопустимые поля изменения добычи.');
            }
            foreach (['before', 'after', 'guard'] as $side) foreach ($entry[$side] as $field => $value) {
                if (!is_string($field) || !is_scalar($value) || is_float($value) && !is_finite($value)) {
                    throw new \InvalidArgumentException('Checkpoint должен хранить плоские значения серверной строки.');
                }
                if ($side !== 'guard' && !is_string($value) && ((!is_int($value) && !is_float($value)) || $value < 0)) {
                    throw new \InvalidArgumentException('Некорректное значение добычи.');
                }
                if ($side !== 'guard' && in_array($field, ['state', 'params'], true)) {
                    if (!is_string($value) || strlen($value) > ($kind === 'survey' ? 4096 : 2048)
                        || !is_array(json_decode($value, true, 512, JSON_THROW_ON_ERROR))) {
                        throw new \InvalidArgumentException('Некорректный JSON checkpoint добычи.');
                    }
                } elseif ($side !== 'guard' && !is_int($value) && !is_float($value)) {
                    throw new \InvalidArgumentException('Ожидалось числовое поле добычи.');
                }
            }
            $key = self::entryKey($entry);
            if (isset($parts[$key])) throw new \InvalidArgumentException('Сущность checkpoint добычи повторяется.');
            $payload = self::json($entry);
            if (strlen($payload) > self::MAX_ENTRY_BYTES) throw new \OverflowException('Сущность checkpoint добычи слишком велика.');
            $parts[$key] = ['digest' => hash('sha256', $payload), 'payload' => $payload];
        }
        ksort($parts, SORT_STRING);
        return $parts;
    }

    public static function decodeEntries(array $parts): array
    {
        $entries = [];
        foreach ($parts as $key => $part) {
            if (!is_string($part['payload'] ?? null) || strlen($part['payload']) > self::MAX_ENTRY_BYTES
                || hash('sha256', $part['payload']) !== ($part['digest'] ?? null)) {
                throw new \LogicException('Повреждена часть checkpoint добычи.');
            }
            $entry = json_decode($part['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || $key !== self::entryKey($entry)) {
                throw new \LogicException('Неверный ключ части checkpoint добычи.');
            }
            $entries[] = $entry;
        }
        if (self::encodeEntries($entries) !== $parts) throw new \LogicException('Нарушен канонический формат checkpoint добычи.');
        return $entries;
    }

    private static function entryKey(array $entry): string
    {
        $key = ($entry['kind'] ?? '') . ':' . ($entry['id'] ?? '');
        return ($entry['kind'] ?? '') === 'ships'
            ? $key . ':' . substr(hash('sha256', (string)($entry['group'] ?? '')), 0, 32) : $key;
    }

    private static function readParts(int $siteId, string $token): array
    {
        $parts = [];
        foreach (Database::getInstance()->fetchAll('SELECT entry_key,digest,payload FROM mining_checkpoint_rows
            WHERE site_id=? AND token=? ORDER BY entry_key LIMIT ' . (self::MAX_ENTRIES + 1), [$siteId, $token]) as $row) {
            $parts[$row['entry_key']] = ['digest' => $row['digest'], 'payload' => $row['payload']];
        }
        return $parts;
    }

    private static function manifest(array $head, array $parts): string
    {
        return hash('sha256', self::json(['site' => (int)$head['site_id'], 'owner' => (int)$head['owner_id'],
            'revision' => (int)$head['revision'], 'token' => $head['token'], 'time' => (float)$head['checkpoint_time'],
            'metadata' => $head['metadata'], 'parts' => array_map(static fn($row) => $row['digest'], $parts)]));
    }

    private static function json(array $value): string
    {
        $canonical = static function (mixed $item) use (&$canonical): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($canonical, $item);
        };
        return json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function context(): string
    {
        return getmypid() . ':' . (class_exists(\Swoole\Coroutine::class, false) ? \Swoole\Coroutine::getCid() : -1);
    }

    private static function assertLocked(int $siteId): void
    {
        if ((self::$held[$siteId] ?? null) !== self::context()) throw new \LogicException('Checkpoint добычи требует блокировки объекта.');
    }

    private static function positive(int $id): void
    {
        if ($id <= 0) throw new \InvalidArgumentException('ID checkpoint добычи должен быть положительным.');
    }

    private static function token(string $token): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $token)) throw new \InvalidArgumentException('Некорректный token checkpoint добычи.');
    }
}
