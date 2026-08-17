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
     * What this class is, once {@see classifyAs()} has said so. Not a
     * constructor argument: classification needs the complete index of
     * discovered FQCNs, because a class reaches its base through parents that
     * may be declared in a file not yet parsed. Null only during that window,
     * and private so the window is not observable — {@see kind()} raises
     * rather than answering `Unknown`, which is a classification and not the
     * absence of one.
     */
    private ?ClassKind $kind = null;

    /**
     * @param list<string> $interfaces resolved FQCNs
     * @param list<string> $traits     resolved FQCNs
     * @param list<string> $attributes resolved attribute FQCNs
     */
    public function __construct(
        public readonly string $fqcn,
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

    /**
     * Record what {@see Discovery::classify()} decided. Called exactly once per
     * discovered class, for every class discovery parsed — including one whose
     * FQCN a later file re-declared, which is reported as ATOMS-E002 and still
     * needs its kind, because the pass that reports unclassifiable files reads
     * every parsed class and not only the survivors of that collision.
     */
    public function classifyAs(ClassKind $kind): void
    {
        if ($this->kind !== null) {
            throw new \LogicException($this->fqcn . ' was classified twice.');
        }

        $this->kind = $kind;
    }

    public function kind(): ClassKind
    {
        return $this->kind ?? throw new \LogicException(
            $this->fqcn . ' was read before the classification pass ran.',
        );
    }

    public function basename(): string
    {
        $pos = strrpos($this->fqcn, '\\');

        return $pos === false ? $this->fqcn : substr($this->fqcn, $pos + 1);
    }
}
