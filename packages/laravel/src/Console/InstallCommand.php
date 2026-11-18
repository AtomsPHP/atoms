<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan atoms:install` — writes atoms.json (via `atoms init`),
 * publishes config/atoms.php, and creates the Atoms PHPStan configuration.
 * Existing project and analysis configuration is preserved on repeated runs.
 */
final class InstallCommand extends AtomsBinaryCommand
{
    protected $signature = 'atoms:install';

    protected $description = 'Install Atoms into this application (atoms.json, config, PHPStan, callback route)';

    public function handle(): int
    {
        if (!is_file($this->laravel->basePath('atoms.json'))) {
            $exitCode = $this->runBinary(['init']);
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        $exitCode = $this->call('vendor:publish', ['--tag' => 'atoms-config']);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $exitCode = $this->runBinary(['phpstan:install']);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }
        $this->components->info(
            "Run 'atoms ai:install' to generate this project's Claude Code / agent skills.",
        );

        return $exitCode;
    }
}
