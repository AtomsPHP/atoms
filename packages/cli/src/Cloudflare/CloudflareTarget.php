<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Release\RuntimeVersion;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The resolved coordinates of one Wrangler invocation: which Worker, in whose
 * Cloudflare account, from which Worker project directory.
 *
 * Credentials are resolved here and then travel exactly one way — into the
 * child process environment in {@see self::credentialEnv()}. Atoms never writes
 * them to a file, never logs them, and never sends them anywhere but
 * Cloudflare's own API by way of Wrangler.
 *
 * **There is deliberately no `--api-token` option.** A credential passed as a
 * command-line argument is in this process's argv, visible to every other
 * process on the machine, and usually in shell history as well — which would
 * make the invariant above false at the very first hop. `$apiToken` stays a
 * parameter for testing and for callers that already hold the value; the only
 * way a user supplies one is `CLOUDFLARE_API_TOKEN` in the environment.
 *
 * **No token is not an error here.** Wrangler resolves credentials itself, and
 * an existing `wrangler login` OAuth session is one Atoms never sees: with no
 * `CLOUDFLARE_API_TOKEN` set, nothing is injected and Wrangler uses its own.
 * That is the invariant above at its strongest — no credential passes through
 * this process at all — so the CLI hands off rather than pre-empting. When
 * Wrangler has nothing either it says so, and {@see WranglerResult::assertOk()}
 * reports that as ATOMS-E072.
 *
 * **No account id is not an error here either**, for the same reason: a login
 * that can reach exactly one account needs no telling, and only Wrangler knows
 * how many it can reach. A login that can reach several cannot be resolved
 * silently — Wrangler says so, and that becomes ATOMS-E075. Setting
 * `account_id` in atoms.json still makes the target explicit, and remains the
 * recommendation; it is simply no longer a precondition.
 */
final class CloudflareTarget
{
    /**
     * Where the Worker project lives: a committed directory beside atoms.json.
     * Its location is a convention, like atoms.json's own, rather than a
     * setting — atoms.json no longer names it (the per-environment
     * `worker_dir` key is refused, ATOMS-E109). `--worker-dir` remains the
     * explicit per-invocation override for an unusual layout.
     */
    public const DEFAULT_WORKER_DIR = 'atoms-worker';

    /**
     * Where the pre-committed scaffold used to live, gitignored and
     * regenerated per checkout. Only consulted to make a migration error
     * more specific: a repository with this directory and no committed one
     * is following the old docs.
     */
    public const LEGACY_WORKER_DIR = '.atoms/worker';

    /**
     * The Worker var gating the `/debug` routes. Off by default in the Worker
     * (`worker/src/config.js`) and absent from the scaffolded wrangler.jsonc.
     * atoms.json's per-environment `debug_endpoints` is the supported switch,
     * forwarded as a `--var`: wrangler.jsonc is one file for every
     * environment, and this flag is the one setting that must be able to
     * differ between staging and production.
     */
    public const DEBUG_ENDPOINTS_VAR = 'ATOMS_DEBUG_ENDPOINTS';

