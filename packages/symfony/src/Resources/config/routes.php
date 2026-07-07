<?php

declare(strict_types=1);

use Atoms\Symfony\Controller\CallbackController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * Default callback route (POST /atoms/callback), matching the bundle
 * config's `callback_path` default. Import it from the app's own routing,
 * e.g. in config/routes/atoms.php:
 *
 *     $routes->import(
 *         '%kernel.project_dir%/vendor/atoms/symfony/src/Resources/config/routes.php',
 *     );
 *
 * If `atoms.callback_path` is configured to something other than the
 * default, don't import this file — define your own route pointing at
 * Atoms\Symfony\Controller\CallbackController instead.
 */
$routes = new RouteCollection();
$routes->add('atoms_callback', new Route(
    path: '/atoms/callback',
    defaults: ['_controller' => CallbackController::class],
    methods: ['POST'],
));

return $routes;
