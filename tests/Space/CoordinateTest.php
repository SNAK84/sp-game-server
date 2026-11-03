<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;
use SPGame\Game\Space\ObjectType;

final class CoordinateTest extends TestCase
{
    public function testObjectCoordinate(): void
    {
        $coordinate = Coordinate::object(1, 10, 4, ObjectType::PLANET);

        self::assertSame(CoordinateType::OBJECT, $coordinate->getType());
        self::assertSame(1, $coordinate->getGalaxy());
        self::assertSame(10, $coordinate->getSystem());
        self::assertSame(4, $coordinate->getOrbit());
        self::assertSame(ObjectType::PLANET, $coordinate->getObjectType());
        self::assertNull($coordinate->getDistance());
        self::assertNull($coordinate->getAngle());
        self::assertNull($coordinate->getFleetId());
        self::assertNull($coordinate->getLastKnownPosition());

        self::assertSame([
            'type' => CoordinateType::OBJECT->value,
            'galaxy' => 1,
            'system' => 10,
            'orbit' => 4,
            'objectType' => ObjectType::PLANET->value,
        ], $coordinate->toArray());
    }

    public function testPointCoordinateKeepsFloatDistanceAndAngle(): void
    {
        $coordinate = Coordinate::point(1, 10, 2500.5, 135.25);

        self::assertSame(CoordinateType::POINT, $coordinate->getType());
        self::assertSame(2500.5, $coordinate->getDistance());
        self::assertSame(135.25, $coordinate->getAngle());
        self::assertNull($coordinate->getOrbit());
        self::assertNull($coordinate->getObjectType());

        self::assertSame([
            'type' => CoordinateType::POINT->value,
            'galaxy' => 1,
            'system' => 10,
            'distance' => 2500.5,
            'angle' => 135.25,
        ], $coordinate->toArray());
    }

    public function testStationaryObjectKeepsMaterializedFleetAngle(): void
    {
        $coordinate = Coordinate::object(1, 10, 12, null, 135.25);

        self::assertSame(CoordinateType::OBJECT, $coordinate->getType());
        self::assertNull($coordinate->getObjectType());
        self::assertSame(135.25, $coordinate->getAngle());
        self::assertSame([
            'type' => CoordinateType::OBJECT->value,
            'galaxy' => 1,
            'system' => 10,
            'orbit' => 12,
            'angle' => 135.25,
        ], $coordinate->toArray());
        self::assertSame($coordinate->toArray(), Coordinate::fromArray($coordinate->toArray())->toArray());
    }

    public function testFleetCoordinateContainsPointSnapshot(): void
    {
        $snapshot = Coordinate::point(1, 10, 2500.5, 135.25);
        $coordinate = Coordinate::fleet(123, $snapshot);

        self::assertSame(CoordinateType::FLEET, $coordinate->getType());
        self::assertSame(123, $coordinate->getFleetId());
        self::assertNull($coordinate->getGalaxy());
        self::assertNull($coordinate->getSystem());
        self::assertSame($snapshot, $coordinate->getLastKnownPosition());
        self::assertSame([
            'type' => CoordinateType::FLEET->value,
            'fleetId' => 123,
            'lastKnownPosition' => $snapshot->toArray(),
        ], $coordinate->toArray());
    }

    /** @dataProvider invalidObjectArguments */
    public function testObjectRejectsInvalidCoordinates(int $galaxy, int $system, int $orbit): void
    {
        $this->expectException(InvalidArgumentException::class);
        Coordinate::object($galaxy, $system, $orbit, ObjectType::PLANET);
    }

    public static function invalidObjectArguments(): array
    {
        return [[0, 1, 1], [1, 0, 1], [1, 1, 0]];
    }

    /** @dataProvider invalidPointArguments */
    public function testPointRejectsInvalidDistanceAndAngle(float $distance, float $angle): void
    {
        $this->expectException(InvalidArgumentException::class);
        Coordinate::point(1, 1, $distance, $angle);
    }

    public static function invalidPointArguments(): array
    {
        return [[-0.01, 0.0], [0.0, -0.01], [0.0, 360.0], [INF, 0.0], [0.0, NAN]];
    }

    public function testFleetRejectsInvalidIdAndNonPointSnapshot(): void
    {
        $snapshot = Coordinate::object(1, 1, 1, ObjectType::PLANET);

        try {
            Coordinate::fleet(0, Coordinate::point(1, 1, 100.0, 0.0));
            self::fail('Ожидалось исключение для недопустимого ID флота.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        Coordinate::fleet(2, $snapshot);
    }
}
