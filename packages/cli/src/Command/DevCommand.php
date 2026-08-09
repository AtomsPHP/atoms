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
 * `atoms dev` — build, stage, and run the Worker locally under `wrangler dev`.
 *
 * Replaces `atoms local`, which ran `ghcr.io/atomsphp/runtime:local`: an image
 * of the superseded Amp runtime, built by a platform that no longer exists.
 *
 * No Cloudflare credentials are required. `wrangler dev` runs workerd on this
 * machine, so a developer with no Cloudflare account can still work — which is
 * why {@see CloudflareTarget::resolve()} is called with `requireCredentials`
 * false here and nowhere else.
 *
 * ## The callback URL is staged, not wired (M2)
 *
 * `--callback-url` is passed to the Worker as an `ATOMS_CALLBACK_URL` var, and
 * the Worker currently ignores it. The monolith half of the callback channel is
 * real — `atoms/client`'s `CallbackKernel` verifies Ed25519-signed callbacks
 * today — but the Worker half is `Atom::app()`, which throws
 * `AtomsNotSupported` by design until M2 implements it. Passing the var now
 * means the loopback address is decided and plumbed; nothing calls back through
 * it yet. The command says so at startup rather than implying a working loop.
 */
#[AsCommand(name: 'dev', description: 'Run the Atoms Worker locally with wrangler dev')]
final class DevCommand extends AbstractCommand
{
    /**
     * The Worker var carrying the monolith's callback endpoint. Inert until M2;
     * named now so the Worker half has a name to implement against.
     */
    public const CALLBACK_VAR = 'ATOMS_CALLBACK_URL';

    private const DEFAULT_PORT = '8787';

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
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment whose settings to use', 'staging');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port for wrangler dev', self::DEFAULT_PORT);
        $this->addOption('callback-url', null, InputOption::VALUE_REQUIRED, 'Monolith callback URL (else atoms.json callback_url)');
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory (else atoms.json)');
        $this->addOption('no-build', null, InputOption::VALUE_NONE, 'Use the bundle already staged in the Worker project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = self::stringOption($input, 'env') ?? 'staging';
        $port = self::stringOption($input, 'port') ?? self::DEFAULT_PORT;

        try {
            $config = $this->atomsJson($input);
            $target = CloudflareTarget::resolve(
                $config,
                $env,
                null,
                self::stringOption($input, 'worker-dir'),
                requireCredentials: false,
            );

            if ($input->getOption('no-build') !== true) {
                $output->writeln('Building bundle…');
                // --fast: no PHP-Scoper stage. A dev loop reruns this on every
                // restart, and scoping only affects the vendored tree.
                $result = $this->builder->build($config, $config->rootDir . '/.atoms/build', fast: true);
                $this->stager->stage($target, $result->bundlePath, $result->manifestPath);
            }

            $callback = self::stringOption($input, 'callback-url') ?? $config->callbackUrls[$env] ?? null;

            $output->writeln('Starting wrangler dev on port ' . $port . '…');
            if ($callback !== null) {
                $output->writeln('  ' . self::CALLBACK_VAR . '=' . $callback);
                $output->writeln('  <comment>Note: the Worker does not call back yet — Atom::app() and dispatch()</comment>');
                $output->writeln('  <comment>throw AtomsNotSupported until M2 implements them. The var is plumbed,</comment>');
                $output->writeln('  <comment>not wired.</comment>');
            }

            $result = $this->wrangler->dev(
                $target,
                $port,
                $callback === null ? [] : [self::CALLBACK_VAR => $callback],
            );
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }
}
