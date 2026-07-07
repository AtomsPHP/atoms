<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * Real process execution via proc_open — deliberately dependency-free (no
 * symfony/process) since this bundle otherwise only requires
 * symfony/config|dependency-injection|http-kernel.
 */
final class ProcOpenProcessRunner implements ProcessRunner
{
    public function run(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

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
