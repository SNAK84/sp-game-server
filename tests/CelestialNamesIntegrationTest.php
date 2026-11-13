<?php

declare(strict_types=1);

namespace SPGame\Game\Services {
    // Отдельная последовательность только в процессе теста позволяет сравнить
    // физику с зафиксированным результатом генератора ДО интеграции имён.
    final class CelestialTestRandom
    {
        public static \Random\Randomizer $random;
    }
    function random_int(int $min, int $max): int { return CelestialTestRandom::$random->getInt($min, $max); }
    function mt_rand(int $min, int $max): int { return random_int($min, $max); }
    function rand(int $min, int $max): int { return random_int($min, $max); }
    function array_rand(array $values): int|string { return array_keys($values)[random_int(0, count($values) - 1)]; }
    function time(): int { return 1792880000; }
    function microtime(bool $asFloat = false): float|string { return $asFloat ? 1792880000.25 : '0.250000 1792880000'; }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use SPGame\Core\{Logger, LogLevel};
    use SPGame\Game\Repositories\{Config, Galaxy, GalaxyOrbits, OrbitalResourceSites, Planets};
    use SPGame\Game\Services\{CelestialNameGenerator as Names, CelestialTestRandom, GalaxyGenerator, MoonFormationService};
    use Swoole\Table;

    if (!extension_loaded('swoole')) { echo "SKIP: нужен native Swoole.\n"; exit(77); }
    $logger = Logger::getInstance();
    (new \ReflectionProperty($logger, 'islogFile'))->setValue($logger, false);
    (new \ReflectionProperty($logger, 'minLevel'))->setValue($logger, LogLevel::ERROR);

    function celestialIntegrationAssert(bool $ok, string $message): void
    {
        if (!$ok) throw new \RuntimeException($message);
    }

    /** Только Tables этого процесса; init()/MySQL/игровой runtime не используются. */
    function celestialIntegrationInstall(string $class): void
    {
        $schema = (new \ReflectionProperty($class, 'tableSchema'))->getValue();
        $table = new Table(128);
        foreach ($schema['columns'] as $column => $definition) $table->column($column, ...$definition['swoole']);
        $table->create();
        $sync = new Table(256);
        foreach (['need_update', 'need_delete', 'last_id'] as $column) $sync->column($column, Table::TYPE_INT, 8);
        $sync->create();
        $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
        $indexes = [];
        foreach ((new \ReflectionProperty($class, 'indexes'))->getValue() as $name => $definition) {
            $index = new Table(128);
            $index->column('id', Table::TYPE_INT, 8);
            if (!$definition['Unique']) $index->column('ids', Table::TYPE_STRING, 2048);
            $index->create();
            $indexes[$name] = $index;
        }
        foreach (['table' => $table, 'syncTable' => $sync, 'indexTables' => $indexes, 'logger' => Logger::getInstance()] as $key => $value) {
            (new \ReflectionProperty($class, $key))->setValue(null, $value);
        }
        // assertMutable() не должен обращаться к игровым checkpoint.
        (new \ReflectionProperty($class, 'tableName'))->setValue(null, '');
    }

    function celestialPhysical(array $row): array
    {
        unset($row['name'], $row['name_generated']);
        return $row;
    }

    foreach ([Config::class, Galaxy::class, GalaxyOrbits::class, Planets::class, OrbitalResourceSites::class] as $class) celestialIntegrationInstall($class);
    foreach (['SpeedPlanets' => '24', 'MaxGalaxy' => '1', 'MaxSystem' => '50', 'LastGalaxyPos' => '1', 'LastSystemPos' => '1'] as $key => $value) Config::add(['name' => $key, 'value' => $value]);
    CelestialTestRandom::$random = new \Random\Randomizer(new \Random\Engine\Mt19937(421731));

