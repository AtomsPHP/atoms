<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

/**
 * Stand-in "Atom" class: MethodsResolver only needs the FQCN to exist and to
 * resolve `...\GameRoom\Methods` by convention; it never has to actually
 * extend Atoms\Atom.
 */
final class GameRoom
{
}
