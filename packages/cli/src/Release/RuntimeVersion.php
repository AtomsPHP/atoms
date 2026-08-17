<?php

declare(strict_types=1);

namespace Atoms\Cli\Release;

/**
 * Generated from release/manifest.json. Do not edit by hand.
 */
final class RuntimeVersion
{
    public const PACKAGE = '@atomsphp/runtime-cloudflare';

    public const VERSION = '0.3.0';

    public const CORE_VERSION = '0.3.0';

    public static function scaffoldCommand(string $target = '.atoms/worker'): string
    {
        return sprintf(
            'npm exec --yes --package=%s@%s -- atoms-runtime-cloudflare init %s',
            self::PACKAGE,
            self::VERSION,
            $target,
        );
    }
}