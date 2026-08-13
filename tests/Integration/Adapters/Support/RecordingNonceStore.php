<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;

/**
 * Decorates an {@see InMemoryNonceStore}, recording every nonce it is asked
 * about (first-seen or replay) before delegating. Lets S7 assert that a
 * NonceStore supplied via HostOptions is the one a host actually wires into
 * its callback kernel, instead of a host silently falling back to its own
 * default.
 */
final class RecordingNonceStore implements NonceStore
{
    /** @var list<string> */
    public array $seen = [];

    private readonly InMemoryNonceStore $delegate;

    public function __construct(?InMemoryNonceStore $delegate = null)
    {
        $this->delegate = $delegate ?? new InMemoryNonceStore();
    }

    public function seen(string $nonce): bool
    {
        $this->seen[] = $nonce;

        return $this->delegate->seen($nonce);
    }
}
