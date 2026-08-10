<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Cli\Process\ProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Find the Wrangler executable to run — and never fetch one.
 *
 * `npx wrangler` is deliberately not a fallback. It downloads whatever version
 * the registry currently serves, at deploy time, over the network: that defeats
 * the pin in the Worker project's `package-lock.json`, makes a deploy depend on
 * npm being reachable, and means two machines can deploy the same tree with two
 * different toolchains. A missing Wrangler is a setup error with a fix line
 * (ATOMS-E073), not something to paper over silently.
 *
 * Precedence, most explicit first:
 *
 *   1. `$ATOMS_WRANGLER_BIN` — an absolute path, for unusual layouts and CI.
 *   2. `{workerDir}/node_modules/.bin/wrangler` — the pinned install. Normal.
 *   3. `wrangler` on `PATH` — a global install; honoured, but unpinned.
 */
final class WranglerBinary
{
    public const ENV_OVERRIDE = 'ATOMS_WRANGLER_BIN';

    /**
     * @throws AtomsError E073 when no executable Wrangler can be found
     */
    public static function resolve(ProcessRunner $runner, CloudflareTarget $target): string
    {
        $override = getenv(self::ENV_OVERRIDE);
        if (\is_string($override) && $override !== '') {
            if (!is_file($override) || !is_executable($override)) {
                throw self::notFound(
                    $target,
                    self::ENV_OVERRIDE . " is set to {$override}, which is not an executable file",
                );
            }

            return $override;
        }

        $local = $target->workerDir . '/node_modules/.bin/wrangler';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        $onPath = $runner->which('wrangler');
        if ($onPath !== null) {
            return $onPath;
        }

        return throw self::notFound(
            $target,
            "no node_modules/.bin/wrangler in {$target->workerDir}, and none on PATH",
        );
    }

    private static function notFound(CloudflareTarget $target, string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::WranglerNotFound,
            ErrorCatalog::format(ErrorCode::WranglerNotFound, [
                'environment' => $target->environment,
                'reason' => $reason,
            ]),
        );
    }
}
