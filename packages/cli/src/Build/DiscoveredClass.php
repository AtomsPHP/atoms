<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node\Stmt\ClassLike;

/**
 * One class-like declaration found under the Atoms path, with its classification
 * and the resolved parent chain needed for transitive Atom detection.
 */
final class DiscoveredClass
{
    /**
     * @param list<string> $interfaces resolved FQCNs
     * @param list<string> $traits     resolved FQCNs
     * @param list<string> $attributes resolved attribute FQCNs
     */
    public function __construct(
        public readonly string $fqcn,
        public ClassKind $kind,
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly int $line,
        public readonly ?string $parent,
        public readonly array $interfaces,
        public readonly array $traits,
        public readonly array $attributes,
        public readonly ClassLike $node,
    ) {
    }

    public function basename(): string
    {
        $pos = strrpos($this->fqcn, '\\');

        return $pos === false ? $this->fqcn : substr($this->fqcn, $pos + 1);
    }
}
