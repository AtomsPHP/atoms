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
 * `atoms status --env X` — list an environment's retained versions and the one
 * currently serving (GET /v1/{project}/deploys).
 */
#[AsCommand(name: 'status', description: 'Show deployed versions for an environment')]
final class StatusCommand extends AbstractCommand
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

            $response = $this->api->deploys($target);
            if (!$response->isSuccess()) {
                throw PlatformError::from($response);
            }
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $current = $response->json['current_version'] ?? '(none)';
        $versions = $response->json['versions'] ?? [];
        $output->writeln('Environment: ' . $env);
        $output->writeln('Current:     ' . (\is_scalar($current) ? (string) $current : '(none)'));
        if (\is_array($versions) && $versions !== []) {
            $output->writeln('Versions:');
            foreach ($versions as $v) {
                $output->writeln('  - ' . (\is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR)));
            }
        }

        return self::SUCCESS;
    }
}
