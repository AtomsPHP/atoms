<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

use Atoms\Client\Deployment\CallerEnvironment;
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
 *
 * ## The child's environment
 *
 * The child is given the environment *this process was started with*, not the
 * one it currently has. Artisan runs after Laravel has loaded the
 * application's `.env`, and the Atoms CLI resolves deployment configuration
 * from its own environment — so an inherited environment would quietly make
 * the application's local `.env` a deployment input, and a developer's
 * `ATOMS_CALLBACK_URL=http://localhost:8000/...` would outrank the committed
 * production one on `php artisan atoms:deploy --env production`.
 *
 * {@see CallerEnvironment} snapshots the environment during autoload, before
 * any of that bootstrap runs, and {@see CallerEnvironment::restoration()}
 * turns it into the map handed to Process here: shell and CI values survive
 * untouched, and names the bootstrap added are removed. A `.env.atoms.<env>`
 * beside atoms.json is how a developer supplies those values locally instead;
 * the CLI reads it, below whatever the caller supplied.
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
        $process = new Process(
            [$this->locate(), ...$args],
            $cwd ?? $this->resolvedBasePath(),
            // Empty when nothing snapshotted an environment — the standalone
            // binary, or a test — and then the child inherits as before.
            // Symfony's Process reads `false` as "remove this variable".
            CallerEnvironment::restoration(),
        );
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
