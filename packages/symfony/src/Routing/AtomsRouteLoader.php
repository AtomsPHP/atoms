<?php

declare(strict_types=1);

namespace Atoms\Symfony\Routing;

use Atoms\Symfony\Controller\CallbackController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Auto-imports the callback route (POST `atoms.callback_path`, default
 * `/atoms/callback`) under a custom `atoms` resource type, so mounting it is
 * three lines in the app's own routing rather than a vendor-path import:
 *
 *     # config/routes/atoms.yaml
 *     atoms:
 *         resource: .
 *         type: atoms
 *
 * Registered as a `routing.loader`-tagged service (see AtomsBundle), so
 * Symfony's RoutingResolverPass wires it into `routing.resolver`
 * automatically — no path into vendor/ for the app to hard-code, and no
 * route to duplicate by hand when `atoms.callback_path` is reconfigured.
 */
final class AtomsRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly string $callbackPath,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'atoms';
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \RuntimeException('Do not add the "atoms" route loader twice — the atoms routes are already imported.');
        }

        $this->loaded = true;

        $routes = new RouteCollection();
        $routes->add('atoms_callback', new Route(
            path: $this->callbackPath,
            defaults: ['_controller' => CallbackController::class],
            methods: ['POST'],
        ));

        return $routes;
    }
}
