<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:dev` — thin wrapper over `atoms dev`, which builds,
 * stages, and serves the Worker through `wrangler dev` on this machine — no
 * Cloudflare account and no credentials needed, so this wrapper forwards none.
 */
final class DevCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:dev
        {--env= : Environment whose settings to use (default: staging)}
        {--port= : Port for wrangler dev (default: 8787)}
        {--callback-url= : Monolith callback URL (else ATOMS_CALLBACK_URL, else atoms.json callback_url)}
        {--worker-dir= : Worker project directory (else atoms.json)}
        {--no-build : Serve the bundle already staged in the Worker project}';

    protected $description = 'Run the Atoms Worker locally with wrangler dev';

    public function handle(): int
    {
        $args = ['dev'];

        foreach (['env', 'port', 'callback-url', 'worker-dir'] as $option) {
            if (($value = $this->option($option)) !== null) {
                $args[] = '--' . $option;
                $args[] = (string) $value;
            }
        }

        if ($this->option('no-build')) {
            $args[] = '--no-build';
        }

        return $this->runBinary($args);
    }
}
