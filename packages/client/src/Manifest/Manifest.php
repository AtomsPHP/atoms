<?php

declare(strict_types=1);

namespace Atoms\Client\Manifest;

/**
 * Parsed view of a `manifest.json` (schema 1) plus the canonical manifest hash
 * the client sends as `X-Atoms-Manifest-Hash`.
 *
 * The hash is the sha256 of the canonical JSON encoding of the manifest with the
 * `content_hash` key removed: keys sorted recursively, no whitespace. The CLI
 * computes the identical value — the two implementations must agree byte for
 * byte.
 */
final readonly class Manifest
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $project
     * @param list<ManifestAtom>   $atoms
     * @param list<ManifestMethod> $methods
     * @param list<ManifestJob>    $jobs
     * @param list<ManifestShared> $shared
     * @param array<string, mixed> $toolchain
     */
    public function __construct(
        public int $schema,
        public array $project,
        public array $atoms,
        public array $methods,
        public array $jobs,
        public array $shared,
        public array $toolchain,
        public ?string $contentHash,
        private array $raw,
    ) {
    }

    /**
     * The canonical manifest hash (`X-Atoms-Manifest-Hash`).
     */
    public function hash(): string
    {
        $data = $this->raw;
        unset($data['content_hash']);

        $canonical = self::canonicalize($data);
        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Recursively sort associative-array keys; preserve list order.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }

        return $out;
    }
}
