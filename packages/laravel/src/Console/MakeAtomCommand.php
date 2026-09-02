<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

/**
 * `php artisan make:atom` — thin wrapper over `atoms make:atom`, which
 * scaffolds the two-worlds layout (see docs/two-worlds.md).
 */
final class MakeAtomCommand extends AtomsBinaryCommand
{
    protected $signature = 'make:atom
        {name : The Atom class name, e.g. GameRoom}
        {--with-methods : Also scaffold a Methods class}
        {--with-migration : Also scaffold an initial migration}
        {--websocket : Scaffold WebSocket handler stubs}';

    protected $description = 'Scaffold a new Atom (and, optionally, its Methods class and migration)';

    public function handle(): int
    {
        $args = ['make:atom', (string) $this->argument('name')];

        if ($this->option('with-methods')) {
            $args[] = '--with-methods';
        }

        if ($this->option('with-migration')) {
            $args[] = '--with-migration';
        }

        if ($this->option('websocket')) {
            $args[] = '--websocket';
        }

        return $this->runBinary($args);
    }
}
