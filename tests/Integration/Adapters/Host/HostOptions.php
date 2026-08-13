<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Client\Callback\NonceStore;

/**
 * Everything {@see AdapterHost::boot()} needs, independent of which host is
 * booting. The test process plays the platform's signer role and holds the
 * Ed25519 keypair (see {@see \Atoms\Tests\Integration\Adapters\Support\CallbackSigner});
 * hosts are only ever given the public half here, exactly like a real
 * deployment configures `ATOMS_PLATFORM_PUBLIC_KEY`.
 */
final readonly class HostOptions
{
    /**
     * @param string             $endpoint       Worker base URL a host's AtomsClient calls out to (never dialed for real — see FakePsr18Client).
     * @param string|null        $apiKey         Bearer key for outbound calls; null means "no auth"; '' is a deliberate misconfiguration (see AtomsConfig).
     * @param string             $publicKey      Base64 Ed25519 public key the host's callback kernel verifies inbound signatures against.
     * @param string             $callbackPath   Path a routing-capable host mounts its callback kernel on.
     * @param list<class-string> $methodsClasses AtomMethods classes to register with the host's MethodsResolver via registerMethodsClass(), so #[MethodsFor] drives resolution.
     * @param bool               $queueAvailable When false, a queue-capable host wires a NullQueueBridge instead of a recording one, so job callbacks fail loudly (see S4).
     */
    public function __construct(
        public string $endpoint,
        public ?string $apiKey,
        public string $publicKey,
        public string $callbackPath,
        public array $methodsClasses,
        public ?NonceStore $nonceStore = null,
        public bool $queueAvailable = true,
    ) {
    }
}
