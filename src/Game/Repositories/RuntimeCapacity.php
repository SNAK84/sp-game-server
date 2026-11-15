<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\{Environment, Logger};

/** Бюджеты хранения, не технологические лимиты игрового баланса. */
final class RuntimeCapacity
{
    /** Не более80% заполнения выбранных бюджетов в расчётном target-сценарии. */
    public const TARGET_MAX_FILL = 0.80;

    public const TARGET = [
        'Accounts' => 32768, 'Users' => 32768, 'Techs' => 32768, 'UserResources' => 32768,
        'Planets' => 131072, 'PlanetResources' => 131072, 'Builds' => 131072,
        'Ships' => 131072, 'Defenses' => 131072, 'EntitySettings' => 1048576,
        // Сценарий20K/1K:480000 команд с историей плюс минимум20% свободных мест.
        'Fleets' => 131072, 'FleetsShips' => 131072, 'FleetCommands' => 1048576,
        'Queues' => 131072, 'Friends' => 131072, 'Messages' => 1048576,
        'MessageBodiesOverflow' => 262144, 'ProtocolSubscriptions' => 16384,
        //1000 online ×4 больших события; не менее20% свободных мест.
        'PlayerQueuePayloads' => 8192,
        'Galaxy' => 8192, 'GalaxyOrbits' => 262144,
        'ResourceSurveys' => 131072, 'SurveyResults' => 32768,
        'SensorContacts' => 32768,
        'Config' => 256,
    ];

    public static function profile(): string
    {
        $profile = Environment::get('SP_GAME_CAPACITY_PROFILE', 'development');
        if (!in_array($profile, ['development', 'target'], true)) throw new \InvalidArgumentException('Неизвестный профиль ёмкости.');
        return $profile;
    }

    public static function budget(string $name, int $default): int
    {
        if (self::profile() === 'target') return self::TARGET[$name] ?? $default;
        return $name === 'Config' ? 256 : $default;
    }

    public static function indexBudget(string $name, int $main): int
    {
        // Список ID разбит на блоки16; для почты не нужны миллион buckets на каждый индекс.
        return $name === 'Messages' && self::profile() === 'target' ? 262144 : $main;
    }

    /** До создания первой Table: SQL-вместимость и консервативная проверка RAM. */
    public static function preflight(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/bin/audit-capacity.php', '--' . self::profile(), '--sql-counts'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Не запущена проверка ёмкости.');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new \RuntimeException('Проверка ёмкости не завершена.');
        $inventory = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        foreach (array_merge($inventory['repositories'], $inventory['runtimeStores']) as $repository) {
            if (($repository['runtimeSqlRows'] ?? $repository['sqlRows'] ?? 0) > $repository['layout']['mainBuckets']) {
                throw new \OverflowException('До загрузки мира: ' . $repository['class'] . ' превышает бюджет.');
            }
        }
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $match);
        $physical = isset($match[1]) ? (int)$match[1] * 1024 : 0;
        $limit = @file_get_contents('/sys/fs/cgroup/memory.max');
        if (is_string($limit) && ctype_digit(trim($limit))) $physical = $physical > 0 ? min($physical, (int)trim($limit)) : (int)trim($limit);
        $model = (int)$inventory['totalSharedAllocationModelBytes'];
        if ($physical > 0 && $model > $physical * 0.70) {
            throw new \OverflowException('Профиль ' . self::profile() . ': модель Table '
                . round($model / 1073741824, 2) . ' GiB превышает70% RAM '
                . round($physical / 1073741824, 2) . ' GiB; запуск до выделения памяти запрещён.');
        }
        Logger::getInstance()->info('Предварительная проверка ёмкости завершена',
            ['profile' => self::profile(), 'tableModelMiB' => round($model / 1048576, 2), 'physicalMiB' => round($physical / 1048576, 2)]);
    }
}
