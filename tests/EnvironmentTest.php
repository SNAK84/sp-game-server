<?php

declare(strict_types=1);

// Настоящий Environment, но только тестовые env-ключи/файл: без игры/SQL/Swoole.
require __DIR__ . '/bootstrap.php';
use SPGame\Core\Environment;

$property = new ReflectionProperty(Environment::class, 'env');
$fileProperty = new ReflectionProperty(Environment::class, 'envFile');
$key = 'SP_GAME_ENV_TEST_VALUE';
$saved = [$property->getValue(), $fileProperty->getValue(), array_key_exists($key, $_ENV), $_ENV[$key] ?? null, getenv($key)];
$passed = $failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) { ++$passed; return; }
    ++$failed; echo 'FAIL ' . $label . PHP_EOL;
};
$required = static function () use ($key): ?string {
    try { return Environment::require($key); }
    catch (RuntimeException) { return null; }
};
$select = static function (string $source, string $value) use ($property, $key): void {
    // Нижестоящие значения специально отличаются, чтобы ловить смену приоритета.
    $property->setValue(null, $source === 'file' ? [$key => $value] : []);
    $_ENV[$key] = $source === 'file' ? '91' : $value;
    putenv($key . '=' . ($source === 'process' ? $value : '92'));
    if ($source === 'process') unset($_ENV[$key]);
};

try {
    foreach (['file', 'superglobal', 'process'] as $source) {
        foreach (['0', '00', '0.0', '-1', '1', 'false', 'true', 'FALSE', 'yes', 'on', 'no', 'off', '42', ' ', 'value'] as $value) {
            $select($source, $value);
            $label = $source . '/' . json_encode($value);
            $check(Environment::get($key, 'fallback') === $value, $label . ' get');
            $check(Environment::get($key) === $value, $label . ' get-null-default');
            $check(Environment::getInt($key, 7) === (int)$value, $label . ' int');
            $bool = in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
            $check(Environment::getBool($key, true) === $bool, $label . ' bool');
            $check($required() === $value, $label . ' require');
        }
        $select($source, '');
        $check(Environment::get($key, 'fallback') === 'fallback', $source . ' empty-default-not-lower-source');
        $check(Environment::get($key) === null, $source . ' empty-null');
        $check(Environment::getInt($key, 7) === 7, $source . ' empty-int-default');
        $check(Environment::getBool($key, true) === true, $source . ' empty-bool-default');
        $check($required() === null, $source . ' require-empty-rejects');
    }

    $property->setValue(null, [$key => null]); $_ENV[$key] = '0'; putenv($key . '=99');
    $check(Environment::get($key, 'fallback') === '0', 'null-file-falls-through-to-superglobal-zero');
    $_ENV[$key] = null; putenv($key . '=0');
    $check(Environment::get($key, 'fallback') === '0', 'null-superglobal-falls-through-to-process-zero');
    $property->setValue(null, []); unset($_ENV[$key]); putenv($key);
    $check(Environment::get($key, 'fallback') === 'fallback', 'missing-default');
    $check(Environment::get($key, '0') === '0', 'missing-zero-default');
    $check(Environment::get($key) === null, 'missing-null');
    $check(Environment::getInt($key, 7) === 7, 'missing-int-default');
    $check(Environment::getBool($key, true) === true, 'missing-bool-default');
    $check($required() === null, 'require-missing-rejects');

    // Проверяем также реальный parser/init/reload, не только env-map через Reflection.
    $fixture = tempnam(sys_get_temp_dir(), 'sp-game-env-test-');
    if ($fixture === false) throw new RuntimeException('Не создан временный env-fixture.');
    $content = "# Только синтетические значения, никаких credentials\n"
        . $key . "=0 # ноль не комментарий\nSP_GAME_ENV_TEST_QUOTED=\"0\"\n"
        . "SP_GAME_ENV_TEST_SINGLE='0'\nSP_GAME_ENV_TEST_EMPTY=\nSP_GAME_ENV_TEST_EMPTY_QUOTED=\"\"\n";
    if (file_put_contents($fixture, $content) === false) throw new RuntimeException('Не записан env-fixture.');
    Environment::init($fixture);
    $check(Environment::get($key, 'fallback') === '0', 'parser-unquoted-zero');
    $check(Environment::getInt($key, 7) === 0, 'parser-int-zero');
    $check(Environment::getBool($key, true) === false, 'parser-bool-zero');
    $check(Environment::get('SP_GAME_ENV_TEST_QUOTED', 'fallback') === '0', 'parser-double-quoted-zero');
    $check(Environment::get('SP_GAME_ENV_TEST_SINGLE', 'fallback') === '0', 'parser-single-quoted-zero');
    foreach (['SP_GAME_ENV_TEST_EMPTY', 'SP_GAME_ENV_TEST_EMPTY_QUOTED'] as $empty) {
        $check(Environment::get($empty, 'fallback') === 'fallback', 'parser-empty-default');
    }
    $check(Environment::all()[$key] === '0' && Environment::all()['SP_GAME_ENV_TEST_EMPTY'] === '', 'all-keeps-raw-values');
    if (file_put_contents($fixture, $key . "=41\n") === false) throw new RuntimeException('Не обновлён env-fixture.');
    Environment::init($fixture);
    $check(Environment::getInt($key, 7) === 41, 'reload-replaces-cache');
} finally {
    $property->setValue(null, $saved[0]); $fileProperty->setValue(null, $saved[1]);
    if ($saved[2]) $_ENV[$key] = $saved[3]; else unset($_ENV[$key]);
    if ($saved[4] === false) putenv($key); else putenv($key . '=' . $saved[4]);
}

echo "RESULT environment: {$passed} passed / {$failed} failed; SQL=0, Tables=0\n";
exit($failed > 0 ? 1 : 0);
