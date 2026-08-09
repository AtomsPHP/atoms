<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Cloudflare\WorkerConfig;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms secrets:set KEY [VALUE] --env X` — store a Worker secret readable from
 * Atom code as `$this->config('KEY')`.
 *
 * The secret is stored under the name the Worker's `config.get` allowlist
 * resolves `KEY` to — read from the Worker project's own Wrangler config by
 * {@see WorkerConfig}, because the prefix is overridable. Storing the bare
 * name, or a name the deny list blocks, would appear to work and then read
 * back as null.
 *
 * The value never appears in an argv: it goes to Wrangler on stdin.
 */
#[AsCommand(name: 'secrets:set', description: 'Set a Worker secret readable via $this->config()')]
final class SecretsSetCommand extends AbstractCommand
{
    public function __construct(private readonly Wrangler $wrangler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('key', InputArgument::REQUIRED, 'Secret name, e.g. PAYMENTS_API_KEY');
        $this->addArgument('value', InputArgument::OPTIONAL, 'Secret value (read from STDIN when omitted)');
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory (else atoms.json)');
        $this->addOption('api-token', null, InputOption::VALUE_REQUIRED, 'Cloudflare API token (else $CLOUDFLARE_API_TOKEN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $input->getOption('env');
        $key = $input->getArgument('key');
        if (!\is_string($env) || $env === '' || !\is_string($key) || $key === '') {
            $output->writeln('<error>--env and a KEY are required</error>');

            return self::FAILURE;
        }

        $valueArg = $input->getArgument('value');
        $value = \is_string($valueArg) ? $valueArg : $this->readStdin();
        if ($value === '') {
            $output->writeln('<error>Refusing to store an empty value for ' . $key . '.</error>');

            return self::FAILURE;
        }

        try {
            $target = CloudflareTarget::resolve(
                $this->atomsJson($input),
                $env,
                self::stringOption($input, 'api-token'),
                self::stringOption($input, 'worker-dir'),
            );

            // The prefix is read from the Worker project rather than assumed:
            // ATOMS_CONFIG_ENV_PREFIX is overridable, and guessing it wrong
            // stores a secret the Atom can never read, with no error anywhere.
            $target->assertWorkerDir();
            $worker = WorkerConfig::fromWorkerDir($target->workerDir);
            $workerName = $worker->workerNameFor($key);

            $unreadable = $worker->unreadableReason($workerName);
            if ($unreadable !== null) {
                throw new AtomsError(
                    ErrorCode::SecretNotReadable,
                    ErrorCatalog::format(ErrorCode::SecretNotReadable, [
                        'key' => $key,
                        'name' => $workerName,
                        'reason' => $unreadable,
                    ]),
                );
            }

            $result = $this->wrangler->putSecret($target, $workerName, $value);
            if (!$result->ok()) {
                $output->write($result->stderr);
            }
            $result->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Set ' . $key . ' for ' . $env . '.</info>');
        $output->writeln('  worker secret: ' . $workerName);
        $output->writeln('  read it with:  $this->config(' . var_export($key, true) . ')');

        return self::SUCCESS;
    }

    private function readStdin(): string
    {
        $data = @stream_get_contents(\STDIN);

        return \is_string($data) ? trim($data) : '';
    }
}
