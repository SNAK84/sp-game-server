<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, MiningCheckpoints, ProtocolCommands, RepositoryGuard, Users};
use Swoole\Timer;

/** Временный вызов из onStart основного dev. Только тестовые terminal без игровых действий. */
return static function (string $path): void {
    if (file_exists($path)) return;
    $account = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA-аккаунта.');
    $player = Users::findByIndex('account', (string)$account['id'])['id'] ?? throw new LogicException('Нет QA-игрока.');
    $records = (new ReflectionProperty(ProtocolCommands::class, 'records'))->getValue();
    $get = static fn(object $obj, string $name) => (new ReflectionProperty($obj, $name))->getValue($obj);
    $rows = $get($records, 'rows'); $keys = $get($records, 'keys'); $dirty = $get($records, 'dirty');
    $blocks = $get($get($records, 'bodies'), 'blocks');
    $world = 'qa-expiry-' . bin2hex(random_bytes(8));
    $expiry = microtime(true) + 40;
    $tracked = [];
    MiningCheckpoints::worldLocked(static function () use ($records, $rows, $keys, $world, $player, $expiry, &$tracked): void {
        for ($i = 0; $i < 128; ++$i) {
            $id = sprintf('ffffffff-ffff-4fff-8fff-%012x', $i);
            $key = "$world:$player:$id";
            $hash = substr(hash('sha256', $key), 0, 32);
            if ($keys->exists($hash)) throw new LogicException('Ключ тестовой квитанции занят.');
            $records->put($key, ['world_id' => $world, 'player_id' => $player, 'request_id' => $id,
                'fingerprint' => hash('sha256', $id), 'epoch' => str_repeat('0', 32), 'claim' => str_repeat('1', 32),
                'operation' => 'planet.rename', 'outcome' => json_encode(['sample' => base64_encode(random_bytes(640))], JSON_THROW_ON_ERROR),
                'state' => ['applied', 'rejected', 'unknown'][$i % 3], 'created_at' => $expiry - ProtocolCommands::RETENTION, 'expires_at' => $expiry]);
            $slot = (string)$keys->get($hash, 'slot');
            $row = $rows->get($slot);
            if (!$row || !$row['body_id']) throw new RuntimeException('Квитанция должна занимать внешний блок.');
            $tracked[$hash] = [$slot, $row['body_id'], $row['body_capacity']];
        }
    }, true);
    $proof = ['world' => $world, 'count' => count($tracked), 'expiry' => $expiry, 'observations' => [], 'complete' => false];
    $write = static function (array $proof) use ($path): void {
        file_put_contents($path, json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX);
    };
    $write($proof);
    $previous = [0, 0];
    Timer::tick(250, static function (int $timer) use ($keys, $rows, $dirty, $blocks, $tracked, $expiry, &$proof, &$previous, $write): void {
        [$expired, $released] = RepositoryGuard::run('ProtocolCommands:runtime', static function () use ($keys, $rows, $dirty, $blocks, $tracked): array {
            $expired = $released = 0;
            foreach ($tracked as $hash => [$slot, $body, $bytes]) {
                if ($keys->exists($hash)) {
                    if ($rows->get($slot, 'deleted') === 1) ++$expired;
                    continue;
                }
                if (!$rows->exists($slot) && $dirty->exists($slot)) throw new RuntimeException('Осталась отметка удалённой квитанции.');
                for ($part = 0; $part < (int)ceil($bytes / \SPGame\Game\Repositories\SharedBlobStore::BLOCK_BYTES); ++$part) {
                    if ($blocks->exists("$body:$part")) throw new RuntimeException('ACK не освободил тело квитанции.');
                }
                ++$expired; ++$released;
            }
            return [$expired, $released];
        });
        $age = microtime(true) - $expiry;
        if ($age < 0 && $expired > 0) throw new RuntimeException('Квитанция удалена до истечения срока.');
        if ($previous !== [$expired, $released]) {
            $previous = [$expired, $released];
            $proof['observations'][] = ['secondsAfterExpiry' => round($age, 3), 'expired' => $expired, 'released' => $released];
            $proof['complete'] = $released === count($tracked);
            $write($proof);
        }
        if ($proof['complete'] || $age > 240) Timer::clear($timer);
    });
};
