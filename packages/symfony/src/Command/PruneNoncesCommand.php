<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Symfony\Callback\DoctrineNonceStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atoms:nonces:prune', description: 'Delete expired callback nonce records')]
final class PruneNoncesCommand extends Command
{
    /** @param \Closure(): NonceStore $store */
    public function __construct(private readonly \Closure $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = ($this->store)();
        if (!$store instanceof DoctrineNonceStore) {
            throw new NonceStoreConfigurationException('The nonce pruning command requires atoms.callback_nonce.driver: database.');
        }

        $output->writeln(sprintf('Deleted %d expired callback nonce records.', $store->prune()));
        return self::SUCCESS;
    }
}
