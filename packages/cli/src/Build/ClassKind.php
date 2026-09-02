<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * How a discovered class-like under the Atoms path is classified (§3.1 stage 1).
 */
enum ClassKind: string
{
    case Atom = 'atom';
    case Methods = 'methods';
    case Job = 'job';
    case Shared = 'shared';
    case Support = 'support';
    case Unknown = 'unknown';

    /** Does this kind ship to the platform (Atom-side)? */
    public function isBundled(): bool
    {
        return $this === self::Atom || $this === self::Shared || $this === self::Support;
    }
}
