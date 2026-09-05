<?php

declare(strict_types=1);

namespace Atoms\Cli\Release;

use Atoms\Cli\Process\ShellArg;

/**
 * Generated from release/manifest.json. Do not edit by hand.
 */
final class RuntimeVersion
{
    public const PACKAGE = '@atomsphp/runtime-cloudflare';

    public const VERSION = '0.6.0';

    public const CORE_VERSION = '0.6.0';

    /** The committed Worker directory, relative to atoms.json. */
    public const WORKER_DIR = 'atoms-worker';

    public static function scaffoldCommand(string $target = self::WORKER_DIR): string
    {
        return sprintf(
            'npm exec --yes --package=%s@%s -- atoms-runtime-cloudflare init %s',
            self::PACKAGE,
            self::VERSION,
            ShellArg::quote($target),
        );
    }

    public static function upgradeCommand(string $target = self::WORKER_DIR): string
    {
        return sprintf(
            'npm exec --yes --package=%s@%s -- atoms-runtime-cloudflare upgrade %s',
            self::PACKAGE,
            self::VERSION,
            ShellArg::quote($target),
        );
    }
}