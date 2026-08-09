<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Platform\PlatformApi;
use Atoms\Cli\Platform\PlatformError;
use Atoms\Cli\Platform\PlatformTarget;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms deploy --env X` — build (unless --bundle) and POST the tar.gz to the
 * platform's deploy endpoint. Missing credentials are ATOMS-E072; a 422 rejection
 * surfaces as ATOMS-E042 with the platform's reason.
 */
#[AsCommand(name: 'deploy', description: 'Deploy an Atoms bundle to an environment')]
final class DeployCommand extends AbstractCommand
{
    public function __construct(
        private readonly PlatformApi $api,
        private readonly Builder $builder = new Builder(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('bundle', null, InputOption::VALUE_REQUIRED, 'Deploy a prebuilt bundle instead of building');
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

            $bundleOpt = $input->getOption('bundle');
            if (\is_string($bundleOpt) && $bundleOpt !== '') {
                $bundlePath = $bundleOpt;
            } else {
                $output->writeln('Building bundle…');
                $bundlePath = $this->builder->build($config, $config->rootDir . '/.atoms/build')->bundlePath;
            }

            $response = $this->api->deploy($target, $bundlePath);
            if (!$response->isSuccess()) {
                throw PlatformError::from($response);
            }
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $version = $response->json['version'] ?? '(unknown)';
        $output->writeln('<info>✓ Deployed ' . $config->project . ' to ' . $env . '.</info>');
        $output->writeln('  version: ' . (\is_scalar($version) ? (string) $version : '(unknown)'));

        return self::SUCCESS;
    }
}
