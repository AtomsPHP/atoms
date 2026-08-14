<?php

declare(strict_types=1);

namespace Tests;

use Atoms\Laravel\AtomsServiceProvider;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AtomsServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        require __DIR__ . '/../routes/api.php';
    }
}
