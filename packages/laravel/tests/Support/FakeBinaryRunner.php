<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Support;

use Atoms\Laravel\Console\BinaryRunner;

/**
 * Records the argv every Artisan wrapper command would have shelled out with,
 * instead of actually running a process — see docs/conventions.md "Tests
 * must not hit the network" (and, by the same logic, must not depend on the
 * `atoms` binary existing on the test runner).
 */
final class FakeBinaryRunner extends BinaryRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    public int $exitCode = 0;

    public function __construct()
    {
    }

    public function locate(): string
    {
        return 'atoms';
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args, ?callable $onOutput = null, ?string $cwd = null): int
    {
        $this->calls[] = $args;

        return $this->exitCode;
    }
}
