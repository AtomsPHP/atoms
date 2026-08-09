<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Platform\PlatformApi;
use Atoms\Cli\Platform\PlatformError;
use Atoms\Cli\Platform\PlatformTarget;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms secrets:list --env X` — list the secret names configured for an
 * environment (values are never returned). Experimental (integration-plan §4.5).
 */
#[AsCommand(name: 'secrets:list', description: '[experimental] List platform secret names for an environment')]
final class SecretsListCommand extends AbstractCommand
{
    public function __construct(private readonly PlatformApi $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
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

            $response = $this->api->listSecrets($target);
            if (!$response->isSuccess()) {
                throw PlatformError::from($response);
            }
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $keys = $response->json['keys'] ?? [];
        if (!\is_array($keys) || $keys === []) {
            $output->writeln('No secrets set for ' . $env . '.');

            return self::SUCCESS;
        }

        $output->writeln('Secrets for ' . $env . ':');
        foreach ($keys as $key) {
            $output->writeln('  - ' . (\is_scalar($key) ? (string) $key : ''));
        }

        return self::SUCCESS;
    }
}
