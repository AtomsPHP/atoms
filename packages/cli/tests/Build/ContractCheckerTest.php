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
 * The Atom→AtomJob half of the contract check. `new SomeJob(...)` used to
 * validate clean and then fail at runtime as `Class "SomeJob" not found`, so
 * the E104 case below is a regression test, not a nicety.
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

    /** Undecidable statically; guessing would false-positive on legal code. */
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
     * Write $source over the sample app's Atom and return its violation codes,
     * sorted for a stable compare.
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
