<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:local` — thin wrapper over `atoms local`.
 */
final class LocalCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:local
        {--platform-parity : Run the full build pipeline, including the scoper, for pre-deploy confidence}';

    protected $description = 'Run the local Atoms development runtime';

    public function handle(): int
    {
        $args = ['local'];

        if ($this->option('platform-parity')) {
            $args[] = '--platform-parity';
        }

        return $this->runBinary($args);
    }
}
