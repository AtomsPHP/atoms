<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\DevVars;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms dev` — build, stage, and run the Worker locally under `wrangler dev`.
 *
 * No Cloudflare credentials are required. `wrangler dev` runs workerd on this
 * machine, so a developer with no Cloudflare account can still work — which is
 * why {@see CloudflareTarget::resolve()} is called with `requireCredentials`
 * false here and nowhere else.
 *
 * ## The dev secret
 *
 * Every route the Worker serves, local or deployed, needs `ATOMS_SHARED_SECRET`
 * (docs/shared-secret.md). This command guarantees one is present in the
 * Worker project's `.dev.vars` before starting `wrangler dev`: an existing
 * `ATOMS_SHARED_SECRET` line is left untouched; otherwise a fresh
 * `base64_encode(random_bytes(32))` value is generated and appended, creating
 * the file with mode 0600 when it does not yet exist. `wrangler dev` loads
 * `.dev.vars` on its own, so the value never appears on this command's argv or
 * in a `--var` flag — a secret there would sit in the process table and in
 * shell history, which the CLI-never-holds-a-credential rule exists to
 * prevent. This command prints the `.dev.vars` path and reminds the operator
 * that the app side's own `.env` needs the identical value — never the value
 * itself.
 *
 * A `.dev.vars` inside a git work tree must be gitignored before a secret is
 * generated into it: a committed dev secret would be a known master key.
 * Outside a git work tree, or once `.dev.vars` is gitignored, generation
 * proceeds.
 *
 * ## The callback channel
 *
 * `--callback-url` is passed to the Worker as an `ATOMS_CALLBACK_URL` var, and
 * the Worker calls back through it for `$this->app()` and `$this->dispatch()`.
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
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner(),
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

            $target->assertWorkerDir();
            $devSecret = $this->ensureDevSecret($target->workerDir);
            $output->writeln(
                ($devSecret['generated'] ? 'Generated a per-machine dev secret at ' : 'Using the dev secret at ')
                . $devSecret['path'] . '.'
            );
            $output->writeln('  Set the identical ATOMS_SHARED_SECRET in the app\'s .env.');

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
                $output->writeln('  The Worker will call back to this URL for $this->app() and $this->dispatch().');
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

    /**
     * Guarantee $workerDir/.dev.vars carries an ATOMS_SHARED_SECRET line,
     * generating and appending one when absent.
     *
     * @return array{path: string, generated: bool}
     *
     * @throws AtomsError E105 when the directory is a git work tree and
     *                    .dev.vars is not gitignored
     */
    private function ensureDevSecret(string $workerDir): array
    {
        $path = DevVars::path($workerDir);

        if (DevVars::readSecret($workerDir) !== null) {
            return ['path' => $path, 'generated' => false];
        }

        $this->assertDevVarsIsGitSafe($workerDir);
        DevVars::appendGeneratedSecret($workerDir);

        return ['path' => $path, 'generated' => true];
    }

    /**
     * A `.dev.vars` inside a git work tree must be gitignored before this
     * command writes a generated secret into it: a committed dev secret would
     * be a known master key, readable by anyone with the repository. Outside a
     * git work tree there is nothing to protect against, so this passes
     * silently — including when `git` itself is unavailable, which this
     * treats the same as "not a work tree".
     *
     * @throws AtomsError E105
     */
    private function assertDevVarsIsGitSafe(string $workerDir): void
    {
        $inWorkTree = $this->processRunner->run(['git', '-C', $workerDir, 'rev-parse', '--is-inside-work-tree']);
        if (!$inWorkTree->ok() || trim($inWorkTree->stdout) !== 'true') {
            return;
        }

        // `git check-ignore -q PATH` exits 0 when PATH is ignored.
        $ignored = $this->processRunner->run(['git', '-C', $workerDir, 'check-ignore', '-q', DevVars::FILE]);
        if ($ignored->ok()) {
            return;
        }

        throw new AtomsError(
            ErrorCode::SharedSecretMissing,
            ErrorCode::SharedSecretMissing->value . ': ' . $workerDir . ' is a git work tree, and its .dev.vars '
                . 'is not listed in .gitignore. Add "' . DevVars::FILE . '" to ' . $workerDir . '/.gitignore, '
                . 'then rerun `atoms dev` — a per-machine dev secret committed to git would be a known master key.',
        );
    }
}
