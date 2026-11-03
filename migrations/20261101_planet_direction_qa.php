<?php
declare(strict_types=1);

// B018: подготовка только отдельного QA-аккаунта в основном dev.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--inspect', '--prepare-account', '--prepare-flight'], true)) throw new LogicException('Неизвестный режим B018.');
$template = $db->fetchOne('SELECT * FROM accounts WHERE id=15');
if (($template['login'] ?? '') !== 'qaown_260924') throw new LogicException('Другой dev-контекст.');
$account = $db->fetchOne('SELECT id FROM accounts WHERE login=?', ['qa_b018_home']);
if ($mode !== '--inspect') {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
    if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
    $insert = static function(string $table, array $row) use ($db): void {
        $db->query('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    };
    $db->beginTransaction();
    try {
        if ($mode === '--prepare-account') {
            if ($account !== null) throw new LogicException('QA-аккаунт уже существует.');
            $accountId = (int)$db->fetchOne('SELECT MAX(id) n FROM accounts')['n'] + 1;
            $userId = (int)$db->fetchOne('SELECT MAX(id) n FROM users')['n'] + 1;
            $insert('accounts', array_replace($template, ['id' => $accountId, 'login' => 'qa_b018_home',
                'email' => 'qa_b018_home@example.invalid', 'token' => null, 'verify_email' => 1,
                'reg_time' => time(), 'last_time' => time()]));
            $user = $db->fetchOne('SELECT * FROM users WHERE id=9');
            $insert('users', array_replace($user, ['id' => $userId, 'account_id' => $accountId,
                'name' => 'QA B018', 'main_planet' => 0, 'current_planet' => 0, 'create_time' => time()]));
            $techs = $db->fetchOne('SELECT * FROM techs WHERE id=9');
            $insert('techs', array_replace($techs, ['id' => $userId, 'expedition_tech' => 1]));
        } else {
            if ($account === null) throw new LogicException('Нет QA-аккаунта.');
            $user = $db->fetchOne('SELECT * FROM users WHERE account_id=?', [$account['id']]);
            $planets = $db->fetchAll('SELECT * FROM planets WHERE owner_id=?', [$user['id']]);
            if (count($planets) !== 1 || (int)$user['main_planet'] !== (int)$planets[0]['id']
                || $db->fetchOne('SELECT id FROM fleets WHERE owner_id=?', [$user['id']]) !== null) {
                throw new LogicException('Ожидалась одна новая домашняя планета без флотов.');
            }
            $planetId = (int)$planets[0]['id'];
            $ships = $db->fetchOne('SELECT * FROM ships WHERE id=?', [$planetId]);
            if ($ships === null || (int)$ships['colonizer'] !== 0 || (int)$ships['small_ship_cargo'] !== 0) {
                throw new LogicException('Корабли уже подготовлены или отсутствует строка ангара.');
            }
            $db->query('UPDATE ships SET colonizer=1,small_ship_cargo=1 WHERE id=?', [$planetId]);
            $db->query('UPDATE resources_planet SET deuterium=deuterium+20000 WHERE id=?', [$planetId]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $account = $db->fetchOne('SELECT id FROM accounts WHERE login=?', ['qa_b018_home']);
}
$user = $account === null ? null : $db->fetchOne('SELECT id,account_id,main_planet,current_planet FROM users WHERE account_id=?', [$account['id']]);
echo json_encode(['account' => $account, 'user' => $user, 'planets' => $user === null ? [] : $db->fetchAll(
    'SELECT p.id,p.name,p.galaxy,p.system,p.planet,p.rotation,g.rotation system_rotation FROM planets p JOIN galaxy g ON p.galaxy=g.galaxy AND p.system=g.system WHERE p.owner_id=?', [$user['id']])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
