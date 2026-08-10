<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms deploy --env X` — build, stage the bundle into the Worker project, and
 * `wrangler deploy` into the user's own Cloudflare account.
 *
 * There is no Atoms-hosted service in this path. The user's Cloudflare
 * credentials go straight into Wrangler's process environment and nowhere
 * else — Atoms never proxies or retains them.
 */
#[AsCommand(name: 'deploy', description: 'Deploy an Atoms bundle to your Cloudflare account')]
final class DeployCommand extends AbstractCommand
{
    public function __construct(
        private readonly Wrangler $wrangler,
        private readonly BundleStager $stager = new BundleStager(),
        private readonly Builder $builder = new Builder(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('bundle', null, InputOption::VALUE_REQUIRED, 'Deploy a prebuilt bundle instead of building');
        $this->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Manifest for --bundle (default: manifest.json beside it)');
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
            $config = $this->atomsJson($input);
            $target = CloudflareTarget::resolve(
                $config,
                $env,
                null,
                self::stringOption($input, 'worker-dir'),
            );

            $bundleOpt = self::stringOption($input, 'bundle');
            if ($bundleOpt !== null) {
                $bundlePath = $bundleOpt;
                $manifestPath = self::stringOption($input, 'manifest') ?? \dirname($bundlePath) . '/manifest.json';
            } else {
                $output->writeln('Building bundle…');
                $result = $this->builder->build($config, $config->rootDir . '/.atoms/build');
                $bundlePath = $result->bundlePath;
                $manifestPath = $result->manifestPath;
            }

            $output->writeln('Staging bundle into ' . $target->workerDir . '…');
            $this->stager->stage($target, $bundlePath, $manifestPath);

            $output->writeln('Deploying Worker ' . $target->workerName . ' with wrangler…');
            $wrangler = $this->wrangler->deploy($target);

            // Wrangler's own output is the deploy log — including the URL it
            // published to and any Cloudflare API rejection. Reprinting it is
            // more useful than summarising it.
            $output->write($wrangler->stdout);
            $output->write($wrangler->stderr);

            $wrangler->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Deployed ' . $config->project . ' to ' . $env . '.</info>');
        $output->writeln('  worker:   ' . $target->workerName);
        $output->writeln('  endpoint: ' . $target->endpoint);
        // Uploading is not the same as serving. Measured on a real account:
        // /healthz reached the new Worker while the first invocation still
        // 404'd, and a conformance run went 1/12 -> 7/12 -> 12/12 as
        // propagation completed. Saying so beats a success line that overstates
        // what just happened; there is no readiness signal to wait on.
        $output->writeln('');
        $output->writeln('<comment>Cloudflare propagates a new version eventually, so this is not yet</comment>');
        $output->writeln('<comment>fully in force. Atoms already resident keep serving the previous</comment>');
        $output->writeln('<comment>bundle until they next activate — check with `atoms status --env '
            . $env . '`</comment>');
        $output->writeln('<comment>before deploying a monolith that depends on new Atom methods.</comment>');

        return self::SUCCESS;
    }
}
