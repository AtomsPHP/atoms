<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

/**
 * Real process execution via proc_open — deliberately dependency-free (no
 * symfony/process) since this bundle otherwise only requires
 * symfony/config|dependency-injection|http-kernel.
 */
final class ProcOpenProcessRunner implements ProcessRunner
{
    public function run(array $command, ?array $env = null): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // A non-null $env *replaces* the child's environment rather than
        // adding to it — which is what the caller wants here, and why it
        // passes a whole snapshot rather than a handful of names.
        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            return [
                'exitCode' => 127,
                'stdout' => '',
                'stderr' => 'Failed to start process: ' . implode(' ', $command),
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }
}
