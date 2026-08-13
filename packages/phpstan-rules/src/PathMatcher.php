<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

/**
 * Normalized-segment path matching shared by every rule/config class that
 * needs to ask "is this file under one of these repo-relative paths?" —
 * originally lived inline in {@see AtomsRulesConfig::isUnderAnyPath()};
 * extracted here so {@see AtomsLayeringConfig} (and anything else that grows
 * its own path-scoped configuration later) can reuse it instead of
 * reimplementing it.
 *
 * Matching is done on normalized, slash-separated path segments, so it is
 * agnostic to the project root, to the OS directory separator, and to
 * whether $file is absolute or already relative.
 */
final class PathMatcher
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $paths
     */
    public static function isUnderAnyPath(string $file, array $paths): bool
    {
        $normalizedFile = '/' . ltrim(str_replace('\\', '/', $file), '/');

        foreach ($paths as $path) {
            $normalizedPath = trim(str_replace('\\', '/', $path), '/');
            if ($normalizedPath === '') {
                continue;
            }

            if (
                str_contains($normalizedFile, '/' . $normalizedPath . '/')
                || str_ends_with($normalizedFile, '/' . $normalizedPath)
            ) {
                return true;
            }
        }

        return false;
    }
}
