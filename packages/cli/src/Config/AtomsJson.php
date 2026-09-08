<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The repo-root toolchain anchor. Parsed and validated;
 * every structural problem surfaces as ATOMS-E070 with the catalog fix line.
 *
 * Deploy-target facts live here: every environment names its Worker
 * explicitly, and holds every setting that differs between environments —
 * `account_id`, `debug_endpoints` and `callback_url`. This file is the
 * committed default, and the environment layers override it:
 * `CLOUDFLARE_ACCOUNT_ID` wins over an environment's account_id, and
 * `ATOMS_CALLBACK_URL` wins over its callback_url, on every command. Nothing
 * compares the two sources. Legacy endpoint keys are ignored: Wrangler
 * reports deployed URLs; the monolith configures ATOMS_ENDPOINT. Callback
 * environment references stay literal during parsing and building.
 *
 * The Worker directory is not a setting: it is a committed part of the
 * repository at `atoms-worker/` beside this file
 * ({@see \Atoms\Cli\Cloudflare\CloudflareTarget::DEFAULT_WORKER_DIR}).
 *
 * `debug_endpoints` is the per-environment switch for the Worker's `/debug`
 * routes: wrangler.jsonc is one file for every environment, so the setting
 * that must differ between staging and production lives here, and
 * `atoms dev`/`atoms deploy` forward it to Wrangler as a `--var` override.
 * Off unless explicitly true.
 *
 * @phpstan-type Environment array{region: string, worker_name: string, account_id: string, debug_endpoints: bool, callback_url: string, routes: list<string>, custom_domains: list<string>}
 */
final class AtomsJson
{
    /**
     * @param array<string, Environment> $environments
     * @param array<string, mixed>       $atomConfig
     */
    private function __construct(
        public readonly string $rootDir,
        public readonly string $project,
        public readonly string $atomsPath,
        public readonly string $sharedPath,
        public readonly string $php,
        public readonly array $environments,
        public readonly array $atomConfig,
    ) {
    }

    /**
     * Absolute path to the Atoms source directory.
     */
    public function atomsDir(): string
    {
        return $this->rootDir . '/' . $this->atomsPath;
    }

    public function sharedDir(): string
    {
        return $this->rootDir . '/' . $this->sharedPath;
    }

    /**
     * @return Environment
     * @throws AtomsError E070 when the environment is not configured
     */
    public function environment(string $name): array
    {
        if (!isset($this->environments[$name])) {
            throw new AtomsError(
                ErrorCode::AtomsJsonInvalid,
                ErrorCatalog::format(ErrorCode::AtomsJsonInvalid, [
                    'reason' => "environment '{$name}' is not defined under \"environments\"",
                ]),
            );
        }

        return $this->environments[$name];
    }

