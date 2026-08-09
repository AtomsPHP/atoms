<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Reference;

use Atoms\Serialization\Payload;

final class BadSharedDto implements Payload
{
    public function __construct(public readonly string $id)
    {
    }

    public static function fromLegacyUser(): self
    {
        $user = new \App\Models\User();

        return new self($user->id);
    }
}
