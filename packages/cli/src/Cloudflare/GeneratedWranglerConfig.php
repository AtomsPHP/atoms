<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The per-environment Wrangler config `atoms deploy` and `atoms dev` hand to
 * Wrangler, derived from the user's own `wrangler.jsonc` and the selected
 * atoms.json environment.
 *
 * Wrangler looks for `.wrangler/deploy/config.json` beside the user's config
 * before `deploy`, `dev`, `versions upload` and `versions deploy`. When the file
 * is there it reads the config it points at instead of the user's, and says so
 * in its output. That is Cloudflare's documented channel for build tools that
 * already know which environment they are targeting, and it is what this class
 * writes:
 *
 *   <workerDir>/.wrangler/deploy/config.json    { "configPath": "wrangler.json" }
 *   <workerDir>/.wrangler/deploy/wrangler.json  the generated config
 *
 * The generated document is the user's, with the selected environment applied
 * on top: `name` is the environment's `worker_name`, `routes` is rebuilt from
 * its `custom_domains`, the runtime vars the CLI resolves are
 * merged into `vars` (winning over the file), any `env` blocks are dropped
 * because a generated config targets one environment by construction, and the
 * keys Wrangler resolves relative to the config file's own directory (`main`
 * and its kin) are rewritten to point back at the Worker directory. Everything
 * else copies through unchanged, so logging, placement, limits, bindings and
 * migrations remain the user's file's to set.
 *
 * `status`, `rollback` and the secrets commands are unaffected: Wrangler does
 * not consult the redirect for those, and they select the Worker with `--name`.
 *
 * Both files are removed after Wrangler exits, so a `wrangler deploy` run by
 * hand in the Worker directory sees the user's own config again rather than
 * whichever environment Atoms targeted last.
 */
class GeneratedWranglerConfig
{
    public const DIR = '.wrangler/deploy';
    public const REDIRECT_FILE = 'config.json';
    public const FILE = 'wrangler.json';

    /**
     * Keys Wrangler resolves relative to the directory holding the config
     * file that declares them. The generated file lives two levels below the
     * Worker directory, so each relative value gains a `../../` prefix.
     * Keys are given as paths into the document.
     *
     * @var list<list<string>>
     */
    private const RELATIVE_PATH_KEYS = [
        ['main'],
        ['tsconfig'],
        ['$schema'],
        ['assets', 'directory'],
        ['site', 'bucket'],
    ];

