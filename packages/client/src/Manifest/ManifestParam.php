<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * A single method/constructor parameter as described in the manifest. `$type`
 * uses PHP type syntax (`string`, `?int`, `App\Atoms\Shared\PlayerSnapshot`).
 */
final readonly class ManifestParam
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $optional = false,
        public mixed $default = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'mixed'),
            optional: (bool) ($data['optional'] ?? false),
            default: $data['default'] ?? null,
        );
    }
}
