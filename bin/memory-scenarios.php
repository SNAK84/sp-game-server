#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Read-only модель заполнения. Не создаёт мир, игровые строки или Swoole Table. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Repositories\Messages;
use SPGame\Game\Repositories\ProtocolSubscriptions;

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
        . "--commands-per-active=24 --messages-per-day=5\n";
    exit(0);
}
try {
    $options = [
        'accounts' => '100,500,1000,5000,10000,20000', 'online' => '10,25,50,250,500,1000',
        'bases-per-account' => '5', 'fleets-per-base' => '1', 'active-fraction' => '0.1',
        'commands-per-active' => '24', 'messages-per-day' => '5',
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
    foreach (['bases-per-account', 'fleets-per-base', 'active-fraction', 'commands-per-active', 'messages-per-day'] as $key) {
        if (!is_numeric($options[$key]) || !is_finite((float)$options[$key]) || (float)$options[$key] < 0 || (float)$options[$key] > 1000000) {
            throw new InvalidArgumentException('Неверный коэффициент ' . $key);
        }
        $options[$key] = (float)$options[$key];
    }
    if ($options['active-fraction'] > 1) throw new InvalidArgumentException('Доля активных флотов должна быть от0 до1.');
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
        foreach (['Connect', 'ProtocolSubscriptions'] as $name) $budgets[$name] = $inventory['extraTables'][$name]['buckets'];
        $profiles[$profile] = ['sharedAllocationModelBytes' => $inventory['totalSharedAllocationModelBytes'], 'selectedBudgets' => $budgets];
    }
    // Неизменённые закрытые константы читаем без init() и выделения таблиц.
    $subscriptions = (int)(new ReflectionClass(ProtocolSubscriptions::class))->getConstant('PER_CONNECTION');
    $days = Messages::RETENTION_SECONDS / 86400;
    $scenarios = [];
    foreach ($options['accounts'] as $index => $accounts) {
        $online = $options['online'][$index];
        if ($online > $accounts) throw new InvalidArgumentException('Онлайн не может превышать число аккаунтов.');
        $bases = scenarioRows($accounts * $options['bases-per-account']);
        $fleets = scenarioRows($bases * $options['fleets-per-base']);
        $active = scenarioRows($fleets * $options['active-fraction']);
        $rows = ['Accounts' => $accounts, 'Users' => $accounts, 'Techs' => $accounts, 'UserResources' => $accounts,
            'Planets' => $bases, 'PlanetResources' => $bases, 'Builds' => $bases, 'Ships' => $bases, 'Defenses' => $bases,
            'Fleets' => $fleets, 'FleetsShips' => $fleets,
            'FleetCommands' => scenarioRows($active * $options['commands-per-active']),
            'Messages' => scenarioRows($accounts * $options['messages-per-day'] * $days),
            'Connect' => $online, 'ProtocolSubscriptions' => $online * $subscriptions];
        $estimates = [];
        foreach ($profiles as $profile => $inventory) {
            $utilization = [];
            $exceeded = [];
            foreach ($rows as $name => $count) {
                $budget = $inventory['selectedBudgets'][$name];
                $utilization[$name] = round(100 * $count / $budget, 2);
                if ($count > $budget) $exceeded[$name] = ['rows' => $count, 'budget' => $budget];
            }
            $estimates[$profile] = ['sharedAllocationModelBytes' => $inventory['sharedAllocationModelBytes'],
                'selectedUtilizationPercent' => $utilization, 'exceededBudgets' => (object)$exceeded,
                'selectedBudgetsFit' => $exceeded === []];
        }
        $scenarios[] = ['accounts' => $accounts, 'online' => $online, 'activeFleets' => $active,
            'estimatedSelectedRows' => $rows, 'profiles' => $estimates];
    }
    echo json_encode(['generatedAtUtc' => gmdate(DATE_ATOM), 'modelNotRss' => true, 'loadTestPerformed' => false,
        'assumptions' => $options + ['retentionDays' => $days, 'subscriptionsPerOnline' => $subscriptions],
        'limitations' => [
            'Table выделены целиком при старте; онлайн и заполнение не меняют размер профиля.',
            'Коэффициенты сценария не измерены; история завершённых команд и пики сверх них не включены.',
            'Проверены только перечисленные бюджеты, не все индексы, blob-блоки, очереди, доклады и квитанции.',
            'PHP heap, COW, сокеты, снимки, ОС, БД и другие сервисы требуют отдельного запаса.',
            'Это не доказательство CPU/tick SLO или готовности мира20K/1000.',
        ], 'profiles' => $profiles, 'scenarios' => $scenarios],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    // Не выводим исходный SQL/окружение или stderr дочернего процесса с конфигурацией.
    fwrite(STDERR, 'Расчёт не выполнен (' . get_class($error) . "). Проверьте параметры, окружение и доступ к БД.\n");
    exit(1);
}
