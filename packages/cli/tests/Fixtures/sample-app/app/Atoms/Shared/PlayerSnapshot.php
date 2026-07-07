<?php

declare(strict_types=1);

namespace App\Atoms\Shared;

use Atoms\Serialization\Payload;

/**
 * A DTO that crosses the RPC boundary. Ships in the bundle AND is autoloaded in
 * the monolith, so it may only use atoms/core + stdlib — pure data.
 */
final class PlayerSnapshot implements Payload
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $elo,
    ) {
    }
}
