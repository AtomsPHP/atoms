<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * A Shared DTO entry in the manifest: its class and its (name, type) properties.
 */
final readonly class ManifestShared
{
    /**
     * @param list<array{name: string, type: string}> $properties
     */
    public function __construct(
        public string $class,
        public array $properties,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $properties = [];
        foreach ((is_array($data['properties'] ?? null) ? $data['properties'] : []) as $property) {
            if (is_array($property)) {
                $properties[] = [
                    'name' => (string) ($property['name'] ?? ''),
                    'type' => (string) ($property['type'] ?? 'mixed'),
                ];
            }
        }

        return new self(
            class: (string) ($data['class'] ?? ''),
            properties: $properties,
        );
    }
}
