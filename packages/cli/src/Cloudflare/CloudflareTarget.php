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
     * @param string      $endpoint   Base URL the deployed Worker serves on; what `atoms/client` calls.
     * @param string      $workerName `wrangler --name`.
     * @param string      $accountId  Cloudflare account id; '' when unresolved.
     * @param string|null $apiToken   Cloudflare API token; null when unresolved.
     * @param string      $workerDir  Absolute path to the Worker project (holds wrangler + src/).
     * @param bool        $debugEndpoints Whether atoms.json enables the Worker's /debug routes for this environment.
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $endpoint,
        public readonly string $workerName,
        public readonly string $accountId,
        public readonly ?string $apiToken,
        public readonly string $workerDir,
        public readonly bool $debugEndpoints = false,
    ) {
    }

    /**
     * Resolve from atoms.json plus explicit overrides plus the environment.
     *
     * `$requireCredentials` is false for commands that never talk to
     * Cloudflare's API (`atoms dev` runs Wrangler entirely locally), so a
     * developer without an account can still work.
     *
     * @throws AtomsError E070 (unknown environment), E072 (no API token),
     *                    E075 (no account id), E076 (unusable Worker directory)
     */
    public static function resolve(
        AtomsJson $config,
        string $environment,
        ?string $apiToken = null,
        ?string $workerDir = null,
        bool $requireCredentials = true,
    ): self {
        $env = $config->environment($environment);

        $token = self::firstNonEmpty($apiToken, self::env('CLOUDFLARE_API_TOKEN'));
        if ($requireCredentials && $token === null) {
            throw new AtomsError(
                ErrorCode::DeployCredentialsMissing,
                ErrorCatalog::format(ErrorCode::DeployCredentialsMissing, ['environment' => $environment]),
            );
        }

        $accountId = self::firstNonEmpty($env['account_id'], self::env('CLOUDFLARE_ACCOUNT_ID')) ?? '';
        if ($requireCredentials && $accountId === '') {
            throw new AtomsError(
                ErrorCode::CloudflareAccountMissing,
                ErrorCatalog::format(ErrorCode::CloudflareAccountMissing, ['environment' => $environment]),
            );
        }

        $dir = self::firstNonEmpty($workerDir, $env['worker_dir']) ?? self::DEFAULT_WORKER_DIR;

        return new self(
            environment: $environment,
            endpoint: $env['endpoint'],
            workerName: $env['worker_name'] !== '' ? $env['worker_name'] : $config->project,
            accountId: $accountId,
            apiToken: $token,
            workerDir: self::absolute($config->rootDir, $dir),
            debugEndpoints: $env['debug_endpoints'],
        );
    }

    /**
     * Worker vars this environment's atoms.json asks for, in the shape
     * Wrangler's `--var` takes. Both `atoms dev` and `atoms deploy` pass these
     * through, which is what makes atoms.json the single declaration: the
     * scaffolded Worker directory is gitignored and regenerated, so a var that
     * only lived in its wrangler.jsonc would not survive CI.
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
