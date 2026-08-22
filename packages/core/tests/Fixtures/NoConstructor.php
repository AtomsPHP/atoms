<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Serialization\Payload;

/**
 * A Payload with no constructor at all — hydrating it takes no arguments.
 */
final class NoConstructor implements Payload
{
}
