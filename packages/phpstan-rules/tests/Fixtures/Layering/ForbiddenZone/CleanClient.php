<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Layering\ForbiddenZone;

use Atoms\Serialization\Payload;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Framework-free by design — this client only ever sees Psr\* interfaces
 * and Atoms\* core types, unlike the adapters that live in atoms/laravel
 * (Laravel/Symfony are both integration targets for the framework
 * adapters, never for this package).
 */
final class CleanClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function fetch(Payload $payload): ClientInterface
    {
        return $this->httpClient;
    }
}