    /**
     * Walk up from $startDir until an atoms.json is found; load and validate it.
     *
     * @throws AtomsError E070 when no atoms.json is found or it is invalid
     */
    public static function locate(string $startDir): self
    {
        $dir = rtrim($startDir, '/');
        $dir = $dir === '' ? '/' : $dir;

        while (true) {
            $candidate = $dir . '/atoms.json';
            if (is_file($candidate)) {
                return self::load($candidate);
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new AtomsError(
            ErrorCode::AtomsJsonInvalid,
            ErrorCatalog::format(ErrorCode::AtomsJsonInvalid, [
                'reason' => 'no atoms.json found in this directory or any parent',
            ]),
        );
    }

    /**
     * @throws AtomsError E070
     */
    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw self::invalid("could not read {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalid('invalid JSON: ' . $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw self::invalid('top-level value must be a JSON object');
        }

        $rootDir = \dirname($path);

        $project = self::requireString($decoded, 'project');

        $paths = $decoded['paths'] ?? null;
        if (!\is_array($paths)) {
            throw self::invalid('"paths" must be an object with an "atoms" key');
        }
        $atomsPath = self::requireString($paths, 'paths.atoms', 'atoms');
        $sharedPath = isset($paths['shared'])
            ? self::requireString($paths, 'paths.shared', 'shared')
            : rtrim($atomsPath, '/') . '/Shared';

        $php = isset($decoded['php']) ? self::requireString($decoded, 'php') : '8.3';

        $environments = self::parseEnvironments($decoded['environments'] ?? null);

        $atomConfig = [];
        if (isset($decoded['atom_config'])) {
            if (!\is_array($decoded['atom_config'])) {
                throw self::invalid('"atom_config" must be an object');
            }
            /** @var array<string, mixed> $atomConfig */
            $atomConfig = $decoded['atom_config'];
        }

        return new self(
            rootDir: $rootDir,
            project: $project,
            atomsPath: trim($atomsPath, '/'),
            sharedPath: trim($sharedPath, '/'),
            php: $php,
            environments: $environments,
            atomConfig: $atomConfig,
        );
    }

    /**
     * @param mixed $value
     * @return array<string, Environment>
     */
    private static function parseEnvironments(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!\is_array($value)) {
            throw self::invalid('"environments" must be an object');
        }

        $out = [];
        foreach ($value as $name => $env) {
            if (!\is_string($name) || !\is_array($env)) {
                throw self::invalid('each environment must be an object keyed by name');
            }
            $out[$name] = [
                // Vestigial: Cloudflare places a Durable Object itself. Still
                // parsed so an older atoms.json loads, and ignored everywhere.
                'region' => self::optionalString($env, 'region'),
                'worker_name' => self::requireString($env, "environments.{$name}.worker_name", 'worker_name'),
                'account_id' => self::optionalString($env, 'account_id'),
                'debug_endpoints' => self::optionalBool($env, "environment '{$name}'", 'debug_endpoints'),
                // Beside worker_name and account_id, not in a parallel map
                // keyed by the same names: a second block would let
                // `"prodction"` name an environment that does not exist,
                // parse clean, and leave the real one with no callback while
                // the file plainly declares one.
                'callback_url' => self::optionalString($env, 'callback_url'),
                // Where this environment's Worker is reachable. Per
                // environment because `wrangler.jsonc` is one file for every
                // environment and `atoms deploy` selects the Worker with
                // `--name`: a hostname declared there travels with every
                // deploy, and Cloudflare moves a custom domain to the last
                // Worker that claimed it, silently. Measured, not assumed —
                // two deploys of the same hostname under different names left
                // one attachment, pointing at the second.
                'routes' => self::optionalStringList($env, "environments.{$name}.routes", 'routes'),
                'custom_domains' => self::optionalStringList($env, "environments.{$name}.custom_domains", 'custom_domains'),
            ];
        }

        return $out;
    }

    /**
     * Absent means false. Anything but a JSON boolean is refused rather than
     * coerced: `"debug_endpoints": "false"` silently reading as enabled is
     * exactly the kind of surprise this key must not have.
     *
     * @param array<array-key, mixed> $source
     */
    private static function optionalBool(array $source, string $context, string $key): bool
    {
        $value = $source[$key] ?? false;
        if (!\is_bool($value)) {
            throw self::invalid("{$context}: \"{$key}\" must be a JSON boolean (true or false)");
        }

        return $value;
    }

    /**
     * A list of non-empty strings, or []. Anything else is refused rather
     * than coerced: a route silently dropped because it was written as an
     * object, or a bare string where a list was meant, is a hostname that
     * quietly does not get served.
     *
     * @param array<array-key, mixed> $source
     * @return list<string>
     */
    private static function optionalStringList(array $source, string $label, string $key): array
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (!\is_array($value) || array_is_list($value) === false) {
            throw self::invalid("\"{$label}\" must be an array of strings");
        }

        $out = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || trim($entry) === '') {
                throw self::invalid("\"{$label}\" entries must be non-empty strings");
            }
            $out[] = trim($entry);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function optionalString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $source
     */
    private static function requireString(array $source, string $label, ?string $key = null): string
    {
        $key ??= $label;
        $value = $source[$key] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            throw self::invalid("\"{$label}\" must be a non-empty string");
        }

        return $value;
    }

    private static function invalid(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::AtomsJsonInvalid,
            ErrorCatalog::format(ErrorCode::AtomsJsonInvalid, ['reason' => $reason]),
        );
    }
}
