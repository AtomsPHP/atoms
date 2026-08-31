<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

/**
 * The two-worlds model (docs/two-worlds.md), plus the classification
 * outcomes every boundary rule branches on.
 *
 * - AtomSide: extends Atoms\Atom. Ships to the platform; runs on the Atoms runtime.
 * - Shared: crosses the RPC boundary (Payload/#[SharedWithAtoms]/Shared\ path).
 *           Subject to the strictest rules — atoms/core + stdlib only.
 * - AppSide: extends Atoms\AtomMethods or Atoms\AtomJob. Stays in the monolith.
 * - Other:  anything else (not classified by this toolchain).
 */
enum Side: string
{
    case AtomSide = 'ATOM_SIDE';
    case Shared = 'SHARED';
    case AppSide = 'APP_SIDE';
    case Other = 'OTHER';
}
