<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Game\Services\RepositorySaver;

/** Квитанции и очередь сохраняются одним снимком мира; запросы читают только RAM. */
final class ProtocolCommands
{
    public const RETENTION = 86400;
    public const MAX_AGE = 300;
    public const MAX_RECORD_BYTES = 16384;
    private static RuntimeRecords $records;

    public static function init(?RepositorySaver $saver = null): void
    {
        Database::independent()->query('CREATE TABLE IF NOT EXISTS protocol_commands (
            world_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
            epoch CHAR(32) CHARACTER SET ascii NOT NULL,
            claim CHAR(32) CHARACTER SET ascii NOT NULL,
            operation VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT \'planet.rename\',
            state VARCHAR(16) CHARACTER SET ascii NOT NULL,
            outcome TEXT NOT NULL,
            created_at DOUBLE NOT NULL,
            expires_at DOUBLE NOT NULL,
            PRIMARY KEY (world_id, player_id, request_id), INDEX expires (expires_at)
        ) ENGINE=InnoDB');
        $db = Database::independent();
        if ($db->fetchOne("SHOW COLUMNS FROM protocol_commands LIKE 'operation'") === null) {
            $db->query("ALTER TABLE protocol_commands ADD operation VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT 'planet.rename'");
        }
        if ($db->fetchOne("SHOW INDEX FROM protocol_commands WHERE Key_name='state_epoch'") === null) {
            $db->query('ALTER TABLE protocol_commands ADD INDEX state_epoch (state,epoch,created_at)');
        }
        self::$records = new RuntimeRecords('ProtocolCommands', self::MAX_RECORD_BYTES);
        $cursor = ['', 0, ''];
        do {
            $rows = $db->fetchAll('SELECT * FROM protocol_commands WHERE (expires_at>? OR state=?) AND (world_id,player_id,request_id)>(?,?,?) ORDER BY world_id,player_id,request_id LIMIT 512', [microtime(true), 'accepted', ...$cursor]);
            foreach ($rows as $row) {
                // PlayerQueue уже восстановлена. Сначала загружаем квитанцию,
                // затем помечаем orphan dirty для общего снимка, не replay.
                self::$records->put(self::key($row['world_id'], (int)$row['player_id'], $row['request_id']), $row, false);
                self::reconcilePending($row);
                $cursor = [$row['world_id'], (int)$row['player_id'], $row['request_id']];
            }
        } while (count($rows) === 512);
        $saver?->register(self::class);
    }


    private static function key(string $world, int $player, string $requestId): string
    {
        return $world . ':' . $player . ':' . strtolower($requestId);
    }

    public static function find(string $world, int $player, string $requestId): ?array
    {
        $row = self::$records->get(self::key($world, $player, $requestId));
        return $row !== null && $row['state'] !== 'accepted' && $row['expires_at'] <= microtime(true) ? null : $row;
    }

    public static function reserve(string $world, int $player, string $requestId, string $fingerprint, string $epoch, string $operation = 'planet.rename'): array
    {
        return RepositoryGuard::run('ProtocolCommands:runtime', static function () use ($world, $player, $requestId, $fingerprint, $epoch, $operation): array {
            $old = self::find($world, $player, $requestId);
            if ($old !== null) return [$old, false];
            $now = microtime(true);
            $row = ['world_id' => $world, 'player_id' => $player, 'request_id' => strtolower($requestId),
                'fingerprint' => $fingerprint, 'epoch' => $epoch, 'claim' => bin2hex(random_bytes(16)),
                'operation' => $operation, 'state' => 'accepted', 'outcome' => '{}',
                'created_at' => $now, 'expires_at' => $now + self::RETENTION];
            self::$records->put(self::key($world, $player, $requestId), $row);
            return [$row, true];
        });
    }

    public static function finish(array $row, string $state, array $outcome): array
    {
        if (!in_array($state, ['applied', 'rejected', 'unknown'], true)) throw new \LogicException('Неверный исход команды.');
        return RepositoryGuard::run('ProtocolCommands:runtime', static function () use ($row, $state, $outcome): array {
            $key = self::key($row['world_id'], (int)$row['player_id'], $row['request_id']);
            $before = self::$records->get($key) ?? throw new \RuntimeException('Потерян исход команды.');
            if ($before['state'] !== 'accepted' || !hash_equals($before['claim'], $row['claim'])) return $before;
            try {
                $after = array_replace($before, ['state' => $state, 'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                self::$records->put($key, $after);
            } catch (\JsonException|\OverflowException) {
                // Не оставляем accepted навсегда из-за слишком большого или
                // некодируемого результата. Действие могло выполниться: replay
                // запрещён, клиент сверяет состояние обычным query.
                $after = array_replace($before, ['state' => 'unknown', 'outcome' => '{"reason":"outcome_unavailable"}']);
                self::$records->put($key, $after);
            }
            return $after;
        });
    }

    public static function cleanup(float $now, ?string $epoch = null): void
    {
        // Bounded sweep: accepted проверяются после MAX_AGE, но возраст сам
        // по себе не отменяет ещё ожидающее событие и не разрешает replay.
        self::$records->sweep(static function (array $row) use ($now): bool {
            if ($row['state'] === 'accepted') { self::reconcilePending($row); return false; }
            return $row['expires_at'] < $now;
        }, RuntimeRecords::sweepBudget('ProtocolCommands', 512), $now);
    }

    /** Только под world EX либо при startup после restore PlayerQueue. */
    public static function reconcilePending(array $row): array
    {
        if ($row['state'] !== 'accepted' || PlayerQueue::hasProtocolCommand($row)) return $row;
        return self::finish($row, 'unknown', ['reason' => 'execution_unconfirmed']);
    }

    public static function saveGuardKey(): string { return 'ProtocolCommands:save'; }
    public static function purgeExpiredSql(float $now): void
    {
        RepositoryGuard::run(self::saveGuardKey(), static fn() => Database::getInstance()->executeAffected('DELETE FROM protocol_commands WHERE expires_at<? AND state<>? ORDER BY expires_at LIMIT 2048', [$now, 'accepted']));
    }
    public static function captureSaveSnapshot(): array { return self::$records->snapshot(); }
    public static function acknowledgeSaveSnapshot(array $snapshot): void { self::$records->acknowledge($snapshot); }
    public static function syncStats(float $now): array { return self::$records->stats($now); }

    public static function writeSaveSnapshot(array $snapshot, Database $db): void
    {
        foreach ($snapshot as $entry) {
            $row = self::$records->snapshotValue($entry);
            if ($entry['deleted']) $db->query('DELETE FROM protocol_commands WHERE world_id=? AND player_id=? AND request_id=?',
                [$row['world_id'], $row['player_id'], $row['request_id']]);
            else {
                $fields = ['world_id','player_id','request_id','fingerprint','epoch','claim','operation','state','outcome','created_at','expires_at'];
                $db->query('INSERT INTO protocol_commands (' . implode(',', $fields) . ') VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '
                    . implode(',', array_map(static fn(string $f): string => $f . '=VALUES(' . $f . ')', $fields)),
                    array_map(static fn(string $f) => is_float($row[$f]) ? json_encode($row[$f], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : $row[$f], $fields));
            }
        }
    }
}
