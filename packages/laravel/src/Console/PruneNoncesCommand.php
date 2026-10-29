<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Laravel\Callback\NonceStoreFactory;
use Illuminate\Console\Command;

final class PruneNoncesCommand extends Command
{
    protected $signature = 'atoms:nonces:prune';

    protected $description = 'Delete expired callback nonces from the configured database';

    public function handle(NonceStoreFactory $factory): int
    {
        if ($this->laravel['config']->get('atoms.callback_nonce.driver') !== 'database') {
            $this->components->error((new NonceStoreConfigurationException(
                'Set atoms.callback_nonce.driver to database before pruning database nonces.',
            ))->getMessage());

            return self::FAILURE;
        }

        try {
            $count = $factory->database()->prune();
        } catch (NonceStoreConfigurationException | NonceStoreUnavailableException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Deleted %d expired callback nonces.', $count));

        return self::SUCCESS;
    }
}
