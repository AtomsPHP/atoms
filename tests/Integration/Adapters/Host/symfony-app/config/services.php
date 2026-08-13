<?php

declare(strict_types=1);

use Atoms\Client\Callback\NonceStore;
use Atoms\Tests\Integration\Adapters\Support\RecordingLogger;
use Atoms\Tests\Integration\Adapters\Support\TestDoubles;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The app-side wiring a real host would write: test doubles registered under
 * the SAME service ids AtomsBundle itself resolves against
 * (Psr\Http\Client\ClientInterface, 'logger', Atoms\Client\Callback\NonceStore,
 * Symfony\Component\Messenger\MessageBusInterface), read from
 * {@see TestDoubles}'s static registry so a fresh SymfonyHost::boot() can
 * swap them per case. Registering these here — not by mutating the compiled
 * container after the fact — is what makes HttpClientPass/MessengerBridgePass
 * perform their REAL upgrade: both check `$container->has(...)` at compile
 * time, and app-registered services always win over a bundle's own defaults
 * (Symfony re-applies the app's pre-extension definitions after every
 * bundle's extension has loaded — see MergeExtensionConfigurationPass).
 *
 * The MessageBusInterface service is registered ONLY when
 * TestDoubles::$queueAvailable is true, so that with it false the bundle's
 * NullQueueBridge default stays live and S4's 500 ATOMS-E103 comes through
 * the real path — MessengerBridgePass only upgrades QueueBridge when
 * `$container->has(MessageBusInterface::class)`.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ClientInterface::class)
        ->factory([TestDoubles::class, 'client'])
        ->public();

    $services->set('logger', RecordingLogger::class)
        ->factory([TestDoubles::class, 'logger'])
        ->public();

    // Backs framework.yaml's 'atoms_silent' exceptions.log_channel — see the
    // comment there for why Symfony's own router/kernel exception logging
    // (not CallbackKernel's) is routed away from the shared RecordingLogger.
    $services->set('monolog.logger.atoms_silent', NullLogger::class);

    $services->set(NonceStore::class)
        ->factory([TestDoubles::class, 'nonceStore'])
        ->public();

    if (TestDoubles::$queueAvailable) {
        $services->set(MessageBusInterface::class)
            ->factory([TestDoubles::class, 'bus'])
            ->public();
    }
};
