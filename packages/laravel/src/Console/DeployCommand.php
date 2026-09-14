<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:deploy` — thin wrapper over `atoms deploy`.
 *
 * The CLI's `--api-token` is deliberately NOT exposed here: a Cloudflare
 * credential on an Artisan command line would land in the process table and in
 * shell history. It travels in the child's environment instead — the
 * environment *this process was started with*, not the one it currently has,
 * so a `CLOUDFLARE_API_TOKEN` exported by the shell or by CI reaches the CLI
 * while one that Laravel loaded out of the application's `.env` does not.
 * {@see BinaryRunner} §The child's environment for why that distinction is the
 * whole point, and `.env.atoms.<environment>` beside atoms.json for where
 * local deployment values belong instead.
 *
 * Note that Laravel reads its own `--env` off the command line too, and will
 * load `.env.production` for `--env production`. That no longer decides
 * anything here: whatever it loads is application configuration, and stays on
 * this side of the process boundary.
 */
final class DeployCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:deploy
        {--env= : Environment to deploy to (e.g. staging, production)}
        {--bundle= : Path to a prebuilt bundle instead of building one}
        {--manifest= : Manifest for --bundle (default: manifest.json beside it)}
        {--worker-dir= : Worker project directory (else atoms-worker/ beside atoms.json)}
        {--callback-url= : Callback URL for this deployment (beats ATOMS_CALLBACK_URL and atoms.json callback_url)}';

    protected $description = 'Deploy the current build to your Atoms Worker';

    public function handle(): int
    {
        $args = ['deploy'];

        foreach (['env', 'bundle', 'manifest', 'worker-dir', 'callback-url'] as $option) {
            if (($value = $this->option($option)) !== null) {
                $args[] = '--' . $option;
                $args[] = (string) $value;
            }
        }

        return $this->runBinary($args);
    }
}
