<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests;

use Atoms\Serialization\SerializationException;
use Atoms\Testing\AtomHarness;
use Atoms\Testing\Tests\Fixtures\ChatRoom;
use Atoms\Testing\Tests\Fixtures\RecordResult;
use Atoms\Testing\Tests\Fixtures\Score;
use PHPUnit\Framework\TestCase;

final class AtomHarnessTest extends TestCase
{
    /**
     * @return AtomHarness<ChatRoom>
     */
    private function harness(): AtomHarness
    {
        return AtomHarness::for(ChatRoom::class, 'room-1');
    }

    public function testBootAppliesMigrations(): void
    {
        $harness = $this->harness();
        $harness->boot();

        $version = $harness->db()->query('PRAGMA user_version')[0]['user_version'];
        self::assertSame(2, $version);

        $tables = $harness->db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'messages'");
        self::assertCount(1, $tables);

        $harness->shutdown();
    }

    public function testInvokeJoinReturnsArrayAndPersistsRow(): void
    {
        $harness = $this->harness();

        $result = $harness->invoke('join', ['alice']);

        self::assertIsArray($result);
        self::assertSame('alice', $result[0]['username']);
        self::assertSame('alice joined', $result[0]['body']);

        $rows = $harness->db()->query('SELECT username FROM messages WHERE username = ?', ['alice']);
        self::assertCount(1, $rows);

        $harness->shutdown();
    }

    public function testAppProxyExecutesRealMethodsWithPayloadAndDateTimeArgs(): void
    {
        $harness = $this->harness();

        $result = $harness->invoke('describeScore', [
            new Score(42, 'gold'),
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ]);

        self::assertSame('gold@42:2026-01-01', $result);

        $harness->shutdown();
    }

    public function testAppProxyRejectsUnknownMethod(): void
    {
        $harness = $this->harness();

        $this->expectException(\BadMethodCallException::class);

        $harness->invoke('callUnknownAppMethod');
    }

    public function testAssertNothingDispatchedInitially(): void
    {
        $harness = $this->harness();
        $harness->boot();

        $harness->assertNothingDispatched();

        $harness->shutdown();
    }

    public function testDispatchRecorderAndFilteredAssertionWithReconstructedProps(): void
    {
        $harness = $this->harness();

        $harness->invoke('recordScore', [
            'alice',
            10,
            new Score(10, 'bronze'),
            new \DateTimeImmutable('2026-02-02T00:00:00+00:00'),
        ]);

        $harness->assertDispatched(
            RecordResult::class,
            fn (RecordResult $job): bool => $job->user === 'alice' && $job->points === 10,
        );

        $jobs = $harness->dispatched();
        self::assertCount(1, $jobs);
        self::assertInstanceOf(RecordResult::class, $jobs[0]);
        self::assertSame('alice', $jobs[0]->user);
        self::assertSame(10, $jobs[0]->points);
        self::assertSame('bronze', $jobs[0]->score->tier);
        self::assertSame(10, $jobs[0]->score->value);
        self::assertEquals(new \DateTimeImmutable('2026-02-02T00:00:00+00:00'), $jobs[0]->recordedAt);

        $harness->shutdown();
    }

    /**
     * `dispatchJob(X::class, [...])` and `dispatch(new X(...))` are the same
     * dispatch — only the World the caller lives in differs. The harness must
     * not let a test tell them apart, or a suite written against one form would
     * silently stop covering an Atom that switched to the other.
     */
    public function testBothDispatchFormsRecordIdentically(): void
    {
        $args = ['bob', 7, new Score(7, 'silver'), new \DateTimeImmutable('2026-03-03T00:00:00+00:00')];

        $byName = $this->harness();
        $byName->invoke('recordScore', $args);

        $byInstance = $this->harness();
        $byInstance->invoke('recordScoreByInstance', $args);

        self::assertEquals($byName->dispatched(), $byInstance->dispatched());

        foreach ([$byName, $byInstance] as $harness) {
            $harness->assertDispatched(
                RecordResult::class,
                fn (RecordResult $job): bool => $job->user === 'bob' && $job->score->tier === 'silver',
            );
            $harness->shutdown();
        }
    }

    public function testBroadcastRecorderAndAssertion(): void
    {
        $harness = $this->harness();

        $result = $harness->invoke('screenAndPost', ['hello']);

        self::assertSame('HELLO', $result);

        $harness->assertBroadcast('room', fn (array $payload): bool => $payload['text'] === 'HELLO');
        self::assertSame(
            [['channel' => 'room', 'payload' => ['text' => 'HELLO']]],
            $harness->broadcasts(),
        );

        $harness->shutdown();
    }

    public function testBadReturnThrowsSerializationException(): void
    {
        $harness = $this->harness();

        $this->expectException(SerializationException::class);

        $harness->invoke('badReturn');
    }

    public function testWebSocketLifecycleThroughFakeConnection(): void
    {
        $harness = $this->harness();

        $conn = $harness->connect(['token' => 'abc']);
        self::assertSame(['connected:abc'], $conn->sent());
        self::assertFalse($conn->isClosed());

        $harness->sendMessage($conn, 'ping');
        self::assertSame(['connected:abc', 'echo:ping'], $conn->sent());

        $harness->disconnect($conn);
        self::assertSame(['connected:abc', 'echo:ping', 'bye'], $conn->sent());

        $harness->shutdown();
    }

    public function testConfigReturnsProvidedValuesAndNullForMissing(): void
    {
        $harness = AtomHarness::for(ChatRoom::class, 'room-1')->withConfig(['FEATURE' => 'on']);

        self::assertSame('on', $harness->invoke('readConfig', ['FEATURE']));
        self::assertNull($harness->invoke('readConfig', ['MISSING']));

        $harness->shutdown();
    }

    public function testLifecycleHooksAreObserved(): void
    {
        $harness = $this->harness();
        $harness->boot();

        $rows = $harness->db()->query("SELECT * FROM messages WHERE username = 'system'");
        self::assertCount(1, $rows);

        $atom = $harness->atom();
        self::assertFalse($atom->deactivated);

        $harness->shutdown();

        self::assertTrue($atom->deactivated);
    }

    public function testShutdownRemovesTempDirectory(): void
    {
        $harness = $this->harness();
        $harness->boot();

        $databases = $harness->db()->query('PRAGMA database_list');
        $dir = \dirname((string) $databases[0]['file']);
        self::assertDirectoryExists($dir);

        $harness->shutdown();

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testShutdownIsIdempotent(): void
    {
        $harness = $this->harness();
        $harness->boot();

        $harness->shutdown();
        $harness->shutdown();

        self::assertTrue(true);
    }

    public function testWithMethodsOverridesConventionResolution(): void
    {
        $custom = new class extends \Atoms\AtomMethods {
            public function screen(string $text): string
            {
                return 'custom:' . $text;
            }
        };

        $harness = AtomHarness::for(ChatRoom::class, 'room-1')->withMethods($custom);

        self::assertSame('custom:hi', $harness->invoke('screenAndPost', ['hi']));

        $harness->shutdown();
    }
}
