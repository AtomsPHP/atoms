<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests;

use Atoms\Serialization\SerializationException;
use Atoms\Testing\FakeConnection;
use PHPUnit\Framework\TestCase;

final class FakeConnectionTest extends TestCase
{
    public function testSendJsonRecordsEncodedAndDecodedFrames(): void
    {
        $conn = new FakeConnection('conn-1');
        $conn->send('raw');
        $conn->sendJson([]);
        $conn->sendJson([
            'items' => [1, 2],
            'at' => new \DateTimeImmutable('2026-08-16T12:00:00.000000+00:00'),
        ]);

        self::assertSame(
            [
                'raw',
                '{}',
                '{"items":[1,2],"at":"2026-08-16T12:00:00.000000+00:00"}',
            ],
            $conn->sent(),
        );
        self::assertSame(
            [
                [],
                [
                    'items' => [1, 2],
                    'at' => '2026-08-16T12:00:00.000000+00:00',
                ],
            ],
            $conn->sentJson(),
        );
    }

    public function testSendJsonRejectsTopLevelListWithoutMutatingSendLogs(): void
    {
        $conn = new FakeConnection('conn-1');
        $conn->send('before');
        $conn->sendJson(['ok' => true]);

        try {
            $conn->sendJson([1, 2]);
            self::fail('Expected \JsonException for a top-level list.');
        } catch (\JsonException) {
            // expected
        }

        self::assertSame(['before', '{"ok":true}'], $conn->sent());
        self::assertSame([['ok' => true]], $conn->sentJson());
    }

    public function testSendJsonRejectsUnserializablePayloadWithoutMutatingSendLogs(): void
    {
        $conn = new FakeConnection('conn-1');
        $conn->sendJson(['ok' => true]);

        try {
            $conn->sendJson(['when' => new \DateTime()]);
            self::fail('Expected SerializationException for a mutable DateTime.');
        } catch (SerializationException) {
            // expected
        }

        self::assertSame(['{"ok":true}'], $conn->sent());
        self::assertSame([['ok' => true]], $conn->sentJson());
    }

    public function testSendJsonRejectsInvalidUtf8WithoutMutatingSendLogs(): void
    {
        $conn = new FakeConnection('conn-1');
        $conn->sendJson(['ok' => true]);

        try {
            $conn->sendJson(['bytes' => "\xff\xfe"]);
            self::fail('Expected \JsonException for invalid UTF-8.');
        } catch (\JsonException) {
            // expected
        }

        self::assertSame(['{"ok":true}'], $conn->sent());
        self::assertSame([['ok' => true]], $conn->sentJson());
    }
}
