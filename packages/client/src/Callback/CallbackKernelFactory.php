<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\Serialization\Serializer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The plain-PHP/bare-host entry point for wiring a {@see CallbackKernel}: the
 * safe defaults — a {@see NullQueueBridge}, a fresh {@see MethodsResolver}, an
 * in-process {@see InMemoryNonceStore} — collected in one tested place, so a
 * host that is neither Laravel nor Symfony does not have to rediscover them.
 *
 * Nothing is autodetected: the PSR-17 factories stay required positional
 * arguments, so atoms/client's composer `require` stays psr-only.
 */
final class CallbackKernelFactory
{
    public static function create(
        string $platformPublicKey,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ?QueueBridge $queueBridge = null,
        ?MethodsResolver $resolver = null,
        ?NonceStore $nonceStore = null,
        int $timestampWindow = 300,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
        ?Serializer $serializer = null,
    ): CallbackKernel {
        return new CallbackKernel(
            new Ed25519Verifier($platformPublicKey),
            $nonceStore ?? new InMemoryNonceStore(),
            $resolver ?? new MethodsResolver(),
            $queueBridge ?? new NullQueueBridge(),
            $responseFactory,
            $streamFactory,
            $timestampWindow,
            $container,
            $serializer,
            $logger,
        );
    }
}
