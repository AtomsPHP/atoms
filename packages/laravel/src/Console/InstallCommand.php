<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:install` — writes atoms.json (via `atoms init`),
 * publishes config/atoms.php, and points the developer at the two follow-ups
 * that are out of this command's scope: wiring atoms/phpstan-rules into
 * phpstan.neon, and `atoms ai:install` for the agent skills.
 */
final class InstallCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:install';

    protected $description = 'Install Atoms into this application (atoms.json, config, callback route)';

    public function handle(): int
    {
        $exitCode = $this->runBinary(['init']);

        $this->call('vendor:publish', ['--tag' => 'atoms-config']);

        $this->components->info(
            'Add atoms/phpstan-rules to your phpstan.neon "includes" to enforce the Atoms boundary in CI.',
        );
        $this->components->info(
            "Run 'atoms ai:install' to generate this project's Claude Code / agent skills.",
        );

        return $exitCode;
    }
}
