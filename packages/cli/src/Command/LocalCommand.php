<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms local` — run the real Amp runtime image in local mode with app/Atoms
 * bind-mounted and callbacks looped back to the host app (integration-plan §6.2).
 * The process runner is injected; this command is never driven against real
 * Docker in the test suite.
 */
#[AsCommand(name: 'local', description: 'Run the Atoms runtime locally via Docker')]
final class LocalCommand extends AbstractCommand
{
    private const IMAGE = 'ghcr.io/atomsphp/runtime:local';

    private readonly ProcessRunner $runner;

    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new SymfonyProcessRunner();
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment whose callback_url to use', 'staging');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Host port to bind', '8080');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the docker command without running it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $docker = $this->runner->which('docker');
        if ($docker === null) {
            $output->writeln('<error>docker was not found on PATH. Install Docker to run `atoms local`.</error>');

            return self::FAILURE;
        }

        try {
            $config = $this->atomsJson($input);
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $env = $input->getOption('env');
        $env = \is_string($env) ? $env : 'staging';
        $port = $input->getOption('port');
        $port = \is_string($port) ? $port : '8080';
        $callback = $config->callbackUrls[$env] ?? 'http://host.docker.internal';

        $command = [
            $docker, 'run', '--rm', '-it',
            '-p', $port . ':8080',
            '-v', $config->atomsDir() . ':/app/Atoms',
            '-e', 'ATOMS_LOCAL=1',
            '-e', 'ATOMS_CALLBACK_URL=' . $callback,
            '--add-host', 'host.docker.internal:host-gateway',
            self::IMAGE,
        ];

        if ($input->getOption('dry-run') === true) {
            $output->writeln(implode(' ', $command));

            return self::SUCCESS;
        }

        $output->writeln('Starting ' . self::IMAGE . ' on port ' . $port . '…');
        $result = $this->runner->run($command, $config->rootDir);

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }
}
