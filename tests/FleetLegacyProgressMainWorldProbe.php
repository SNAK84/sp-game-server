<?php
declare(strict_types=1);

use Swoole\Table;
use SPGame\Game\Repositories\{Accounts, Users, Fleets, FleetCommands};
use SPGame\Game\Services\{FleetsData, FleetControlService as Control};

/** Только счётчик read-through: основная Table/индексы/SQL не копируются. */
final class LegacyProgressReadTable extends Table
{
    public int $reads = 0;
    public function __construct(private readonly Table $source)
    {
        parent::__construct(64);
        $this->column('id', self::TYPE_INT, 8);
        if (!$this->create()) throw new RuntimeException('Нет счётчика чтений QA.');
    }
    public function get(string $key, ?string $field = null): array|string|int|float|false
    {
        ++$this->reads;
        return $field === null ? $this->source->get($key) : $this->source->get($key, $field);
    }
    public function set(string $key, array $value): bool { throw new LogicException('Progress обязан быть read-only.'); }
    public function del(string $key): bool { throw new LogicException('Progress обязан быть read-only.'); }
}

/** Только временный bootstrap основного dev со старой историей QA, до сети/saver. */
return static function (int $fleetId, string $output): void {
    if (file_exists($output)) return;
    $fleet = Fleets::findExistingById($fleetId);
    $owner = $fleet === null ? null : Users::findExistingById((int)$fleet['owner_id']);
    $account = $owner === null ? null : Accounts::findExistingById((int)$owner['account_id']);
    if (($account['login'] ?? '') !== 'qaown_260924' || (int)$fleet['status'] !== 1) throw new LogicException('Нужен QA-рейс.');
    $rows = FleetCommands::getFleetCommandChain($fleetId);
    $holds = $legacy = 0;
    foreach ($rows as $row) {
        if ((int)$row['command_type'] === 43) ++$holds;
        $p = Control::params($row);
        if (in_array((int)$row['command_type'], [10,11,12], true) && !empty($p['cancelled'])
            && !isset($p['progressCompleted']) && (float)($p['startedAt'] ?? 0) > 0) ++$legacy;
    }
    if (count($rows) < 64 || $legacy < 24) throw new LogicException('Нужна настоящая многократная история старых остановок.');
    $reference = require __DIR__ . '/FleetLegacyProgressReference.php';
    [$weights,$completed] = $expected = $reference($rows);
    $normalized = $rows;
    foreach ($normalized as &$row) {
        if (!in_array((int)$row['command_type'], [10,11,12], true)) continue;
        $p = Control::params($row);
        if (isset($weights[$row['id']])) $p['progressWeight'] = $weights[$row['id']];
        if (!empty($p['cancelled'])) $p['progressCompleted'] ??= $completed[$row['id']] ?? 0;
        $row['params'] = json_encode($p, JSON_THROW_ON_ERROR);
    }
    unset($row);
    $object = FleetsData::getInstance()->get($fleetId);
    $property = new ReflectionProperty(FleetCommands::class, 'table');
    $source = $property->getValue();
    $counter = new LegacyProgressReadTable($source);
    $sync = (new ReflectionProperty(FleetCommands::class, 'syncTable'))->getValue();
    $before = iterator_to_array($sync);
    $checks = 0; $error = null; $reads = $routeReads = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('B074 main: ' . $label);
        ++$checks;
    };
    try {
        $property->setValue(null, $counter);
        $view = FleetCommands::view($fleetId);
        $actual = (new ReflectionMethod(Control::class, 'legacyProgress'))->invoke(null, $view);
        $reads = $counter->reads;
        $check($actual === $expected, 'старый oracle и настоящий ID-view равны');
        $check($reads <= 3 * count($rows) + 2 * $holds, 'нет вложенных native чтений истории');
        $counter->reads = 0;
        $route = Control::routeProgress($object, $view);
        $routeReads = $counter->reads;
        $check($routeReads <= 4 * count($rows) + 2 * $holds, 'bounded чтение всей шкалы');
        $check($route === Control::routeProgress($object, $normalized), 'полная шкала совпала с metadata старого oracle');
    } catch (Throwable $failure) { $error = $failure; }
    finally { $property->setValue(null, $source); }
    $check(FleetCommands::getFleetCommandChain($fleetId) === $rows, 'вся история read-only');
    $check(Fleets::findExistingById($fleetId) === $fleet, 'Fleet read-only');
    $check(iterator_to_array($sync) === $before, 'dirty markers read-only');
    file_put_contents($output, json_encode(['ok'=>$error===null,'checks'=>$checks,'rows'=>count($rows),
        'legacyMoves'=>$legacy,'legacyReads'=>$reads,'routeReads'=>$routeReads,'error'=>$error?->getMessage()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
