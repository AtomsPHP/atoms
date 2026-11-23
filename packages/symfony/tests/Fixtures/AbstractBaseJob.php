<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * Abstract AtomJob subclass to verify AtomJobPass skips abstract definitions.
 */
abstract class AbstractBaseJob extends AtomJob
{
}
