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
 * project will be deployed with is a large improvement on assuming, and the
 * CLI already knows where that project is.
 *
 * It is not authoritative, and the commands say which file they read so a
 * mismatch is visible. The Worker's real `env` is whatever the LAST deploy of
 * that Worker name established, which a working-tree file cannot see: the
 * prefix could have been set as a secret rather than a var, or the live Worker
 * deployed from another branch, another machine, or `wrangler deploy -e`.
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
     * @param string|null  $parseError        Why a config file that exists could not be read.
     */
    public function __construct(
        public readonly string $configEnvPrefix = self::DEFAULT_PREFIX,
        public readonly array $configEnvKeys = [],
        public readonly array $configEnvDenyKeys = self::DEFAULT_DENY_KEYS,
        public readonly ?string $source = null,
        public readonly ?string $parseError = null,
    ) {
    }

    /**
     * Read the Wrangler config in $workerDir.
     *
     * A config file that exists but cannot be parsed sets {@see $parseError}
     * rather than quietly yielding defaults. Callers must check it: falling
     * back silently would put a Worker with a custom prefix straight back into
     * the failure this class exists to remove, and the user would have no way
     * to tell the two apart.
     */
    public static function fromWorkerDir(string $workerDir): self
    {
        foreach (['wrangler.jsonc', 'wrangler.json'] as $name) {
            $path = rtrim($workerDir, '/') . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if ($raw === false) {
                return new self(parseError: "could not read {$path}");
            }

            try {
                return self::fromVars(self::jsoncVars($raw), $path);
            } catch (\Throwable $e) {
                return new self(parseError: "could not parse {$path}: " . $e->getMessage());
            }
        }

        $toml = rtrim($workerDir, '/') . '/wrangler.toml';
        if (is_file($toml)) {
            $raw = @file_get_contents($toml);
            if ($raw === false) {
                return new self(parseError: "could not read {$toml}");
            }

            try {
                return self::fromVars(self::tomlVars($raw), $toml);
            } catch (\Throwable $e) {
                return new self(parseError: "could not read {$toml}: " . $e->getMessage());
            }
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
     *
     * Carrying the prefix is necessary but not sufficient. The reachable set is
     * not "prefix + anything", it is "prefix + normalize(key)", and normalize
     * only ever emits `[A-Z0-9_]` with no doubled underscore. `ATOMS_CONFIG_foo`
     * and `ATOMS_CONFIG_A__B` both carry the prefix and are both dead — no key
     * normalizes onto them — so readability is decided by round-tripping the
     * name back through the transform.
     */
    public function isReadable(string $workerName): bool
    {
        if (\in_array($workerName, $this->configEnvDenyKeys, true)) {
            return false;
        }

        if (\in_array($workerName, $this->configEnvKeys, true)) {
            return true;
        }

        $key = SecretName::toKey($workerName, $this->configEnvPrefix);

        return $key !== null && SecretName::toWorker($key, $this->configEnvPrefix) === $workerName;
    }

    /**
     * Why storing $key could never be read back as `$this->config($key)`, or
     * null when it can be.
     */
    public function keyRefusalReason(string $key): ?string
    {
        // PHP's strtoupper() is byte-wise ASCII; JavaScript's toUpperCase() is
        // Unicode-aware. They agree on ASCII and diverge whenever a character
        // uppercases into ASCII: "straße" becomes STRASSE in the Worker and
        // STRA_E here, so the CLI would write a name the Worker never looks
        // up. Rather than reimplement Unicode casing in two places, keep keys
        // to the range where the two implementations are provably identical.
        if (preg_match('/[^\x20-\x7E]/', $key) === 1) {
            return "{$key} contains non-ASCII characters, which PHP and the Worker's JavaScript uppercase "
                . 'differently (ß becomes SS there and _ here), so the name stored would not be the name read';
        }

        return $this->unreadableReason($this->workerNameFor($key));
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
        // config.js `str()` falls back when the value is absent OR empty;
        // `list()` falls back when it is absent OR blank after trimming. An
        // explicitly-empty ATOMS_CONFIG_ENV_DENY_KEYS therefore leaves the
        // Worker on its DEFAULTS, not on an empty deny list — and modelling
        // that as empty would let the CLI bless writes to ATOMS_APP_KEY, the
        // Worker's own bearer secret.
        $prefix = $vars['ATOMS_CONFIG_ENV_PREFIX'] ?? '';
        $deny = $vars['ATOMS_CONFIG_ENV_DENY_KEYS'] ?? '';

        return new self(
            configEnvPrefix: $prefix !== '' ? $prefix : self::DEFAULT_PREFIX,
            configEnvKeys: self::commaList($vars['ATOMS_CONFIG_ENV_KEYS'] ?? ''),
            configEnvDenyKeys: self::jsTrim($deny) === '' ? self::DEFAULT_DENY_KEYS : self::commaList($deny),
            source: $source,
        );
    }

    /**
     * `vars` from a wrangler.jsonc.
     *
     * Wrangler's config format is JSON with comments and trailing commas,
     * which `json_decode()` rejects outright. `colinodell/json5` parses JSON5,
     * a superset of that format, so everything Cloudflare accepts parses here.
     *
     * This was briefly a hand-written comment stripper. Removing comments
     * correctly means tracking string literals (every `https://` contains a
     * `//`) and therefore escape sequences, which is a small parser — and a
     * small parser that is silently wrong reintroduces exactly the bug this
     * class removes. A library that does it properly is the cheaper answer,
     * and `atoms/cli` already depends on php-parser and three Symfony
     * components, so one more is not a new kind of cost.
     *
     * Throws on a malformed document; the caller turns that into $parseError.
     *
     * @return array<string, string>
     */
    private static function jsoncVars(string $raw): array
    {
        /** @var mixed $decoded */
        $decoded = json5_decode($raw, true);

        if (!\is_array($decoded) || !\is_array($decoded['vars'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($decoded['vars'] as $name => $value) {
            // config.js requires `typeof v === 'string'` and ignores anything
            // else; coercing 5 to "5" here would compute a prefix the Worker
            // never uses.
            if (\is_string($name) && \is_string($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * The `[vars]` table of a wrangler.toml.
     *
     * Scoped to that one table on purpose. Scanning the whole file would pick
     * up `[env.staging.vars]` — the very sections this class documents as not
     * applying — and would read a same-named key out of any other table too.
     * Both TOML string forms are accepted; `vars = { … }` inline-table syntax
     * is not understood, and says so rather than silently reading nothing.
     *
     * @return array<string, string>
     * @throws \RuntimeException when the file uses a form this cannot read
     */
    private static function tomlVars(string $raw): array
    {
        if (preg_match('/^\s*vars\s*=\s*\{/m', $raw) === 1) {
            throw new \RuntimeException(
                'its vars are written as an inline table, which this reader does not parse; '
                . 'use a [vars] table, or wrangler.jsonc'
            );
        }

        $out = [];
        $section = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*\[([^\]]*)\]/', $line, $m) === 1) {
                $section = trim($m[1]);
                continue;
            }
            if ($section !== 'vars') {
                continue;
            }
            // KEY = "value" or KEY = 'value'
            if (preg_match('/^\s*([A-Za-z0-9_-]+)\s*=\s*("([^"]*)"|\x27([^\x27]*)\x27)/', $line, $m) === 1) {
                // Which alternation matched decides which capture holds the
                // value; an empty double-quoted string leaves group 3 empty
                // too, so the opening quote is what distinguishes them.
                $out[$m[1]] = str_starts_with($m[2], '"') ? $m[3] : ($m[4] ?? '');
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function commaList(string $value): array
    {
        if (self::jsTrim($value) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $value) as $item) {
            $item = self::jsTrim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * `String.prototype.trim()`, which is what config.js uses. PHP's `trim()`
     * strips only ASCII whitespace, so a value of U+00A0 alone reads as blank
     * in the Worker and as content here — a small difference that decides
     * whether the deny list is the default one or a list containing garbage.
     *
     * Falls back to ASCII trimming if the value is not valid UTF-8, since
     * `preg_replace` returns null rather than a string in that case.
     */
    private static function jsTrim(string $value): string
    {
        $pattern = '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+'
            . '|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u';

        $trimmed = preg_replace($pattern, '', $value);

        return \is_string($trimmed) ? $trimmed : trim($value);
    }
}
