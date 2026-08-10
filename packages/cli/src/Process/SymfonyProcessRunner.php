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
    public function run(array $command, ?string $cwd = null, array $env = [], ?float $timeout = null, ?string $stdin = null): ProcessResult
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, $stdin, $timeout);
        $process->run();

        return new ProcessResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    public function runForeground(array $command, ?string $cwd = null, array $env = []): ProcessResult
    {
        // No timeout: the caller ends this by interrupting it.
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, null);
        $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? \STDERR : \STDOUT, $buffer);
        });

        return new ProcessResult($process->getExitCode() ?? 1, '', '');
    }

    public function which(string $binary): ?string
    {
        return (new ExecutableFinder())->find($binary);
    }
}
