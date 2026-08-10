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
 * A secret stored under the bare name is simply invisible to the Atom that
 * needs it — silently, since `config.get` answers null for an unknown key
 * rather than erroring.
 *
 * **The prefix is a parameter, not a constant.** It defaults to
 * `ATOMS_CONFIG_` and is overridable per deployment through
 * `ATOMS_CONFIG_ENV_PREFIX`; {@see WorkerConfig} reads whichever one the Worker
 * project is actually configured with. Callers should get it from there rather
 * than taking the default, or they reintroduce exactly the silent mismatch this
 * class exists to prevent.
 *
 * The transformation is duplicated from JavaScript into PHP here; the two must
 * move together, which is why the source line above is quoted rather than
 * paraphrased.
 */
final class SecretName
{
    /**
     * The Worker secret name backing an Atom-facing config key.
     */
    public static function toWorker(string $key, string $prefix = WorkerConfig::DEFAULT_PREFIX): string
    {
        return $prefix . preg_replace('/[^A-Z0-9]+/', '_', strtoupper($key));
    }

    /**
     * The Atom-facing key a Worker secret name backs, or null when the secret
     * does not carry the prefix at all (an operational variable, or one
     * reachable only through `ATOMS_CONFIG_ENV_KEYS`).
     */
    public static function toKey(string $workerName, string $prefix = WorkerConfig::DEFAULT_PREFIX): ?string
    {
        if ($prefix === '' || !str_starts_with($workerName, $prefix)) {
            return null;
        }

        $key = substr($workerName, \strlen($prefix));

        return $key === '' ? null : $key;
    }
}
