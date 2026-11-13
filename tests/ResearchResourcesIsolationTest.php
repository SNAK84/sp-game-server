<?php

declare(strict_types=1);

namespace SPGame\Core {
    // Проверка не имеет доступа к MySQL, даже на чтение.
    final class Database
    {
        public static int $calls = 0;

        public static function getInstance(): self
        {
            ++self::$calls;
            throw new \LogicException('SQL запрещён в изолированной проверке исследований.');
        }
    }
}

namespace {
    if (!extension_loaded('swoole')) {
        fwrite(STDERR, "SKIP: необходим Swoole; запустите тест отдельным PHP-процессом в контейнере.\n");
        exit(77);
    }

    require __DIR__ . '/bootstrap.php';

    use SPGame\Core\{Database, Logger, LogLevel};
    use SPGame\Game\Repositories\{Accounts, Builds, Config, Defenses, EntitySettings, PlanetBattles,
        PlanetResources, Planets, PlayerQueue, Queues, Resources, Ships, Techs, Users, UserResources, Vars};
    use SPGame\Game\Services\{AccountData, EventLoop, Helpers, QueuesServices};
    use Swoole\Table;

    // Все репозитории получают НОВЫЕ Tables этого процесса, без init()/загрузки БД.
    ini_set('memory_limit', '1G');
    $logger = Logger::getInstance();
    (new ReflectionProperty($logger, 'minLevel'))->setValue($logger, LogLevel::ERROR);
    (new ReflectionProperty($logger, 'logFile'))->setValue($logger, sys_get_temp_dir() . '/sp-game-research-test-' . getmypid() . '.log');
    (new ReflectionProperty($logger, 'islogFile'))->setValue($logger, false);
    set_error_handler(static function (int $code, string $message, string $file, int $line): never {
        $GLOBALS['researchWarnings'][] = $message;
        throw new ErrorException($message, 0, $code, $file, $line);
    });
    class_exists(Resources::class); // В этом файле объявлены также PlanetResources/UserResources.

    function researchRepository(string $class, array $rows, array $extraColumns = []): void
    {
        $schema = (new ReflectionProperty($class, 'tableSchema'))->getValue();
        foreach ($extraColumns as $name) {
            $schema['columns'][$name] = ['swoole' => [Table::TYPE_INT, 8], 'default' => 0];
        }
        (new ReflectionProperty($class, 'tableSchema'))->setValue(null, $schema);
        $table = new Table(128);
        foreach ($schema['columns'] as $name => $column) {
            $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 8);
        }
        $table->create();
        (new ReflectionProperty($class, 'table'))->setValue(null, $table);

