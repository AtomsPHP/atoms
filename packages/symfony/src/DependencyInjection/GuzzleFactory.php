<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

/**
 * Lazy factories for the bundle's Guzzle-backed defaults. The bundle itself
 * declares no hard dependency on a concrete PSR-18/17 implementation (that
 * choice stays the app's, mirroring atoms/client's framework-free design —
 * AtomsClient and CallbackKernel only ever take interfaces); these factories
 * are only invoked when nothing else was configured, and fail with a clear,
 * actionable message rather than a bare "class not found" fatal.
 */
final class GuzzleFactory
{
    /**
     * Default PSR-18 client used when no `atoms.http_client` service id is
     * configured and the app defines no Psr\Http\Client\ClientInterface itself.
     */
    public static function httpClient(): ClientInterface
    {
        if (!class_exists(GuzzleClient::class)) {
            throw new \RuntimeException(
                'Atoms has no PSR-18 HTTP client configured (atoms.http_client) and '
                . 'guzzlehttp/guzzle is not installed. Run `composer require guzzlehttp/guzzle`, '
                . 'set atoms.http_client to a service id, or register your own '
                . 'Psr\\Http\\Client\\ClientInterface service.',
            );
        }

        return new GuzzleClient();
    }

    /**
     * Default PSR-17 factory (request/stream/response/server-request) backing
     * AtomsClient, CallbackKernel and CallbackController.
     */
    public static function psr17Factory(): HttpFactory
    {
        if (!class_exists(HttpFactory::class)) {
            throw new \RuntimeException(
                'Atoms needs a PSR-17 factory and guzzlehttp/psr7 is not installed. '
                . 'Run `composer require guzzlehttp/psr7`.',
            );
        }

        return new HttpFactory();
    }
}
