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
 *
 * The match is an unanchored substring-by-segment test, not a prefix or
 * root-relative match: a configured path of `src` matches any file with a
 * `/src/` segment anywhere in it (`packages/core/src/Foo.php`, but equally
 * `vendor/some/nested/src/Bar.php`), so callers should pick paths distinctive
 * enough not to false-match an unrelated directory of the same name.
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
