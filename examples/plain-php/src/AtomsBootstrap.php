<?php

declare(strict_types=1);

namespace Atoms\Examples\PlainPhp;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernelFactory;
use Atoms\Client\Callback\MethodsResolver;
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
 */
final class AtomsBootstrap
{
    public static function create(
        string $endpoint,
        ?string $apiKey,
        string $platformPublicKey,
        string $callbackPath,
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        ServerRequestFactoryInterface $serverRequestFactory,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ?QueueBridge $queueBridge = null,
        ?MethodsResolver $resolver = null,
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null,
    ): PlainPhpApp {
        $config = AtomsConfig::fromArray([
            'endpoint' => $endpoint,
            'apiKey' => $apiKey,
            'platformPublicKey' => $platformPublicKey,
        ]);

        $client = new AtomsClient($config, $http, $requestFactory, $streamFactory, $logger);

        $kernel = CallbackKernelFactory::create(
            $platformPublicKey,
            $responseFactory,
            $streamFactory,
            queueBridge: $queueBridge ?? new NullQueueBridge('Pass a QueueBridge to AtomsBootstrap::create().'),
            resolver: $resolver,
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
