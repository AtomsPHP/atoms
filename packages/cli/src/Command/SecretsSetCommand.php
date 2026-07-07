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
 * `atoms secrets:set KEY [VALUE] --env X` — store a platform-side secret injected
 * into the Machine at bundle load and read via $this->config() (integration-plan
 * §4.5). Experimental: the secrets endpoint is not part of the frozen v1 contract.
 */
#[AsCommand(name: 'secrets:set', description: '[experimental] Set a platform secret for an environment')]
final class SecretsSetCommand extends AbstractCommand
{
    public function __construct(private readonly PlatformApi $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('key', InputArgument::REQUIRED, 'Secret name, e.g. STRIPE_KEY');
        $this->addArgument('value', InputArgument::OPTIONAL, 'Secret value (read from STDIN when omitted)');
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key (else $ATOMS_API_KEY)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $input->getOption('env');
        $key = $input->getArgument('key');
        if (!\is_string($env) || $env === '' || !\is_string($key)) {
            $output->writeln('<error>--env and a KEY are required</error>');

            return self::FAILURE;
        }

        $valueArg = $input->getArgument('value');
        $value = \is_string($valueArg) ? $valueArg : $this->readStdin();

        try {
            $config = $this->atomsJson($input);
            $apiKey = $input->getOption('api-key');
            $target = PlatformTarget::resolve($config, $env, \is_string($apiKey) ? $apiKey : null);

            $response = $this->api->setSecret($target, $key, $value);
            if (!$response->isSuccess()) {
                throw PlatformError::from($response);
            }
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Set ' . $key . ' for ' . $env . '.</info>');

        return self::SUCCESS;
    }

    private function readStdin(): string
    {
        $data = @stream_get_contents(\STDIN);

        return \is_string($data) ? trim($data) : '';
    }
}
