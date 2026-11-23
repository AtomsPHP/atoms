<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

/**
 * Stand-in Atom class paired with {@see ConventionRoom\Methods} purely by
 * namespace convention (no `#[MethodsFor]`), used to verify container
 * autowiring without listing the Methods class in `atoms.methods_classes`.
 */
final class ConventionRoom
{
}
