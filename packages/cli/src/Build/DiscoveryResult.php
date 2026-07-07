<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * The output of §3.1 stage 1: every classified class under the Atoms path,
 * indexed by FQCN, plus any E001 warnings for unclassifiable files.
 */
final class DiscoveryResult
{
    /** @var array<string, DiscoveredClass> */
    private array $byFqcn = [];

    /**
     * @param list<DiscoveredClass> $classes
     * @param list<Violation>       $warnings
     */
    public function __construct(
        public readonly array $classes,
        public readonly array $warnings,
    ) {
        foreach ($classes as $class) {
            $this->byFqcn[$class->fqcn] = $class;
        }
    }

    public function get(string $fqcn): ?DiscoveredClass
    {
        return $this->byFqcn[ltrim($fqcn, '\\')] ?? null;
    }

    public function has(string $fqcn): bool
    {
        return isset($this->byFqcn[ltrim($fqcn, '\\')]);
    }

    /**
     * @return list<DiscoveredClass>
     */
    public function ofKind(ClassKind $kind): array
    {
        return array_values(array_filter(
            $this->classes,
            static fn (DiscoveredClass $c): bool => $c->kind === $kind,
        ));
    }
}
