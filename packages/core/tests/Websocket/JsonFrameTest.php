<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Websocket;

use Atoms\Core\Tests\Fixtures\PlayerSnapshot;
use Atoms\Core\Tests\Fixtures\Status;
use Atoms\Serialization\SerializationException;
use Atoms\Websocket\JsonFrame;
use PHPUnit\Framework\TestCase;

final class JsonFrameTest extends TestCase
{
    public function testEncodesAPlainMapAsAJsonObject(): void
    {
        self::assertSame('{"kind":"welcome","seat":1}', JsonFrame::encode(['kind' => 'welcome', 'seat' => 1]));
    }

    public function testDoesNotEscapeSlashes(): void
    {
        // A URL in a frame is the common case, and \/ is noise no client wants.
        self::assertSame('{"url":"https://example.test/a/b"}', JsonFrame::encode(['url' => 'https://example.test/a/b']));
    }

    public function testEncodesAnEmptyMapAsAnObjectSoItRoundTrips(): void
    {
        $encoded = JsonFrame::encode([]);

        self::assertSame('{}', $encoded);
        self::assertSame([], JsonFrame::decode($encoded));
    }

    public function testANestedEmptyArrayStaysAList(): void
    {
        // Documented edge: only the top level is forced to an object, because
        // JSON_FORCE_OBJECT would corrupt every nested list.
        self::assertSame('{"moves":[]}', JsonFrame::encode(['moves' => []]));
    }

    public function testAppliesTheSerializationAlgebra(): void
    {
        $encoded = JsonFrame::encode([
            'player' => new PlayerSnapshot('p-1', 'Ada', 2400),
            'status' => Status::Active,
            'at' => new \DateTimeImmutable('2026-08-16T12:00:00.000000+00:00'),
        ]);

        self::assertSame(
            '{"player":{"id":"p-1","name":"Ada","elo":2400},"status":"A","at":"2026-08-16T12:00:00.000000+00:00"}',
            $encoded,
        );
    }

    public function testRejectsAValueOutsideTheAlgebra(): void
    {
        $this->expectException(SerializationException::class);

        JsonFrame::encode(['when' => new \DateTime()]);
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(\JsonException::class);

        JsonFrame::encode(['bytes' => "\xff\xfe"]);
    }

    public function testEncodesLargeIntegersExactly(): void
    {
        // The guest builds the frame, so int64 survives outbound. This is the
        // same rule the Worker's ws.broadcast frame relies on.
        self::assertSame('{"n":9007199254740993}', JsonFrame::encode(['n' => 9007199254740993]));
    }

    public function testDecodesAnObject(): void
    {
        self::assertSame(
            ['kind' => 'move', 'pit' => 3, 'nested' => ['a' => [1, 2]]],
            JsonFrame::decode('{"kind":"move","pit":3,"nested":{"a":[1,2]}}'),
        );
    }

    public function testDecodesNumericStringKeysAsIntKeys(): void
    {
        // Why json() is documented as array<array-key, mixed> and not
        // array<string, mixed>: PHP turns "0" into an int key.
        self::assertSame([0 => 'a'], JsonFrame::decode('{"0":"a"}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectFrames(): iterable
    {
        yield 'top-level list' => ['[1,2]'];
        yield 'string' => ['"hello"'];
        yield 'int' => ['5'];
        yield 'null' => ['null'];
        yield 'malformed' => ['{oops'];
        yield 'empty' => [''];
    }

    /**
     * One catch covers every unusable frame: malformed JSON and a well-formed
     * non-object both arrive as \JsonException.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonObjectFrames')]
    public function testRefusesAnythingThatIsNotAJsonObject(string $frame): void
    {
        $this->expectException(\JsonException::class);

        JsonFrame::decode($frame);
    }

    public function testRoundTrips(): void
    {
        $payload = ['kind' => 'state', 'pits' => [4, 4, 0], 'turn' => null, 'live' => true];

        self::assertSame($payload, JsonFrame::decode(JsonFrame::encode($payload)));
    }

    /**
     * The in-PHP guard against drifting from the Worker's broadcast frame, which
     * conformance check 20 pins byte-for-byte on the other side of the boundary.
     * CfAtomContext::broadcast() builds exactly this envelope through this
     * encoder; if the encoder's flags change, this fails here rather than in a
     * Worker run that needs wrangler.
     */
    public function testProducesTheBroadcastEnvelopeByteForByte(): void
    {
        self::assertSame(
            '{"kind":"broadcast","channel":"lobby","payload":{"text":"hello"}}',
            JsonFrame::encode(['kind' => 'broadcast', 'channel' => 'lobby', 'payload' => ['text' => 'hello']]),
        );
    }
}
