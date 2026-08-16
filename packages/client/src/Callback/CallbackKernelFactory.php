<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\Client\Crypto\KeyDerivation;
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
    /**
     * @param string      $sharedSecret         ATOMS_SHARED_SECRET: base64 of 32 random bytes, identical on the app and the Worker. The callback HMAC key is derived from it.
     * @param string|null $sharedSecretPrevious ATOMS_SHARED_SECRET_PREVIOUS: during a rotation overlap, a callback signed under this secret verifies too.
     *
     * @throws \InvalidArgumentException when either secret is absent or malformed (ATOMS-E105).
     */
    public static function create(
        string $sharedSecret,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ?string $sharedSecretPrevious = null,
        ?QueueBridge $queueBridge = null,
        ?MethodsResolver $resolver = null,
        ?NonceStore $nonceStore = null,
        int $timestampWindow = 300,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
        ?Serializer $serializer = null,
    ): CallbackKernel {
        return new CallbackKernel(
            new HmacVerifier(KeyDerivation::callbackKeys($sharedSecret, $sharedSecretPrevious)),
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
