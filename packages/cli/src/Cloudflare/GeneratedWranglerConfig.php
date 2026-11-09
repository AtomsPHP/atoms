<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Derives the selected environment's config from the user's Wrangler file.
 * Each invocation writes a unique file beside the source and passes it with
 * --config. No project-wide redirect is created: even if cleanup cannot run,
 * the leftover file cannot affect Wrangler's configuration discovery.
 *
 * Keeping the copy beside the source preserves relative paths, .dev.vars
 * discovery and the existing local Durable Object storage directory.
 */
final class GeneratedWranglerConfig
{
    private readonly string $filename;
    private bool $ownsFile = false;

    /** @var resource|null Held until remove(); the lock file stays in place. */
    private $lock = null;

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
        $this->filename = '.wrangler.atoms-' . bin2hex(random_bytes(16)) . '.json';
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

        return new self($target->environment, $workerDir, $document, $source, $declaresRouting);
    }

    public function path(): string
    {
        return $this->workerDir . '/' . $this->filename;
    }

    /**
     * Write this invocation's config before building or staging the bundle.
     * Hold the Worker-directory lock until remove().
     *
     * @return string The generated config's absolute path
     *
     * @throws AtomsError E076 when the directory is locked or the file cannot be written
     */
    public function write(): string
    {
        if ($this->lock !== null) {
            return $this->path();
        }

        $dir = $this->workerDir . '/.wrangler/deploy';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw $this->writeError("could not create {$dir}");
        }
        $lock = @fopen($dir . '/atoms.lock', 'c');
        if ($lock === false) {
            throw $this->writeError("could not open {$dir}/atoms.lock");
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw $this->writeError('another atoms dev or deploy command is using this Worker directory; stop it before retrying');
        }
        $this->lock = $lock;

        try {
            $config = json_encode($this->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            // Exclusive creation preserves any existing file, even after an interrupted run.
            $file = @fopen($this->path(), 'x');
            if ($file === false) {
                throw $this->writeError('could not create ' . $this->path());
            }
            $this->ownsFile = true;
            try {
                if (@fwrite($file, $config) !== \strlen($config)) {
                    throw $this->writeError('could not write ' . $this->path());
                }
            } finally {
                fclose($file);
            }
        } catch (\Throwable $e) {
            $this->remove();
            throw $e;
        }

        return $this->path();
    }

    /** Remove only this invocation's config and release the Worker-directory lock. */
    public function remove(): void
    {
        if ($this->ownsFile && is_file($this->path())) {
            @unlink($this->path());
        }
        $this->ownsFile = false;
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function writeError(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::WorkerDirectoryInvalid,
            ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                'environment' => $this->environment,
                'reason' => $reason,
            ]),
        );
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
