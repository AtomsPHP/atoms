<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

use Atoms\Client\AtomsConfig;
use Illuminate\Console\Command;

/**
 * `php artisan atoms:check` — resolves {@see AtomsConfig} from the container so
 * misconfigured secrets (`ATOMS-E105`) or ticket TTLs fail before deployment
 * instead of on the first request.
 */
final class CheckCommand extends Command
{
    protected $signature = 'atoms:check';

    protected $description = 'Validate the Atoms configuration (shared secret and ticket TTL)';

    public function handle(): int
    {
        try {
            $this->laravel->make(AtomsConfig::class);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Atoms configuration is valid.');

        return self::SUCCESS;
    }
}
