<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clean;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
