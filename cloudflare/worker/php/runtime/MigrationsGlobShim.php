<?php

/**
 * Host compatibility shim for `Atoms\Migrations\MigrationSet::fromDirectory()`.
 *
 * MEASURED (php-wasm 8.3.32 Asyncify build, wrangler dev, 2026-08-04):
 * this build's libc `glob()` has no brace support, so
 *
 *     defined('GLOB_BRACE')                        === false
 *     glob('/app/migrations/*.{sql,php}', 1024)    === false
 *     glob('/app/migrations/*.{sql,php}', 0)       === []
 *
 * while plain patterns (`/app/migrations/*.sql`) work correctly. The verbatim
 * `MigrationSet::fromDirectory()` uses exactly the brace form, so without this
 * file every Atom activates with *zero* discovered migrations — the one failure
 * mode this runtime must never have. (The bootstrap's count guard turns it into
 * a loud activation failure rather than a silent one, which is how it was
 * found.)
 *
 * atoms-core is verbatim and is never patched, so the fix lives here instead:
 * PHP resolves an unqualified function call or constant inside a namespace
 * against that namespace FIRST and only then against the global scope. Both
 * declarations below therefore shadow the missing libc feature for
 * `MigrationSet` alone — nothing else in the guest sees a different `glob()`.
 *
 * The shim is exact, not best-effort: it expands the brace alternatives itself
 * and delegates each expansion to the real `\glob()`, preserving the platform's
 * ordering contract by leaving `MigrationSet`'s own `sort()` to do the ordering.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Migrations;

/**
 * The value glibc uses. Only ever passed back to this file's own `glob()`,
 * which strips it before calling the real one.
 */
const GLOB_BRACE = 1024;

/**
 * Brace-aware `glob()` for the guest.
 *
 * @param string $pattern
 * @param int $flags
 * @return list<string>|false the real `\glob()`'s answer, false on failure
 */
function glob($pattern, $flags = 0)
{
    $expansions = expand_braces((string) $pattern);

    // Nothing to expand: this is the plain form the build already handles.
    if (count($expansions) === 1 && $expansions[0] === (string) $pattern) {
        return \glob($pattern, $flags & ~GLOB_BRACE);
    }

    $matches = [];

    foreach ($expansions as $expanded) {
        $found = \glob($expanded, $flags & ~GLOB_BRACE);

        if ($found === false) {
            // Preserve \glob()'s own failure signal rather than reporting an
            // empty directory, which would silently skip migrations.
            return false;
        }

        foreach ($found as $path) {
            $matches[$path] = true;
        }
    }

    return array_keys($matches);
}

/**
 * Expand every `{a,b,c}` alternative in a glob pattern, outermost first and
 * recursively, the way glibc's GLOB_BRACE does.
 *
 * @param string $pattern
 * @return list<string>
 */
function expand_braces($pattern)
{
    $open = -1;
    $depth = 0;

    for ($i = 0, $len = strlen($pattern); $i < $len; $i++) {
        $char = $pattern[$i];

        if ($char === '\\') {
            $i++; // escaped character, never a brace
            continue;
        }

        if ($char === '{') {
            if ($depth === 0) {
                $open = $i;
            }
            $depth++;
            continue;
        }

        if ($char === '}' && $depth > 0) {
            $depth--;

            if ($depth === 0) {
                $prefix = substr($pattern, 0, $open);
                $suffix = substr($pattern, $i + 1);
                $body = substr($pattern, $open + 1, $i - $open - 1);

                $out = [];
                foreach (split_alternatives($body) as $alternative) {
                    foreach (expand_braces($prefix . $alternative . $suffix) as $expanded) {
                        $out[] = $expanded;
                    }
                }

                return $out;
            }
        }
    }

    return [$pattern];
}

/**
 * Split a brace body on its top-level commas, ignoring commas nested in inner
 * braces or escaped.
 *
 * @param string $body
 * @return list<string>
 */
function split_alternatives($body)
{
    $parts = [];
    $current = '';
    $depth = 0;

    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
        $char = $body[$i];

        if ($char === '\\' && $i + 1 < $len) {
            $current .= $char . $body[$i + 1];
            $i++;
            continue;
        }

        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            $parts[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $parts[] = $current;

    return $parts;
}
