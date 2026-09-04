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
 * `endpoint` is the base URL the deployed Worker serves on — what `atoms/client`
 * calls, and what `atoms status` reports. The Cloudflare keys (`worker_name`,
 * `account_id`) are optional here because each has a fallback: the project
 * name and `$CLOUDFLARE_ACCOUNT_ID` respectively. An account id in particular
 * is better supplied by the environment than committed, which is why
 * atoms.json only offers to hold it.
 *
 * The Worker directory is not a setting: it is a committed part of the
 * repository at `atoms-worker/` beside this file
 * ({@see \Atoms\Cli\Cloudflare\CloudflareTarget::DEFAULT_WORKER_DIR}). The
 * per-environment `worker_dir` key that used to name it is refused with
 * ATOMS-E109 rather than ignored: a repository following the old docs must
 * hear that the directory moved, not deploy from a default it never chose.
 *
 * `debug_endpoints` is the per-environment switch for the Worker's `/debug`
 * routes: wrangler.jsonc is one file for every environment, so the setting
 * that must differ between staging and production lives here, and
 * `atoms dev`/`atoms deploy` forward it to Wrangler as a `--var` override.
 * Off unless explicitly true.
 *
 * @phpstan-type Environment array{endpoint: string, region: string, worker_name: string, account_id: string, debug_endpoints: bool}
 */
final class AtomsJson
{
    /**
     * @param array<string, Environment> $environments
     * @param array<string, string>      $callbackUrls
     * @param array<string, mixed>       $atomConfig
     */
    private function __construct(
        public readonly string $rootDir,
        public readonly string $project,
        public readonly string $atomsPath,
        public readonly string $sharedPath,
        public readonly string $php,
        public readonly array $environments,
        public readonly array $callbackUrls,
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

        if (\array_key_exists('worker_dir', $decoded)) {
            throw self::workerDirRemoved('at the top level');
        }

        $environments = self::parseEnvironments($decoded['environments'] ?? null);

        $callbackUrls = [];
        if (isset($decoded['callback_url'])) {
            if (!\is_array($decoded['callback_url'])) {
                throw self::invalid('"callback_url" must be an object of environment => url');
            }
            foreach ($decoded['callback_url'] as $env => $url) {
                if (!\is_string($env) || !\is_string($url)) {
                    throw self::invalid('"callback_url" entries must be strings');
                }
                $callbackUrls[$env] = $url;
            }
        }

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
            callbackUrls: $callbackUrls,
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
            $endpoint = $env['endpoint'] ?? null;
            if (!\is_string($endpoint) || $endpoint === '') {
                throw self::invalid("environment '{$name}' is missing a string \"endpoint\"");
            }
            if (\array_key_exists('worker_dir', $env)) {
                throw self::workerDirRemoved("on environment '{$name}'");
            }

            $out[$name] = [
                'endpoint' => rtrim($endpoint, '/'),
                // Vestigial: Cloudflare places a Durable Object itself. Still
                // parsed so an older atoms.json loads, and ignored everywhere.
                'region' => self::optionalString($env, 'region'),
                'worker_name' => self::optionalString($env, 'worker_name'),
                'account_id' => self::optionalString($env, 'account_id'),
                'debug_endpoints' => self::optionalBool($env, "environment '{$name}'", 'debug_endpoints'),
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
        if (!\is_string($value) || $value === '') {
            throw self::invalid("\"{$label}\" must be a non-empty string");
        }

        return $value;
    }

    /**
     * The one key this loader refuses rather than ignores. Unknown keys are
     * otherwise tolerated (a newer atoms.json loads under an older CLI), but
     * `worker_dir` is not unknown: it used to steer every Cloudflare command,
     * and a repository still setting it has a gitignored Worker directory
     * where a committed one now belongs. Silently deploying from the new
     * default would be the wrong kind of quiet.
     */
    private static function workerDirRemoved(string $where): AtomsError
    {
        return new AtomsError(
            ErrorCode::WorkerDirKeyRemoved,
            ErrorCatalog::format(ErrorCode::WorkerDirKeyRemoved, [
                'where' => $where,
                'default' => \Atoms\Cli\Cloudflare\CloudflareTarget::DEFAULT_WORKER_DIR . '/',
            ]),
        );
    }

    private static function invalid(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::AtomsJsonInvalid,
            ErrorCatalog::format(ErrorCode::AtomsJsonInvalid, ['reason' => $reason]),
        );
    }
}
