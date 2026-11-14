#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Read-only модель заполнения. Не создаёт мир, игровые строки или Swoole Table. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Repositories\Messages;
use SPGame\Game\Repositories\ProtocolSubscriptions;
use SPGame\Game\Repositories\{BattleReports, ProtocolCommands, RuntimeCapacity, RuntimeRecords, SharedBlobStore};

/** Защита самой модели от переполнения при пользовательских коэффициентах. */
function scenarioRows(float $value): int
{
    if (!is_finite($value) || $value < 0 || $value >= PHP_INT_MAX) throw new OverflowException('Сценарий превышает диапазон целых.');
    return (int)ceil($value);
}

if (in_array('--help', $argv, true)) {
    echo "Запуск в контейнере: php bin/memory-scenarios.php [параметры]\n"
        . "JSON: модель двух профилей из audit-capacity.php, не измерение RSS.\n"
        . "--accounts=100,500,1000,5000,10000,20000 --online=10,25,50,250,500,1000\n"
        . "--bases-per-account=5 --fleets-per-base=1 --active-fraction=0.1\n"
        . "--commands-per-active=24 --history-commands-per-active=24 --messages-per-day=5\n"
        . "--commands-per-online-minute=2 --command-hours-per-day=24 --receipt-packed-bytes=384\n"
        . "--pending-commands-per-online=1 (отдельно от суточных terminal-квитанций)\n"
        . "--message-overflow-fraction=0.25 --message-overflow-bytes=1024\n"
        . "--reports-per-account-day=0.1 --saved-reports-per-account=1 --bookmarks-per-account=2\n"
        . "--report-packed-bytes=8192\n"
        . "Все коэффициенты — сценарии, не измерения и не игровые лимиты.\n"
        . "Размеры packed включают служебный префикс после сжатия; saved reports —\n"
        . "непересекающийся со свежими докладами сохранённый архив.\n";
    exit(0);
}
try {
    $options = [
        'accounts' => '100,500,1000,5000,10000,20000', 'online' => '10,25,50,250,500,1000',
        'bases-per-account' => '5', 'fleets-per-base' => '1', 'active-fraction' => '0.1',
        'commands-per-active' => '24', 'messages-per-day' => '5',
        'history-commands-per-active' => '24',
        'commands-per-online-minute' => '2', 'command-hours-per-day' => '24',
        'pending-commands-per-online' => '1',
        'receipt-packed-bytes' => '384', 'message-overflow-fraction' => '0.25',
        'message-overflow-bytes' => '1024', 'reports-per-account-day' => '0.1',
        'saved-reports-per-account' => '1', 'bookmarks-per-account' => '2',
        'report-packed-bytes' => '8192',
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/D', $argument, $match) || !array_key_exists($match[1], $options)) {
            throw new InvalidArgumentException('Неизвестный параметр; см. --help.');
        }
        $options[$match[1]] = $match[2];
    }
    foreach (['accounts', 'online'] as $key) {
        if (!preg_match('/^\d+(,\d+)*$/D', $options[$key])) throw new InvalidArgumentException('Неверный список ' . $key);
        $options[$key] = array_map('intval', explode(',', $options[$key]));
        if (max($options[$key]) > 1000000) throw new InvalidArgumentException('Слишком большой сценарий.');
    }
    if (count($options['accounts']) !== count($options['online'])) throw new InvalidArgumentException('Число сценариев должно совпадать.');
    foreach (array_diff(array_keys($options), ['accounts', 'online']) as $key) {
        if (!is_numeric($options[$key]) || !is_finite((float)$options[$key]) || (float)$options[$key] < 0 || (float)$options[$key] > 1000000) {
            throw new InvalidArgumentException('Неверный коэффициент ' . $key);
        }
        $options[$key] = (float)$options[$key];
    }
    if ($options['active-fraction'] > 1) throw new InvalidArgumentException('Доля активных флотов должна быть от0 до1.');
    if ($options['message-overflow-fraction'] > 1 || $options['command-hours-per-day'] > 24
        || $options['saved-reports-per-account'] > $options['bookmarks-per-account']) {
        throw new InvalidArgumentException('Неверная доля переполнения, время активности или число закладок.');
    }
    foreach (['receipt-packed-bytes', 'message-overflow-bytes', 'report-packed-bytes'] as $key) {
        if ($options[$key] < 1 || floor($options[$key]) !== $options[$key]) throw new InvalidArgumentException('Нужен целый размер packed в байтах.');
    }
    if (!extension_loaded('swoole')) throw new RuntimeException('Запустите команду в игровом контейнере с Swoole.');

    // Используем реальные схемы аудитора; формулу native allocation здесь не дублируем.
    $profiles = [];
    foreach (['development', 'target'] as $profile) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/audit-capacity.php', '--' . $profile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Не запущен аудитор.');
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Ошибка аудитора профиля ' . $profile);
        $inventory = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        if (!$inventory['modelApplicable']) throw new RuntimeException('Native-модель не сверена с установленной версией Swoole/архитектурой.');
        $budgets = [];
        foreach ($inventory['repositories'] as $repository) $budgets[$repository['class']] = $repository['layout']['mainBuckets'];
        foreach (['Connect', 'ProtocolSubscriptions', 'MessageBodiesOverflow',
            'ProtocolCommandsBodies', 'BattleReportsBodies'] as $name) {
            $budgets[$name] = $inventory['extraTables'][$name]['buckets'];
        }
        foreach (['ProtocolCommands', 'BattleReports', 'BattleReportSaves'] as $name) {
            $budgets[$name] = $inventory['extraTables'][$name . 'Rows']['buckets'];
        }
        $profiles[$profile] = ['sharedAllocationModelBytes' => $inventory['totalSharedAllocationModelBytes'],
            'selectedBudgets' => $budgets, 'cleanupPolicies' => $inventory['cleanupPolicies']];
    }
    // Неизменённые закрытые константы читаем без init() и выделения таблиц.
    $subscriptions = (int)(new ReflectionClass(ProtocolSubscriptions::class))->getConstant('PER_CONNECTION');
    $days = Messages::RETENTION_SECONDS / 86400;
    $reportDays = BattleReports::RETENTION_SECONDS / 86400;
    $receiptDays = ProtocolCommands::RETENTION / 86400;
    $messageInline = (int)(new ReflectionClass(Messages::class))->getConstant('INLINE_BYTES');
    $runtimeInline = RuntimeRecords::columns()['inline'][1];
    $blocks = static fn(float $bytes, int $inline): int => $bytes <= $inline ? 0 : scenarioRows($bytes / SharedBlobStore::BLOCK_BYTES);
    $completionReserve = ProtocolCommands::MAX_RECORD_BYTES + 1;
    $completionBlocks = (int)ceil($completionReserve / SharedBlobStore::BLOCK_BYTES);
    $scenarios = [];
    foreach ($options['accounts'] as $index => $accounts) {
        $online = $options['online'][$index];
        if ($online > $accounts) throw new InvalidArgumentException('Онлайн не может превышать число аккаунтов.');
        $bases = scenarioRows($accounts * $options['bases-per-account']);
        $fleets = scenarioRows($bases * $options['fleets-per-base']);
        $active = scenarioRows($fleets * $options['active-fraction']);
        $futureCommands = scenarioRows($active * $options['commands-per-active']);
        $historyCommands = scenarioRows($active * $options['history-commands-per-active']);
        $messages = scenarioRows($accounts * $options['messages-per-day'] * $days);
        $overflowMessages = $options['message-overflow-bytes'] <= $messageInline
            ? 0 : scenarioRows($messages * $options['message-overflow-fraction']);
        $receipts = scenarioRows($online * $options['commands-per-online-minute'] * 60 * $options['command-hours-per-day'] * $receiptDays);
        $pendingReceipts = scenarioRows($online * $options['pending-commands-per-online']);
        $recentReports = scenarioRows($accounts * $options['reports-per-account-day'] * $reportDays);
        $savedReports = scenarioRows($accounts * $options['saved-reports-per-account']);
        $reports = scenarioRows((float)$recentReports + $savedReports);
        $rows = ['Accounts' => $accounts, 'Users' => $accounts, 'Techs' => $accounts, 'UserResources' => $accounts,
            'Planets' => $bases, 'PlanetResources' => $bases, 'Builds' => $bases, 'Ships' => $bases, 'Defenses' => $bases,
            'Fleets' => $fleets, 'FleetsShips' => $fleets,
            'FleetCommands' => scenarioRows((float)$futureCommands + $historyCommands),
            'Messages' => $messages,
            'MessageBodiesOverflow' => scenarioRows($overflowMessages * $blocks($options['message-overflow-bytes'], $messageInline)),
            'ProtocolCommands' => scenarioRows((float)$receipts + $pendingReceipts),
            'ProtocolCommandsBodies' => scenarioRows($receipts * $blocks($options['receipt-packed-bytes'], $runtimeInline)
                + $pendingReceipts * $completionBlocks),
            'BattleReports' => $reports,
            'BattleReportsBodies' => scenarioRows($reports * $blocks($options['report-packed-bytes'], $runtimeInline)),
            'BattleReportSaves' => scenarioRows($accounts * $options['bookmarks-per-account']),
            'Connect' => $online, 'ProtocolSubscriptions' => $online * $subscriptions];
        $estimates = [];
        foreach ($profiles as $profile => $inventory) {
            $utilization = [];
            $exceeded = [];
            $reserveShortfalls = [];
            foreach ($rows as $name => $count) {
                $budget = $inventory['selectedBudgets'][$name];
                $utilization[$name] = round(100 * $count / $budget, 2);
                if ($count > $budget) $exceeded[$name] = ['rows' => $count, 'budget' => $budget];
                $safeRows = (int)floor($budget * RuntimeCapacity::TARGET_MAX_FILL);
                if ($count > $safeRows) $reserveShortfalls[$name] = ['rows' => $count, 'budget' => $budget, 'safeRows' => $safeRows];
            }
            $estimates[$profile] = ['sharedAllocationModelBytes' => $inventory['sharedAllocationModelBytes'],
                'selectedUtilizationPercent' => $utilization, 'exceededBudgets' => (object)$exceeded,
                'selectedBudgetsFit' => $exceeded === [],
                'selectedReserveFit' => $reserveShortfalls === [], 'insufficientReserveBudgets' => (object)$reserveShortfalls];
        }
        $scenarios[] = ['accounts' => $accounts, 'online' => $online, 'activeFleets' => $active,
            'demandBreakdown' => ['futureCommands' => $futureCommands, 'historyCommands' => $historyCommands,
                'overflowMessages' => $overflowMessages, 'recentReports' => $recentReports, 'savedReports' => $savedReports,
                'terminalReceipts' => $receipts, 'pendingReceipts' => $pendingReceipts,
                'pendingReceiptBlocks' => $pendingReceipts * $completionBlocks],
            'estimatedSelectedRows' => $rows, 'profiles' => $estimates];
    }
    echo json_encode(['generatedAtUtc' => gmdate(DATE_ATOM), 'modelNotRss' => true, 'loadTestPerformed' => false,
        'assumptions' => $options + ['retentionDays' => $days, 'reportRetentionDays' => $reportDays,
            'receiptRetentionDays' => $receiptDays, 'subscriptionsPerOnline' => $subscriptions,
            'messageInlineBytes' => $messageInline, 'runtimeInlineBytes' => $runtimeInline,
            'blockBytes' => SharedBlobStore::BLOCK_BYTES, 'targetMaxFill' => RuntimeCapacity::TARGET_MAX_FILL],
        'completionReservation' => ['bytesPerPending' => $completionReserve, 'blocksPerPending' => $completionBlocks],
        'limitations' => [
            'Table выделены целиком при старте; онлайн и заполнение не меняют размер профиля.',
            'Коэффициенты сценария не измерены; история команд учитывается только для активных флотов, не дольше последнего STAY.',
            'Ритм terminal-команд постоянен в заданные часы; backlog accepted задан отдельно, это не измеренные частоты и не все возможные accepted за пределами TTL.',
            'Сохранённые доклады добавлены отдельно от свежих без пересечения; их бессрочный архив не ограничен числом аккаунтов.',
            'Размеры packed и доля overflow заданы явно, не получены из маленькой выборки dev; компрессия не гарантируется.',
            'Учтён физический резерв завершения accepted; прочие индексы, журналы добычи, очереди, mailbox, tombstones и обновления других типов тел вне модели.',
            'idealFullPassCalls очистки не учитывает time-budget hits, lag, ошибки и ожидание ACK: это не гарантия освобождения памяти за заданное время.',
            'PHP heap, COW, сокеты, снимки, ОС, БД и другие сервисы требуют отдельного запаса.',
            'Это не доказательство CPU/tick SLO или готовности мира20K/1000.',
        ], 'profiles' => $profiles, 'scenarios' => $scenarios],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    // Не выводим исходный SQL/окружение или stderr дочернего процесса с конфигурацией.
    fwrite(STDERR, 'Расчёт не выполнен (' . get_class($error) . "). Проверьте параметры, окружение и доступ к БД.\n");
    exit(1);
}