    /**
     * @param string      $endpoint   Base URL the deployed Worker serves on; what `atoms/client` calls.
     * @param string      $workerName `wrangler --name`.
     * @param string      $accountId  Cloudflare account id; '' when unresolved.
     * @param string|null $apiToken   Cloudflare API token; null when unresolved.
     * @param string      $workerDir  Absolute path to the Worker project (holds wrangler + src/).
     * @param bool        $debugEndpoints Whether atoms.json enables the Worker's /debug routes for this environment.
     * @param string      $rootDir    The repository root atoms.json was found in; '' when unknown.
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $endpoint,
        public readonly string $workerName,
        public readonly string $accountId,
        public readonly ?string $apiToken,
        public readonly string $workerDir,
        public readonly bool $debugEndpoints = false,
        public readonly string $rootDir = '',
    ) {
    }

    /**
     * Resolve from atoms.json plus explicit overrides plus the environment.
     *
     * The Worker directory is `$workerDir` when given, else
     * {@see DEFAULT_WORKER_DIR} under the repository root. atoms.json has no
     * say: it used to carry a per-environment `worker_dir`, which let two
     * environments deploy two different runtimes and made the directory
     * something to regenerate rather than commit.
     *
     * Nothing about credentials fails here any more. Neither half is Atoms'
     * to adjudicate: the token may be a `wrangler login` session this process
     * cannot see, and the account may be the only one that session can reach,
     * in which case Wrangler selects it without being told. Both answers live
     * in the child process, so both are read back out of its failure by
     * {@see WranglerResult::assertOk()} — E072 and E075 respectively.
     *
     * There is consequently no `$requireCredentials` flag left to pass: the
     * commands that never touch Cloudflare's API used it to opt out of checks
     * that no longer exist.
     *
     * @throws AtomsError E070 (unknown environment),
     *                    E076 (unusable Worker directory)
     */
    public static function resolve(
        AtomsJson $config,
        string $environment,
        ?string $apiToken = null,
        ?string $workerDir = null,
    ): self {
        $env = $config->environment($environment);

        // Absent is a legitimate answer for both: Wrangler resolves its own
        // credentials, and its own account, when this process supplies none.
        $token = self::firstNonEmpty($apiToken, self::env('CLOUDFLARE_API_TOKEN'));
        $accountId = self::firstNonEmpty($env['account_id'], self::env('CLOUDFLARE_ACCOUNT_ID')) ?? '';

        $dir = self::firstNonEmpty($workerDir) ?? self::DEFAULT_WORKER_DIR;

        return new self(
            environment: $environment,
            endpoint: $env['endpoint'],
            workerName: $env['worker_name'] !== '' ? $env['worker_name'] : $config->project,
            accountId: $accountId,
            apiToken: $token,
            workerDir: self::absolute($config->rootDir, $dir),
            debugEndpoints: $env['debug_endpoints'],
            rootDir: $config->rootDir,
        );
    }

    /**
     * Worker vars this environment's atoms.json asks for, in the shape
     * Wrangler's `--var` takes. Both `atoms dev` and `atoms deploy` pass these
     * through, which is what makes atoms.json the single per-environment
     * declaration: the committed wrangler.jsonc is shared by every
     * environment (the CLI selects the Worker with `--name`, never `-e`), so
     * a var set there would enable the debug surface everywhere at once.
     *
     * @return array<string, string>
     */
    public function runtimeVars(): array
    {
        return $this->debugEndpoints ? [self::DEBUG_ENDPOINTS_VAR => '1'] : [];
    }

    /**
     * Assert the Worker project directory is one Wrangler can actually run in.
     *
     * Checked before every invocation rather than at resolve time: the most
     * common real failure is a correct path whose `npm ci` has not been run,
     * and that deserves its own fix line rather than a bare "wrangler not
     * found".
     *
     * @throws AtomsError E076
     */
    public function assertWorkerDir(): void
    {
        if (!is_dir($this->workerDir)) {
            throw $this->workerDirError("{$this->workerDir} is not a directory" . $this->legacyHint());
        }

        foreach (['wrangler.jsonc', 'wrangler.json', 'wrangler.toml'] as $candidate) {
            if (is_file($this->workerDir . '/' . $candidate)) {
                return;
            }
        }

        throw $this->workerDirError("{$this->workerDir} has no wrangler.jsonc, wrangler.json or wrangler.toml");
    }

