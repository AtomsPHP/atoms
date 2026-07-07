<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Tests\Fixtures\CustomGameMethods;
use Atoms\Client\Tests\Fixtures\GameRoom;
use Atoms\Client\Tests\Fixtures\GameRoom\Methods as GameRoomMethods;
use PHPUnit\Framework\TestCase;

final class MethodsResolverTest extends TestCase
{
    public function testResolvesByConventionThroughTypeMap(): void
    {
        $resolver = (new MethodsResolver())->registerTypeMap(['GameRoom' => GameRoom::class]);

        self::assertSame(GameRoomMethods::class, $resolver->resolve('GameRoom'));
    }

    public function testResolvesByFqcnConvention(): void
    {
        $resolver = new MethodsResolver();

        self::assertSame(GameRoomMethods::class, $resolver->resolve(GameRoom::class));
    }

    public function testExplicitMapOverridesConvention(): void
    {
        $resolver = (new MethodsResolver())->map(['Widget' => GameRoomMethods::class]);

        self::assertSame(GameRoomMethods::class, $resolver->resolve('Widget'));
    }

    public function testMethodsForAttributeOverridesConvention(): void
    {
        $resolver = (new MethodsResolver())->registerMethodsClass(CustomGameMethods::class);

        self::assertSame(CustomGameMethods::class, $resolver->resolve('GameRoom'));
        self::assertSame(CustomGameMethods::class, $resolver->resolve(GameRoom::class));
    }

    public function testUnresolvableReturnsNull(): void
    {
        $resolver = new MethodsResolver();

        self::assertNull($resolver->resolve('Ghost'));
        self::assertSame('Ghost\\Methods', $resolver->expectedMethodsClass('Ghost'));
    }

    public function testExpectedMethodsClassUsesResolvedFqcn(): void
    {
        $resolver = (new MethodsResolver())->registerTypeMap(['GameRoom' => GameRoom::class]);

        self::assertSame(GameRoom::class . '\\Methods', $resolver->expectedMethodsClass('GameRoom'));
    }
}
