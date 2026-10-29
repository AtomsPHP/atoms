<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

final class UnconfiguredNonceStore implements NonceStore
{
    public function seen(string $nonce): bool
    {
        throw new NonceStoreConfigurationException('Configure a shared nonce store before accepting callbacks.');
    }
}
