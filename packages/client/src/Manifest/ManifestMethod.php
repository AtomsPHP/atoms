<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * A callable method exposed on an Atom (or its Methods class) in the manifest.
 */
final readonly class ManifestMethod
{
    /**
     * @param list<ManifestParam> $params
     */
    public function __construct(
        public string $name,
        public array $params,
        public string $return,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $params = [];
        foreach ((is_array($data['params'] ?? null) ? $data['params'] : []) as $param) {
            if (is_array($param)) {
                $params[] = ManifestParam::fromArray($param);
            }
        }

        return new self(
            name: (string) ($data['name'] ?? ''),
            params: $params,
            return: (string) ($data['return'] ?? 'mixed'),
        );
    }
}
