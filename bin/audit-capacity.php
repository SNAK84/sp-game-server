#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Read-only инвентаризация. Не вызывает init() игровых репозиториев и не пишет в БД. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\BaseRepository;
use SPGame\Game\Repositories\{PlayerQueuePayloads, PlanetBattles, FleetExpeditions, WorkerMailbox};
use SPGame\Game\Services\{FleetBattleService, FleetExpeditionService};
use Swoole\Table;

Environment::init(__DIR__ . '/../.env');
if (in_array('--target', $argv, true) && in_array('--development', $argv, true)) {
    throw new InvalidArgumentException('Выберите один профиль: --target или --development.');
}
if (in_array('--target', $argv, true) || in_array('--development', $argv, true)) {
    $environment = new ReflectionProperty(Environment::class, 'env');
    $environment->setValue(null, array_replace($environment->getValue(), [
        'SP_GAME_CAPACITY_PROFILE' => in_array('--target', $argv, true) ? 'target' : 'development',
    ]));
}
$database = Database::independent();
$variables = $database->fetchAll('SELECT name,class FROM vars');
$counts = [];
foreach ($variables as $variable) $counts[(int)$variable['class']][] = $variable['name'];

// Модель именно установленного Swoole 5.1.6/x86-64, сверена с upstream.
// Все INT занимают sizeof(long); getMemorySize() этой версии возвращает
// остаток collision pool, а не всю mmap-область. Не путать с PHP heap/RSS.
function memoryModel(int $requested, array $columns, float $conflict = .2): array
{
    $size = 64;
    while ($size < $requested) $size *= 2;
    $item = 0;
    foreach ($columns as $definition) {
        [$type, $bytes] = $definition + [1 => 0];
        $item += $type === Table::TYPE_STRING ? (int)(ceil($bytes / 8) * 8) + 4 : 8;
    }
    $extra = (int)($size * (1 + $conflict)) - $size;
    $bytes = ($size + $extra) * (88 + $item) + $size * 8 + $extra * 24 + 48;
    return ['buckets' => $size, 'collisionRows' => $extra, 'payloadBytes' => $item,
        'sharedAllocationModelBytes' => $bytes];
}

