<?php

declare(strict_types=1);

namespace Atoms\Cli\Process;

/**
 * Render a value as one argument in a command printed for a human to paste.
 *
 * The scaffold and upgrade commands the CLI prints carry a directory that
 * may have come from `--worker-dir`, whose purpose is an unusual location —
 * so it may hold a space or a shell metacharacter. Quoted when it needs to
 * be, and left bare when it does not, so the common case reads as a path
 * rather than a quoted string.
 */
final class ShellArg
{
    public static function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/-]+$/', $value) === 1) {
            return $value;
        }

        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
