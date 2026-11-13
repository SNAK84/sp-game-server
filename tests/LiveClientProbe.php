<?php

declare(strict_types=1);

/**
 * Live-проверка текущего WebSocket pipeline как минимальный клиент.
 *
 * Запуск:
 *   php tests/LiveClientProbe.php
 *
 * На stdin передаются две строки: login и password.
 * Пароль не выводится и не сохраняется.
 */

const WS_HOST = '127.0.0.1';
const WS_PORT = 9501;

function readExact($socket, int $length): string
{
    $result = '';
    while (strlen($result) < $length) {
        $chunk = fread($socket, $length - strlen($result));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('WebSocket connection closed while reading frame.');
        }
        $result .= $chunk;
    }
    return $result;
}

function wsFrame(string $payload, int $opcode = 0x1): string
{
    $length = strlen($payload);
    $mask = random_bytes(4);
    $masked = '';

    for ($i = 0; $i < $length; ++$i) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }

    $header = chr(0x80 | $opcode);
    if ($length < 126) {
        $header .= chr(0x80 | $length);
    } elseif ($length <= 0xffff) {
        $header .= chr(0x80 | 126) . pack('n', $length);
    } else {
        $header .= chr(0x80 | 127) . pack('J', $length);
    }

    return $header . $mask . $masked;
}

/** @return array{opcode:int,payload:string} */
function readWsFrame($socket): array
{
    $first = ord(readExact($socket, 1));
    $second = ord(readExact($socket, 1));
    $opcode = $first & 0x0f;
    $length = $second & 0x7f;

    if ($length === 126) {
        $length = unpack('n', readExact($socket, 2))[1];
    } elseif ($length === 127) {
        $parts = unpack('N2', readExact($socket, 8));
        $length = ($parts[1] << 32) | $parts[2];
    }

    $masked = ($second & 0x80) !== 0;
    $mask = $masked ? readExact($socket, 4) : '';
    $payload = readExact($socket, $length);

    if ($masked) {
        $unmasked = '';
        for ($i = 0; $i < $length; ++$i) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $unmasked;
    }

    return ['opcode' => $opcode, 'payload' => $payload];
}

function connectWebSocket()
{
    $errorCode = 0;
    $errorMessage = '';
    $socket = fsockopen(WS_HOST, WS_PORT, $errorCode, $errorMessage, 5);
    if ($socket === false) {
        throw new RuntimeException("WebSocket connect failed: {$errorMessage} ({$errorCode})");
    }

    stream_set_timeout($socket, 5);
    $key = base64_encode(random_bytes(16));
    $request = "GET / HTTP/1.1\r\n"
        . "Host: " . WS_HOST . ':' . WS_PORT . "\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";

    fwrite($socket, $request);
    $headers = '';
    while (!str_contains($headers, "\r\n\r\n")) {
        $chunk = fgets($socket);
        if ($chunk === false) {
            throw new RuntimeException('WebSocket handshake failed.');
        }
        $headers .= $chunk;
    }

    if (!str_contains($headers, ' 101 ')) {
        throw new RuntimeException('Unexpected WebSocket handshake response.');
    }

    return $socket;
}

function sendMessage($socket, array $message): array
{
    fwrite($socket, wsFrame(json_encode($message, JSON_THROW_ON_ERROR)));

    while (true) {
        $frame = readWsFrame($socket);
        if ($frame['opcode'] === 0x9) {
            fwrite($socket, wsFrame($frame['payload'], 0xA));
            continue;
        }
        if ($frame['opcode'] === 0xA) {
            continue;
        }
        if ($frame['opcode'] !== 0x1) {
            continue;
        }

        $response = json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('WebSocket response is not an object.');
        }
        return $response;
    }
}

function assertResponse(array $response, string $requestId, string $label): void
{
    if (($response['requestId'] ?? null) !== $requestId) {
        throw new RuntimeException("{$label}: requestId mismatch.");
    }
    if (($response['status'] ?? null) !== 'ok') {
        $error = $response['error']['message'] ?? 'unknown server error';
        throw new RuntimeException("{$label}: server returned error: {$error}");
    }
    if (!isset($response['serverTime'])) {
        throw new RuntimeException("{$label}: serverTime is missing.");
    }
}

function request(string $mode, string $action, array $data = [], ?string $token = null): array
{
    static $sequence = 0;
    ++$sequence;

    $message = [
        'status' => 'ok',
        'requestId' => sprintf('live-client-%03d', $sequence),
        'mode' => $mode,
        'action' => $action,
        'data' => $data,
    ];
    if ($token !== null) {
        $message['token'] = $token;
    }
    return $message;
}

$login = rtrim((string)fgets(STDIN), "\r\n");
$password = rtrim((string)fgets(STDIN), "\r\n");
if ($login === '' || $password === '') {
    fwrite(STDERR, "Expected login and password on stdin.\n");
    exit(2);
}

$socket = null;
try {
    $socket = connectWebSocket();

    $handshake = request('connect', 'handshake');
    $response = sendMessage($socket, $handshake);
    if (($response['requestId'] ?? null) !== $handshake['requestId']) {
        throw new RuntimeException('handshake: requestId mismatch.');
    }
    if (($response['status'] ?? null) === 'ok') {
        if (!isset($response['serverTime'])) {
            throw new RuntimeException('handshake: serverTime is missing.');
        }
        echo "PASS handshake\n";
    } elseif (($response['error']['code'] ?? null) === 'missing_token') {
        echo "OBSERVED handshake without token: missing_token\n";
    } else {
        $error = $response['error']['message'] ?? 'unknown server error';
        throw new RuntimeException("handshake: unexpected server error: {$error}");
    }

    $loginRequest = request('login', 'login', [
        'login' => $login,
        'password' => $password,
    ]);
    $loginResponse = sendMessage($socket, $loginRequest);
    assertResponse($loginResponse, $loginRequest['requestId'], 'login');

    $token = (string)($loginResponse['token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('login: token is missing.');
    }
    echo "PASS login\n";

    $overviewRequest = request('overview', '', [], $token);
    $overviewResponse = sendMessage($socket, $overviewRequest);
    assertResponse($overviewResponse, $overviewRequest['requestId'], 'overview');
    echo "PASS overview\n";

    $fleetsRequest = request('fleets', 'ActualData', [], $token);
    $fleetsResponse = sendMessage($socket, $fleetsRequest);
    assertResponse($fleetsResponse, $fleetsRequest['requestId'], 'fleets');

    $page = $fleetsResponse['data']['Page'] ?? null;
    if (!is_array($page) || ($page['page'] ?? null) !== 'fleet') {
        throw new RuntimeException('fleets: expected fleet page payload.');
    }
    if (!isset($page['FleetList']) || !is_array($page['FleetList'])) {
        throw new RuntimeException('fleets: FleetList is missing.');
    }
    echo 'PASS fleets (' . count($page['FleetList']) . " fleets)\n";

    fwrite($socket, wsFrame('', 0x8));
    fclose($socket);
    echo "LIVE CLIENT CHECK PASSED\n";
    exit(0);
} catch (Throwable $exception) {
    if (is_resource($socket)) {
        fclose($socket);
    }
    fwrite(STDERR, 'LIVE CLIENT CHECK FAILED: ' . $exception->getMessage() . "\n");
    exit(1);
}
