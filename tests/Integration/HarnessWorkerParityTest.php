<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use App\Atoms\Vault;
use App\Jobs\Notify;
use Atoms\Core\Tests\Fixtures\Status;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\Serializer;
use Atoms\Testing\AtomHarness;
use Atoms\Testing\FakeAppProxy;
use Atoms\Testing\Tests\Fixtures\Score;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class HarnessWorkerParityTest extends TestCase
{
    /** @return AtomHarness<Vault> */
    private function vault(): AtomHarness
    {
        $fixture = __DIR__ . '/../../cloudflare/worker/fixtures/counter/app';
        require_once $fixture . '/Vault.php';
        require_once $fixture . '/Jobs/Notify.php';

        return AtomHarness::for(Vault::class, 'vault-1')->withMigrations($fixture . '/migrations/vault');
    }

    public function testCallbackReturnsDecodedValues(): void
    {
        $proxy = new FakeAppProxy(new class {
            public function date(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-31T00:00:00+00:00');
            }

            public function score(): Score
            {
                return new Score(42, 'gold');
            }

            public function status(): Status
            {
                return Status::Active;
            }
        }, new Serializer());

        self::assertSame('2026-10-31T00:00:00.000000+00:00', $proxy->date());
        self::assertSame(['value' => 42, 'tier' => 'gold'], $proxy->score());
        self::assertSame('A', $proxy->status());
    }

    public function testTransactionRejectsCallbackBeforeTheHandlerRuns(): void
    {
        $methods = new class {
            public int $calls = 0;

            public function echoBig(int $value): int
            {
                ++$this->calls;

                return $value;
            }
        };
        $vault = $this->vault()->withMethods($methods);
        try {
            $vault->invoke('appInsideTransaction');
            self::fail('Expected the callback to fail.');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::CallbackInTransaction, $e->errorCode);
        }
        self::assertSame(0, $methods->calls);
        self::assertSame(0, $vault->invoke('getBig', ['app-inside-tx']));
        self::assertSame(42, $vault->invoke('echoViaApp', [42]));
        self::assertSame(1, $methods->calls);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testJobsAndTimersFollowTheSqlTransaction(bool $commit): void
    {
        $vault = $this->vault();
        $timers = $vault->timers();
        $due = new \DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $timers->schedule('pending', $due);

        $pdo = $vault->db()->pdo();
        $pdo->beginTransaction();
        $timers->cancel('pending');
        $timers->schedule('sent', $due);
        $vault->atom()->transferAndNotify(false);
        $vault->assertNothingDispatched();
        self::assertEquals(['sent' => $due], $timers->scheduled());

        if ($commit) {
            $pdo->commit();
            self::assertSame(1, $vault->invoke('getBig', ['transfer-and-notify']));
            self::assertEquals([new Notify('vault-1', 'transfer-ok')], $vault->dispatched());
            self::assertEquals(['sent' => $due], $timers->scheduled());
        } else {
            $pdo->rollBack();
            self::assertSame(0, $vault->invoke('getBig', ['transfer-and-notify']));
            $vault->assertNothingDispatched();
            self::assertEquals($due, $timers->scheduledAt('pending'));
            self::assertNull($timers->scheduledAt('sent'));
        }
    }
}
