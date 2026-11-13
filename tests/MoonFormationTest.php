<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$logger = SPGame\Core\Logger::getInstance();
(new ReflectionProperty($logger, 'islogFile'))->setValue($logger, false);
require __DIR__ . '/FleetAttackTest.php';

use SPGame\Game\Repositories\{Builds, Config, DebrisFields, Defenses, Messages, PlanetBattles, PlanetResources, Planets, Ships, Vars};
use SPGame\Game\Services\{AccountData, FleetAttackService as Attack, Helpers, MoonFormationService as Moons};

/** Config::setValue() намеренно пишет в настоящую БД; unit fixture меняет только runtime double. */
function moonSetting(string $name, string $value): void
{
    $row = Config::findByIndex('name', $name);
    if ($row === null) Config::add(['name' => $name, 'value' => $value]);
    else Config::update(['id' => $row['id'], 'value' => $value]);
}

function moonFixture(bool $consume = true): array
{
    $f = attackFixture();
    (new ReflectionProperty(Config::class, 'syncTable'))->setValue(null, transportSyncTable(3));
    moonSetting('MoonFactor', '100000');
    moonSetting('MoonChance', '100');
    moonSetting('DebrisMoon', $consume ? '1' : '0');
    moonSetting('FieldsByMoonBasis', '3');
    Vars::$resource[41] = 'mondbasis';
    return $f;
}

function moonBattle(array $f): float
{
    attackStart($f);
    attackArrive($f);
    return attackCalculate();
}

function moonAt(): ?array
{
    return Planets::findByIndex('galaxy_system_planet_type', [1, 10, 8, 3]);
}

$f = attackFixture();
$rules = Moons::rules();
transportAssert($rules === ['factor' => 1.0, 'maximum' => 20, 'consumeDebris' => true], 'Дефолты Nova.');
foreach ([0 => 0, 49999 => 0, 50000 => 1, 100000 => 1, 150000 => 2, 2000000 => 20, 9000000 => 20] as $amount => $chance) {
    transportAssert(Moons::chance($amount, $rules) === $chance, 'Nova round/cap: ' . $amount);
}
transportAssert(Moons::chance(100000, ['factor' => 2, 'maximum' => 20]) === 2, 'Коэффициент шанса.');
DebrisFields::addResources(1, 10, 8, 9000000, 1000000, 100);
transportAssert(Moons::prepare($f['target'], [901 => 0, 902 => 0], 'seed', 100, $rules)['chance'] === 0,
    'Старые обломки сами не дают шанса.');
$yes = $no = null;
for ($i = 0; $i < 100 && ($yes === null || $no === null); ++$i) {
    $value = Moons::prepare($f['target'], [901 => 2000000, 902 => 0], (string)$i, 100, $rules);
    if ($value['created']) $yes = [(string)$i, $value];
    else $no = [(string)$i, $value];
}
transportAssert($yes !== null && $no !== null, 'Проверены успех и неудачный бросок при 20%.');
transportAssert(Moons::prepare($f['target'], [901 => 2000000, 902 => 0], $yes[0], 100, $rules) === $yes[1], 'Повтор стабилен.');
transportAssert($yes[1]['row']['size'] >= 8366 && $yes[1]['row']['size'] <= 8944, 'Диаметр Nova при 20%.');
transportAssert(moonAt() === null, 'Prepare не создаёт runtime луну.');
echo "OK moon Nova chance/rounding/cap, old debris excluded, deterministic success/failure and size\n";

$f = moonFixture();
DebrisFields::addResources(1, 10, 8, 1000, 2000, 100);
$now = moonBattle($f);
$parent = Planets::findExistingById(11);
Attack::process($now);
$moon = moonAt();
$state = PlanetBattles::state(11);
transportAssert($moon !== null && $state['phase'] === 'applied', 'Луна создана при применении боя.');
transportAssert($moon['name_generated'] === 1
    && $moon['name'] === \SPGame\Game\Services\CelestialNameGenerator::moonName(1, 10, 8), 'Луна получает каноническое процедурное имя в существующем commit.');
