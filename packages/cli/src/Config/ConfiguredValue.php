<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

/**
 * One resolved setting, and the source that actually supplied it.
 *
 * Kept through resolution rather than reconstructed afterwards: only the
 * resolver knows which layer won, and `atoms deploy` prints that attribution
 * before it does anything, so a surprising deployment can be explained without
 * guessing. {@see \Atoms\Cli\Cloudflare\CloudflareTarget::$sources}
 *
 * $source is a human-readable label, never parsed: `atoms.json`,
 * `--callback-url`, `caller environment: ATOMS_CALLBACK_URL`,
 * `.env.atoms.production: ATOMS_CALLBACK_URL`.
 */
final class ConfiguredValue
{
    public function __construct(
        public readonly string $value,
        public readonly string $source,
    ) {
    }
}
