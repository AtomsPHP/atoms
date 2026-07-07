<?php

declare(strict_types=1);

namespace Atoms\Cli\Process;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Real process execution via symfony/process. Not exercised by the test suite
 * (which injects a fake runner) — the network/Docker stages run here in the
 * field only.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, ?string $cwd = null, array $env = [], ?float $timeout = null): ProcessResult
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, $timeout);
        $process->run();

        return new ProcessResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    public function which(string $binary): ?string
    {
        return (new ExecutableFinder())->find($binary);
    }
}
