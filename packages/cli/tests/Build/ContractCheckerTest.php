<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ContractChecker;
use Atoms\Cli\Build\Discovery;
use Atoms\Cli\Build\DiscoveryResult;
use Atoms\Cli\Build\MethodsResolver;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\TestCase;

/**
 * The Atom→AtomJob half of the contract check.
 *
 * The regression behind these: an Atom that dispatched `new SomeJob(...)`
 * validated clean and then died at runtime with `Class "SomeJob" not found`,
 * because a job's source is World B and never ships. Wrapped in the
 * `catch (\Throwable)` that best-effort dispatches usually carry, that failure
 * was completely silent — no delivery attempted, no failure counted. It has to
 * be caught here, at build time.
 */
final class ContractCheckerTest extends TestCase
{
    public function testRejectsAJobConstructedInsideAnAtom(): void
    {
        $codes = $this->check(<<<'PHP'
            <?php
            namespace App\Atoms;

            use App\Atoms\Jobs\RecordGameResult;

            final class GameRoom extends \Atoms\Atom
            {
                public function play(string $ref): void
                {
                    $this->dispatch(new RecordGameResult($ref, 0));
                }
            }
            PHP);

        self::assertSame(['ATOMS-E104'], $codes);
    }

    public function testAcceptsTheByNameForm(): void
    {
        $codes = $this->check(<<<'PHP'
            <?php
            namespace App\Atoms;

            use App\Atoms\Jobs\RecordGameResult;

            final class GameRoom extends \Atoms\Atom
            {
                public function play(string $ref): void
                {
                    $this->dispatch(RecordGameResult::class, ['ref' => $ref, 'seat' => 1]);
                }
            }
            PHP);

        self::assertSame([], $codes);
    }

    public function testRejectsAnUnknownConstructorArgumentName(): void
    {
        $codes = $this->check(<<<'PHP'
            <?php
            namespace App\Atoms;

            use App\Atoms\Jobs\RecordGameResult;

            final class GameRoom extends \Atoms\Atom
            {
                public function play(string $ref): void
                {
                    $this->dispatch(RecordGameResult::class, ['ref' => $ref, 'set' => 1]);
                }
            }
            PHP);

        self::assertSame(['ATOMS-E032'], $codes);
    }

    public function testRejectsAMissingRequiredArgument(): void
    {
        $codes = $this->check(<<<'PHP'
            <?php
            namespace App\Atoms;

            use App\Atoms\Jobs\RecordGameResult;

            final class GameRoom extends \Atoms\Atom
            {
                public function play(string $ref): void
                {
                    $this->dispatch(RecordGameResult::class, ['ref' => $ref]);
                }
            }
            PHP);

        self::assertSame(['ATOMS-E032'], $codes);
    }

    public function testRejectsDispatchingAClassThatIsNotAJob(): void
    {
        $codes = $this->check(<<<'PHP'
            <?php
            namespace App\Atoms;

            use App\Atoms\Shared\PlayerSnapshot;

            final class GameRoom extends \Atoms\Atom
            {
                public function play(string $ref): void
                {
                    $this->dispatch(PlayerSnapshot::class, ['id' => $ref]);
                }
            }
            PHP);

        self::assertSame(['ATOMS-E033'], $codes);
    }

    /**
     * A computed class name or a non-literal argument array cannot be decided
     * statically. Guessing would mean false positives on legal code, so these
     * pass the build and are the runtime's problem.
     */
    public function testLeavesUndecidableDispatchesAlone(): void
    {
        $codes = $this->check(<<<'PHP'
            <?php
            namespace App\Atoms;

            final class GameRoom extends \Atoms\Atom
            {
                public function play(string $ref, string $job, array $args): void
                {
                    $this->dispatch($job, $args);
                }
            }
            PHP);

        self::assertSame([], $codes);
    }

    /**
     * Write $source over the sample app's Atom, discover the whole project, and
     * return the contract violations for that Atom, sorted for a stable compare.
     *
     * @return list<string>
     */
    private function check(string $source): array
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/app/Atoms/GameRoom.php', $source);

        $config = AtomsJson::load($root . '/atoms.json');
        $discovery = (new Discovery())->discover($config);

        $checker = new ContractChecker($discovery, new MethodsResolver($discovery));

        $codes = [];
        foreach ($this->atomsOf($discovery) as $atom) {
            foreach ($checker->check($atom) as $violation) {
                $codes[] = $violation->code->value;
            }
        }

        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * @return list<\Atoms\Cli\Build\DiscoveredClass>
     */
    private function atomsOf(DiscoveryResult $discovery): array
    {
        return $discovery->ofKind(\Atoms\Cli\Build\ClassKind::Atom);
    }
}
