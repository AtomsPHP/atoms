<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * An Atom entry in the manifest: its wire type, backing class, callable methods,
 * whether it has WebSocket handlers, and its migration head version.
 */
final readonly class ManifestAtom
{
    /**
     * @param list<ManifestMethod> $methods
     */
    public function __construct(
        public string $type,
        public string $class,
        public array $methods,
        public bool $websocket = false,
        public int $migrationsHead = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $methods = [];
        foreach ((is_array($data['methods'] ?? null) ? $data['methods'] : []) as $method) {
            if (is_array($method)) {
                $methods[] = ManifestMethod::fromArray($method);
            }
        }

        $migrations = is_array($data['migrations'] ?? null) ? $data['migrations'] : [];

        return new self(
            type: (string) ($data['type'] ?? ''),
            class: (string) ($data['class'] ?? ''),
            methods: $methods,
            websocket: (bool) ($data['websocket'] ?? false),
            migrationsHead: (int) ($migrations['head'] ?? 0),
        );
    }
}
