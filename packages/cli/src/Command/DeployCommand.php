<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Cloudflare\WorkerConfig;
use Atoms\Cli\Config\AtomsDotenv;
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
 *
 * The Worker vars atoms.json declares for the environment — `debug_endpoints`
 * and `callback_url` — ride along as `wrangler deploy --var`, the same
 * way `atoms dev` forwards them. The callback resolves in the order every
 * command and every setting uses: `--callback-url`, then `ATOMS_CALLBACK_URL`
 * in the environment this command was started with, then in
 * `.env.atoms.<env>` beside atoms.json, then the file entry, which may be an
 * explicit ${VARIABLE} reference resolved against those same two environment
 * layers. The nearer source simply wins; nothing is compared or refused —
 * instead the whole resolution is printed, with its sources, before anything
 * is built or shipped. The callback URL is not a secret, so argv is a fine
 * road for it; `ATOMS_SHARED_SECRET` is not forwarded here and never will be —
 * that is `atoms shared-secret:set`.
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
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory (default: atoms-worker/ beside atoms.json)');
        $this->addOption('callback-url', null, InputOption::VALUE_REQUIRED, 'Callback URL for this deployment (beats ATOMS_CALLBACK_URL and atoms.json callback_url)');
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
                self::stringOption($input, 'callback-url'),
            );

            // Before the build, not after it: a Worker directory that is
            // missing (E076) or scaffolded by another release (E108) fails
            // here, in seconds, rather than after a build and a vendor
            // resolution that were never going to ship.
            $target->assertWorkerDir();
            $target->assertRuntimeVersion();

            // Before any side effect — before the build writes .atoms/build,
            // before a byte is staged or uploaded — say what this deployment
            // resolved to and which source supplied each value. Precedence is
            // silent by design; this is what keeps it explicable.
            self::writeResolvedConfiguration($output, $target);
            $output->writeln('');

            // The Worker project's wrangler config is one file for every
            // environment, and this command selects the Worker with `--name`,
            // so a hostname declared there ships with every deploy. The two
            // kinds then fail differently, both measured against a real
            // account:
            //
            //   custom domain — Cloudflare hands it to whichever Worker
            //     claimed it last. Both deploys report success; the hostname
            //     just moves.
            //   route — Cloudflare refuses it (API 10020) and the deploy
            //     fails. But the script has already uploaded by then, so the
            //     environment gets the new code without the routing.
            //
            // Warned rather than refused: a single-environment project that
            // put its hostname there is not wrong, and this command must not
            // start failing for it.
            $workerConfig = WorkerConfig::fromWorkerDir($target->workerDir);
            if ($workerConfig->declaresRouting) {
                $output->writeln('<comment>! ' . ($workerConfig->source ?? 'the Worker config')
                    . ' declares routes or a custom domain at the top level.</comment>');
                $output->writeln('<comment>  That file is shared by every environment, so those hostnames ship '
                    . 'with every deploy.</comment>');
                $output->writeln('<comment>  A custom domain then moves to whichever environment deployed last, '
                    . 'with no error.</comment>');
                $output->writeln('<comment>  A route is refused instead, and the deploy fails after the script '
                    . 'has uploaded —</comment>');
                $output->writeln('<comment>  new code live, routing not. Move them to '
                    . '"routes"/"custom_domains" on each</comment>');
                $output->writeln('<comment>  environment in atoms.json, which this command forwards per '
                    . 'target.</comment>');
                $output->writeln('');
            }

            $bundleOpt = self::stringOption($input, 'bundle');
            if ($bundleOpt !== null) {
                $bundlePath = $bundleOpt;
                $manifestPath = self::stringOption($input, 'manifest') ?? \dirname($bundlePath) . '/manifest.json';
            } else {
                $output->writeln('Building bundle…');
                $result = $this->builder->build($config, $config->rootDir . '/.atoms/build');
                $bundlePath = $result->bundlePath;
                $manifestPath = $result->manifestPath;
                if ($result->vendor !== null && $result->vendor->prunedDataFiles !== []) {
                    $output->writeln(sprintf(
                        '<comment>note: %d vendor data-looking file(s) were pruned from the bundle and will not exist in the guest — run `atoms build` for the list.</comment>',
                        \count($result->vendor->prunedDataFiles),
                    ));
                }
            }

            $output->writeln('Staging bundle into ' . $target->workerDir . '…');
            $this->stager->stage($target, $bundlePath, $manifestPath);

            $output->writeln('Deploying Worker ' . $target->workerName . ' with wrangler…');
            if ($target->debugEndpoints) {
                // Debug endpoints are a second gate behind the Worker's auth
                // check — but under ATOMS_BEARER_AUTH=disabled (an
                // authenticating proxy in front of the Worker), the flag is
                // the only thing in front of /debug, so enabling it deserves
                // more than its row in the table above.
                $output->writeln('  <comment>/debug is reachable on this Worker; under '
                    . 'ATOMS_BEARER_AUTH=disabled this flag is the only gate in front of it.</comment>');
            }
            if ($target->callbackUrl !== null) {
                $output->writeln('  The Worker will call back to the URL above for $this->app() and $this->dispatch().');
            } else {
                $output->writeln(
                    '  No callback URL configured: $this->app() and $this->dispatch() will fail with '
                    . 'ATOMS-E080 unless ' . $target::CALLBACK_VAR . ' is set on the Worker some other way. '
                    . 'Supply one from any of the four sources, nearest first: --callback-url, '
                    . $target::CALLBACK_VAR . ' in the environment this command was started with, '
                    . $target::CALLBACK_VAR . ' in ' . AtomsDotenv::fileName($env) . ' beside atoms.json, '
                    . 'or atoms.json "environments"."' . $env . '"."callback_url" set to a URL '
                    . 'or "${ATOMS_CALLBACK_URL}".'
                );
            }
            $wrangler = $this->wrangler->deploy($target, $target->runtimeVars());

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
