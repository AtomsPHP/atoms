<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Signature;

use Atoms\AtomJob;

final class SignatureJob extends AtomJob
{
    public function __construct(
        public readonly string $id,
        public readonly \Illuminate\Database\Eloquent\Model $model,
    ) {
    }

    public function handle(\DateTime $ignoredOnNonConstructor): void
    {
    }
}
