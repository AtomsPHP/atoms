<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\DevVars;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Config\EnvFile;
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
 * No Cloudflare credentials are required: `wrangler dev` runs workerd on this
 * machine, so a developer with no Cloudflare account can still work.
 * {@see CloudflareTarget::resolve()} no longer checks credentials, so there is
 * nothing to opt out of; commands that need credentials surface the failure
 * from Wrangler's own output.
 *
 * ## The dev secret
 *
 * Every route the Worker serves, local or deployed, needs `ATOMS_SHARED_SECRET`
 * (docs/shared-secret.md), and the app needs the identical value under the
 * identical name. This command guarantees both before starting `wrangler dev`.
 *
 * The app's dotenv file is the source of truth — the one file a human edits,
 * and where a fresh `base64_encode(random_bytes(32))` is generated when the
 * key is absent, as `php artisan key:generate` does. The Worker's `.dev.vars`
 * is a generated projection of it, rewritten whenever the two differ: it
 * exists only because `wrangler dev` reads that file and nothing else, and
 * carries exactly the one var the Worker needs rather than the app's whole
 * environment. Treat it as a build artifact.
 *
 * The value reaches Wrangler through that file rather than argv or a `--var`
 * flag, which would put a secret in the process table and shell history — the
 * CLI-never-holds-a-credential rule. It is never printed either: terminal
 * scrollback is the surface a developer shares most casually.
 *
 * Any file about to hold the secret must be gitignored if it sits in a git
 * work tree; a committed dev secret would be a known master key. That check
 * also picks the app's file: Laravel gitignores `.env`, Symfony commits `.env`
 * and gitignores `.env.local`, so asking git lands on the right one without
 * naming either framework.
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
            );

            $target->assertWorkerDir();
            // Same check as deploy, for the same reason: a Worker directory
            // from another release would run a runtime the PHP packages on
            // this machine do not match, and the confusion would surface as
            // a guest error rather than as the skew it is.
            $target->assertRuntimeVersion();
            $this->ensureDevSecret($config->rootDir, $target->workerDir, $output);

            if ($input->getOption('no-build') !== true) {
                $output->writeln('Building bundle…');
                // Full build: an app whose Atoms use atoms-composer.json
                // packages needs the vendor tree in the dev bundle too. The
                // vendor stage caches its resolution under .atoms/vendor-cache,
                // so only the first run (or a lock change) pays for composer.
                $result = $this->builder->build($config, $config->rootDir . '/.atoms/build');
                $this->stager->stage($target, $result->bundlePath, $result->manifestPath);
            }

            $callback = self::stringOption($input, 'callback-url') ?? $config->callbackUrls[$env] ?? null;

            // atoms.json is the per-environment home for Worker settings; the
            // committed wrangler.jsonc is shared by every environment.
            // Forwarded here and on deploy, so dev and deploy agree on what
            // one declaration means.
            $vars = $target->runtimeVars();

            $output->writeln('Starting wrangler dev on port ' . $port . '…');
            if ($target->debugEndpoints) {
                $output->writeln(
                    '  ' . $target::DEBUG_ENDPOINTS_VAR . '=1 (debug endpoints enabled by atoms.json '
                    . '"debug_endpoints" for ' . $env . '; /debug stays behind the Worker\'s bearer check '
                    . 'unless ATOMS_BEARER_AUTH=disabled, where this flag is the only gate)'
                );
            }
            if ($callback !== null) {
                $output->writeln('  ' . self::CALLBACK_VAR . '=' . $callback);
                $output->writeln('  The Worker will call back to this URL for $this->app() and $this->dispatch().');
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
     * Leave both halves holding the same dev secret.
     *
     * The app's dotenv file is the source of truth — one is generated there if
     * absent — and `.dev.vars` is a projection of it, rewritten whenever the
     * two differ. A project with no dotenv file keeps the secret in
     * `.dev.vars` alone. Prints paths only, never the value.
     *
     * @throws AtomsError E105 when a file that must hold the secret is not
     *                    gitignored
     */
    private function ensureDevSecret(string $rootDir, string $workerDir, OutputInterface $output): void
    {
        $appPath = $this->resolveAppEnvFile($rootDir);

        if ($appPath === null) {
            $this->ensureWorkerOnlySecret($workerDir, $output);

            return;
        }

        $secret = EnvFile::read($appPath, DevVars::SECRET_KEY);
        if ($secret === null) {
            $secret = DevVars::generate();
            EnvFile::write($appPath, DevVars::SECRET_KEY, $secret, 'Per-machine dev secret, generated by atoms dev.');
            $output->writeln('Generated a per-machine dev secret in ' . $appPath . '.');
        } else {
            $output->writeln('Using the dev secret in ' . $appPath . '.');
        }

        if (DevVars::readSecret($workerDir) !== $secret) {
            $this->assertDevVarsIsGitSafe($workerDir);
            DevVars::writeSecret($workerDir, $secret);
            $output->writeln('  Projected into ' . DevVars::path($workerDir) . '.');
        }
    }

    /**
     * A project with no dotenv file is not using dotenv, so `.dev.vars` is
     * both source and sink: generate into it once, then leave it alone.
     *
     * @throws AtomsError E105
     */
    private function ensureWorkerOnlySecret(string $workerDir, OutputInterface $output): void
    {
        $path = DevVars::path($workerDir);

        if (DevVars::readSecret($workerDir) !== null) {
            $output->writeln('Using the dev secret at ' . $path . '.');

            return;
        }

        $this->assertDevVarsIsGitSafe($workerDir);
        DevVars::writeSecret($workerDir, DevVars::generate());
        $output->writeln('Generated a per-machine dev secret at ' . $path . '.');
    }

    /**
     * The dotenv file that holds the secret, or null when the project has none
     * and so is not using dotenv.
     *
     * `.env.local` wins when it already exists, or when `.env` is committed
     * and so cannot hold a secret — Symfony's layout. Laravel gitignores
     * `.env` and ships no `.env.local`, so it lands on `.env`.
     *
     * @throws AtomsError E105 when the chosen file is not gitignored
     */
    private function resolveAppEnvFile(string $rootDir): ?string
    {
        $root = rtrim($rootDir, '/');

        if (!is_file($root . '/.env') && !is_file($root . '/.env.local')) {
            return null;
        }

        $name = '.env';
        if (is_file($root . '/.env.local') || !$this->isGitSafe($root, '.env')) {
            $name = '.env.local';
        }

        $this->assertGitSafe($root, $name, 'a shared secret committed to git would be a known master key');

        return $root . '/' . $name;
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
        $this->assertGitSafe(
            $workerDir,
            DevVars::FILE,
            'a per-machine dev secret committed to git would be a known master key',
        );
    }

    /**
     * True when git cannot expose a secret written to $dir/$file: either $dir
     * is not a work tree, or $file is gitignored. `check-ignore` matches
     * patterns, so this also answers for a $file that does not exist yet.
     */
    private function isGitSafe(string $dir, string $file): bool
    {
        $inWorkTree = $this->processRunner->run(['git', '-C', $dir, 'rev-parse', '--is-inside-work-tree']);
        if (!$inWorkTree->ok() || trim($inWorkTree->stdout) !== 'true') {
            return true;
        }

        // `git check-ignore -q PATH` exits 0 when PATH is ignored.
        return $this->processRunner->run(['git', '-C', $dir, 'check-ignore', '-q', $file])->ok();
    }

    /**
     * @throws AtomsError E105
     */
    private function assertGitSafe(string $dir, string $file, string $because): void
    {
        if ($this->isGitSafe($dir, $file)) {
            return;
        }

        throw new AtomsError(
            ErrorCode::SharedSecretMissing,
            ErrorCode::SharedSecretMissing->value . ': ' . $dir . ' is a git work tree, and its ' . $file
                . ' is not listed in .gitignore. Add "' . $file . '" to ' . $dir . '/.gitignore, '
                . 'then rerun `atoms dev` — ' . $because . '.',
        );
    }
}
