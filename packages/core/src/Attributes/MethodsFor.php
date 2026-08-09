<?php

declare(strict_types=1);

namespace Atoms\Attributes;

use Attribute;

/**
 * Overrides Methods-class resolution: `#[MethodsFor(GameRoom::class)]` on a
 * class declares it as the Methods class for the given Atom type, replacing the
 * namespace-convention default.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MethodsFor
{
    public function __construct(
        public readonly string $atomClass,
    ) {
    }
}
