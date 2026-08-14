<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

/**
 * A thin seam over process execution so the atoms:* console wrappers can be
 * driven by a fake in tests without spawning a real subprocess.
 */
interface ProcessRunner
{
    /**
     * @param list<string> $command argv, binary first — never passed through a shell.
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(array $command): array;
}
