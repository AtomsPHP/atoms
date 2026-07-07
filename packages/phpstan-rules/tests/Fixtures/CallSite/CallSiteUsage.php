<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\CallSite;

final class CallSiteUsage
{
    public function ok(): string
    {
        return FakeAtomsClient::get(GreeterAtom::class, 'room-1')->greet('Ada');
    }

    public function badArity(): string
    {
        return FakeAtomsClient::get(GreeterAtom::class, 'room-1')->greet();
    }

    public function unknownMethod(): void
    {
        FakeAtomsClient::get(GreeterAtom::class, 'room-1')->doesNotExist();
    }

    public function privateMethod(): void
    {
        FakeAtomsClient::get(GreeterAtom::class, 'room-1')->secret();
    }
}
