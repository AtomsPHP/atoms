<?php

declare(strict_types=1);

namespace Atoms\Attributes;

use Attribute;

/**
 * Marks a DTO that lives outside the `Shared/` directory as boundary-shared, so
 * the toolchain applies Shared-zone purity rules to it wherever it sits.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SharedWithAtoms
{
}
