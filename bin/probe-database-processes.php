#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Только свои SQL-соединения и SELECT; ни одного игрового UPDATE/INSERT. */
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};

if (!in_array('--execute', $argv, true) || !extension_loaded('swoole')) exit(64);
Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$db->fetchOne('SELECT 1 AS n');
Database::beforeFork();
$workers = [];
for ($i = 0; $i < 2; ++$i) {
    $worker = new \Swoole\Process(static function (\Swoole\Process $process): void {
        $id = (int)Database::getInstance()->fetchOne('SELECT CONNECTION_ID() AS id')['id'];
        $process->write(json_encode(['pid' => getmypid(), 'connection' => $id], JSON_THROW_ON_ERROR));
        usleep(300000);
    });
    $worker->start();
    $workers[] = $worker;
}
$own = (int)$db->fetchOne('SELECT CONNECTION_ID() AS id')['id'];
$ids = [$own];
foreach ($workers as $worker) $ids[] = json_decode($worker->read(), true, 8, JSON_THROW_ON_ERROR)['connection'];
foreach ($workers as $worker) \Swoole\Process::wait(true);
if (count(array_unique($ids)) !== 3) throw new RuntimeException('SQL-соединения процессов совпали.');
$db->beginTransaction();
try { Database::beforeFork(); throw new RuntimeException('Разрешён fork в транзакции.'); }
catch (LogicException) { $db->rollBack(); }
$charset = $db->fetchOne('SELECT @@character_set_connection AS encoding')['encoding'];
if ($charset !== 'utf8mb4') throw new RuntimeException('Нет utf8mb4.');
$own = (int)$db->fetchOne('SELECT CONNECTION_ID() AS id')['id'];
$db->beginTransaction();
$killer = Database::independent();
// Точный ID только нашего CLI; не останавливаем MySQL или игровой процесс.
$killer->query('KILL CONNECTION ' . $own);
$failed = false;
try { $db->fetchOne('SELECT 1 AS n'); } catch (Throwable) { $failed = true; }
if (!$failed) throw new RuntimeException('Потеря соединения в транзакции не обнаружена.');
$committed = false;
try { $db->commit(); $committed = true; } catch (Throwable) { }
if ($committed) throw new RuntimeException('Commit сменил соединение потерянной транзакции.');
try { $db->rollBack(); } catch (Throwable) { }
if ((int)$db->fetchOne('SELECT 1 AS n')['n'] !== 1) throw new RuntimeException('Не восстановлено соединение.');
echo "DATABASE_PROCESSES_RECOVERY_OK fork=3_connections transaction_no_reconnect=1 recovery=1 utf8mb4=1\n";
