<?php

declare(strict_types=1);

/** B017: Logger основного контейнера, без запуска игрового мира или второго сервера. */
require dirname(__DIR__) . '/vendor/autoload.php';

use SPGame\Core\Environment;
use SPGame\Core\Logger;
use SPGame\Core\LogLevel;
use SPGame\Core\Time;

Environment::init(dirname(__DIR__) . '/.env');
Time::initTimeZone();
$logger = Logger::getInstance();
// Уровень меняется только у этого CLI-экземпляра, не в сервере и не в .env.
(new ReflectionProperty($logger, 'minLevel'))->setValue($logger, LogLevel::DEBUG);
(new ReflectionMethod($logger, 'updateLogFile'))->invoke($logger);
$file = (new ReflectionProperty($logger, 'logFile'))->getValue($logger);
clearstatcache(true, $file);
$offset = is_file($file) ? filesize($file) : 0;

$fields = ['token', 'access_token', 'RefreshToken', 'scanToken', 'password', 'old_password',
    'newPassword', 'password_hash', 'passwordConfirmation', 'passwd', 'pwd', 'passphrase',
    'email_pin', 'PIN', 'pin_code', 'otp', 'verification_code', 'secret', 'client_secret',
    'api_key', 'private_key', 'Authorization', 'Set-Cookie', 'session_id'];
$context = ['accountId' => 15, 'requestId' => 'B017-test-request', 'resources' => [901 => 37]];
$secrets = [];
foreach ($fields as $field) {
    $secrets[] = $context['nested'][$field] = 'b017_' . bin2hex(random_bytes(12));
}
$context['object'] = (object)['password' => $secrets[0], 'visible' => 'object-ok'];
$context['serializable'] = new class($secrets[1]) implements JsonSerializable {
    public function __construct(private string $secret) {}
    public function jsonSerialize(): array { return ['refresh_token' => $this->secret, 'visible' => 'json-ok']; }
};
$context['sqlError'] = "SQLSTATE[23000]: Duplicate entry '{$secrets[2]}' for key 'uniq_token'";
$context['cycle'] = [];
$context['cycle']['self'] = &$context['cycle'];
$original = $context['nested'];

ob_start();
foreach (LogLevel::cases() as $level) $logger->log($level, 'B017_TEST context', $context);
ini_set('zend.exception_ignore_args', '0');
try {
    (static function (string $token, string $password): never {
        throw new RuntimeException('B017_TEST exception');
    })($secrets[0], $secrets[1]);
} catch (Throwable $error) {
    $logger->logException($error, 'B017_TEST trace');
}
$output = ob_get_clean();
$fresh = file_get_contents($file, false, null, $offset);
$checks = [
    'consoleClean' => !array_filter($secrets, static fn(string $secret): bool => str_contains($output, $secret)),
    'fileClean' => !array_filter($secrets, static fn(string $secret): bool => str_contains($fresh, $secret)),
    'inputUnchanged' => $context['nested'] === $original
        && $context['object']->password === $secrets[0]
        && $context['serializable']->jsonSerialize()['refresh_token'] === $secrets[1],
    'diagnosticsPreserved' => str_contains($fresh, 'B017-test-request') && str_contains($fresh, 'uniq_token')
        && str_contains($fresh, 'object-ok') && str_contains($fresh, 'json-ok') && str_contains($fresh, '"901":37'),
    'redacted' => substr_count($fresh, '[REDACTED]') >= count($fields) * count(LogLevel::cases()),
    'boundedCycle' => str_contains($fresh, '[TRUNCATED]'),
    'traceWithoutArgs' => !str_contains($fresh, '"args"'),
];
echo json_encode(['passed' => !in_array(false, $checks, true), 'checks' => $checks,
    'levels' => count(LogLevel::cases()), 'fields' => count($fields), 'file' => $file,
    'offset' => $offset], JSON_THROW_ON_ERROR), PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
