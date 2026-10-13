<?php

declare(strict_types=1);

/**
 * Изолированные проверки взаимодействий, видимых клиенту.
 *
 * Запуск:
 *   php tests/ClientInteractionTest.php
 *
 * Тесты не требуют Swoole/MySQL и не изменяют production-код.
 */

require_once __DIR__ . '/bootstrap.php';

use \SPGame\Core\Message;
use \SPGame\Game\Fleet\Fleet;
use \SPGame\Game\Fleet\FleetTarget;
use \SPGame\Game\Space\Coordinate;
use \SPGame\Game\Space\CoordinateType;
use \SPGame\Game\Space\ObjectType;

final class ClientInteractionTestFailure extends RuntimeException
{
}

function clientAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ClientInteractionTestFailure($message);
    }
}

function clientAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new ClientInteractionTestFailure(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function makeRawFleetForClientTest(array $overrides = []): Fleet
{
    $rawData = array_merge([
        'id' => 501,
        'current_coordinate_type' => CoordinateType::OBJECT->value,
        'current_galaxy' => 1,
        'current_system' => 10,
        'current_orbit' => 4,
        'current_object_type' => ObjectType::PLANET->value,
        'current_distance' => 0.0,
        'current_angle' => 0.0,
        'current_position_time' => 1000.0,
    ], $overrides);

    $fleet = (new ReflectionClass(Fleet::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionClass(Fleet::class);

    $id = $reflection->getProperty('id');
    $id->setAccessible(true);
    $id->setValue($fleet, (int)$rawData['id']);

    $raw = $reflection->getProperty('rawData');
    $raw->setAccessible(true);
    $raw->setValue($fleet, $rawData);

    $currentCoordinate = $reflection->getProperty('currentCoordinate');
    $currentCoordinate->setAccessible(true);
    $currentCoordinate->setValue($fleet, null);

    return $fleet;
}

function readFleetRawData(Fleet $fleet): array
{
    $property = new ReflectionProperty(Fleet::class, 'rawData');
    $property->setAccessible(true);
    return $property->getValue($fleet);
}

function testClientRequestResponseCorrelation(): void
{
    $requestId = 'client-test-request-001';
    $request = new Message([
        'requestId' => $requestId,
        'token' => 'test-token',
        'mode' => 'fleets',
        'action' => 'list',
        'data' => [],
    ]);

    $response = new Message();
    $response
        ->setRequestId($request->getRequestId())
        ->setMode($request->getMode())
        ->setAction($request->getAction())
        ->setData('Page', ['page' => 'fleet']);

    $payload = $response->source();

    clientAssertSame($requestId, $payload['requestId'], 'response must correlate with client request');
    clientAssertSame('ok', $payload['status'], 'normal page response status');
    clientAssertSame('fleets', $payload['mode'], 'response mode');
    clientAssertSame('list', $payload['action'], 'response action');
    clientAssert(isset($payload['serverTime']) && $payload['serverTime'] > 0, 'serverTime must be present');
    clientAssertSame(['page' => 'fleet'], $payload['data']['Page'], 'response data');
}

function testClientSerializesValidCurrentObjectCoordinate(): void
{
    $fleet = makeRawFleetForClientTest();
    $coordinate = $fleet->getCurrentCoordinate();

    clientAssertSame(CoordinateType::OBJECT, $coordinate->getType(), 'current coordinate type');
    clientAssertSame(ObjectType::PLANET, $coordinate->getObjectType(), 'current object type');
    clientAssertSame([
        'type' => CoordinateType::OBJECT->value,
        'galaxy' => 1,
        'system' => 10,
        'orbit' => 4,
        'objectType' => ObjectType::PLANET->value,
    ], $coordinate->toArray(), 'fleet page coordinate payload');
}

function testClientSerializesValidCurrentPointCoordinate(): void
{
    $fleet = makeRawFleetForClientTest([
        'current_coordinate_type' => CoordinateType::POINT->value,
        'current_galaxy' => 2,
        'current_system' => 7,
        'current_orbit' => 0,
        'current_object_type' => 0,
        'current_distance' => 2500.5,
        'current_angle' => 135.25,
    ]);

    $coordinate = $fleet->getCurrentCoordinate();

    clientAssertSame(CoordinateType::POINT, $coordinate->getType(), 'point coordinate type');
    clientAssertSame([
        'type' => CoordinateType::POINT->value,
        'galaxy' => 2,
        'system' => 7,
        'distance' => 2500.5,
        'angle' => 135.25,
    ], $coordinate->toArray(), 'point coordinate payload');
}

function testClientInteractionUpdatesCurrentCoordinatePayload(): void
{
    $fleet = makeRawFleetForClientTest();
    $newCoordinate = Coordinate::object(2, 3, 8, ObjectType::MOON);

    $fleet->setCurrentCoordinate($newCoordinate, 2000.0);
    $rawData = readFleetRawData($fleet);

    clientAssertSame(CoordinateType::OBJECT->value, $rawData['current_coordinate_type'], 'updated coordinate type');
    clientAssertSame(2, $rawData['current_galaxy'], 'updated galaxy');
    clientAssertSame(3, $rawData['current_system'], 'updated system');
    clientAssertSame(8, $rawData['current_orbit'], 'updated orbit');
    clientAssertSame(ObjectType::MOON->value, $rawData['current_object_type'], 'updated object type');
    clientAssertSame(2000.0, $rawData['current_position_time'], 'updated position time');
    clientAssertSame($newCoordinate->toArray(), $fleet->getCurrentCoordinate()->toArray(), 'client sees updated coordinate');
}

function testInvalidStoredObjectTypeIsDetected(): void
{
    $fleet = makeRawFleetForClientTest([
        'current_object_type' => 0,
    ]);

    $thrown = false;
    try {
        $fleet->getCurrentCoordinate();
    } catch (ValueError $exception) {
        $thrown = str_contains($exception->getMessage(), '0 is not a valid backing value for enum');
    }

    clientAssert($thrown, 'invalid persisted object type must be detected instead of producing a fake coordinate');
}

function testClientObjectTargetUsesCoordinateContract(): void
{
    $target = FleetTarget::fromCoordinates([
        'type' => CoordinateType::OBJECT->value,
        'galaxy' => 1,
        'system' => 8,
        'orbit' => 4,
        'objectType' => ObjectType::MOON->value,
    ]);

    clientAssertSame(1, $target->getGalaxy(), 'object target galaxy');
    clientAssertSame(8, $target->getSystem(), 'object target system');
    clientAssertSame(4, $target->getOrbit(), 'object target orbit');
    clientAssertSame(ObjectType::MOON->value, $target->getType(), 'object target type adapter');
}

function testClientPointTargetUsesCoordinateContract(): void
{
    $target = FleetTarget::fromCoordinates([
        'type' => CoordinateType::POINT->value,
        'galaxy' => 2,
        'system' => 7,
        'distance' => 2500.5,
        'angle' => 135.25,
    ]);

    clientAssertSame(2, $target->getGalaxy(), 'point target galaxy');
    clientAssertSame(7, $target->getSystem(), 'point target system');
    clientAssertSame(2500.5, $target->getDistance(), 'point target distance');
    clientAssertSame(135.25, $target->getDeg(), 'point target angle adapter');
}

function testClientFleetTargetDoesNotSendLastKnownPosition(): void
{
    $target = FleetTarget::fromCoordinates([
        'type' => CoordinateType::FLEET->value,
        'fleetId' => 77,
    ]);

    clientAssertSame(77, $target->getFleetId(), 'fleet target id');
}

$tests = [
    'testClientRequestResponseCorrelation',
    'testClientSerializesValidCurrentObjectCoordinate',
    'testClientSerializesValidCurrentPointCoordinate',
    'testClientInteractionUpdatesCurrentCoordinatePayload',
    'testInvalidStoredObjectTypeIsDetected',
    'testClientObjectTargetUsesCoordinateContract',
    'testClientPointTargetUsesCoordinateContract',
    'testClientFleetTargetDoesNotSendLastKnownPosition',
];

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    try {
        $test();
        ++$passed;
        echo "PASS {$test}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL {$test}: {$exception->getMessage()}\n";
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