// В одном Resources.php объявлены ещё два настоящих репозитория.
class_exists(SPGame\Game\Repositories\Resources::class);
foreach (glob(__DIR__ . '/../src/Game/Repositories/*.php') as $file) {
    class_exists('SPGame\\Game\\Repositories\\' . basename($file, '.php'));
}
$repositories = [];
foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, BaseRepository::class)) continue;
    $properties = (new ReflectionClass($class))->getDefaultProperties();
    if (empty($properties['tableSchema']['columns'])) continue;
    $name = (new ReflectionClass($class))->getShortName();
    $columns = $class::runtimeColumns();
    foreach (match ($name) {
        'Builds' => $counts[0] ?? [], 'Techs' => $counts[100] ?? [],
        'Ships' => $counts[200] ?? [], 'Defenses' => array_merge($counts[400] ?? [], $counts[500] ?? []),
        default => [],
    } as $field) $columns[$field] = [Table::TYPE_INT, 8];
    if ($name === 'FleetsShips') foreach ($counts[200] ?? [] as $field) {
        foreach (['count', 'status'] as $part) $columns[$field . '_' . $part] = [Table::TYPE_INT, 8];
        foreach (['damage', 'experience', 'morale'] as $part) $columns[$field . '_' . $part] = [Table::TYPE_FLOAT, 8];
    }
    if ($name === 'Fleets') foreach (['metal', 'crystal', 'deuterium', 'credit', 'doubloon'] as $field) {
        $columns['resource_' . $field] = [Table::TYPE_FLOAT, 8];
    }
    $layout = $class::runtimeLayout();
    $size = $layout['mainBuckets'];
    $main = memoryModel($size, $columns, $layout['mainConflict']);
    $indexMemory = 0;
    foreach ($properties['indexes'] as $index) {
        $indexColumns = [[Table::TYPE_INT, 8]];
        if (!$index['Unique']) $indexColumns[] = [Table::TYPE_STRING, $layout['indexIdsBytes']];
        $indexMemory += memoryModel($layout['indexBuckets'], $indexColumns, $layout['indexConflict'])['sharedAllocationModelBytes'];
    }
    $positions = $layout['positionColumns'] === [] ? 0 : memoryModel($layout['positionBuckets'],
        array_fill(0, count($layout['positionColumns']), [Table::TYPE_STRING, 24]), $layout['positionConflict'])['sharedAllocationModelBytes'];
    $sync = memoryModel($layout['syncBuckets'], array_fill(0, 4, [Table::TYPE_INT, 8]), $layout['syncConflict'])['sharedAllocationModelBytes'];
    $row = ['class' => $name, 'sqlTable' => $properties['tableName'], 'columns' => count($columns), 'layout' => $layout] + $main
        + ['indexes' => count($properties['indexes']), 'indexAllocationModelBytes' => $indexMemory,
           'syncAllocationModelBytes' => $sync, 'indexPositionAllocationModelBytes' => $positions,
           'totalAllocationModelBytes' => $main['sharedAllocationModelBytes'] + $indexMemory + $sync + $positions];
    if (in_array('--sql-counts', $argv, true) && preg_match('/^[a-z_]+$/D', $properties['tableName'])) {
        $row['sqlRows'] = (int)$database->fetchOne('SELECT COUNT(*) AS n FROM `' . $properties['tableName'] . '`')['n'];
        $row['runtimeSqlRows'] = $name === 'Messages' ? (int)$database->fetchOne('SELECT COUNT(*) n FROM messages WHERE time>?',
            [microtime(true) - \SPGame\Game\Repositories\Messages::RETENTION_SECONDS])['n'] : $row['sqlRows'];
    }
    $repositories[] = $row;
}
usort($repositories, static fn(array $a, array $b): int => strcmp($a['class'], $b['class']));
$extra = [
    'Connect' => memoryModel(2048, array_merge(array_fill(0, 11, [Table::TYPE_INT, 8]),
        [[Table::TYPE_STRING, 45], [Table::TYPE_STRING, 32], [Table::TYPE_STRING, 36]]), 1),
    'ProtocolSubscriptions' => memoryModel(\SPGame\Game\Repositories\ProtocolSubscriptions::capacity(), array_merge(array_fill(0, 3, [Table::TYPE_INT, 8]),
        array_map(static fn(int $size): array => [Table::TYPE_STRING, $size], [32, 36, 64, 2048, 20])), 1),
    'ProtocolResources' => memoryModel(16384, [[Table::TYPE_STRING, 64], [Table::TYPE_INT, 8], [Table::TYPE_FLOAT, 8]], 1),
    'PlayerQueuePayloads' => memoryModel(PlayerQueuePayloads::capacity(), array_merge(array_fill(0, 4, [Table::TYPE_INT, 4]),
        [[Table::TYPE_FLOAT], [Table::TYPE_STRING, 32], [Table::TYPE_STRING, PlayerQueuePayloads::MAX_BYTES]]), 1),
    'PlayerQueuePayloadAccounts' => memoryModel(PlayerQueuePayloads::capacity(), [[Table::TYPE_INT, 4]], 1),
    'PhalanxScans' => memoryModel(1024, [[Table::TYPE_FLOAT, 8], [Table::TYPE_STRING, 16384]], 1),
    'SensorContacts' => memoryModel(\SPGame\Game\Repositories\SensorContacts::capacity(),
        [[Table::TYPE_FLOAT, 8], [Table::TYPE_STRING, \SPGame\Game\Repositories\SensorContacts::MAX_BYTES]], 1),
    'CombatMailbox' => memoryModel(FleetBattleService::MAILBOX_CAPACITY,
        [[Table::TYPE_STRING, PlanetBattles::MAX_STATE_BYTES]], WorkerMailbox::CONFLICT),
    'ExpeditionMailbox' => memoryModel(FleetExpeditionService::MAILBOX_CAPACITY,
        [[Table::TYPE_STRING, FleetExpeditions::MAX_BYTES]], WorkerMailbox::CONFLICT),
    'MessageBodiesOverflow' => memoryModel(\SPGame\Game\Repositories\Messages::overflowBuckets(),
        [[Table::TYPE_STRING, \SPGame\Game\Repositories\SharedBlobStore::BLOCK_BYTES]], 1.0),
    'RuntimeMetrics' => memoryModel(64, array_fill(0, 21, [Table::TYPE_INT, 8]), 1.0),
];
$runtimeStores = [];
foreach (\SPGame\Game\Repositories\RuntimeRecords::SPECS as $name => $unused) {
    $layout = \SPGame\Game\Repositories\RuntimeRecords::layout($name);
    $extra[$name . 'Rows'] = memoryModel($layout['rows'], array_values(\SPGame\Game\Repositories\RuntimeRecords::columns()), 1.0);
    $extra[$name . 'Keys'] = memoryModel($layout['rows'], [[Table::TYPE_INT, 8]], 1.0);
    $extra[$name . 'Dirty'] = memoryModel($layout['rows'], [[Table::TYPE_INT, 8]], 1.0);
    $extra[$name . 'Bodies'] = memoryModel($layout['blocks'], [[Table::TYPE_STRING, 1024]], 1.0);
    if (in_array('--sql-counts', $argv, true)) {
        [$query, $parameters] = match ($name) {
            'ProtocolCommands' => ['SELECT COUNT(*) n FROM protocol_commands WHERE expires_at>? OR state=?', [microtime(true), 'accepted']],
            'MiningCheckpoints' => ['SELECT COUNT(*) n FROM mining_checkpoints', []],
            'BattleReports' => ['SELECT COUNT(*) n FROM battle_reports WHERE saved_count>0 OR expires_at>? OR ready=0', [microtime(true)]],
            'BattleReportSaves' => ['SELECT COUNT(*) n FROM battle_report_saves s JOIN battle_reports r ON r.id=s.report_id WHERE r.saved_count>0 OR r.expires_at>? OR r.ready=0', [microtime(true)]],
        };
        $runtimeStores[] = ['class' => $name, 'runtimeSqlRows' => (int)$database->fetchOne($query, $parameters)['n'], 'layout' => ['mainBuckets' => $layout['rows']]];
    }
}
$extra['MiningPending'] = memoryModel(\SPGame\Game\Repositories\RuntimeRecords::layout('MiningCheckpoints')['rows'], [[Table::TYPE_INT, 8]], 1.0);
$cleanupPolicies = [];
foreach (['ProtocolCommands' => 512, 'BattleReports' => 128] as $name => $minimum) {
    $capacity = \SPGame\Game\Repositories\RuntimeRecords::layout($name)['rows'];
    $steps = \SPGame\Game\Repositories\RuntimeRecords::sweepBudget($name, $minimum);
    $cleanupPolicies[$name] = ['capacity' => $capacity, 'maxStepsPerCall' => $steps,
        'idealFullPassCalls' => (int)ceil($capacity / $steps),
        'budgetMs' => \SPGame\Game\Repositories\RuntimeRecords::SWEEP_BUDGET_MS];
}
$result = ['php' => PHP_VERSION, 'swoole' => phpversion('swoole'),
    'workerMailboxPolicy' => ['reserveBeforeDispatch' => true, 'lateReplyCreatesRow' => false,
        'conflict' => WorkerMailbox::CONFLICT,
        'CombatMailbox' => ['slots' => FleetBattleService::MAILBOX_CAPACITY, 'maxBytes' => PlanetBattles::MAX_STATE_BYTES],
        'ExpeditionMailbox' => ['slots' => FleetExpeditionService::MAILBOX_CAPACITY, 'maxBytes' => FleetExpeditions::MAX_BYTES,
            'workerLimit' => FleetExpeditionService::WORKER_LIMIT]],
    'playerQueuePayloadPolicy' => ['slots' => PlayerQueuePayloads::capacity(), 'maxBytes' => PlayerQueuePayloads::MAX_BYTES,
        'perAccount' => PlayerQueuePayloads::MAX_PER_ACCOUNT, 'ttlSeconds' => PlayerQueuePayloads::TTL_SECONDS],
    'snapshotPolicy' => ['maxBytes' => \SPGame\Game\Services\RepositorySaver::configuredSnapshotMaxBytes(),
        'entryMaxBytes' => \SPGame\Game\Services\RepositorySaver::SNAPSHOT_ENTRY_MAX_BYTES,
        'sqlBatchRows' => (new ReflectionMethod(\SPGame\Game\Repositories\BaseRepository::class, 'writeSaveSnapshot'))->getParameters()[2]->getDefaultValue(),
        'format' => 'packed-entry', 'budget' => 'php-peak-delta-at-boundaries'],
    'profile' => \SPGame\Game\Repositories\RuntimeCapacity::profile(),
    'modelApplicable' => phpversion('swoole') === '5.1.6' && PHP_INT_SIZE === 8,
    'modelNotRss' => true, 'repositories' => $repositories, 'extraTables' => $extra, 'runtimeStores' => $runtimeStores,
    'cleanupPolicies' => $cleanupPolicies,
    'totalSharedAllocationModelBytes' => array_sum(array_column($repositories, 'totalAllocationModelBytes'))
        + array_sum(array_column($extra, 'sharedAllocationModelBytes'))];
if (in_array('--probe', $argv, true)) {
    // Отдельные маленькие extension-таблицы, не игровой мир и не нагрузочный тест.
    $result['boundedProbes'] = [];
    foreach ([[1024, 'decimal', 1500], [2048, 'decimal', 3000], [8192, 'decimal', 10000],
        [16384, 'decimal', 20000], [4096, 'sha256', 8000], [16384, 'sha256', 16000]] as [$size, $keys, $limit]) {
        $table = new Table($size);
        $table->column('value', Table::TYPE_INT, 1);
        $table->create();
        $failed = null;
        for ($id = 1; $id <= $limit; ++$id) {
            $key = $keys === 'decimal' ? (string)$id : substr(hash('sha256', 'bounded-audit-' . $id), 0, 32);
            if (!@$table->set($key, ['value' => 300])) { $failed = $id; break; }
        }
        $result['boundedProbes'][] = ['buckets' => $size, 'keys' => $keys, 'firstRejected' => $failed,
            'count' => $table->count(), 'stats' => $table->stats()];
        $table->destroy();
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
