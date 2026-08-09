<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * Reads and parses a `manifest.json` (schema 1) into a {@see Manifest}.
 */
final class ManifestLoader
{
    public function load(string $path): Manifest
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read manifest at {$path}.");
        }

        return $this->parse($raw);
    }

    public function parse(string $json): Manifest
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Manifest JSON must decode to an object.');
        }

        return $this->fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Manifest
    {
        return new Manifest(
            schema: (int) ($data['schema'] ?? 0),
            project: is_array($data['project'] ?? null) ? $data['project'] : [],
            atoms: $this->mapList($data['atoms'] ?? null, ManifestAtom::fromArray(...)),
            methods: $this->mapMethodGroups($data['methods'] ?? null),
            jobs: $this->mapList($data['jobs'] ?? null, ManifestJob::fromArray(...)),
            shared: $this->mapList($data['shared'] ?? null, ManifestShared::fromArray(...)),
            toolchain: is_array($data['toolchain'] ?? null) ? $data['toolchain'] : [],
            contentHash: isset($data['content_hash']) ? (string) $data['content_hash'] : null,
            raw: $data,
        );
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $factory
     * @return list<T>
     */
    private function mapList(mixed $items, callable $factory): array
    {
        $out = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $out[] = $factory($item);
            }
        }

        return $out;
    }

    /**
     * The `methods` section groups methods under an `atom_type`; flatten each
     * group's methods into {@see ManifestMethod} objects.
     *
     * @return list<ManifestMethod>
     */
    private function mapMethodGroups(mixed $groups): array
    {
        $out = [];
        foreach (is_array($groups) ? $groups : [] as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach (is_array($group['methods'] ?? null) ? $group['methods'] : [] as $method) {
                if (is_array($method)) {
                    $out[] = ManifestMethod::fromArray($method);
                }
            }
        }

        return $out;
    }
}
