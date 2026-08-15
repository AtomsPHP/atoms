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
 * ## The callback channel
 *
 * `--callback-url` is passed to the Worker as an `ATOMS_CALLBACK_URL` var, and
 * the Worker calls back through it for `$this->app()` and `$this->dispatch()`.
 * The signing key is never passed by this command: `ATOMS_CALLBACK_SIGNING_KEY`
 * must already be in the Worker project's `.dev.vars`, which `wrangler dev`
 * loads on its own — putting a private key on this command's argv or in a
 * `--var` flag would put it in the process table and in shell history, which
 * the CLI-never-holds-a-credential rule exists to prevent. This command only
 * warns when the key looks to be missing.
 */
#[AsCommand(name: 'dev', description: 'Run the Atoms Worker locally with wrangler dev')]
final class DevCommand extends AbstractCommand
{
    /**
     * The Worker var carrying the monolith's callback endpoint. The Worker
     * consumes this for the signed callback channel (`$this->app()`/`dispatch()`).
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

            // atoms.json is the durable home for Worker settings — the Worker
            // project directory is gitignored and regenerated, so its
            // wrangler.jsonc is not. Forwarded here and on deploy, so dev and
            // deploy agree on what one declaration means.
            $vars = $target->runtimeVars();

            $output->writeln('Starting wrangler dev on port ' . $port . '…');
            if ($target->debugEndpoints) {
                $output->writeln(
                    '  ' . $target::DEBUG_ENDPOINTS_VAR . '=1 (debug endpoints enabled by atoms.json '
                    . '"debug_endpoints" for ' . $env . '; with local dev auth off, the flag is the only '
                    . 'gate in front of /debug)'
                );
            }
            if ($callback !== null) {
                $output->writeln('  ' . self::CALLBACK_VAR . '=' . $callback);
                $output->writeln('  The Worker will call back to this URL for $this->app() and $this->dispatch().');
                if (!$this->hasCallbackSigningKeyConfigured($target)) {
                    $output->writeln(
                        '  <comment>Warning: the Worker has no ATOMS_CALLBACK_SIGNING_KEY configured — '
                        . 'app()/dispatch() will fail with ATOMS-E081. Add it to '
                        . $target->workerDir . '/.dev.vars.</comment>'
                    );
                }
            }

            if ($callback !== null) {
                $vars[self::CALLBACK_VAR] = $callback;
            }

            $result = $this->wrangler->dev($target, $port, $vars);
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Best-effort check for an `ATOMS_CALLBACK_SIGNING_KEY` entry in the
     * Worker project's `.dev.vars` — the only local-dev delivery vehicle for
     * the signing key, since this command never passes it itself (see the
     * class docblock). Absence is not fatal here: it is a warning, because
     * the key may be provisioned some other way this command cannot see.
     */
    private function hasCallbackSigningKeyConfigured(CloudflareTarget $target): bool
    {
        $path = $target->workerDir . '/.dev.vars';
        if (!is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        return $contents !== false && preg_match('/^\s*ATOMS_CALLBACK_SIGNING_KEY\s*=/m', $contents) === 1;
    }
}