    $system = GalaxyGenerator::generateSystem(1, 1);
    $orbits = GalaxyOrbits::findByIndex('galaxy_system', [1, 1]);
    usort($orbits, static fn(array $a, array $b): int => $a['orbit'] <=> $b['orbit']);
    $sites = array_values(OrbitalResourceSites::getAll() ?? []);
    usort($sites, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    $position = null;
    foreach ($orbits as $orbit) {
        if ((int)$orbit['type'] === GalaxyOrbits::EMPTY) {
            $position = ['galaxy' => 1, 'system' => 1, 'orbit' => $orbit['orbit'], 'distance' => $orbit['distance']];
            break;
        }
    }
    $explicit = GalaxyGenerator::CreatePlanet(51, 'Тихая гавань', false, $position);
    celestialIntegrationAssert($explicit['rotation'] === $system['rotation'], 'CreatePlanet меняет направление системы.');
    // Старый физический fixture включал лишний rand() после создания планеты.
    // Только здесь пропускаем его отсчёт, чтобы не перезаписывать эталон
    // несвязанных температур/размеров из-за удаления этого вызова в B018.
    CelestialTestRandom::$random->getInt(0, 1);
    $physics = [];
    foreach (Galaxy::$starTypes as $type => $profile) {
        $size = (int)round(array_sum($profile['size']) / 2);
        foreach ([500, 1500, 4500] as $distance) $physics[] = GalaxyGenerator::generatePlanet($type, $distance, false, $size, 1);
    }
    $homePhysics = GalaxyGenerator::generatePlanet('G', 1500, true, 16, 1);
    $moon = MoonFormationService::prepare($explicit, [901 => 9000000, 902 => 500000], 'physical-baseline', 1792880000.25,
        ['factor' => 1.0, 'maximum' => 100, 'consumeDebris' => true]);
    $snapshot = ['system' => celestialPhysical($system), 'orbits' => $orbits, 'sites' => $sites,
        'explicitPlanet' => celestialPhysical($explicit), 'physicalPlanets' => $physics, 'homePhysics' => $homePhysics,
        'moonPhysics' => celestialPhysical($moon['row'])];
    if (in_array('--physical-snapshot', $argv, true)) {
        echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    $baseline = json_decode(file_get_contents(__DIR__ . '/fixtures/celestial-physical-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
    celestialIntegrationAssert($snapshot === $baseline, 'Геометрия/орбиты/ресурсы/физика планеты или луны изменились относительно версии до интеграции имён.');
    celestialIntegrationAssert($system['name'] === Names::systemName(1, 1), 'Имя новой системы не сохранено.');
    celestialIntegrationAssert($explicit['name'] === 'Тихая гавань' && $explicit['name_generated'] === 0, 'Явное имя изменено.');
    celestialIntegrationAssert($moon['row']['name_generated'] === 1 && $moon['row']['name'] !== '', 'Существующее образование луны не получило имя.');
    celestialIntegrationAssert($moon === MoonFormationService::prepare($explicit, [901 => 9000000, 902 => 500000], 'physical-baseline', 1792880000.25,
        ['factor' => 1.0, 'maximum' => 100, 'consumeDebris' => true]), 'Повтор подготовки луны изменяет имя или физику.');

    $used = [$explicit['name']];
    foreach ([null, '', '   '] as $name) {
        $free = array_values(array_filter(GalaxyOrbits::findByIndex('galaxy_system', [1, 1]), static fn(array $row): bool => $row['type'] === GalaxyOrbits::EMPTY));
        $orbit = $free[0];
        $auto = GalaxyGenerator::CreatePlanet(51, $name, false,
            ['galaxy' => 1, 'system' => 1, 'orbit' => $orbit['orbit'], 'distance' => $orbit['distance']]);
        celestialIntegrationAssert($auto['name_generated'] === 1 && !in_array($auto['name'], $used, true), 'Автоматическое имя пусто или занято.');
        celestialIntegrationAssert(Planets::findById($auto['id'])['name'] === $auto['name'], 'Имя обрезалось в native Swoole.');
        celestialIntegrationAssert($auto['rotation'] === $system['rotation'], 'Автоматическая планета теряет направление системы.');
        $used[] = $auto['name'];
    }
    $home = GalaxyGenerator::CreatePlanet(52, HomeWorld: true);
    $homeSystem = Galaxy::getSystem((int)$home['galaxy'], (int)$home['system']);
    celestialIntegrationAssert($home['rotation'] === $homeSystem['rotation'], 'Домашняя планета теряет направление системы.');
    celestialIntegrationAssert($home['name_generated'] === 1 && $home['name'] !== 'HomeworldName', 'Стартовая планета не получает автоматическое имя.');
    celestialIntegrationAssert(Planets::findById($explicit['id'])['name'] === 'Тихая гавань', 'Существующее ручное имя перезаписано.');
    try {
        GalaxyGenerator::CreatePlanet(51, str_repeat('Я', 17), false, $position);
        throw new \RuntimeException('Принято явное имя сверх байтового ограничения.');
    } catch (\InvalidArgumentException) {}
    echo "PASS native creation: explicit/null/empty/default homeworld names, moon retry and byte limits\n";
    echo "PASS pre-integration physical baseline: star, all orbits/sites, 21 planets, homeworld and moon unchanged\n";
}
