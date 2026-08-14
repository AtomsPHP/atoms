<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

/**
 * The `parameters.atomsLayering` configuration surface (see layering.neon):
 * a list of zones, each pairing a set of repo-relative paths with the
 * namespace prefixes code under those paths may not reference, and prefixes
 * exempted from that ban (docs/conventions.md §Layering). Also carries the
 * global list of framework helper function names ({@see LayeringRule})
 * treated as forbidden wherever a zone applies, mirroring
 * BoundaryFunctionCallRule's HELPERS list but expressed as configuration
 * rather than hard-coded, since layering.neon is opt-in per consuming
 * project.
 *
 * Path matching is delegated to {@see PathMatcher}, the same
 * normalized-segment matcher AtomsRulesConfig uses — agnostic to the project
 * root and to the OS directory separator.
 */
final class AtomsLayeringConfig
{
    /**
     * @param list<array{paths: list<string>, forbid: list<string>, allow: list<string>}> $zones
     * @param list<string> $forbiddenFunctions
     */
    public function __construct(
        private readonly array $zones = [],
        private readonly array $forbiddenFunctions = [],
    ) {
    }

    /**
     * @return list<array{paths: list<string>, forbid: list<string>, allow: list<string>}>
     */
    public function zones(): array
    {
        return $this->zones;
    }

    /** @return list<string> */
    public function forbiddenFunctions(): array
    {
        return $this->forbiddenFunctions;
    }

    /**
     * The zones (if any) whose `paths` cover $file. A file can legitimately
     * fall under more than one zone (e.g. a package path nested under a
     * broader one), so this returns every match rather than the first.
     *
     * @return list<array{paths: list<string>, forbid: list<string>, allow: list<string>}>
     */
    public function zonesContaining(string $file): array
    {
        $matches = [];
        foreach ($this->zones as $zone) {
            if (PathMatcher::isUnderAnyPath($file, $zone['paths'])) {
                $matches[] = $zone;
            }
        }

        return $matches;
    }
}
