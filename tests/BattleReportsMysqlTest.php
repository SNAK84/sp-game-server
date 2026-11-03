<?php
declare(strict_types=1);

// Реальный MySQL. Создаёт и удаляет ТОЛЬКО две собственные таблицы со случайным суффиксом.
require __DIR__ . '/bootstrap.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\BattleReports;

function reportAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function reportReject(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}

Environment::init();
$db = Database::getInstance();
$suffix = bin2hex(random_bytes(6));
$reports = 'qa_battle_reports_' . $suffix;
$saves = 'qa_battle_saves_' . $suffix;
(new ReflectionProperty(BattleReports::class, 'reportsTable'))->setValue(null, $reports);
(new ReflectionProperty(BattleReports::class, 'savesTable'))->setValue(null, $saves);
try {
    BattleReports::init();
    $now = microtime(true);
    $id = bin2hex(random_bytes(16));
    $payload = ['version'=>1, 'battleId'=>$id, 'finishedAt'=>$now, 'startedAt'=>$now-10,
        'groups'=>['fleet:1:204'=>['before'=>1000000]], 'unicode'=>'Доклад <script> не HTML'];
    $summary = ['BattleId'=>$id, 'TargetCoordinate'=>'[1:7:2]'];
    BattleReports::prepare($payload, $summary);
    reportAssert(BattleReports::get($id, 1, $now) === null, 'Prepared виден игроку');
    BattleReports::publish($id);
    reportAssert(BattleReports::get($id, 987654, $now)['report'] === $payload, 'Посторонний игрок не может открыть ссылку');
    BattleReports::prepare($payload, $summary);
    BattleReports::publish($id);
    reportAssert((int)$db->fetchOne("SELECT COUNT(*) n FROM `$reports`")['n'] === 1, 'Дублирование при retry');
    try {
        BattleReports::prepare($payload + ['changed'=>true], $summary);
        throw new LogicException('Опубликованный доклад изменён');
    } catch (RuntimeException $error) {
        if ($error instanceof LogicException) throw $error;
    }
    reportAssert(BattleReports::get($id, 2, $now)['report'] === $payload, 'Нарушена неизменяемость');
    echo "OK mysql public link, prepared visibility, immutable snapshot and idempotent publish\n";

    $trigger = 'qa_battle_save_fail_' . $suffix;
    $db->query("CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$reports` FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected archive counter failure'");
    try {
        BattleReports::setSaved($id, 123, true, $now);
        throw new LogicException('Не сработала SQL failure injection');
    } catch (PDOException) {
        reportAssert((int)$db->fetchOne("SELECT COUNT(*) n FROM `$saves` WHERE report_id=?", [$id])['n'] === 0,
            'Ошибка счётчика оставила половину сохранения');
    } finally {
        $db->query("DROP TRIGGER `$trigger`");
    }
    BattleReports::setSaved($id, 123, true, $now);
    BattleReports::setSaved($id, 123, false, $now);
    echo "OK mysql partial bookmark write rolls back on SQL failure and retries successfully\n";

    $expiry = (float)ceil($now + BattleReports::RETENTION_SECONDS);
    reportAssert(BattleReports::get($id, 2, $expiry - .001) !== null, 'Преждевременный expiry');
    reportAssert(BattleReports::get($id, 2, $expiry) === null, 'Доклад доступен ровно после 7 дней');
    reportReject(fn()=>BattleReports::setSaved($id, 2, true, $expiry), 'Можно воскресить просроченный доклад');
    BattleReports::setSaved($id, 1, true, $now);
    BattleReports::setSaved($id, 1, true, $now);
    BattleReports::setSaved($id, 2, true, $now + 1);
    $first = BattleReports::get($id, 1, $expiry + 1);
    reportAssert($first['savedByMe'] && $first['expiresAt'] === null, 'Сохранение не продлевает хранение');
    reportAssert(!BattleReports::get($id, 3, $expiry + 1)['savedByMe'], 'Чужая закладка стала личной');
    BattleReports::deleteExpired($expiry + 2);
    reportAssert(BattleReports::get($id, 3, $expiry + 2) !== null, 'Cleanup удалил сохранённый доклад');
    reportAssert(count(BattleReports::savedFor(1)['reports']) === 1 && count(BattleReports::savedFor(3)['reports']) === 0,
        'Личный архив не изолирован');
    BattleReports::setSaved($id, 1, false, $expiry + 3);
    BattleReports::setSaved($id, 1, false, $expiry + 3);
    reportAssert(BattleReports::get($id, 3, $expiry + 3) !== null, 'Чужая закладка удалена');
    BattleReports::setSaved($id, 2, false, $expiry + 4);
    reportAssert(BattleReports::get($id, 3, $expiry + 4) === null, 'Последнее снятие закладки сбросило срок');
    BattleReports::deleteExpired($expiry + 5);
    reportAssert((int)$db->fetchOne("SELECT COUNT(*) n FROM `$reports`")['n'] === 0, 'Просроченный доклад не удалён');
    echo "OK mysql exact 7-day boundary, independent saves, idempotent unsave and expiry cleanup\n";

    foreach ([null, 1, [], "' OR 1=1", str_repeat('a', 31), str_repeat('a', 33), str_repeat('G', 32)] as $bad) {
        reportReject(fn()=>BattleReports::validateId($bad), 'Некорректный ID принят');
    }
    reportReject(fn()=>BattleReports::get(str_repeat('a',32), 0), 'Принят анонимный просмотр');
    reportReject(fn()=>BattleReports::setSaved(str_repeat('a',32), 0, true), 'Принято анонимное сохранение');
    reportReject(fn()=>BattleReports::setSaved(str_repeat('a',32), 1, true), 'Можно сохранить отсутствующий доклад');
    try {
        BattleReports::prepare(['version'=>1,'battleId'=>$id,'finishedAt'=>$now,'large'=>str_repeat('x',BattleReports::MAX_BYTES)], []);
        throw new LogicException('Нет лимита размера');
    } catch (OverflowException) {}
    echo "OK mysql malformed IDs, SQL injection string, anonymous/missing report and size limit\n";

    for ($i = 0; $i < BattleReports::PAGE_SIZE + 2; ++$i) {
        $entry = array_replace($payload, ['battleId'=>bin2hex(random_bytes(16))]);
        BattleReports::prepare($entry, $summary);
        BattleReports::publish($entry['battleId']);
        BattleReports::setSaved($entry['battleId'], 1, true, $now + $i);
    }
    reportAssert(count(BattleReports::savedFor(1)['reports']) === BattleReports::PAGE_SIZE && BattleReports::savedFor(1)['hasMore'], 'Нет пагинации');
    reportAssert(count(BattleReports::savedFor(1, 2)['reports']) === 2 && !BattleReports::savedFor(1, 2)['hasMore'], 'Неверная вторая страница');
    reportAssert(!array_key_exists('report', BattleReports::savedFor(1)['reports'][0]), 'Список загружает полный payload');
    echo "OK mysql paginated archive list does not load full report payloads\n";
    $account = (new ReflectionClass(SPGame\Game\Services\AccountData::class))->newInstanceWithoutConstructor();
    $account['User'] = ['id'=>1];
    $request = new SPGame\Core\Message(['mode'=>'battlereport','action'=>'list','data'=>['page'=>1]]);
    $page = new SPGame\Game\Pages\BattleReportPage($request);
    reportAssert(count($page->render($account)['reports']) === BattleReports::PAGE_SIZE, 'WS Message преобразовал номер страницы');
    foreach ([null, 0, -1, 1.5, '1', [], true] as $bad) {
        $page = new SPGame\Game\Pages\BattleReportPage(new SPGame\Core\Message([
            'mode'=>'battlereport','action'=>'list','data'=>['page'=>$bad]]));
        reportReject(fn()=>$page->render($account), 'Принят некорректный номер страницы');
    }
    echo "OK mysql page endpoint uses real Message and validates raw pagination types\n";

    // Реальные два соединения: save начался до expiry, DELETE ждёт его row lock.
    $raceId = bin2hex(random_bytes(16));
    BattleReports::prepare(array_replace($payload, ['battleId'=>$raceId]), $summary);
    BattleReports::publish($raceId);
    $lock = 'qa_report_lock_' . $suffix;
    $trigger = 'qa_battle_save_wait_' . $suffix;
    $db->query("CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$reports` FOR EACH ROW BEGIN
        IF NEW.saved_count > OLD.saved_count THEN
            DO GET_LOCK('$lock',5); DO SLEEP(1); DO RELEASE_LOCK('$lock');
        END IF; END");
    $childCode = 'require "tests/bootstrap.php"; SPGame\\Core\\Environment::init();'
        . '(new ReflectionProperty(SPGame\\Game\\Repositories\\BattleReports::class,"reportsTable"))->setValue(null,$argv[1]);'
        . '(new ReflectionProperty(SPGame\\Game\\Repositories\\BattleReports::class,"savesTable"))->setValue(null,$argv[2]);'
        . 'SPGame\\Game\\Repositories\\BattleReports::setSaved($argv[3],99,true,(float)$argv[4]-1);';
    $process = proc_open([PHP_BINARY,'-r',$childCode,$reports,$saves,$raceId,(string)$expiry],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    reportAssert(is_resource($process), 'Не запущено второе MySQL соединение');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 5;
        do {
            $locked = $db->fetchOne('SELECT IS_USED_LOCK(?) owner', [$lock])['owner'] !== null;
            if ($locked) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        reportAssert($locked, 'Не подтверждён row lock сохраняющего соединения');
        BattleReports::deleteExpired($expiry + 1);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        reportAssert($exit === 0, 'Сохранение во втором соединении не завершилось: ' . $output . $error);
        reportAssert(BattleReports::get($raceId, 3, $expiry + 2) !== null, 'Cleanup проигнорировал успешное сохранение');
        reportAssert(BattleReports::get($raceId, 99, $expiry + 2)['savedByMe'], 'Потеряна конкурентная закладка');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        $db->query("DROP TRIGGER `$trigger`");
    }
    echo "OK mysql concurrent expiry DELETE waits for bookmark commit and preserves saved report\n";
} finally {
    // Имена созданы именно этим запуском; игровые таблицы не затрагиваются.
    $db->query("DROP TABLE IF EXISTS `$saves`");
    $db->query("DROP TABLE IF EXISTS `$reports`");
}
