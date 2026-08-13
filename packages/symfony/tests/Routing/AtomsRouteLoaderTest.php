<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Routing;

use Atoms\Symfony\Controller\CallbackController;
use Atoms\Symfony\Routing\AtomsRouteLoader;
use PHPUnit\Framework\TestCase;

final class AtomsRouteLoaderTest extends TestCase
{
    public function testSupportsOnlyTheAtomsResourceType(): void
    {
        $loader = new AtomsRouteLoader('/atoms/callback');

        self::assertTrue($loader->supports('.', 'atoms'));
        self::assertFalse($loader->supports('.', 'yaml'));
        self::assertFalse($loader->supports('.', 'annotation'));
        self::assertFalse($loader->supports('.', null));
    }

    public function testLoadReturnsAPostOnlyRouteAtTheConfiguredPathPointingAtTheController(): void
    {
        $loader = new AtomsRouteLoader('/custom/callback/path');

        $routes = $loader->load('.', 'atoms');

        self::assertCount(1, $routes);
        $route = $routes->get('atoms_callback');
        self::assertNotNull($route);
        self::assertSame('/custom/callback/path', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(CallbackController::class, $route->getDefault('_controller'));
    }

    public function testLoadingASecondTimeThrows(): void
    {
        $loader = new AtomsRouteLoader('/atoms/callback');
        $loader->load('.', 'atoms');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Do not add the "atoms" route loader twice — the atoms routes are already imported.');

        $loader->load('.', 'atoms');
    }
}
