<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Вывод содержит хеши: только приватный pipe в импортёр/шифрование, не логи или публичный файл.
ini_set('display_errors', '0');
try {
    if (stream_isatty(STDOUT)) {
        throw new RuntimeException('Экспорт секретных данных в терминал запрещён.');
    }
    $options = getopt('', ['source:']);
    $root = dirname(__DIR__);
    $source = $options['source'] ?? '';
    if (!is_string($source) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $source)) {
        throw new InvalidArgumentException('Нужен --source.');
    }
    require $root . '/vendor/autoload.php';
    \SPGame\Core\Environment::init($root . '/.env');
    $database = \SPGame\Core\Database::independent();
    $database->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $database->query('SET TRANSACTION READ ONLY');
    $database->beginTransaction();
    $rows = $database->fetchAll('SELECT a.id,a.login,a.email,a.password,a.verify_email,a.lang,a.reg_time,u.id AS player_id
        FROM accounts a LEFT JOIN users u ON u.account_id = a.id ORDER BY a.id');
    $orphans = $database->fetchAll('SELECT u.id,u.account_id FROM users u LEFT JOIN accounts a ON a.id = u.account_id WHERE a.id IS NULL ORDER BY u.id');
    $database->commit();
    $accounts = [];
    foreach ($rows as $row) {
        $accounts[] = ['id' => (int) $row['id'], 'login' => $row['login'], 'email' => $row['email'], 'passwordHash' => $row['password'],
            'verified' => (bool) $row['verify_email'], 'language' => $row['lang'], 'registeredAt' => (int) $row['reg_time'],
            'playerId' => $row['player_id'] === null ? null : (int) $row['player_id']];
    }
    echo json_encode(['format' => 1, 'source' => $source, 'accounts' => $accounts, 'orphanPlayers' => $orphans], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, 'Экспорт аккаунтов не выполнен: ' . $error::class . PHP_EOL);
    exit(1);
}
