<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:list` — thin wrapper over `atoms status`.
 */
final class ListCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:list {--env= : Filter to one environment}';

    protected $description = 'Show deployed Atoms status for this project';

    public function handle(): int
    {
        $args = ['status'];

        if (($env = $this->option('env')) !== null) {
            $args[] = '--env';
            $args[] = (string) $env;
        }

        return $this->runBinary($args);
    }
}