    /**
     * @param array<string, mixed> $document        The generated config
     * @param string               $source          The user's config file it was derived from
     * @param bool                 $declaresRouting Whether the user's file carried top-level routes, which are ignored
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $workerDir,
        public readonly array $document,
        public readonly string $source,
        public readonly bool $declaresRouting,
    ) {
    }

    /**
     * Derive the config for $target from the user's `wrangler.jsonc`.
     *
     * Reads `wrangler.jsonc`, then `wrangler.json`. A `wrangler.toml` is
     * refused rather than parsed: the CLI carries no TOML parser, and the
     * scaffold ships JSONC. Unparseable input is an error, not a default —
     * a Worker deployed from a guessed config is worse than one not deployed.
     *
     * @param array<string, string> $vars Runtime vars resolved by the CLI; they win over the file's own
     *
     * @throws AtomsError E076
     */
    public static function generate(CloudflareTarget $target, array $vars): self
    {
        $workerDir = rtrim($target->workerDir, '/');

        $source = null;
        foreach (['wrangler.jsonc', 'wrangler.json'] as $candidate) {
            if (is_file($workerDir . '/' . $candidate)) {
                $source = $workerDir . '/' . $candidate;
                break;
            }
        }
        if ($source === null) {
            $reason = is_file($workerDir . '/wrangler.toml')
                ? "{$workerDir}/wrangler.toml is not supported; convert it to wrangler.jsonc"
                : "{$workerDir} has no wrangler.jsonc or wrangler.json";

            throw self::error($target, $reason);
        }

        $raw = @file_get_contents($source);
        if ($raw === false) {
            throw self::error($target, "could not read {$source}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json5_decode($raw, true);
        } catch (\Throwable $e) {
            throw self::error($target, "could not parse {$source}: " . $e->getMessage());
        }
        if (!\is_array($decoded)) {
            throw self::error($target, "{$source} is not a JSON object");
        }
        /** @var array<string, mixed> $decoded */

        $declaresRouting = ($decoded['routes'] ?? null) !== null || ($decoded['route'] ?? null) !== null;

        $document = $decoded;
        $document['name'] = $target->workerName;
        unset($document['env'], $document['route'], $document['routes']);

        // Custom domains only, in the object form Wrangler needs to treat a
        // pattern as one (checked with `wrangler deploy --dry-run`, not
        // assumed). Plain route patterns are never written: the Worker serves
        // only its own paths, so a path-prefixed pattern could reach nothing.
        $routes = [];
        foreach ($target->customDomains as $hostname) {
            $routes[] = ['pattern' => $hostname, 'custom_domain' => true];
        }
        if ($routes !== []) {
            $document['routes'] = $routes;
        }

        $fileVars = \is_array($document['vars'] ?? null) ? $document['vars'] : [];
        $merged = [...$fileVars, ...$vars];
        if ($merged !== []) {
            $document['vars'] = $merged;
        } else {
            unset($document['vars']);
        }

        foreach (self::RELATIVE_PATH_KEYS as $path) {
            self::rewriteRelativePath($document, $path);
        }

        return new self($target->environment, $workerDir, $document, $source, $declaresRouting);
    }

    /**
     * The generated config's path, relative to the Worker directory.
     */
    public static function relativePath(): string
    {
        return self::DIR . '/' . self::FILE;
    }

    public function path(): string
    {
        return $this->workerDir . '/' . self::relativePath();
    }

    public function redirectPath(): string
    {
        return $this->workerDir . '/' . self::DIR . '/' . self::REDIRECT_FILE;
    }

    /**
     * Write the generated config and the redirect that points Wrangler at it.
     *
     * @return string The generated config's absolute path
     *
     * @throws AtomsError E076 when the directory cannot be written
     */
    public function write(): string
    {
        $dir = $this->workerDir . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new AtomsError(
                ErrorCode::WorkerDirectoryInvalid,
                ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                    'environment' => $this->environment,
                    'reason' => "could not create {$dir}",
                ]),
            );
        }

        $config = json_encode($this->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $redirect = json_encode(['configPath' => self::FILE], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

        file_put_contents($this->path(), $config, LOCK_EX);
        file_put_contents($this->redirectPath(), $redirect, LOCK_EX);

        return $this->path();
    }

    /**
     * Remove the two generated files. Only those: `.wrangler/` is Wrangler's
     * own state directory and the rest of it is left alone.
     */
    public function remove(): void
    {
        foreach ([$this->path(), $this->redirectPath()] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string>         $path
     */
    private static function rewriteRelativePath(array &$document, array $path): void
    {
        $key = array_shift($path);
        if ($path === []) {
            if (\is_string($document[$key] ?? null) && !self::isAbsoluteOrUrl($document[$key])) {
                $document[$key] = '../../' . $document[$key];
            }

            return;
        }

        if (\is_array($document[$key] ?? null)) {
            /** @var array<string, mixed> $child */
            $child = $document[$key];
            self::rewriteRelativePath($child, $path);
            $document[$key] = $child;
        }
    }

    private static function isAbsoluteOrUrl(string $value): bool
    {
        return str_starts_with($value, '/') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1;
    }

    private static function error(CloudflareTarget $target, string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::WorkerDirectoryInvalid,
            ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                'environment' => $target->environment,
                'reason' => $reason,
            ]),
        );
    }
}
