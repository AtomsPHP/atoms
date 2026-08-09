<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\SecretName;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Cloudflare\WorkerConfig;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms secrets:list --env X` — the Worker's secret names. Cloudflare never
 * returns values, so neither does this.
 *
 * Secrets Atom code can actually read are listed by the key it would use;
 * anything else is listed separately under its raw name, because a Worker
 * legitimately holds operational secrets that Atom code cannot reach. Which is
 * which comes from the Worker project's own config ({@see WorkerConfig}) — the
 * prefix, the extra exact names, and the deny list are all overridable.
 */
#[AsCommand(name: 'secrets:list', description: 'List Worker secret names for an environment')]
final class SecretsListCommand extends AbstractCommand
{
    public function __construct(private readonly Wrangler $wrangler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory (else atoms.json)');
        $this->addOption('api-token', null, InputOption::VALUE_REQUIRED, 'Cloudflare API token (else $CLOUDFLARE_API_TOKEN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $input->getOption('env');
        if (!\is_string($env) || $env === '') {
            $output->writeln('<error>--env is required</error>');

            return self::FAILURE;
        }

        try {
            $target = CloudflareTarget::resolve(
                $this->atomsJson($input),
                $env,
                self::stringOption($input, 'api-token'),
                self::stringOption($input, 'worker-dir'),
            );

            $target->assertWorkerDir();
            $worker = WorkerConfig::fromWorkerDir($target->workerDir);

            $result = $this->wrangler->listSecrets($target);
            if (!$result->ok()) {
                $output->write($result->stderr);
            }
            $result->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $decoded = $result->json();
        if ($decoded === null) {
            $output->writeln('Could not parse `wrangler secret list` output:');
            $output->write($result->stdout);

            return self::SUCCESS;
        }

        $keys = [];
        $other = [];
        foreach ($decoded as $entry) {
            $name = \is_array($entry) ? ($entry['name'] ?? null) : $entry;
            if (!\is_string($name) || $name === '') {
                continue;
            }
            if (!$worker->isReadable($name)) {
                $other[] = $name;
                continue;
            }

            // A name reachable only through ATOMS_CONFIG_ENV_KEYS is read by
            // Atom code under its own exact name, with no prefix to strip.
            $keys[] = SecretName::toKey($name, $worker->configEnvPrefix) ?? $name;
        }

        if ($keys === [] && $other === []) {
            $output->writeln('No secrets set for ' . $env . '.');

            return self::SUCCESS;
        }

        if ($keys !== []) {
            $output->writeln('Readable from Atom code via $this->config() in ' . $env . ':');
            foreach ($keys as $key) {
                $output->writeln('  - ' . $key);
            }
        }

        if ($other !== []) {
            $output->writeln('Other Worker secrets in ' . $env . ' (not readable from Atom code):');
            foreach ($other as $name) {
                $output->writeln('  - ' . $name);
            }
        }

        return self::SUCCESS;
    }
}
