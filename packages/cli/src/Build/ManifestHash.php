<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * Canonical manifest hashing (conventions.md "Manifest schema"): sha256 of the
 * recursively key-sorted, whitespace-free JSON encoding of the manifest with the
 * `content_hash` key removed. atoms/client implements the identical function so
 * a manifest hash means the same thing on both sides of the boundary.
 */
final class ManifestHash
{
    /**
     * @param array<string, mixed> $manifest
     */
    public static function of(array $manifest): string
    {
        unset($manifest['content_hash']);

        return hash('sha256', self::encode(self::canonicalize($manifest)));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function canonicalJson(array $manifest): string
    {
        unset($manifest['content_hash']);

        return self::encode(self::canonicalize($manifest));
    }

    private static function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json;
    }

    /**
     * Recursively sort associative-array keys; preserve list order.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }

        return $out;
    }
}
