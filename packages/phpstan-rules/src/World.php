<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

/**
 * The two-worlds model (docs/two-worlds.md), plus the classification
 * outcomes every boundary rule branches on.
 *
 * - WorldA: extends Atoms\Atom. Ships to the platform; runs on the Atoms runtime.
 * - Shared: crosses the RPC boundary (Payload/#[SharedWithAtoms]/Shared\ path).
 *           Subject to the strictest rules — atoms/core + stdlib only.
 * - WorldB: extends Atoms\AtomMethods or Atoms\AtomJob. Stays in the monolith.
 * - Other:  anything else (not classified by this toolchain).
 */
enum World: string
{
    case WorldA = 'WORLD_A';
    case Shared = 'SHARED';
    case WorldB = 'WORLD_B';
    case Other = 'OTHER';
}
