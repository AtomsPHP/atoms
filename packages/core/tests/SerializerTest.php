<?php

declare(strict_types=1);

namespace Atoms\Core\Tests;

use Atoms\Core\Tests\Fixtures\NonPromoted;
use Atoms\Core\Tests\Fixtures\PlayerSnapshot;
use Atoms\Core\Tests\Fixtures\Priority;
use Atoms\Core\Tests\Fixtures\Status;
use Atoms\Core\Tests\Fixtures\Team;
use Atoms\Core\Tests\Fixtures\TestMethods;
use Atoms\Core\Tests\Fixtures\Timestamped;
use Atoms\Core\Tests\Fixtures\WithDefaults;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\SerializationException;
use Atoms\Serialization\Serializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SerializerTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function scalarProvider(): iterable
    {
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'int' => [42];
        yield 'float' => [3.5];
        yield 'string' => ['hello'];
    }

    #[DataProvider('scalarProvider')]
    public function testNormalizeScalarsPassThrough(mixed $value): void
    {
        self::assertSame($value, $this->serializer->normalize($value));
    }

    public function testNormalizeNestedArray(): void
    {
        $value = ['a' => 1, 'b' => [2, 3], 'c' => ['x' => true]];

        self::assertSame($value, $this->serializer->normalize($value));
    }

    public function testNormalizeAndDenormalizePayload(): void
    {
        $player = new PlayerSnapshot('p1', 'Ann', 1500);

        $wire = $this->serializer->normalize($player);
        self::assertSame(['id' => 'p1', 'name' => 'Ann', 'elo' => 1500], $wire);

        $back = $this->serializer->denormalize($wire, PlayerSnapshot::class);
        self::assertInstanceOf(PlayerSnapshot::class, $back);
        self::assertSame('Ann', $back->name);
        self::assertSame(1500, $back->elo);
    }

    public function testNormalizeAndDenormalizeNestedPayloadWithListsMapsEnums(): void
    {
        $team = new Team(
            name: 'Reds',
            captain: new PlayerSnapshot('p1', 'Ann', 1500),
            players: [new PlayerSnapshot('p1', 'Ann', 1500), new PlayerSnapshot('p2', 'Bob', 1400)],
            scores: ['round1' => 3, 'round2' => 5],
            status: Status::Active,
            mascot: null,
        );

        $wire = $this->serializer->normalize($team);
        self::assertSame('Reds', $wire['name']);
        self::assertSame('A', $wire['status']);
        self::assertSame(['round1' => 3, 'round2' => 5], $wire['scores']);
        self::assertCount(2, $wire['players']);
        self::assertNull($wire['mascot']);
        self::assertSame('Bob', $wire['players'][1]['name']);

        $back = $this->serializer->denormalize($wire, Team::class);
        self::assertInstanceOf(Team::class, $back);
        self::assertSame(Status::Active, $back->status);
        self::assertNull($back->mascot);
        // Directly-typed Payload properties hydrate...
        self::assertInstanceOf(PlayerSnapshot::class, $back->captain);
        self::assertSame('Ann', $back->captain->name);
        // ...but a plain `array` property (PHPDoc list<PlayerSnapshot>) carries no
        // element type at reflection level, so its items stay as raw arrays.
        self::assertSame(['round1' => 3, 'round2' => 5], $back->scores);
        self::assertSame(['id' => 'p2', 'name' => 'Bob', 'elo' => 1400], $back->players[1]);
    }

    public function testBackedIntEnumRoundTrip(): void
    {
        self::assertSame(9, $this->serializer->normalize(Priority::High));
        self::assertSame(Priority::High, $this->serializer->denormalize(9, Priority::class));
    }

    public function testDateTimeImmutableMicrosecondRoundTrip(): void
    {
        $dt = new \DateTimeImmutable('2026-07-07 12:34:56.123456', new \DateTimeZone('+02:00'));

        $wire = $this->serializer->normalize($dt);
        self::assertIsString($wire);
        self::assertSame('2026-07-07T12:34:56.123456+02:00', $wire);

        $back = $this->serializer->denormalize($wire, \DateTimeImmutable::class);
        self::assertInstanceOf(\DateTimeImmutable::class, $back);
        self::assertSame($dt->format(Serializer::DATETIME_FORMAT), $back->format(Serializer::DATETIME_FORMAT));
    }

    public function testDateTimeInsidePayloadRoundTrip(): void
    {
        $value = new Timestamped('created', new \DateTimeImmutable('2026-01-02T03:04:05.000007+00:00'));

        $wire = $this->serializer->normalize($value);
        $back = $this->serializer->denormalize($wire, Timestamped::class);

        self::assertInstanceOf(Timestamped::class, $back);
        self::assertSame(
            $value->at->format(Serializer::DATETIME_FORMAT),
            $back->at->format(Serializer::DATETIME_FORMAT),
        );
    }

    public function testDenormalizeUsesDefaultsForMissingOptionalParams(): void
    {
        $back = $this->serializer->denormalize(['name' => 'job'], WithDefaults::class);

        self::assertInstanceOf(WithDefaults::class, $back);
        self::assertSame('job', $back->name);
        self::assertSame(3, $back->retries);
        self::assertNull($back->note);
    }

    public function testDenormalizeIgnoresUnknownKeys(): void
    {
        $back = $this->serializer->denormalize(
            ['id' => 'p1', 'name' => 'Ann', 'elo' => 1500, 'extra' => 'ignored'],
            PlayerSnapshot::class,
        );

        self::assertInstanceOf(PlayerSnapshot::class, $back);
        self::assertSame('p1', $back->id);
    }

    public function testDenormalizeIntToFloatWidening(): void
    {
        $result = $this->serializer->denormalize(5, 'float');

        self::assertIsFloat($result);
        self::assertSame(5.0, $result);
    }

    public function testDenormalizeNullableReturnsNull(): void
    {
        self::assertNull($this->serializer->denormalize(null, '?int'));
    }

    public function testDenormalizeMixedPassesThrough(): void
    {
        $value = ['anything' => [1, 2, 3]];

        self::assertSame($value, $this->serializer->denormalize($value, 'mixed'));
    }

    // --- Rejection (normalize) ---------------------------------------------

    public function testRejectsClosure(): void
    {
        try {
            $this->serializer->normalize(static fn () => 1);
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::UnserializableValue, $e->errorCode);
        }
    }

    public function testRejectsResource(): void
    {
        $handle = fopen('php://memory', 'rb');
        self::assertIsResource($handle);

        try {
            $this->serializer->normalize($handle);
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::UnserializableValue, $e->errorCode);
        } finally {
            fclose($handle);
        }
    }

    public function testRejectsMutableDateTime(): void
    {
        try {
            $this->serializer->normalize(new \DateTime());
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::UnserializableValue, $e->errorCode);
        }
    }

    public function testRejectsPlainObject(): void
    {
        try {
            $this->serializer->normalize(new \stdClass());
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::UnserializableValue, $e->errorCode);
        }
    }

    public function testRejectsNonPromotedPayload(): void
    {
        try {
            $this->serializer->normalize(new NonPromoted('x'));
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::PayloadNotHydratable, $e->errorCode);
        }
    }

    // --- Rejection (denormalize) -------------------------------------------

    public function testDenormalizeTypeMismatchThrows(): void
    {
        try {
            $this->serializer->denormalize('not-an-int', 'int');
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
        }
    }

    public function testDenormalizeMissingRequiredPayloadPropThrows(): void
    {
        try {
            $this->serializer->denormalize(['id' => 'p1', 'name' => 'Ann'], PlayerSnapshot::class);
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
        }
    }

    public function testDenormalizeInvalidEnumValueThrows(): void
    {
        try {
            $this->serializer->denormalize('Z', Status::class);
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
        }
    }

    public function testDenormalizeBadDateTimeStringThrows(): void
    {
        try {
            $this->serializer->denormalize('not-a-date', \DateTimeImmutable::class);
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
        }
    }

    public function testFloatToIntIsNotCoerced(): void
    {
        try {
            $this->serializer->denormalize(1.5, 'int');
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
        }
    }

    // --- denormalizeArguments ----------------------------------------------

    public function testDenormalizeArgumentsAgainstMethodReflection(): void
    {
        $reflection = new \ReflectionMethod(TestMethods::class, 'record');

        $args = $this->serializer->denormalizeArguments(
            [100, null, ['id' => 'p1', 'name' => 'Ann', 'elo' => 1500], 'A'],
            $reflection,
        );

        self::assertSame(100, $args[0]);
        self::assertNull($args[1]);
        self::assertInstanceOf(PlayerSnapshot::class, $args[2]);
        self::assertSame('Ann', $args[2]->name);
        self::assertSame(Status::Active, $args[3]);
    }
}
