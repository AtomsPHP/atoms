<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:rollback` — thin wrapper over `atoms rollback`.
 */
final class RollbackCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:rollback
        {version? : Bundle version/content-hash to roll back to (defaults to the previous deploy)}
        {--env= : Environment to roll back}';

    protected $description = 'Roll back an Atoms environment to a previous deploy';

    public function handle(): int
    {
        $args = ['rollback'];

        if (($env = $this->option('env')) !== null) {
            $args[] = '--env';
            $args[] = (string) $env;
        }

        if (($version = $this->argument('version')) !== null) {
            $args[] = (string) $version;
        }

        return $this->runBinary($args);
    }
}
