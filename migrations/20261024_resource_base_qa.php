<?php
declare(strict_types=1);

// Разрешённая подготовка только qaown_260924 и включение этапа5А на dev.
// Без --apply только SELECT. Применение запрещено при работающем WS.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{Config, Ships, Techs, Vars};

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$same = static function (array $a, array $b): bool { ksort($a); ksort($b); return $a === $b; };
$check($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] === 'qaown_260924', 'Другой QA-аккаунт');
$check((int)$db->fetchOne('SELECT owner_id FROM planets WHERE id=26')['owner_id'] === 9, 'Другая QA-планета');
$tech = $db->fetchOne('SELECT * FROM techs WHERE id=9');
$ships = $db->fetchOne('SELECT * FROM ships WHERE id=26');
$config = $db->fetchOne('SELECT * FROM config WHERE name=?', ['ResourceBasesEnabled']);
$check($tech !== null && $ships !== null && $config !== null, 'Нет исходных строк');
echo "PREVIEW: QA9 mining technology ->1; planet26 small cargo +2; ResourceBasesEnabled ->1\n";
if (!in_array('--apply', $argv, true)) exit;
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT',9501), $errno, $errstr, .5);
if (is_resource($socket)) { fclose($socket); throw new RuntimeException('Сначала остановите основной сервер'); }
$check((int)$tech['extraterrestrial_mining_tech'] === 0 && (int)$config['value'] === 0, 'Подготовка уже применена либо исходное состояние изменилось');
$backup = __DIR__ . '/../logs/resource-base-qa-20261024-before.json';
$fingerprint = static function () use ($db): array {
    $out = [];
    foreach (['techs' => ' WHERE id<>9', 'ships' => ' WHERE id<>26', 'resources_planet' => '',
        'resources_user' => '', 'fleets' => '', 'fleets_ships' => '', 'resource_surveys' => ''] as $table => $where) {
        $out[$table] = hash('sha256',json_encode($db->fetchAll("SELECT * FROM `$table`$where ORDER BY id"),JSON_THROW_ON_ERROR));
    }
    return $out;
};
$hashes = $fingerprint();
if (file_exists($backup)) {
    $check(in_array('--resume', $argv, true), 'Backup уже существует; нужен явный --resume');
    $saved = json_decode(file_get_contents($backup),true,512,JSON_THROW_ON_ERROR);
    foreach (compact('tech','ships','config','hashes') as $key => $value) {
        $check($same($saved[$key], $value), 'После остановки изменились данные: ' . $key);
    }
} else {
    $check(file_put_contents($backup,json_encode(compact('tech','ships','config','hashes'),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX) !== false, 'Не сохранён backup');
    chmod($backup,0600);
}
Vars::init(); Techs::init(); Ships::init(); Config::init();
$tech['extraterrestrial_mining_tech'] = 1;
$ships['small_ship_cargo'] += 2;
$config['value'] = '1';
Techs::update($tech); Ships::update($ships); Config::update($config);
$check($same(Techs::findById(9), $tech) && $same(Ships::findById(26), $ships) && $same(Config::findById((int)$config['id']), $config), 'Runtime не совпал с ожидаемым');
Techs::syncToDatabase(); Ships::syncToDatabase(); Config::syncToDatabase();
$check($same($db->fetchOne('SELECT * FROM techs WHERE id=9'), $tech) && $same($db->fetchOne('SELECT * FROM ships WHERE id=26'), $ships), 'Сохранение QA не совпало с ожидаемым');
$check($same($db->fetchOne('SELECT * FROM config WHERE id=?',[(int)$config['id']]), $config), 'Флаг не сохранён');
$check($fingerprint() === $hashes, 'Изменились другие данные');
echo "APPLIED: QA mining1/cargo+2; bases enabled; other technologies/ships/resources/fleets/surveys unchanged\n";
