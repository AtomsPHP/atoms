<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Platform\PlatformApi;
use Atoms\Cli\Platform\PlatformError;
use Atoms\Cli\Platform\PlatformTarget;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms rollback --env X [version]` — flip the environment's version pointer to
 * a retained version (the previous one when omitted).
 */
#[AsCommand(name: 'rollback', description: 'Roll an environment back to a previous version')]
final class RollbackCommand extends AbstractCommand
{
    public function __construct(private readonly PlatformApi $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('version', InputArgument::OPTIONAL, 'Version to roll back to (default: previous)');
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key (else $ATOMS_API_KEY)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $input->getOption('env');
        if (!\is_string($env) || $env === '') {
            $output->writeln('<error>--env is required</error>');

            return self::FAILURE;
        }

        try {
            $config = $this->atomsJson($input);
            $apiKey = $input->getOption('api-key');
            $target = PlatformTarget::resolve($config, $env, \is_string($apiKey) ? $apiKey : null);

            $versionArg = $input->getArgument('version');
            $version = \is_string($versionArg) ? $versionArg : null;

            $response = $this->api->rollback($target, $version);
            if (!$response->isSuccess()) {
                throw PlatformError::from($response);
            }
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $current = $response->json['current_version'] ?? '(unknown)';
        $output->writeln('<info>✓ ' . $env . ' now serving ' . (\is_scalar($current) ? (string) $current : '(unknown)') . '.</info>');

        return self::SUCCESS;
    }
}
