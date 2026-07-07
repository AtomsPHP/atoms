<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:deploy` — thin wrapper over `atoms deploy`.
 */
final class DeployCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:deploy
        {--env= : Environment to deploy to (e.g. staging, production)}
        {--bundle= : Path to a prebuilt bundle instead of building one}';

    protected $description = 'Deploy the current build to an Atoms environment';

    public function handle(): int
    {
        $args = ['deploy'];

        if (($env = $this->option('env')) !== null) {
            $args[] = '--env';
            $args[] = (string) $env;
        }

        if (($bundle = $this->option('bundle')) !== null) {
            $args[] = '--bundle';
            $args[] = (string) $bundle;
        }

        return $this->runBinary($args);
    }
}
