<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

enum Status: string
{
    case Active = 'A';
    case Idle = 'I';
}
