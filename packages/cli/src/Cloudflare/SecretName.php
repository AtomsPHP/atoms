<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

/**
 * Map between the key an Atom asks for and the Worker secret that answers it.
 *
 * `$this->config('PAYMENTS_API_KEY')` inside an Atom does not read a Worker
 * variable called `PAYMENTS_API_KEY`. The host resolves it through an allowlist
 * built from a prefix — `cloudflare/worker/src/bridge.js`, `config.get`:
 *
 *     const normalized = configEnvPrefix + key.toUpperCase().replace(/[^A-Z0-9]+/g, '_');
 *
 * with `configEnvPrefix` defaulting to `ATOMS_CONFIG_`. A secret stored under
 * the bare name is simply invisible to the Atom that needs it — silently, since
 * `config.get` answers null for an unknown key rather than erroring.
 *
 * So `atoms secrets:set PAYMENTS_API_KEY` stores
 * `ATOMS_CONFIG_PAYMENTS_API_KEY`, and
 * `atoms secrets:list` presents the Atom-facing name. The transformation is
 * duplicated from JavaScript into PHP here; the two must move together, which
 * is why the source line above is quoted rather than paraphrased.
 */
final class SecretName
{
    /** Must match `ATOMS_CONFIG_ENV_PREFIX`'s default in worker/src/config.js. */
    public const PREFIX = 'ATOMS_CONFIG_';

    /**
     * The Worker secret name backing an Atom-facing config key.
     */
    public static function toWorker(string $key): string
    {
        return self::PREFIX . preg_replace('/[^A-Z0-9]+/', '_', strtoupper($key));
    }

    /**
     * The Atom-facing key a Worker secret name backs, or null when the secret
     * is not part of the `config.get` allowlist at all (an operational
     * variable, or one reachable only via `ATOMS_CONFIG_ENV_KEYS`).
     */
    public static function toKey(string $workerName): ?string
    {
        if (!str_starts_with($workerName, self::PREFIX)) {
            return null;
        }

        $key = substr($workerName, \strlen(self::PREFIX));

        return $key === '' ? null : $key;
    }
}
