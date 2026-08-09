<?php

declare(strict_types=1);

namespace Atoms\Laravel\Facades;

use Atoms\Laravel\AtomsManager;
use Atoms\Laravel\Testing\AtomsFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object get(string $class, string $id)
 * @method static mixed call(string $type, string $id, string $method, list<mixed> $args = [], ?string $atomClass = null, bool $retryTurnDeadline = false)
 * @method static bool destroy(string $type, string $id)
 * @method static AtomsFake fake(array<string, array<string, mixed>> $stubs = [])
 * @method static bool isFake()
 *
 * @see AtomsManager
 */
final class Atoms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AtomsManager::class;
    }
}
