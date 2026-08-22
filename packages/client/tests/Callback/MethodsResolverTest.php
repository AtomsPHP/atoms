<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Manifest\Manifest;
use Atoms\Client\Manifest\ManifestLoader;
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

    public function testRegisterManifestResolvesTheWireTypesTheManifestDeclares(): void
    {
        $resolver = (new MethodsResolver())->registerManifest($this->manifestFor([
            ['type' => 'GameRoom', 'class' => GameRoom::class],
        ]));

        self::assertSame(GameRoomMethods::class, $resolver->resolve('GameRoom'));
    }

    /**
     * An Atom whose manifest entry carries no `type` is still resolvable: the
     * map is keyed by its class, and registerTypeMap() adds the basename.
     */
    public function testRegisterManifestFallsBackToTheClassWhenTypeIsEmpty(): void
    {
        $resolver = (new MethodsResolver())->registerManifest($this->manifestFor([
            ['class' => GameRoom::class],
        ]));

        self::assertSame(GameRoomMethods::class, $resolver->resolve(GameRoom::class));
        self::assertSame(GameRoomMethods::class, $resolver->resolve('GameRoom'));
    }

    public function testRegisterManifestIgnoresAtomsWithNoClass(): void
    {
        $resolver = (new MethodsResolver())->registerManifest($this->manifestFor([
            ['type' => 'Ghost'],
            ['type' => 'GameRoom', 'class' => GameRoom::class],
        ]));

        self::assertNull($resolver->resolve('Ghost'));
        self::assertSame(GameRoomMethods::class, $resolver->resolve('GameRoom'));
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

    /**
     * A manifest carrying just the `atoms` entries under test, built through
     * the real loader so the resolver sees the shape a build produces.
     *
     * @param list<array<string, mixed>> $atoms
     */
    private function manifestFor(array $atoms): Manifest
    {
        return (new ManifestLoader())->fromArray([
            'schema' => 1,
            'project' => ['name' => 'demo'],
            'atoms' => $atoms,
            'methods' => [],
            'jobs' => [],
            'shared' => [],
            'toolchain' => [],
        ]);
    }
}
