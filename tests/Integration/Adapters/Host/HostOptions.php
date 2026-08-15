<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Client\Callback\NonceStore;

/**
 * Everything {@see AdapterHost::boot()} needs, independent of which host is
 * booting. The test process plays the operator's role, holding the shared
 * secret both the outbound client and the inbound callback verifier derive
 * their keys from (see {@see \Atoms\Tests\Integration\Adapters\Support\CallbackSigner});
 * hosts are configured with it exactly like a real deployment configures
 * `ATOMS_SHARED_SECRET`.
 */
final readonly class HostOptions
{
    /**
     * @param string             $endpoint             Worker base URL a host's AtomsClient calls out to (never dialed for real — see FakePsr18Client).
     * @param string             $sharedSecret         Base64, 32 raw bytes. The bearer AtomsClient sends and the key CallbackKernel verifies inbound signatures against are both derived from this via HKDF-SHA256.
     * @param string             $callbackPath         Path a routing-capable host mounts its callback kernel on.
     * @param list<class-string> $methodsClasses       AtomMethods classes to register with the host's MethodsResolver via registerMethodsClass(), so #[MethodsFor] drives resolution.
     * @param string|null        $sharedSecretPrevious Base64, 32 raw bytes, or null. When set, a host accepts a bearer or callback signed under either secret (try-both, acceptance-side only) — see AdapterConformanceTestCase's rotation case.
     * @param bool               $queueAvailable       When false, a queue-capable host wires a NullQueueBridge instead of a recording one, so job callbacks fail loudly (see S4).
     * @param array<class-string, object> $containerBindings Pre-built instances a container-capable host must make resolvable through ITS OWN PSR-11 container path — e.g. a Methods class whose constructor `new $class()` cannot satisfy (see S6). SymfonyHost ignores this: its methods_classes/container wiring is fixed compiled fixture config, not driven from HostOptions (see SymfonyHost's own docblock for why).
     */
    public function __construct(
        public string $endpoint,
        public string $sharedSecret,
        public string $callbackPath,
        public array $methodsClasses,
        public ?string $sharedSecretPrevious = null,
        public ?NonceStore $nonceStore = null,
        public bool $queueAvailable = true,
        public array $containerBindings = [],
    ) {
    }
}
