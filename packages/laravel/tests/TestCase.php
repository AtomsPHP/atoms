<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Laravel\AtomsServiceProvider;
use Atoms\Laravel\Facades\Atoms;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AtomsServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Atoms' => Atoms::class];
    }
}
