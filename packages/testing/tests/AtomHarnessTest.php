<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
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

    public function testDispatchedRefusesAMissingRequiredArgumentWithTheCatalogCode(): void
    {
        $harness = $this->harness();

        $harness->invoke('recordPartialScore', ['alice']);

        try {
            $harness->dispatched();
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
            self::assertStringContainsString('points', $e->getMessage());
        }

        $harness->shutdown();
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

    public function testStructuredFramesAreRecordedEncodedAndDecoded(): void
    {
        $harness = $this->harness();
        $conn = $harness->connect();

        $harness->sendMessage($conn, '{"pit":3,"revision":7}');

        // sentJson() is the assertion surface; sent() still holds every frame in
        // call order, structured ones as the bytes the client would receive.
        self::assertSame([['kind' => 'echo', 'frame' => ['pit' => 3, 'revision' => 7]]], $conn->sentJson());
        self::assertSame(
            ['connected:', '{"kind":"echo","frame":{"pit":3,"revision":7}}'],
            $conn->sent(),
        );

        $harness->shutdown();
    }

    public function testAMalformedStructuredFrameSurfacesAsAJsonException(): void
    {
        $harness = $this->harness();
        $conn = $harness->connect();

        // A top-level list is refused the same way malformed JSON is, so the
        // Atom needs only one catch.
        $harness->sendMessage($conn, '[1,2]');
        $harness->sendMessage($conn, '{oops');

        $kinds = array_column($conn->sentJson(), 'kind');
        self::assertSame(['error', 'error'], $kinds);

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

    /**
     * A migrations directory whose one file has no `NNN_` prefix, so
     * `MigrationSet::fromDirectory()` throws ATOMS-E051. Boot fails after the
     * temp directory and database are open, which is the window the `booted`
     * flag used to be set inside.
     */
    private function migrationsThatFailToLoad(): string
    {
        return __DIR__ . '/Fixtures/UnnumberedMigrations';
    }

    public function testAFailedBootDoesNotLeaveTheHarnessMarkedBooted(): void
    {
        $harness = $this->harness()->withMigrations($this->migrationsThatFailToLoad());

        try {
            $harness->boot();
            self::fail('Expected the migration set to fail to load.');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::MigrationNumberingConflict, $e->errorCode);
        }

        // Before the fix this second call returned $harness untouched — booted
        // was true, database was null — and the caller met a TypeError from
        // db() instead of the migration error above.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AtomHarness::boot() did not complete');

        $harness->db();
    }

    public function testAFailedBootStillCleansUpItsTemporaryDirectory(): void
    {
        $pattern = rtrim(sys_get_temp_dir(), '/') . '/atoms-testing-*';
        $before = glob($pattern) ?: [];

        $harness = $this->harness()->withMigrations($this->migrationsThatFailToLoad());

        try {
            $harness->boot();
        } catch (AtomsError) {
            // asserted in the test above
        }

        $created = array_values(array_diff(glob($pattern) ?: [], $before));
        self::assertCount(1, $created, 'The failed boot should have opened exactly one temp directory.');
        self::assertDirectoryExists($created[0]);

        $harness->shutdown();

        self::assertDirectoryDoesNotExist($created[0]);
    }

    public function testConfigurationCannotBeChangedFromInsideTheBootSequence(): void
    {
        $caught = null;

        $harness = AtomHarness::for(ChatRoom::class, 'room-1')->withConfig([
            'boot_hook' => function () use (&$harness, &$caught): void {
                try {
                    /** @var AtomHarness<ChatRoom> $harness */
                    $harness->withConfig(['FEATURE' => 'too late']);
                } catch (\LogicException $e) {
                    $caught = $e;
                }
            },
        ]);

        $harness->boot();

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertStringContainsString('already booted', $caught->getMessage());

        $harness->shutdown();
    }

    public function testBootIsNotReenteredFromInsideTheBootSequence(): void
    {
        $caught = null;

        $harness = AtomHarness::for(ChatRoom::class, 'room-1')->withConfig([
            'boot_hook' => function () use (&$harness, &$caught): void {
                try {
                    /** @var AtomHarness<ChatRoom> $harness */
                    $harness->db();
                } catch (\LogicException $e) {
                    $caught = $e;
                }
            },
        ]);

        $harness->boot();

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertStringContainsString('AtomHarness::boot() did not complete', $caught->getMessage());

        $harness->shutdown();
    }

    public function testOperationsAfterShutdownThrowInsteadOfUsingTheDeletedTempDirectory(): void
    {
        $harness = $this->harness();
        $harness->boot();
        $harness->shutdown();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AtomHarness has been shut down');

        $harness->db();
    }

    public function testRecordersStayReadableAfterShutdown(): void
    {
        $harness = $this->harness();

        $harness->invoke('screenAndPost', ['hello']);
        $harness->invoke('recordScore', [
            'alice',
            10,
            new Score(10, 'bronze'),
            new \DateTimeImmutable('2026-02-02T00:00:00+00:00'),
        ]);

        $harness->shutdown();

        $harness->assertDispatched(RecordResult::class);
        $harness->assertBroadcast('room');
        self::assertCount(1, $harness->dispatched());
        self::assertCount(1, $harness->broadcasts());
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
