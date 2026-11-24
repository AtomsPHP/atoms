<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures\GameRoom;

use Atoms\AtomMethods;
use Atoms\Client\AtomsConfig;

/**
 * Convention-resolved Methods class (Fixtures\GameRoom -> Fixtures\GameRoom\Methods).
 */
final class Methods extends AtomMethods
{
    public function __construct(private readonly ?AtomsConfig $config = null)
    {
    }

    public function endpoint(): ?string
    {
        return $this->config?->endpoint;
    }

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function boom(): string
    {
        throw new \RuntimeException('customer code failed');
    }
}