    /**
     * Assert the Worker directory was scaffolded by this CLI's release.
     *
     * The Worker directory is committed and co-versioned with the CLI and the
     * Composer packages, so upgrading one without the other is the ordinary
     * way for them to drift. A mismatch is refused before anything is built
     * or shipped (ATOMS-E108), naming both versions and the exact
     * version-pinned upgrade command. Exact equality is the rule: every
     * release publishes a new runtime package, and a range would let a
     * "close enough" runtime deploy against packages it was never tested
     * with. A directory with no stamp at all — scaffolded before stamps
     * existed — is the same finding with "unknown" for the version.
     *
     * Checked by the two commands that stage a bundle into the directory,
     * `deploy` and `dev`; `status`, `rollback` and the secrets commands ship
     * no code and read only wrangler.jsonc.
     *
     * @throws AtomsError E108 on a mismatch or a missing stamp,
     *                    E076 when the stamp exists but is unreadable
     */
    public function assertRuntimeVersion(): void
    {
        $found = RuntimeStamp::version($this->workerDir, $this->environment);
        if ($found === RuntimeVersion::VERSION) {
            return;
        }

        throw new AtomsError(
            ErrorCode::WorkerRuntimeVersionMismatch,
            ErrorCatalog::format(ErrorCode::WorkerRuntimeVersionMismatch, [
                'dir' => $this->workerDir,
                'package' => RuntimeVersion::PACKAGE,
                'found' => $found ?? 'an unknown release (no ' . RuntimeStamp::FILE . ')',
                'expected' => RuntimeVersion::VERSION,
                'command' => RuntimeVersion::upgradeCommand($this->workerDirForCommand()),
            ]),
        );
    }

    /**
     * The credential environment handed to the Wrangler child process. These
     * are the names Wrangler itself reads, deliberately: Atoms is a caller of
     * the user's own toolchain, not a broker sitting between them and
     * Cloudflare.
     *
     * An absent key is the whole mechanism behind the OAuth fallback: with no
     * `CLOUDFLARE_API_TOKEN` here, Wrangler consults its own login session,
     * which Atoms neither reads nor stores.
     *
     * @return array<string, string>
     */
    public function credentialEnv(): array
    {
        $env = [];
        if ($this->apiToken !== null) {
            $env['CLOUDFLARE_API_TOKEN'] = $this->apiToken;
        }
        if ($this->accountId !== '') {
            $env['CLOUDFLARE_ACCOUNT_ID'] = $this->accountId;
        }

        return $env;
    }

    /**
     * The URL `atoms/client` would call to reach a given Atom on this
     * deployment — the Worker's single-tenant, prefixless invoke route.
     */
    public function invokeUrl(string $type, string $id, string $method): string
    {
        return sprintf(
            '%s/invoke/%s/%s/%s',
            rtrim($this->endpoint, '/'),
            rawurlencode($type),
            rawurlencode($id),
            rawurlencode($method),
        );
    }

    /**
     * The directory as a user would type it: relative to the repository root
     * when it sits under one, else absolute.
     */
    private function workerDirForCommand(): string
    {
        if ($this->rootDir === '') {
            return $this->workerDir;
        }

        $root = rtrim($this->rootDir, '/') . '/';
        if (str_starts_with($this->workerDir, $root) && \strlen($this->workerDir) > \strlen($root)) {
            return substr($this->workerDir, \strlen($root));
        }

        return $this->workerDir;
    }

    /**
     * A repository that still has the old gitignored scaffold, and nothing
     * committed, is following the old docs — say so in the E076 reason.
     */
    private function legacyHint(): string
    {
        $legacy = rtrim($this->rootDir, '/') . '/' . self::LEGACY_WORKER_DIR;
        if (!is_dir($legacy)) {
            return '';
        }

        return ' (a pre-commit scaffold exists at ' . $legacy . '; the Worker directory is now committed'
            . ' at ' . self::DEFAULT_WORKER_DIR . '/ — scaffold it fresh there, commit it, and delete '
            . self::LEGACY_WORKER_DIR . ')';
    }

    private function workerDirError(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::WorkerDirectoryInvalid,
            ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                'environment' => $this->environment,
                'reason' => $reason,
            ]),
        );
    }

    private static function absolute(string $rootDir, string $dir): string
    {
        if (str_starts_with($dir, '/')) {
            return rtrim($dir, '/');
        }

        return rtrim($rootDir, '/') . '/' . trim($dir, '/');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
