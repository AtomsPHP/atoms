<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\Symfony\Callback\DoctrineNonceStore;
use Doctrine\DBAL\Schema\AbstractAsset;

final class NonceSchemaFilter
{
    /** @param string|AbstractAsset<\Doctrine\DBAL\Schema\Name> $asset */
    public function __invoke(string|AbstractAsset $asset): bool
    {
        $name = is_string($asset) ? $asset : $asset->getName();
        $parts = explode('.', $name);

        return trim((string) end($parts), '`"[]') !== DoctrineNonceStore::TABLE;
    }
}
