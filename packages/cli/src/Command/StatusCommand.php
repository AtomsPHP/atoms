<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms status --env X` — what is deployed, over `wrangler versions list`.
 *
 * Cloudflare Worker *versions* replace the platform's deploy versions: each
 * `wrangler deploy` mints one, and `atoms rollback` names one.
 */
#[AsCommand(name: 'status', description: 'Show deployed Worker versions for an environment')]
final class StatusCommand extends AbstractCommand
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
                null,
                self::stringOption($input, 'worker-dir'),
            );

            $result = $this->wrangler->versions($target);
            if (!$result->ok()) {
                $output->write($result->stderr);
            }
            $result->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('Environment: ' . $env);
        $output->writeln('Worker:      ' . $target->workerName);
        $output->writeln('Endpoint:    ' . $target->endpoint);

        $versions = $result->json();
        if ($versions === null) {
            // Wrangler's JSON shape is its own; if it changes under us, show
            // what it actually said rather than claiming there are no versions.
            $output->writeln('Versions:    (could not parse `wrangler versions list --json` output)');
            $output->write($result->stdout);

            return self::SUCCESS;
        }

        if ($versions === []) {
            $output->writeln('Versions:    (none — nothing deployed yet)');

            return self::SUCCESS;
        }

        $output->writeln('Versions:');
        foreach ($versions as $version) {
            if (!\is_array($version)) {
                continue;
            }
            // Field placement observed against wrangler 4.118.0 on a real
            // account: `created_on` is nested under `metadata`, and there is
            // no `workers/message` annotation unless a deploy supplied one.
            // Both older and flatter shapes are tolerated rather than assumed.
            $id = self::field($version, 'id');
            $created = self::field($version, 'metadata', 'created_on') ?? self::field($version, 'created_on');
            $message = self::field($version, 'annotations', 'workers/message')
                ?? self::field($version, 'annotations', 'workers/triggered_by');
            $output->writeln(sprintf(
                '  - %s%s%s',
                $id ?? '(no id)',
                $created === null ? '' : '  ' . $created,
                $message === null ? '' : '  ' . $message,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function field(array $source, string ...$path): ?string
    {
        $value = $source;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            /** @var mixed $value */
            $value = $value[$key];
        }

        return \is_scalar($value) ? (string) $value : null;
    }
}
