<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

/**
 * The `config.get` allowlist, read from the Worker project's own Wrangler
 * config rather than assumed.
 *
 * The Worker resolves `$this->config('K')` through three variables, all
 * overridable in `wrangler.jsonc`'s `vars` (see `worker/src/config.js`):
 *
 *   ATOMS_CONFIG_ENV_PREFIX     prefix forming the allowlist  (default ATOMS_CONFIG_)
 *   ATOMS_CONFIG_ENV_KEYS       extra exact names, comma separated
 *   ATOMS_CONFIG_ENV_DENY_KEYS  names never readable, whatever else says
 *
 * `Atoms\Cli\Cloudflare\SecretName` used to hardcode the default prefix. That
 * is correct only until someone overrides it, and then `atoms secrets:set K`
 * writes a name the Worker never looks up — silently, because `config.get`
 * answers null for an unknown key rather than erroring. Reading the config the
 * Worker will actually be deployed with is the only way the CLI can be right
 * about this, and the CLI already knows where that project is.
 *
 * Only top-level `vars` are read. Wrangler's per-environment `env.<name>.vars`
 * sections are deliberately ignored, because `atoms deploy` selects the Worker
 * with `--name` and never passes `-e`, so those sections do not apply to what
 * it deploys.
 */
final class WorkerConfig
{
    /** Defaults, mirroring `loadConfig()` in worker/src/config.js. */
    public const DEFAULT_PREFIX = 'ATOMS_CONFIG_';

    /** @var list<string> */
    public const DEFAULT_DENY_KEYS = [
        'ATOMS_APP_KEY',
        'ATOMS_CONFIG_ENV_KEYS',
        'ATOMS_CONFIG_ENV_DENY_KEYS',
    ];

    /**
     * Variables that configure the allowlist itself. Storing a secret that
     * lands on one of these does not add config — it rewires how config is
     * resolved, or is refused outright by the deny list.
     *
     * @var list<string>
     */
    public const CONTROL_KEYS = [
        'ATOMS_CONFIG_ENV_PREFIX',
        'ATOMS_CONFIG_ENV_KEYS',
        'ATOMS_CONFIG_ENV_DENY_KEYS',
    ];

    /**
     * @param list<string> $configEnvKeys
     * @param list<string> $configEnvDenyKeys
     * @param string|null  $source            Config file read, or null when none was.
     */
    public function __construct(
        public readonly string $configEnvPrefix = self::DEFAULT_PREFIX,
        public readonly array $configEnvKeys = [],
        public readonly array $configEnvDenyKeys = self::DEFAULT_DENY_KEYS,
        public readonly ?string $source = null,
    ) {
    }

    /**
     * Read the Wrangler config in $workerDir. Falls back to the documented
     * defaults when there is nothing to read or it cannot be parsed — the same
     * thing the Worker itself does with an absent variable.
     */
    public static function fromWorkerDir(string $workerDir): self
    {
        foreach (['wrangler.jsonc', 'wrangler.json'] as $name) {
            $path = rtrim($workerDir, '/') . '/' . $name;
            if (is_file($path)) {
                $raw = @file_get_contents($path);

                return $raw === false ? new self() : self::fromVars(self::jsoncVars($raw), $path);
            }
        }

        $toml = rtrim($workerDir, '/') . '/wrangler.toml';
        if (is_file($toml)) {
            $raw = @file_get_contents($toml);

            return $raw === false ? new self() : self::fromVars(self::tomlVars($raw), $toml);
        }

        return new self();
    }

    /**
     * The Worker variable name backing an Atom-facing config key, under this
     * Worker's prefix.
     */
    public function workerNameFor(string $key): string
    {
        return SecretName::toWorker($key, $this->configEnvPrefix);
    }

    /**
     * Whether a Worker variable of this name is reachable from Atom code.
     */
    public function isReadable(string $workerName): bool
    {
        if (\in_array($workerName, $this->configEnvDenyKeys, true)) {
            return false;
        }

        return str_starts_with($workerName, $this->configEnvPrefix)
            || \in_array($workerName, $this->configEnvKeys, true);
    }

    /**
     * Why a secret stored under $workerName could never be read back, or null
     * when it can be.
     */
    public function unreadableReason(string $workerName): ?string
    {
        if (\in_array($workerName, self::CONTROL_KEYS, true)) {
            return "{$workerName} configures the config allowlist itself, so setting it would change how "
                . 'every other key resolves rather than storing a value';
        }

        if (\in_array($workerName, $this->configEnvDenyKeys, true)) {
            return "{$workerName} is in ATOMS_CONFIG_ENV_DENY_KEYS, which the Worker honours above everything else";
        }

        return null;
    }

    /**
     * @param array<string, string> $vars
     */
    private static function fromVars(array $vars, string $source): self
    {
        $prefix = $vars['ATOMS_CONFIG_ENV_PREFIX'] ?? '';

        return new self(
            configEnvPrefix: $prefix !== '' ? $prefix : self::DEFAULT_PREFIX,
            configEnvKeys: self::commaList($vars['ATOMS_CONFIG_ENV_KEYS'] ?? ''),
            configEnvDenyKeys: isset($vars['ATOMS_CONFIG_ENV_DENY_KEYS'])
                ? self::commaList($vars['ATOMS_CONFIG_ENV_DENY_KEYS'])
                : self::DEFAULT_DENY_KEYS,
            source: $source,
        );
    }

    /**
     * `vars` from a wrangler.jsonc. Only string values are kept — the three
     * variables read here are all strings, and Wrangler itself coerces.
     *
     * @return array<string, string>
     */
    private static function jsoncVars(string $raw): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(self::stripJsonc($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded) || !\is_array($decoded['vars'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($decoded['vars'] as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * `[vars]` from a wrangler.toml. Deliberately narrow: this reads the three
     * keys it needs by name rather than implementing TOML. Wrangler's JSON
     * config is the current form and what this repository ships.
     *
     * @return array<string, string>
     */
    private static function tomlVars(string $raw): array
    {
        $out = [];
        foreach (self::CONTROL_KEYS as $name) {
            if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/m', $raw, $m) === 1) {
                $out[$name] = $m[1];
            }
        }

        return $out;
    }

    /**
     * Remove `//` and block comments and trailing commas, so a JSONC file can
     * go through json_decode(). String literals are tracked, so a `//` inside
     * a value survives.
     */
    private static function stripJsonc(string $raw): string
    {
        $out = '';
        $length = \strlen($raw);
        $inString = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            $next = $i + 1 < $length ? $raw[$i + 1] : '';

            if ($inString) {
                $out .= $char;
                if ($char === '\\') {
                    // Copy the escaped character wholesale so an escaped quote
                    // does not look like the end of the string.
                    if ($next !== '') {
                        $out .= $next;
                        $i++;
                    }
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                $out .= $char;
                continue;
            }

            if ($char === '/' && $next === '/') {
                while ($i < $length && $raw[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($raw, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            $out .= $char;
        }

        // Trailing commas, now that no comment can be hiding one.
        return (string) preg_replace('/,(\s*[}\]])/', '$1', $out);
    }

    /**
     * @return list<string>
     */
    private static function commaList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
