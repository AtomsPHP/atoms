<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The `atoms-runtime.json` stamp that `atoms-runtime-cloudflare init` and
 * `upgrade` write at the root of a Worker directory.
 *
 * The Worker directory is a committed part of the user's repository, and it
 * is co-versioned with this CLI and the Composer packages: a repository whose
 * CLI moved on without its Worker directory would otherwise deploy a runtime
 * the PHP side no longer matches, and nothing at deploy time would say so.
 * The stamp records which release scaffolded the directory, so the CLI can
 * refuse that deploy loudly and early (ATOMS-E108) and name the upgrade.
 *
 * It also records the ownership split — which files the runtime rewrites on
 * upgrade and which the user owns — but that half is read by the upgrade
 * command in the runtime package, not here. This class reads the version
 * only.
 */
final class RuntimeStamp
{
    public const FILE = 'atoms-runtime.json';

    /**
     * The release that scaffolded $workerDir, or null when the directory has
     * no stamp at all (a directory scaffolded before stamps existed, or not
     * an Atoms Worker directory).
     *
     * @throws AtomsError E076 when a stamp exists but cannot be read — that
     *                    is a broken directory, not an old one, and the two
     *                    deserve different fix lines
     */
    public static function version(string $workerDir, string $environment): ?string
    {
        $path = self::path($workerDir);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw self::unusable($environment, "could not read {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::unusable($environment, "{$path} is not valid JSON: " . $e->getMessage());
        }

        $version = \is_array($decoded) ? ($decoded['version'] ?? null) : null;
        if (!\is_string($version) || $version === '') {
            throw self::unusable($environment, "{$path} carries no \"version\"");
        }

        return $version;
    }

    public static function path(string $workerDir): string
    {
        return rtrim($workerDir, '/') . '/' . self::FILE;
    }

    private static function unusable(string $environment, string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::WorkerDirectoryInvalid,
            ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                'environment' => $environment,
                'reason' => $reason,
            ]),
        );
    }
}