        $sync = new Table(256);
        foreach (['need_update', 'need_delete', 'last_id'] as $name) {
            $sync->column($name, Table::TYPE_INT, 8);
        }
        $sync->create();
        $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
        (new ReflectionProperty($class, 'syncTable'))->setValue(null, $sync);
        $indexes = [];
        foreach ((new ReflectionProperty($class, 'indexes'))->getValue() as $name => $definition) {
            $index = new Table(128);
            $index->column('id', Table::TYPE_INT, 8);
            // Как в BaseRepository::initIndex: уникальный индекс содержит только id.
            if (!$definition['Unique']) $index->column('ids', Table::TYPE_STRING, 8192);
            $index->create();
            $indexes[$name] = $index;
        }
        (new ReflectionProperty($class, 'indexTables'))->setValue(null, $indexes);
        (new ReflectionProperty($class, 'logger'))->setValue(null, Logger::getInstance());
        if ($class === PlayerQueue::class) {
            \SPGame\Game\Repositories\PlayerQueuePayloads::init();
            \SPGame\Game\Repositories\PlayerQueuePayloads::deleteExpired(PHP_FLOAT_MAX);
        }
        $maxId = 0;
        foreach ($rows as $row) {
            $class::add($class::castRowToSchema($row, true), false);
            $maxId = max($maxId, (int)$row['id']);
        }
        $class::setLastId($maxId);
    }

    function researchFixture(bool $queued = false, bool $warm = false, int $current = 101, int $next = 102): AccountData
    {
        Vars::$resource = [6 => 'university', 14 => 'robot_factory', 15 => 'nano_factory', 21 => 'hangar',
            22 => 'metal_store', 31 => 'laboratory', 33 => 'terraformer', 113 => 'energy_tech',
            123 => 'intergalactic_tech', 131 => 'metal_proc_tech', 132 => 'crystal_proc_tech', 133 => 'deuterium_proc_tech',
            901 => 'metal', 902 => 'crystal', 903 => 'deuterium', 911 => 'energy', 921 => 'credit', 922 => 'doubloon'];
        Vars::$reslist = ['build' => [6, 14, 15, 21, 22, 31, 33], 'tech' => [113, 123, 131, 132, 133],
            'fleet' => [], 'defense' => [], 'missile' => [], 'bonus' => [], 'officier' => [], 'prod' => [],
            'storage' => [22], 'one' => [], 'resstype' => [1 => [901, 902, 903], 2 => [911], 3 => [921, 922]],
            'ressources' => [901, 902, 903, 911, 921, 922], 'allow' => [1 => [6, 14, 15, 21, 22, 31, 33], 3 => []]];
        Vars::$requirement = [113 => [31 => 1]];
        Vars::$bonus = Vars::$production = [];
        Vars::$storage = [22 => [901 => '1000000000', 902 => '1000000000', 903 => '1000000000']];
        Vars::$pricelist = [113 => [901 => 1000, 902 => 500, 903 => 200]];
        Vars::$attributes = [113 => ['factor' => 1, 'max' => 100]];

        $config = [];
        foreach (['GameSpeed' => 2500, 'FactorsUniversity' => 0, 'MinBuildTime' => 1, 'MaxQueueTech' => 5,
            'FieldsByTerraformer' => 5, 'ResourceMultiplier' => 1, 'StorageMultiplier' => 1, 'EnergySpeed' => 1,
            'SpeedPlanets' => 24, 'metalBasicIncome' => 20, 'crystalBasicIncome' => 10, 'deuteriumBasicIncome' => 0] as $name => $value) {
            $config[] = ['id' => count($config) + 1, 'name' => $name, 'value' => (string)$value];
        }
        researchRepository(Config::class, $config); // Не Config::setValue(): он пишет в MySQL.
        researchRepository(Accounts::class, [['id' => 1, 'login' => 'isolated-research', 'lang' => 'ru']]);
        researchRepository(Users::class, [['id' => 1, 'account_id' => 1, 'name' => 'Isolated',
            'main_planet' => 101, 'current_planet' => $current]]);
        $planets = $builds = $resources = $empty = [];
        foreach ([101, 102, 103] as $index => $id) {
            $planets[] = ['id' => $id, 'owner_id' => 1, 'name' => 'P' . ($index + 1), 'galaxy' => 1, 'system' => 1,
                'planet' => $index + 1, 'planet_type' => 1, 'fields' => 1000, 'size' => 10000, 'temp_max' => 40,
                'temp_min' => 0, 'create_time' => 1000, 'update_time' => 1000, 'distance' => 1000 * ($index + 1),
                'speed' => 1.0, 'rotation' => 1, 'deg' => 0.0];
            $builds[] = ['id' => $id, 'laboratory' => 1];
            $resources[] = ['id' => $id, 'metal' => 100000 * ($index + 1), 'crystal' => 80000 * ($index + 1),
                'deuterium' => 60000 * ($index + 1), 'energy' => 0];
            $empty[] = ['id' => $id];
        }
        researchRepository(Planets::class, $planets);
        researchRepository(Builds::class, $builds, array_map(static fn($id) => Vars::$resource[$id], Vars::$reslist['build']));
        researchRepository(Techs::class, [['id' => 1]], array_map(static fn($id) => Vars::$resource[$id], Vars::$reslist['tech']));
        researchRepository(Ships::class, $empty);
        researchRepository(Defenses::class, $empty);
        researchRepository(PlanetResources::class, $resources);
        researchRepository(UserResources::class, [['id' => 1]]);
        researchRepository(EntitySettings::class, []);
        researchRepository(PlanetBattles::class, []); // Только пустая таблица для штатного queue guard.
        researchRepository(PlayerQueue::class, []);
        $rows = [['id' => 1, 'user_id' => 1, 'planet_id' => 101, 'object_id' => 113, 'count' => 1,
            'action' => 'build', 'type' => 'techs', 'time' => 1000, 'start_time' => 1000, 'end_time' => 2000, 'status' => 'active']];
        if ($queued) {
            $rows[] = ['id' => 2, 'user_id' => 1, 'planet_id' => $next, 'object_id' => 113, 'count' => 2,
                'action' => 'build', 'type' => 'techs', 'time' => 1000, 'start_time' => 2000, 'end_time' => 3000, 'status' => 'queued'];
        }
        researchRepository(Queues::class, $rows);
        $account = new AccountData(1);
        if ($warm) {
            foreach ([101, 102, 103] as $id) {
                $account['WorkPlanet'] = $id;
                $account['Resources']->toArray();
            }
            $account['WorkPlanet'] = $current;
        }
        return $account;
    }

    function researchAssert(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    function researchLedger(): array
    {
        $result = [];
        foreach ([101, 102, 103] as $id) {
            $result[$id] = array_intersect_key(PlanetResources::findExistingById($id), array_flip(['metal', 'crystal', 'deuterium']));
        }
        return $result;
    }

    function researchBalances(array $actual, array $expected): void
    {
        foreach ($expected as $id => $row) foreach ($row as $name => $value) {
            researchAssert(isset($actual[$id][$name]) && abs($actual[$id][$name] - $value) < 0.000001,
                "Планета {$id}, {$name}: ожидалось {$value}, получено " . ($actual[$id][$name] ?? 'нет значения'));
        }
    }

    function researchAccrue(array $balances, float $seconds, array $ids = [101, 102, 103]): array
    {
        foreach ($ids as $id) {
            $balances[$id]['metal'] += 20 * $seconds / 3600;
            $balances[$id]['crystal'] += 10 * $seconds / 3600;
        }
        return $balances;
    }

    function researchCharge(array $balances, int $id, float $factor = 1): array
    {
        foreach (['metal' => 1000, 'crystal' => 500, 'deuterium' => 200] as $key => $cost) {
            $balances[$id][$key] -= $cost * $factor;
        }
        return $balances;
    }

    function researchTick(AccountData &$account, float $time): void
    {
        // Только реальные очереди/ресурсы EventLoop, без process() и обработки флотов.
        (new ReflectionMethod(EventLoop::class, 'processPlayerStateAtTime'))->invokeArgs(new EventLoop(), [$time, &$account]);
        $account->save();
    }

    $passed = $failed = 0;
    function researchCase(string $name, callable $test): void
    {
        global $passed, $failed;
        $GLOBALS['researchWarnings'] = [];
        try {
            $test();
            researchAssert($GLOBALS['researchWarnings'] === [], 'PHP warning: ' . implode('; ', $GLOBALS['researchWarnings']));
            ++$passed;
            echo "PASS {$name}\n";
        } catch (Throwable $e) {
            ++$failed;
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }

    if (defined('SP_GAME_RESEARCH_FIXTURE_ONLY') && SP_GAME_RESEARCH_FIXTURE_ONLY) return;

    researchCase('getData(other): баланс, постройки, температура и время запрошенной планеты', static function (): void {
        $a = researchFixture();
        Planets::update(['id' => 102, 'temp_max' => 80, 'update_time' => 1500]);
        Builds::update(['id' => 102, 'metal_store' => 2]);
        Vars::$storage[22][901] = '1000 * pow(2, $BuildLevel) + $BuildTemp';
        $before = researchLedger();
        $data = $a->getData(102);
        researchAssert($data['Planet']['id'] === 102 && $data['Resources'][901]['count'] === 200000.0, 'Загружен баланс другой планеты.');
        researchAssert($data['Resources'][901]['max'] === 4080.0 && $data['Resources'][901]['time'] === 1500.0, 'Потерян контекст расчёта.');
        researchAssert($a['WorkPlanet'] === 101 && $a['User']['current_planet'] === 101, 'Чтение переключило игрока.');
        $a->save();
        researchBalances(researchLedger(), $before);
        $pending = PlanetResources::getPendingSync();
        researchAssert($pending['update'] === [] && $pending['delete'] === [], 'Чтение пометило ресурсы dirty.');
    });

    foreach (['All_Builds', 'All_Resources', 'network'] as $read) foreach ([false, true] as $warm) {
        researchCase("{$read}: " . ($warm ? 'warm' : 'cold'), static function () use ($read, $warm): void {
            $a = researchFixture(false, $warm, 103);
            $before = researchLedger();
            if ($read === 'network') Helpers::getNetworkLevels($a);
            else $a[$read]->toArray();
            $cache = (new ReflectionProperty($a, 'Planets'))->getValue($a);
            foreach ($before as $id => $row) foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $rid => $key) {
                researchAssert($cache[$id]['Resources'][$rid]['count'] === $row[$key], "Неверный cache {$id}/{$key}.");
            }
            researchAssert($a['WorkPlanet'] === 103 && $a['User']['current_planet'] === 103, 'Потерян исходный контекст.');
            $a->save();
            researchBalances(researchLedger(), $before);
        });
    }

    researchCase('All_Builds: сохраняет уже изменённый баланс текущей планеты', static function (): void {
        $a = researchFixture();
        $before = researchLedger();
        $a['Resources'][901]['count'] += 900;
        $a['All_Builds']->toArray();
        researchAssert($a['Resources'][901]['count'] === $before[101]['metal'] + 900, 'Затёрт изменённый баланс.');
        $a->save();
        $before[101]['metal'] += 900;
        researchBalances(researchLedger(), $before);
    });

    researchCase('getData(moon): нулевая базовая добыча луны, а не текущей планеты', static function (): void {
        $a = researchFixture();
        Planets::update(['id' => 102, 'planet_type' => 3]);
        $moon = $a->getData(102);
        researchAssert($moon['Resources'][901]['perhour'] == 0 && $moon['Resources'][902]['perhour'] == 0, 'Луна получила базовую добычу планеты.');
        researchAssert($a['Resources'][901]['perhour'] == 20 && $a['WorkPlanet'] === 101, 'Изменён контекст исходной планеты.');
    });

    foreach ([101, 102] as $next) foreach ([false, true] as $warm) {
        researchCase("complete → next {$next}: " . ($warm ? 'warm' : 'cold'), static function () use ($next, $warm): void {
            $a = researchFixture(true, $warm, 103, $next);
            $before = researchLedger();
            researchTick($a, 2000);
            researchBalances(researchLedger(), researchCharge(researchAccrue($before, 1000), $next));
            researchAssert(Queues::findExistingById(1) === null && Queues::findExistingById(2)['status'] === 'active', 'Следующая задача не активирована.');
            researchAssert(Techs::findExistingById(1)['energy_tech'] === 1, 'Исследование не завершено.');
            researchAssert(Users::findExistingById(1)['current_planet'] === 103, 'Изменена выбранная игроком планета.');
        });
        researchCase("cancel → next {$next}: " . ($warm ? 'warm' : 'cold'), static function () use ($next, $warm): void {
            $a = researchFixture(true, $warm, 103, $next);
            $before = researchLedger();
            QueuesServices::CancelToQueue(1, $a, 1100);
            $a->save();
            $expected = researchCharge($before, 101, -0.9); // Возврат 90% только на исходную планету.
            $expected = researchCharge(researchAccrue($expected, 100, [$next]), $next);
            researchBalances(researchLedger(), $expected);
            researchAssert(Queues::findExistingById(1) === null && Queues::findExistingById(2)['status'] === 'active', 'Отмена/активация не завершена.');
            // Даже повторное использование того же cache не должно распространить возврат.
            researchTick($a, 1100);
            $other = array_values(array_diff([101, 102, 103], [$next]));
            researchBalances(researchLedger(), researchAccrue($expected, 100, $other));
        });
    }

    foreach ([false, true] as $warm) {
        researchCase('cancel last: без warning, возврат только на исходную планету, ' . ($warm ? 'warm' : 'cold'), static function () use ($warm): void {
            $a = researchFixture(false, $warm, 103);
            $before = researchLedger();
            QueuesServices::CancelToQueue(1, $a, 1100);
            $a->save();
            researchBalances(researchLedger(), researchCharge($before, 101, -0.9));
            researchAssert((Queues::getAll() ?? []) === [], 'Последняя задача не удалена.');
        });
    }

    researchCase('cancel queued: без возврата/списания', static function (): void {
        $a = researchFixture(true, false, 103, 101);
        $before = researchLedger();
        QueuesServices::CancelToQueue(2, $a, 1100);
        $a->save();
        researchBalances(researchLedger(), $before);
        researchAssert(Queues::findExistingById(2) === null && Queues::findExistingById(1)['status'] === 'active', 'Изменена активная задача.');
    });

    researchCase('complete last: только обычная добыча каждой планеты', static function (): void {
        $a = researchFixture();
        $before = researchLedger();
        researchTick($a, 2000);
        researchBalances(researchLedger(), researchAccrue($before, 1000));
        researchAssert((Queues::getAll() ?? []) === [] && Techs::findExistingById(1)['energy_tech'] === 1, 'Последнее исследование не завершено.');
    });

    researchCase('start first: одно списание только на исходной планете', static function (): void {
        $a = researchFixture();
        Queues::delete(Queues::findExistingById(1));
        $before = researchLedger();
        QueuesServices::AddToQueue(113, $a, 1100);
        $a->save();
        researchBalances(researchLedger(), researchCharge($before, 101));
        researchAssert(count(Queues::getAll() ?? []) === 1, 'Новая задача не создана.');
    });

    researchCase('cancel → новый AccountData → tick: возврат не распространяется', static function (): void {
        $a = researchFixture(true, false, 103, 101);
        QueuesServices::CancelToQueue(1, $a, 1100);
        $a->save();
        $before = researchLedger();
        $a = new AccountData(1);
        researchTick($a, 1101);
        $expected = researchAccrue($before, 101, [102, 103]);
        researchBalances(researchLedger(), researchAccrue($expected, 1, [101]));
    });

    researchCase('rich source: не создаёт ресурсы на бедных планетах при network=0', static function (): void {
        $a = researchFixture(true, false, 101, 101);
        foreach ([101 => [900000, 800000, 700000], 102 => [2000, 1600, 1200], 103 => [3000, 2400, 1800]] as $id => $v) {
            PlanetResources::update(['id' => $id, 'metal' => $v[0], 'crystal' => $v[1], 'deuterium' => $v[2]]);
        }
        $before = researchLedger();
        researchTick($a, 2000);
        researchBalances(researchLedger(), researchCharge(researchAccrue($before, 1000), 101));
    });

    researchCase('достаточно двух планет: следующий уровень на той же планете', static function (): void {
        $a = researchFixture(true, false, 101, 101);
        Planets::delete(Planets::findExistingById(103));
        $before = researchLedger();
        researchTick($a, 2000);
        researchBalances(researchLedger(), researchCharge(researchAccrue($before, 1000, [101, 102]), 101));
    });

    researchCase('ошибка Resources::get: восстановление контекста и повторная загрузка', static function (): void {
        $a = researchFixture();
        $a['Resources']->toArray();
        $before = researchLedger();
        Vars::$storage[22][901] = '(throw new \\RuntimeException("injected resource load failure"))';
        $caught = false;
        try {
            $a->getData(102);
        } catch (RuntimeException $e) {
            $caught = $e->getMessage() === 'injected resource load failure';
        }
        researchAssert($caught, 'Ошибка загрузки не была передана вызывающему коду.');
        researchAssert($a['WorkPlanet'] === 101 && $a['User']['current_planet'] === 101, 'Контекст не восстановлен после exception.');
        researchAssert(!isset((new ReflectionProperty($a, 'Planets'))->getValue($a)[102]), 'Остался частично загруженный cache.');
        Vars::$storage[22][901] = '1000000000';
        researchAssert($a->getData(102)['Resources'][901]['count'] === $before[102]['metal'], 'Повторная загрузка не восстановилась.');
        $a->save();
        researchBalances(researchLedger(), $before);
    });

    researchCase('безопасность: ни одного обращения к MySQL', static function (): void {
        researchAssert(Database::$calls === 0, 'Тест попытался использовать БД.');
    });
    restore_error_handler();
    echo "TOTAL: {$passed} passed, {$failed} failed; SQL calls: " . Database::$calls . "\n";
    exit($failed === 0 ? 0 : 1);
}
