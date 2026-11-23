<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures;

use Atoms\Atom;

/**
 * Convention-resolved Atom fixture paired with {@see ConventionRoom\Methods}
 * (no `#[MethodsFor]` override), used to verify Symfony autowires
 * convention-named Methods classes without `atoms.methods_classes`.
 */
final class ConventionRoom extends Atom
{
}
