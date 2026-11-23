<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomMethods;

/**
 * Abstract AtomMethods base class to verify MethodsPass skips abstract
 * subclasses registered in the container.
 */
abstract class AbstractBaseMethods extends AtomMethods
{
}
