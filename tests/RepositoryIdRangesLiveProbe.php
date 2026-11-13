<?php
declare(strict_types=1);

/** Реальный allocator и MySQL основного dev: только резервирует пропускаемые ID.
 * Не создаёт игровой мир/сущности, не меняет игровые строки, не запускает saver.
 */
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\RepositoryIdRanges;
use Swoole\Process;
if (($argv[1] ?? '') !== '--dev-reserve') throw new LogicException('Нужен явный --dev-reserve.');
Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') !== 'qaown_260924') {
    throw new LogicException('Другой dev-контекст.');
}
$before = $db->fetchAll('SELECT id FROM fleets ORDER BY id');
$ranges = new RepositoryIdRanges('fleets', max(array_column($before, 'id')));
$children = [];
for ($worker = 0; $worker < 4; $worker++) {
    $process = new Process(static function (Process $process) use ($ranges): void {
        try {
            $ids = [];
            for ($i = 0; $i < 600; $i++) $ids[] = $ranges->next();
            $process->write(json_encode(['ids'=>$ids]));
            $process->exit(0);
        } catch (Throwable) { $process->write('{"error":true}'); $process->exit(1); }
    });
    $process->setTimeout(20);
    if (!$process->start()) throw new RuntimeException('Не запущен собственный QA-процесс.');
    $children[] = $process;
}
$all = [];
foreach ($children as $process) {
    $reply = json_decode($process->read(65536), true, 512, JSON_THROW_ON_ERROR);
    if (count($reply['ids'] ?? []) !== 600) throw new RuntimeException('Проба дочернего процесса не завершилась.');
    $sorted = $reply['ids']; sort($sorted);
    if ($sorted !== $reply['ids']) throw new LogicException('ID одного worker не возрастают.');
    $all = array_merge($all, $reply['ids']);
}
foreach ($children as $_) {
    $exit = Process::wait(true);
    if (($exit['code'] ?? -1) !== 0 || ($exit['signal'] ?? -1) !== 0) throw new RuntimeException('Ошибка QA-worker.');
}
if (count(array_unique($all)) !== 2400) throw new LogicException('Повтор ID между worker.');
$high = static fn(): int => (int)$db->fetchOne('SELECT high_water FROM repository_id_ranges WHERE repository=?', ['fleets'])['high_water'];
if (max($all) > $high()) throw new LogicException('ID выдан за пределами подтверждённого резерва.');

// Собственный дочерний процесс погибает после выдачи ID, не сохранив сущность.
$crashed = new Process(static function (Process $process) use ($ranges): void {
    $process->write(json_encode(['id'=>$ranges->next()]));
    sleep(10);
});
$crashed->setTimeout(20);$crashed->start();
$crashId = json_decode($crashed->read(65536),true,512,JSON_THROW_ON_ERROR)['id'];
Process::kill($crashed->pid, SIGKILL);$exit=Process::wait(true);
if (($exit['signal'] ?? 0) !== SIGKILL) throw new LogicException('Нет подтверждения аварии собственного worker.');
$afterCrash = new RepositoryIdRanges('fleets', 0);
$next = $afterCrash->next();
if ($next <= max($crashId, max($all))) throw new LogicException('ID повторился после потери runtime.');

// Commit резерва независим от внешней игровой транзакции и её rollback.
$reserved = new RepositoryIdRanges('fleets', 0);
$db->beginTransaction();
$inTransaction = $reserved->next();
$db->rollBack();
if ($high() < $inTransaction) throw new LogicException('Rollback отменил резерв.');

// Отказ отдельного SQL-соединения только внутри этой CLI-пробы, без изменения .env.
$failed = new RepositoryIdRanges('fleets', 0);
$property = new ReflectionProperty(Environment::class, 'env');
$originalEnv = $property->getValue();
$property->setValue(null, array_replace($originalEnv, ['DB_HOST'=>'127.0.0.1', 'DB_USER'=>'b029_invalid_user', 'DB_PASS'=>'b029_invalid_password']));
$denied = false;
try { $failed->next(); } catch (RuntimeException) { $denied = true; }
finally { $property->setValue(null, $originalEnv); }
if (!$denied) throw new LogicException('При отказе SQL был выдан неподтверждённый ID.');
$recovered = $failed->next();
if ($recovered <= $inTransaction) throw new LogicException('SQL retry повторил ID.');
if ($db->fetchAll('SELECT id FROM fleets ORDER BY id') !== $before) throw new LogicException('Проба изменила игровые строки.');
echo json_encode(['uniqueIds'=>count($all),'workers'=>count($children),'min'=>min($all),'max'=>max($all),
    'crashedId'=>$crashId,'afterCrash'=>$next,'afterRollback'=>$inTransaction,'afterSqlRetry'=>$recovered,
    'highWater'=>$high(),'gameRowsUnchanged'=>true,'sqlFailureDenied'=>true]), PHP_EOL;
