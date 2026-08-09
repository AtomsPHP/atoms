<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * An AtomJob entry in the manifest: its class and constructor parameters.
 */
final readonly class ManifestJob
{
    /**
     * @param list<ManifestParam> $params
     */
    public function __construct(
        public string $class,
        public array $params,
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
            class: (string) ($data['class'] ?? ''),
            params: $params,
        );
    }
}
