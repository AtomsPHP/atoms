<?php

declare(strict_types=1);

namespace Atoms\Core\Tests;

use Atoms\Core\Tests\Fixtures\RecordResult;
use Atoms\Core\Tests\Fixtures\StubAtomContext;
use Atoms\Core\Tests\Fixtures\TestAtom;
use Atoms\Core\Tests\Fixtures\TestMethods;
use Atoms\Runtime\LifecycleInvoker;
use Atoms\Sqlite\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class AtomTest extends TestCase
{
    private function atom(): array
    {
        $db = SqliteDatabase::open(':memory:');
        $app = new TestMethods();
        $context = new StubAtomContext($db, $app, ['FEATURE_FLAG' => 'on']);
        $atom = new TestAtom('room-1', $context);

        return [$atom, $context, $db, $app];
    }

    public function testIdIsExposed(): void
    {
        [$atom] = $this->atom();

        self::assertSame('room-1', $atom->id);
    }

    public function testDbPassThrough(): void
    {
        [$atom, , $db] = $this->atom();

        self::assertSame($db, $atom->callDb());
    }

    public function testAppPassThrough(): void
    {
        [$atom, , , $app] = $this->atom();

        self::assertSame($app, $atom->callApp());
        self::assertSame('hello world', $atom->callApp()->greeting('world'));
    }

    public function testConfigPassThrough(): void
    {
        [$atom] = $this->atom();

        self::assertSame('on', $atom->callConfig('FEATURE_FLAG'));
        self::assertNull($atom->callConfig('MISSING'));
    }

    public function testDispatchPassThrough(): void
    {
        [$atom, $context] = $this->atom();
        $job = new RecordResult('g-1', 42);

        $atom->callDispatch($job);

        self::assertSame([$job], $context->dispatched);
    }

    public function testBroadcastPassThrough(): void
    {
        [$atom, $context] = $this->atom();

        $atom->callBroadcast('room', ['msg' => 'hi']);

        self::assertSame(
            [['channel' => 'room', 'payload' => ['msg' => 'hi']]],
            $context->broadcasts,
        );
    }

    public function testLifecycleInvokerCallsProtectedHooks(): void
    {
        [$atom] = $this->atom();

        self::assertSame(0, $atom->activations);
        self::assertSame(0, $atom->deactivations);

        LifecycleInvoker::activate($atom);
        LifecycleInvoker::deactivate($atom);

        self::assertSame(1, $atom->activations);
        self::assertSame(1, $atom->deactivations);
    }

    public function testWebSocketHandlersAreOptionalNoops(): void
    {
        [$atom] = $this->atom();

        // Base handlers are empty overrides on TestAtom (inherited); calling them
        // must not error.
        $conn = new class implements \Atoms\Websocket\Connection {
            public function id(): string
            {
                return 'c1';
            }

            public function send(string $payload): void
            {
            }

            public function close(int $code = 1000, string $reason = ''): void
            {
            }
        };

        $atom->onConnect($conn, ['token' => 'x']);
        $atom->onDisconnect($conn);

        self::assertSame('c1', $conn->id());
    }
}
