<?php

declare(strict_types=1);

/** Изолированный реальный Task pool на loopback/случайном порту; без MySQL и аккаунтов. */
require __DIR__ . '/bootstrap.php';

use SPGame\Game\Repositories\PlanetBattles;
use SPGame\Game\Services\FleetBattleService as Battles;
use Swoole\Server;
use Swoole\Table;
use Swoole\Timer;

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "SKIP: нужен ext-swoole; запускать в контейнере swoole_app.\n");
    exit(77);
}

$table = new Table(16);
$table->column('id', Table::TYPE_INT, 8);
$table->column('phase', Table::TYPE_STRING, 16);
$table->column('state', Table::TYPE_STRING, PlanetBattles::MAX_STATE_BYTES);
$table->create();
$sync = new Table(16);
$sync->column('need_update', Table::TYPE_INT);
$sync->column('need_delete', Table::TYPE_INT);
$sync->column('last_id', Table::TYPE_INT);
$sync->create();
foreach (['table' => $table, 'syncTable' => $sync] as $name => $value) {
    (new ReflectionProperty(PlanetBattles::class, $name))->setValue(null, $value);
}
$evidence = new Table(32);
$evidence->column('pid', Table::TYPE_INT, 8);
$evidence->column('round', Table::TYPE_INT);
$evidence->create();
$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'task_worker_num' => 2, 'task_enable_coroutine' => false,
    'log_level' => SWOOLE_LOG_WARNING]);
Battles::initializeWorkers(static fn(array $task): bool => $server->task($task) !== false, 2);
$server->on('Receive', static function (): void {});
$server->on('Task', static function (Server $server, int $taskId, int $workerId, array $task) use ($evidence): void {
    $evidence->set($task['token'], ['pid' => getmypid(), 'round' => count($task['combat']['rounds']) + 1]);
    Battles::executeTask($task);
});
$server->on('Finish', static function (): void {});
$failure = null;
$server->on('Start', static function (Server $server) use ($evidence, &$failure): void {
    $mainPid = getmypid();
    $group = ['type' => 204, 'kind' => 'fleet', 'count' => 2, 'attack' => 500.0,
        'hull' => 1000.0, 'shield' => 0.0, 'unitCost' => 4000.0, 'experience' => 0.0];
    $startedAt = microtime(true);
    Battles::begin(10, ['a' => $group], ['d' => $group], $startedAt);
    $joined = false;
    Timer::tick(100, static function (int $timerId) use ($server, $evidence, $group, $startedAt, $mainPid, &$joined, &$failure): void {
        try {
            $now = microtime(true);
            if (!$joined && $now - $startedAt > 1.15) {
                $state = PlanetBattles::state(10);
                if ($state['phase'] === 'running') {
                    Battles::reinforce(10, $state['id'], 'real-arrival', 'defender', ['reinforcement' => $group], $now);
                    $joined = true;
                }
            }
            Battles::tick($now);
            $state = PlanetBattles::state(10);
            if ($state['phase'] === 'failed') throw new RuntimeException($state['error'] ?? 'Раунд завершился ошибкой.');
            if ($state['phase'] !== 'finished') return;
            $rounds = count($state['combat']['rounds']);
            if ($rounds < 2 || $evidence->count() !== $rounds || !$joined) {
                throw new RuntimeException('Не прошли несколько раундов или не принято подкрепление.');
            }
            $pids = [];
            foreach ($evidence as $event) {
                if ($event['pid'] === $mainPid) throw new RuntimeException('Раунд вычислялся в игровом процессе.');
                $pids[$event['pid']] = true;
            }
            if (isset($state['combat']['rounds'][0]['defender']['reinforcement'])
                || !isset($state['combat']['rounds'][1]['defender']['reinforcement'])) {
                throw new RuntimeException('Подкрепление вступило не во второй раунд.');
            }
            echo 'OK real Swoole battle: rounds=' . $rounds . ', mainPid=' . $mainPid
                . ', taskPids=' . implode(',', array_keys($pids)) . ", reinforcementRound=2\n";
            Timer::clearAll();
            $server->shutdown();
        } catch (Throwable $error) {
            $failure = $error->getMessage();
            fwrite(STDERR, 'FAIL real Swoole battle: ' . $failure . "\n");
            Timer::clearAll();
            $server->shutdown();
        }
    });
    Timer::after(15000, static function () use ($server, &$failure): void {
        $failure = 'Превышен срок изолированного Swoole-теста.';
        fwrite(STDERR, $failure . "\n");
        $server->shutdown();
    });
});
$server->start();
exit($failure === null ? 0 : 1);
