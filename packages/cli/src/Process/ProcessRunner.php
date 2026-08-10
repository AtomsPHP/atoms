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
     * @param string|null           $stdin   written to the child's stdin, for
     *                                       values that must not appear in an
     *                                       argv (secrets)
     */
    public function run(array $command, ?string $cwd = null, array $env = [], ?float $timeout = null, ?string $stdin = null): ProcessResult;

    /**
     * Run a long-lived process in the foreground, streaming its output to this
     * process's own stdout/stderr as it arrives rather than buffering it.
     *
     * `wrangler dev` is the reason this exists: a captured dev server shows the
     * user nothing until it exits, which is never.
     *
     * @param list<string>          $command argv (first element is the binary)
     * @param array<string, string> $env     extra environment variables
     */
    public function runForeground(array $command, ?string $cwd = null, array $env = []): ProcessResult;

    /**
     * Absolute path to an executable on PATH, or null if not found.
     */
    public function which(string $binary): ?string;
}
