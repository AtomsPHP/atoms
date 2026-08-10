<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms rollback [version-id] --env X` — over `wrangler rollback`.
 *
 * A bare `atoms rollback` rolls back to the previous version, which is
 * Wrangler's own default. `atoms status` lists the version ids.
 *
 * "Previous version" means the previous *Worker version*, not the previous
 * bundle. A `wrangler secret put` mints a version too, so on a Worker whose
 * last two versions are one deploy followed by one secret rotation, rolling
 * back lands on the same code it started from. Hence the note printed on
 * success.
 */
#[AsCommand(name: 'rollback', description: 'Roll a Worker back to a previous version')]
final class RollbackCommand extends AbstractCommand
{
    public function __construct(private readonly Wrangler $wrangler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('version', InputArgument::OPTIONAL, 'Worker version id (default: previous)');
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Reason for the rollback');
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

            $versionArg = $input->getArgument('version');
            $version = \is_string($versionArg) && $versionArg !== '' ? $versionArg : null;

            $result = $this->wrangler->rollback($target, $version, self::stringOption($input, 'message'));
            $output->write($result->stdout);
            $output->write($result->stderr);
            $result->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ ' . $target->workerName . ' rolled back to ' . ($version ?? 'the previous version') . '.</info>');
        // Every version counts, including ones that changed no code. Observed
        // on a real account: with a single deploy followed by a secret
        // rotation, a bare rollback selected the rotation's version and the
        // running code did not change.
        $output->writeln('');
        $output->writeln('<comment>This moves the Worker version, not the bundle. Setting a secret also</comment>');
        $output->writeln('<comment>creates a version, so the previous version may run the same code —</comment>');
        $output->writeln('<comment>check `atoms status --env ' . $env . '` if you expected a code change.</comment>');

        return self::SUCCESS;
    }
}
