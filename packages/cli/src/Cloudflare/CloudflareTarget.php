<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Cli\Config\AtomsJson;
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
    /** Where the Worker project lives when atoms.json does not say. */
    public const DEFAULT_WORKER_DIR = '.atoms/worker';

    /**
     * The Worker var gating the `/debug` routes. Off by default in the Worker
     * (`worker/src/config.js`) and absent from the scaffolded wrangler.jsonc;
     * atoms.json's per-environment `debug_endpoints` is the one switch, and it
     * reaches Wrangler as a `--var` so it survives the Worker directory being
     * regenerated.
     */
    public const DEBUG_ENDPOINTS_VAR = 'ATOMS_DEBUG_ENDPOINTS';

    /**
     * The Worker var carrying the monolith's callback endpoint — where
     * `$this->app()` and `$this->dispatch()` reach the app. Not a secret.
     *
     * Resolved the way `account_id` is, and for the same reason: a callback
     * URL is per deployment and often per developer (a tunnel host, a local
     * port, a staging domain that differs by machine), so a committed value
     * is the wrong default. An explicit `--callback-url` wins, then this same
     * name in the CLI's own process environment, then atoms.json's
     * `callback_url.<env>`. The name is deliberately the Worker's: it is what
     * the value is called everywhere else, and setting it in the shell that
     * runs `atoms dev` reads as "give the Worker this var".
     */
    public const CALLBACK_VAR = 'ATOMS_CALLBACK_URL';

    /**
     * @param string      $endpoint   Base URL the deployed Worker serves on; what `atoms/client` calls.
     * @param string      $workerName `wrangler --name`.
     * @param string      $accountId  Cloudflare account id; '' when unresolved.
     * @param string|null $apiToken   Cloudflare API token; null when unresolved.
     * @param string      $workerDir  Absolute path to the Worker project (holds wrangler + src/).
     * @param bool        $debugEndpoints Whether atoms.json enables the Worker's /debug routes for this environment.
     * @param string|null $callbackUrl The monolith's callback endpoint; null when nothing configures one.
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $endpoint,
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
     * @param string|null $callbackUrl An explicit `--callback-url`; beats
     *                                 `ATOMS_CALLBACK_URL` in the environment,
     *                                 which beats atoms.json's `callback_url`.
     *
     * @throws AtomsError E070 (unknown environment),
     *                    E076 (unusable Worker directory)
     */
    public static function resolve(
        AtomsJson $config,
        string $environment,
        ?string $apiToken = null,
        ?string $workerDir = null,
        ?string $callbackUrl = null,
    ): self {
        $env = $config->environment($environment);

        // Absent is a legitimate answer for both: Wrangler resolves its own
        // credentials, and its own account, when this process supplies none.
        $token = self::firstNonEmpty($apiToken, self::env('CLOUDFLARE_API_TOKEN'));
        $accountId = self::firstNonEmpty($env['account_id'], self::env('CLOUDFLARE_ACCOUNT_ID')) ?? '';

        $dir = self::firstNonEmpty($workerDir, $env['worker_dir']) ?? self::DEFAULT_WORKER_DIR;

        $callback = self::firstNonEmpty(
            $callbackUrl,
            self::env(self::CALLBACK_VAR),
            $config->callbackUrls[$environment] ?? null,
        );

        return new self(
            environment: $environment,
            endpoint: $env['endpoint'],
            workerName: $env['worker_name'] !== '' ? $env['worker_name'] : $config->project,
            accountId: $accountId,
            apiToken: $token,
            workerDir: self::absolute($config->rootDir, $dir),
            debugEndpoints: $env['debug_endpoints'],
            callbackUrl: $callback,
        );
    }

    /**
     * Worker vars this environment asks for, in the shape Wrangler's `--var`
     * takes: the debug-endpoints switch and the callback URL. Both `atoms dev`
     * and `atoms deploy` pass these through, which is what makes atoms.json
     * the single declaration: the scaffolded Worker directory is gitignored
     * and regenerated, so a var that only lived in its wrangler.jsonc would
     * not survive CI. Neither var is a secret, so `--var` (argv) is fine.
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