transportAssert($moon['owner_id'] === 200 && $moon['fields'] === 1 && $moon['planet_type'] === 3, 'Луна принадлежит защитнику, одно поле.');
transportAssert($moon['size'] >= 17606 && $moon['size'] <= 17888, 'Размер Nova при тестовых 100%.');
transportAssert($moon['temp_min'] <= $moon['temp_max'] && $moon['temp_max'] < $parent['temp_max'], 'Холоднее родительской планеты.');
transportAssert($moon['distance'] === $parent['distance'] && $moon['speed'] === $parent['speed']
    && $moon['rotation'] === $parent['rotation'], 'Общий orbital anchor.');
$resources = PlanetResources::findExistingById($moon['id']);
transportAssert($resources === ['id' => $moon['id'], 'metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0, 'energy' => 0.0], 'Нет стартовых ресурсов колонии.');
foreach ([Planets::class, Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $repo) {
    transportAssert($repo::findExistingById($moon['id']) !== null && in_array($moon['id'], $repo::getPendingSync()['update'], true), 'Runtime/dirty ' . $repo);
}
transportAssert(DebrisFields::at(1, 10, 8)['metal'] === 0.0 && DebrisFields::at(1, 10, 8)['crystal'] === 0.0, 'Всё поле использовано.');
transportAssert($state['resolution']['moon']['consumedDebris'] === [901 => 3400.0, 902 => 5000.0], 'Учтены и новые, и старые обломки.');
transportAssert(Planets::findExistingById(11) === $parent, 'Планета не превращается в луну.');
$report = BattleReportsMemory::$rows[$state['id']]['report'];
transportAssert($report['moon']['id'] === $moon['id'] && $report['moon']['created'] && $report['moon']['chance'] === 100, 'Итог записан в архив.');
transportAssert(!str_contains(json_encode($report), $state['seed']), 'Seed не раскрывается в докладе.');
$snapshot = [Planets::getAll(), PlanetResources::getAll(), DebrisFields::getAll(), Messages::getAll()];
Attack::process($now + 1);
transportAssert($snapshot === [Planets::getAll(), PlanetResources::getAll(), DebrisFields::getAll(), Messages::getAll()], 'Повтор не создаёт вторую луну.');
$account = new AccountData(2000);
$account['WorkPlanet'] = $moon['id'];
transportAssert($account['Planet']['planet_type'] === 3 && Helpers::getMaxFields($account) === 1, 'Луна доступна через AccountData.');
$account['Builds']['mondbasis'] = 2;
transportAssert(Helpers::getMaxFields($account) === 7, 'Поля расширяются лунной базой.');
transportAssert(count(Planets::findByIndex('owner_id', [200])) >= 1, 'Луна есть в индексе владельца.');
echo "OK moon runtime commit, owner/anchor, zero resources, dirty markers, report, replay and lunar base\n";

$f = moonFixture(false);
DebrisFields::addResources(1, 10, 8, 1000, 2000, 100);
$now = moonBattle($f);
Attack::process($now);
transportAssert(moonAt() !== null && DebrisFields::at(1, 10, 8)['metal'] === 3400.0
    && DebrisFields::at(1, 10, 8)['crystal'] === 5000.0, 'Настройка сохраняет поле обломков.');
echo "OK moon DebrisMoon=0 keeps old and new debris\n";

$f = moonFixture();
$existing = Planets::castRowToSchema(array_replace($f['target'], ['id' => 12, 'planet_type' => 3]), true);
Planets::add($existing);
$now = moonBattle($f);
Attack::process($now);
transportAssert(moonAt() === $existing && PlanetBattles::state(11)['resolution']['moon']['reason'] === 'existing_moon', 'Вторая луна не создаётся.');
transportAssert(DebrisFields::at(1, 10, 8)['metal'] === 2400.0, 'При существующей луне обломки не расходуются.');
transportAssert(Moons::prepare($existing, [901 => 9000000, 902 => 9000000], 'seed', $now, Moons::rules())['reason'] === 'not_planet', 'Бой на луне не создаёт луну.');
echo "OK moon existing moon and moon target suppress formation\n";

$f = moonFixture();
moonSetting('MoonChance', '20');
$now = moonBattle($f);
$state = PlanetBattles::state(11);
$state['seed'] = $no[0];
PlanetBattles::store(11, $state);
Attack::process($now);
transportAssert(moonAt() === null && PlanetBattles::state(11)['resolution']['moon']['reason'] === 'roll_failed'
    && DebrisFields::at(1, 10, 8)['metal'] === 2400.0, 'Неудачный бросок оставляет новые обломки.');
echo "OK moon failed roll keeps battle debris\n";

$f = moonFixture();
Vars::$CombatCaps[202]['attack'] = 2000000;
$now = moonBattle($f);
Attack::process($now);
transportAssert(PlanetBattles::state(11)['resolution']['outcome'] === 'draw' && moonAt() !== null,
    'Победа атакующего не обязательна для образования луны.');
echo "OK moon forms after a draw without requiring attacker victory\n";

foreach ([Planets::class, Builds::class, Ships::class, Defenses::class, PlanetResources::class, Messages::class] as $failedRepo) {
    $f = moonFixture();
    DebrisFields::addResources(1, 10, 8, 1000, 2000, 100);
    $now = moonBattle($f);
    $state = PlanetBattles::state(11);
    $decision = Moons::prepare($f['target'], [901 => 2400, 902 => 3000], $state['seed'], $now, $state['mission']['moonRules']);
    $before = [Planets::getAll(), Builds::getAll(), PlanetResources::getAll(), Ships::getAll(), Defenses::getAll(), DebrisFields::getAll()];
    $table = (new ReflectionProperty($failedRepo, 'table'))->getValue();
    $table->failedWrites = 1;
    $table->failedWriteKey = $failedRepo === Messages::class ? '2' : '12';
    Attack::process($now);
    transportAssert(PlanetBattles::state(11)['phase'] === 'finished' && moonAt() === null, 'Rollback допускает retry: ' . $failedRepo);
    transportAssert($before === [Planets::getAll(), Builds::getAll(), PlanetResources::getAll(), Ships::getAll(), Defenses::getAll(), DebrisFields::getAll()], 'Rollback данных: ' . $failedRepo);
    moonSetting('MoonChance', '0'); // Настройки уже зафиксированы в начале боя.
    Attack::process($now + 1);
    transportAssert(PlanetBattles::state(11)['phase'] === 'applied' && moonAt() !== null, 'Retry успешен: ' . $failedRepo);
    transportAssert(moonAt()['size'] === $decision['row']['size'] && PlanetBattles::state(11)['resolution']['moon']['chance'] === 100, 'Нет переброса/изменения правил.');
    transportAssert(count(BattleReportsMemory::$rows) === 1 && count(Messages::getAll()) === 2, 'Доклады не задвоены.');
    echo "OK moon injected failure/rollback/retry: {$failedRepo}\n";
}

$f = moonFixture();
$now = moonBattle($f);
BattleReportsMemory::$failPrepare = true;
Attack::process($now);
transportAssert(moonAt() === null && PlanetBattles::state(11)['phase'] === 'finished', 'SQL failure не создаёт луну.');
BattleReportsMemory::$failPrepare = false;
BattleReportsMemory::$failPublish = true;
Attack::process($now + 1);
$moon = moonAt();
transportAssert($moon !== null && PlanetBattles::state(11)['phase'] === 'settled', 'Moon commit до публикации архива.');
BattleReportsMemory::$failPublish = false;
Attack::process($now + 2);
transportAssert(moonAt() === $moon && PlanetBattles::state(11)['phase'] === 'applied', 'Retry публикации не пересоздаёт луну.');
echo "OK moon archive prepare/publish failures and retries\n";

if (in_array('--export-report', $argv ?? [], true)) {
    echo 'REPORT_FIXTURE ', json_encode(BattleReportsMemory::$rows[PlanetBattles::state(11)['id']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
}
