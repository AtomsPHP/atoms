<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node\Stmt\ClassLike;

/**
 * A parsed PHP source file: its path (relative to the repo root) and every
 * class-like declaration it contains, with name resolution already applied.
 */
final class PhpFile
{
    /**
     * @param list<ClassLike> $classes name-resolved class-like nodes
     */
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly array $classes,
    ) {
    }
}
