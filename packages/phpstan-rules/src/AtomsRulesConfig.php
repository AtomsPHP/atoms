<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

/**
 * The `parameters.atoms` configuration surface (see rules.neon), wired as a
 * service so every rule/collaborator reads the same normalized values.
 *
 * Paths are project-relative (e.g. "app/Atoms", "app/Atoms/Shared"), matching
 * atoms.json's `paths.atoms` / `paths.shared` (docs/conventions.md). Matching
 * against absolute file paths reported by PHPStan is done on normalized,
 * slash-separated path segments, so it is agnostic to the project root and to
 * the OS directory separator.
 */
final class AtomsRulesConfig
{
    /**
     * @param list<string> $atomsPaths
     * @param list<string> $sharedPaths
     * @param list<string> $allowedNamespaces
     */
    public function __construct(
        private readonly array $atomsPaths = ['app/Atoms'],
        private readonly array $sharedPaths = ['app/Atoms/Shared'],
        private readonly array $allowedNamespaces = [],
    ) {
    }

    /** @return list<string> */
    public function atomsPaths(): array
    {
        return $this->atomsPaths;
    }

    /** @return list<string> */
    public function sharedPaths(): array
    {
        return $this->sharedPaths;
    }

    /** @return list<string> */
    public function allowedNamespaces(): array
    {
        return $this->allowedNamespaces;
    }

    public function isUnderAtomsPath(string $file): bool
    {
        return $this->isUnderAnyPath($file, $this->atomsPaths);
    }

    public function isUnderSharedPath(string $file): bool
    {
        return $this->isUnderAnyPath($file, $this->sharedPaths);
    }

    public function isAllowedNamespace(string $className): bool
    {
        $name = ltrim($className, '\\');
        foreach ($this->allowedNamespaces as $namespace) {
            $namespace = trim($namespace, '\\');
            if ($namespace === '') {
                continue;
            }
            if ($name === $namespace || str_starts_with($name, $namespace . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     */
    private function isUnderAnyPath(string $file, array $paths): bool
    {
        return PathMatcher::isUnderAnyPath($file, $paths);
    }
}
