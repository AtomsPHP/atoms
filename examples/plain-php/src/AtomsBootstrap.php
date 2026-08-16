<?php

declare(strict_types=1);

namespace Atoms\Examples\PlainPhp;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernelFactory;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NullQueueBridge;
use Atoms\Client\Callback\QueueBridge;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The plain-PHP/bare-host wiring point: one call that builds an
 * {@see AtomsClient} and a {@see \Atoms\Client\Callback\CallbackKernel} from
 * the PSR-17/18 implementations a Slim, Mezzio, or vanilla-PHP host already
 * has lying around, and hands both back inside a {@see PlainPhpApp}.
 *
 * Nothing here is autodetected. Every collaborator — the HTTP client, every
 * PSR-17 factory role, the queue bridge, the Methods resolver, the DI
 * container, the logger — is a parameter, not a discovery. That is the whole
 * point of this example: a Laravel or Symfony adapter gets to reach into the
 * container for these; a plain-PHP host has no container to reach into, so
 * this factory makes the wiring explicit instead of magical. Compare
 * {@see CallbackKernelFactory}, which this class is a thin, opinionated shim
 * over.
 *
 * `$sharedSecret` is the one value both collaborators are built from: the
 * outbound `AtomsClient` derives its bearer from it, and the inbound
 * `CallbackKernel` derives its HMAC verification key from it — the same
 * secret an operator configures identically on the Worker as
 * `ATOMS_SHARED_SECRET`. `$sharedSecretPrevious` is the matching optional
 * overlap secret: set it during a rotation window and inbound callbacks
 * signed under either secret verify (see `docs/shared-secret.md`).
 *
 * `$nonceStore` and `$timestampWindow` are the same replay-store and
 * timestamp-window override points {@see CallbackKernelFactory::create()}
 * exposes, forwarded straight through: leave `$nonceStore` null for the
 * default in-process {@see \Atoms\Client\Callback\InMemoryNonceStore}, or
 * supply your own {@see NonceStore} (e.g. a shared cache) so replay checks
 * survive across requests/processes.
 */
final class AtomsBootstrap
{
    public static function create(
        string $endpoint,
        string $sharedSecret,
        string $callbackPath,
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        ServerRequestFactoryInterface $serverRequestFactory,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ?QueueBridge $queueBridge = null,
        ?MethodsResolver $resolver = null,
        ?NonceStore $nonceStore = null,
        ?string $sharedSecretPrevious = null,
        int $timestampWindow = 300,
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null,
    ): PlainPhpApp {
        $config = AtomsConfig::fromArray([
            'endpoint' => $endpoint,
            'sharedSecret' => $sharedSecret,
            'sharedSecretPrevious' => $sharedSecretPrevious,
        ]);

        $client = new AtomsClient($config, $http, $requestFactory, $streamFactory, $logger);

        $kernel = CallbackKernelFactory::create(
            $sharedSecret,
            $responseFactory,
            $streamFactory,
            $sharedSecretPrevious,
            queueBridge: $queueBridge ?? new NullQueueBridge('Pass a QueueBridge to AtomsBootstrap::create().'),
            resolver: $resolver,
            nonceStore: $nonceStore,
            timestampWindow: $timestampWindow,
            container: $container,
            logger: $logger,
        );

        return new PlainPhpApp(
            $client,
            $kernel,
            $callbackPath,
            $serverRequestFactory,
            $responseFactory,
            $streamFactory,
        );
    }
}
