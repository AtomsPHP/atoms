<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\CallSite;

final class FakeAtomsClient
{
    public static function get(string $class, string $id): object
    {
        throw new \RuntimeException('stub only, never called');
    }
}
