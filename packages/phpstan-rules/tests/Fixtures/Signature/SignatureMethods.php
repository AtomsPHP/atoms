<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Signature;

use Atoms\AtomMethods;

final class SignatureMethods extends AtomMethods
{
    public function __construct(private readonly string $ignored)
    {
    }

    public function goodCallback(Note $note): Note
    {
        return $note;
    }

    public function badCallback(\DateTime $when): void
    {
    }
}
