<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

/**
 * One entry of `parameters.atomsLayering.zones` (see layering.neon): a set of
 * repo-relative paths, the namespace prefixes code under those paths may not
 * reference, and the prefixes exempted from that ban
 * (docs/conventions.md §Layering).
 *
 * Exists so those prefix lists are normalized exactly once, when
 * {@see AtomsLayeringConfig} is constructed, instead of being rebuilt inside
 * {@see Rules\LayeringRule}'s nested loops over zones × nodes × prefixes. The
 * normalization used to live in three separate private methods on the rule,
 * each of which had grown its own spelling of "trim the stray backslashes off
 * a configured prefix" — trim-only in one, trim-then-append-a-separator in
 * another, trim-then-preg_quote in the third. The normalized lists are
 * private here, and the questions callers actually ask are exposed as
 * methods, precisely so a fourth spelling cannot appear.
 */
final class Zone
{
    /**
     * Every forbidden prefix, trimmed of stray backslashes and given exactly
     * one trailing backslash, so "does $symbol start with $prefix" already
     * encodes "...followed by a backslash" — a bare namespace prefix like
     * "Laravel" must never match prose that merely contains "Laravel" as a
     * substring (e.g. "Laravel/Symfony" in a docblock, where the separator is
     * a forward slash, not a backslash).
     *
     * @var list<string>
     */
    private readonly array $forbidPrefixes;

    /**
     * The `allow` prefixes, normalized the same way.
     *
     * @var list<string>
     */
    private readonly array $allowPrefixes;

    private readonly bool $forbidsFrameworkGlobals;

    /**
     * The compiled docblock-scanning pattern, or null when this zone forbids
     * nothing at all (in which case there is nothing to scan for).
     */
    private readonly ?string $mentionPattern;

    /**
     * @param list<string> $paths
     * @param list<string> $forbid
     * @param list<string> $allow
     */
    public function __construct(
        private readonly array $paths,
        array $forbid,
        array $allow,
    ) {
        $forbidNames = self::normalizeNames($forbid);

        $this->forbidPrefixes = self::withTrailingSeparator($forbidNames);
        $this->allowPrefixes = self::withTrailingSeparator(self::normalizeNames($allow));
        $this->forbidsFrameworkGlobals = in_array('Illuminate', $forbidNames, true)
            || in_array('Laravel', $forbidNames, true);
        $this->mentionPattern = self::compileMentionPattern($forbidNames);
    }

    /**
     * Builds a zone from the raw neon array shape, which is what PHPStan
     * hands {@see AtomsLayeringConfig} for `parameters.atomsLayering.zones`.
     *
     * @param array{paths: list<string>, forbid: list<string>, allow: list<string>} $zone
     */
    public static function fromArray(array $zone): self
    {
        return new self($zone['paths'], $zone['forbid'], $zone['allow']);
    }

    public function covers(string $file): bool
    {
        return PathMatcher::isUnderAnyPath($file, $this->paths);
    }

    /**
     * Whether $symbol names something this zone may not reference: matched by
     * one of the `forbid` prefixes and not rescued by an `allow` one.
     */
    public function isForbidden(string $symbol): bool
    {
        $symbol = ltrim($symbol, '\\');

        foreach ($this->allowPrefixes as $prefix) {
            if (str_starts_with($symbol, $prefix)) {
                return false;
            }
        }

        foreach ($this->forbidPrefixes as $prefix) {
            // Require something after the separator: a symbol that IS just
            // "Prefix\" with nothing following isn't a reference to the
            // namespace, it's namespace-prefix *data* (e.g. a classifier's
            // own list of framework prefixes to check other code against —
            // packages/cli/src/Build/SymbolClassifier.php has exactly this).
            if (str_starts_with($symbol, $prefix) && strlen($symbol) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the global framework helper functions (config(), app(), ...)
     * apply to this zone. They are Illuminate's own — the function-call sugar
     * for the same framework its FQCNs belong to — so a zone that doesn't
     * forbid that framework by namespace (e.g. atoms/laravel itself, which
     * legitimately calls response()/app()) has no reason to forbid its
     * global-helper spelling either.
     */
    public function forbidsFrameworkGlobals(): bool
    {
        return $this->forbidsFrameworkGlobals;
    }

    /**
     * Scans free-form doc-comment text for occurrences of a forbidden
     * namespace prefix immediately followed by a backslash (never a forward
     * slash — see {@see $forbidPrefixes}), returning the full namespaced
     * symbol found at each occurrence so it can still be exempted by an
     * `allow` prefix like any other reference.
     *
     * @return list<string>
     */
    public function symbolsMentionedIn(string $text): array
    {
        if ($this->mentionPattern === null) {
            return [];
        }

        if (preg_match_all($this->mentionPattern, $text, $matches) === false || $matches[1] === []) {
            return [];
        }

        /** @var list<string> $symbols */
        $symbols = array_values(array_unique($matches[1]));

        return $symbols;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function normalizeNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $name = trim($name, '\\');
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function withTrailingSeparator(array $names): array
    {
        return array_map(static fn (string $name): string => $name . '\\', $names);
    }

    /**
     * @param list<string> $forbidNames
     */
    private static function compileMentionPattern(array $forbidNames): ?string
    {
        if ($forbidNames === []) {
            return null;
        }

        $alternation = array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            $forbidNames,
        );

        return '/\\\\?((?:' . implode('|', $alternation) . ')(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)+)/';
    }
}
