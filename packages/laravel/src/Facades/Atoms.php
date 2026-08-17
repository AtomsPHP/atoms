<?php

declare(strict_types=1);

namespace Atoms\Laravel\Facades;

use Atoms\Client\CallOptions;
use Atoms\Client\Tickets\Ticket;
use Atoms\Laravel\AtomsManager;
use Atoms\Laravel\Testing\AtomsFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object get(string $class, string $id, ?CallOptions $options = null)
 * @method static Ticket ticket(string $class, string $id, array<string, string> $claims = [], ?int $ttlMs = null)
 * @method static string wsUrl(string $class, string $id, array<string, string|int|float|bool|list<string>> $query = [])
 * @method static mixed call(string $type, string $id, string $method, list<mixed> $args = [], ?string $atomClass = null, bool $retryTurnDeadline = false, ?CallOptions $options = null)
 * @method static bool destroy(string $type, string $id)
 * @method static AtomsFake fake(array<string, array<string, mixed>> $stubs = [])
 * @method static bool isFake()
 *
 * A note on static analysis: `AtomsClient::get()` carries `@template T`, but a
 * facade's `@method` block cannot, so `get()` stays `object` here. Inject
 * {@see AtomsManager} or {@see \Atoms\Client\AtomsClient} where you want the
 * Atom's methods checked.
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
