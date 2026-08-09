<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:rollback` — thin wrapper over `atoms rollback`.
 *
 * As with {@see DeployCommand}, `--api-token` is not exposed: the credential
 * travels in the inherited environment (`CLOUDFLARE_API_TOKEN`), never as a
 * command-line argument.
 */
final class RollbackCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:rollback
        {version? : Worker version id to roll back to (defaults to the previous deploy)}
        {--env= : Environment to roll back}
        {--message= : Reason for the rollback}
        {--worker-dir= : Worker project directory (else atoms.json)}';

    protected $description = 'Roll back an Atoms Worker environment to a previous deploy';

    public function handle(): int
    {
        $args = ['rollback'];

        foreach (['env', 'message', 'worker-dir'] as $option) {
            if (($value = $this->option($option)) !== null) {
                $args[] = '--' . $option;
                $args[] = (string) $value;
            }
        }

        if (($version = $this->argument('version')) !== null) {
            $args[] = (string) $version;
        }

        return $this->runBinary($args);
    }
}
