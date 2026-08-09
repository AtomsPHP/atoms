<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:deploy` — thin wrapper over `atoms deploy`.
 *
 * The CLI's `--api-token` is deliberately NOT exposed here: a Cloudflare
 * credential on an Artisan command line would land in the process table and in
 * shell history. The `atoms` child process inherits this process's environment,
 * so `CLOUDFLARE_API_TOKEN` reaches it without ever being an argument.
 */
final class DeployCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:deploy
        {--env= : Environment to deploy to (e.g. staging, production)}
        {--bundle= : Path to a prebuilt bundle instead of building one}
        {--manifest= : Manifest for --bundle (default: manifest.json beside it)}
        {--worker-dir= : Worker project directory (else atoms.json)}';

    protected $description = 'Deploy the current build to your Atoms Worker';

    public function handle(): int
    {
        $args = ['deploy'];

        foreach (['env', 'bundle', 'manifest', 'worker-dir'] as $option) {
            if (($value = $this->option($option)) !== null) {
                $args[] = '--' . $option;
                $args[] = (string) $value;
            }
        }

        return $this->runBinary($args);
    }
}
