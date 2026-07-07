<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Locates and shells out to the `atoms` binary for the Artisan wrapper
 * commands. Deliberately not `final`: tests substitute a recording subclass
 * (see tests/Support/FakeBinaryRunner.php) instead of exercising a real
 * process, since these commands are thin wiring and the CLI itself is out of
 * scope for this package.
 *
 * Discovery order: an explicit override → `vendor/bin/atoms` → `$PATH` → the
 * monorepo-relative fallback `packages/cli/bin/atoms` (useful only when this
 * package is developed inside the atoms-framework monorepo itself).
 */
class BinaryRunner
{
    public function __construct(
        private readonly ?string $binaryOverride = null,
        private readonly ?string $basePath = null,
    ) {
    }

    public function locate(): string
    {
        if ($this->binaryOverride !== null) {
            return $this->binaryOverride;
        }

        $vendorBin = $this->resolvedBasePath() . '/vendor/bin/atoms';
        if (is_file($vendorBin)) {
            return $vendorBin;
        }

        $onPath = (new ExecutableFinder())->find('atoms');
        if ($onPath !== null) {
            return $onPath;
        }

        $monorepoFallback = dirname(__DIR__, 3) . '/cli/bin/atoms';
        if (is_file($monorepoFallback)) {
            return $monorepoFallback;
        }

        throw new \RuntimeException(
            "Could not locate the 'atoms' binary. Checked {$vendorBin}, \$PATH, and the monorepo fallback. "
            . 'Run `composer require atoms/cli` or make sure `atoms` is on your PATH.',
        );
    }

    /**
     * Run the binary with $args, streaming combined stdout/stderr to
     * $onOutput as it arrives. Returns the process exit code.
     *
     * @param list<string> $args
     */
    public function run(array $args, ?callable $onOutput = null, ?string $cwd = null): int
    {
        $process = new Process([$this->locate(), ...$args], $cwd ?? $this->resolvedBasePath());
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer) use ($onOutput): void {
            if ($onOutput !== null) {
                $onOutput($buffer);
            }
        });

        return $process->getExitCode() ?? 1;
    }

    private function resolvedBasePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '.');
    }
}
