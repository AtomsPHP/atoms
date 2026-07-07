<?php

declare(strict_types=1);

namespace Atoms\Cli\Process;

/**
 * A thin seam over process execution so the scoper/vendor stage and `atoms
 * local` can be driven by a fake in tests without spawning real subprocesses.
 */
interface ProcessRunner
{
    /**
     * @param list<string>          $command argv (first element is the binary)
     * @param array<string, string> $env     extra environment variables
     */
    public function run(array $command, ?string $cwd = null, array $env = [], ?float $timeout = null): ProcessResult;

    /**
     * Absolute path to an executable on PATH, or null if not found.
     */
    public function which(string $binary): ?string;
}
