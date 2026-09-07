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
     * setting — atoms.json does not name it. `--worker-dir` is the explicit
     * per-invocation override for an unusual layout.
     */
    public const DEFAULT_WORKER_DIR = 'atoms-worker';

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
     * The Worker var carrying the monolith's callback endpoint — where
     * `$this->app()` and `$this->dispatch()` reach the app. Not a secret.
     *
     * Resolved the same way on every command: `--callback-url`, then
     * `ATOMS_CALLBACK_URL` in the process environment, then the selected
     * `callback_url.<env>` entry in atoms.json — which may be an explicit
     * ${VARIABLE} reference. The file entry is read at all only when neither
     * nearer source supplied a value, so nothing in it — a malformed
     * reference included — can fail a command that was already answered.
     * An empty or whitespace-only value is "unset" from every source alike.
     */
    public const CALLBACK_VAR = 'ATOMS_CALLBACK_URL';

    /**
     * @param string      $workerName `wrangler --name`.
     * @param string      $accountId  Cloudflare account id; '' when unresolved.
     * @param string|null $apiToken   Cloudflare API token; null when unresolved.
     * @param string      $workerDir  Absolute path to the Worker project (holds wrangler + src/).
     * @param bool        $debugEndpoints Whether atoms.json enables the Worker's /debug routes for this environment.
     * @param string|null $callbackUrl The monolith's callback endpoint; null when nothing configures one.
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $workerName,
        public readonly string $accountId,
        public readonly ?string $apiToken,
        public readonly string $workerDir,
        public readonly bool $debugEndpoints = false,
        public readonly ?string $callbackUrl = null,
    ) {
    }

    /**
     * Resolve from atoms.json plus explicit overrides plus the environment.
     *
     * The Worker directory is `$workerDir` when given, else
     * {@see DEFAULT_WORKER_DIR} under the repository root. atoms.json has no
     * say: one directory serves every environment, so every environment
     * deploys the same runtime.
     *
     * Missing credentials are left to Wrangler. Everything else resolves in
     * one order — flag, then process environment, then atoms.json — and the
     * nearer source wins silently: `CLOUDFLARE_ACCOUNT_ID` outranks the
     * environment's `account_id` (there is no `--account-id` flag), and
     * `--callback-url` outranks `ATOMS_CALLBACK_URL`, which outranks the file.
     * Nothing here compares two sources or errors when they differ. A callback
     * is resolved only for commands that configure the runtime; operating
     * commands opt out.
     *
     * @param string|null $callbackUrl An explicit callback URL for this invocation; outranks the environment and the file.
     * @param bool $local Whether this invocation runs a local Worker. Only softens an unresolvable `${VAR}` file reference into "no callback".
     * @param bool $resolveCallback Whether this command configures runtime vars.
     *
     * @throws AtomsError E070 (unknown environment; or, only when the file
     *                    entry is the source that wins, a malformed `${VAR}`
     *                    callback reference, or one that resolves to nothing
     *                    outside local mode), E076 (unusable Worker directory)
     */
    public static function resolve(
        AtomsJson $config,
        string $environment,
        ?string $apiToken = null,
        ?string $workerDir = null,
        ?string $callbackUrl = null,
        bool $local = false,
        bool $resolveCallback = true,
    ): self {
        $env = $config->environment($environment);

        // Absent is a legitimate answer for both: Wrangler resolves its own
        // credentials, and its own account, when this process supplies none.
        $token = self::firstNonEmpty($apiToken, self::env('CLOUDFLARE_API_TOKEN'));
        // One order, everywhere: flag, then environment, then file. Nothing
        // here errors on disagreement — the nearer source simply wins, the
        // order the AWS CLI and npm document for their own configuration.
        // Atoms has no --account-id flag, so this is the environment over the
        // file.
        $accountId = self::env('CLOUDFLARE_ACCOUNT_ID')
            ?? self::firstNonEmpty($env['account_id'])
            ?? '';

        $dir = self::firstNonEmpty($workerDir) ?? self::DEFAULT_WORKER_DIR;

        $callback = $resolveCallback
            ? self::resolveCallback($config, $environment, $callbackUrl, $local)
            : null;

        return new self(
            environment: $environment,
            workerName: $env['worker_name'],
            accountId: $accountId,
            apiToken: $token,
            workerDir: self::absolute($config->rootDir, $dir),
            debugEndpoints: $env['debug_endpoints'],
            callbackUrl: $callback,
        );
    }

    /**
     * Worker vars for this environment, in Wrangler's `--var` format:
     * the debug-endpoints switch and the resolved callback URL. Both `atoms dev`
     * and `atoms deploy` pass these through. The committed wrangler.jsonc is
     * shared by every environment; atoms.json holds per-environment settings.
     * Neither var is a secret, so both can be passed in argv.
     *
     * @return array<string, string>
     */
    public function runtimeVars(): array
    {
        $vars = [];
        if ($this->debugEndpoints) {
            $vars[self::DEBUG_ENDPOINTS_VAR] = '1';
        }
        if ($this->callbackUrl !== null) {
            $vars[self::CALLBACK_VAR] = $this->callbackUrl;
        }

        return $vars;
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
            throw $this->workerDirError("{$this->workerDir} is not a directory");
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
                'command' => RuntimeVersion::upgradeCommand($this->workerDir),
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

    private static function resolveCallback(
        AtomsJson $config,
        string $environment,
        ?string $callbackUrl,
        bool $local,
    ): ?string {
        // Flag, then environment, then file — the same order every command
        // uses. A nearer source wins silently; none of this is an agreement
        // check. Each return below is also a short-circuit: the file entry is
        // never even read once a nearer source answered, so a malformed
        // ${...} entry cannot fail a command that never needed it.
        $flag = self::firstNonEmpty($callbackUrl);
        if ($flag !== null) {
            return $flag;
        }
        $shell = self::env(self::CALLBACK_VAR);
        if ($shell !== null) {
            return $shell;
        }

        // A whitespace-only literal means the same as an empty one: no callback
        // is declared. Trimming here keeps that equivalent to the trim applied
        // to an expanded reference below, rather than forwarding "   " as a var.
        $callback = self::firstNonEmpty(trim((string) ($config->callbackUrls[$environment] ?? '')));
        if ($callback !== null && str_contains($callback, '${')) {
            if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $callback, $match) !== 1) {
                throw self::invalid('callback_url.' . $environment
                    . ' must use a whole-value environment reference such as ${ATOMS_CALLBACK_URL}');
            }
            $expanded = self::env($match[1]);
            $callback = $expanded === null || trim($expanded) === '' ? null : $expanded;
            if ($callback === null && !$local) {
                throw self::invalid('callback_url.' . $environment . ' requires environment variable '
                    . $match[1] . ' to be set to a non-empty callback URL');
            }
            // Locally, an unset reference is simply no callback. `atoms dev`
            // needs one only for $this->app()/dispatch(), warns when it has
            // none, and must not require a variable that belongs to CI.
        }
        return $callback;
    }

    private static function invalid(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::AtomsJsonInvalid,
            ErrorCatalog::format(ErrorCode::AtomsJsonInvalid, ['reason' => $reason]),
        );
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
        $value = \is_string($value) ? trim($value) : '';

        // Trimmed, so a variable holding only whitespace reads as unset rather
        // than winning precedence and reaching Wrangler as a blank --var. The
        // file path normalises the same way; every source must agree on what
        // "no value" is, or the answer depends on which source supplied it.
        return $value !== '' ? $value : null;
    }

    private static function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = $candidate === null ? null : trim($candidate);
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
