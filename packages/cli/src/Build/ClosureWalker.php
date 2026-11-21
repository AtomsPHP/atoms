<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Stages 2+3: compute the transitive symbol closure of every bundled class
 * (Atom, Shared DTO, Support) and top-level/migration PHP code, classify each
 * referenced symbol, and collect boundary violations. Recursion follows only
 * bundled classes — Methods and AtomJob code stays in the monolith and is never
 * walked.
 */
final class ClosureWalker
{
    /**
     * @param array<string, list<Node>> $fileAsts bundled PHP files (relative path => resolved AST)
     */
    public function __construct(
        private readonly DiscoveryResult $discovery,
        private readonly SymbolClassifier $classifier,
        private readonly array $fileAsts = [],
    ) {
    }

    /**
     * @return list<Violation> deduplicated by (code, symbol, file)
     */
    public function walk(): array
    {
        /** @var array<string, true> $visited */
        $visited = [];
        /** @var list<DiscoveredClass> $queue */
        $queue = [];

        foreach ($this->discovery->classes as $class) {
            if ($class->kind()->isBundled()) {
                $queue[] = $class;
                $visited[$class->fqcn] = true;
            }
        }

        /** @var array<string, Violation> $violations keyed by dedupe key */
        $violations = [];

        while ($queue !== []) {
            $class = array_shift($queue);
            $shared = $class->kind() === ClassKind::Shared;

            foreach ($this->collectReferences($class) as $ref) {
                $name = ltrim($ref['name'], '\\');

                // Enqueue transitively-referenced bundled classes.
                $target = $this->discovery->get($name);
                if ($target !== null && $target->kind()->isBundled() && !isset($visited[$target->fqcn])) {
                    $visited[$target->fqcn] = true;
                    $queue[] = $target;
                }

                $violation = $this->classifier->classify(
                    $name,
                    $ref['kind'],
                    $class->relativePath,
                    $ref['line'],
                    $shared,
                    $class->fqcn,
                    $ref['fallback'] ?? null,
                );
                if ($violation !== null) {
                    $violations[$violation->dedupeKey()] = $violations[$violation->dedupeKey()] ?? $violation;
                }
            }
        }

        // Check top-level code, functions, and migration classes not walked above.
        foreach ($this->fileAsts as $file => $ast) {
            $collector = new ReferenceCollector();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new class($visited) extends NodeVisitorAbstract {
                /** @param array<string, true> $walked */
                public function __construct(private readonly array $walked) {}

                public function enterNode(Node $node): ?int
                {
                    if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null
                        && isset($this->walked[$node->namespacedName->toString()])) {
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }

                    return null;
                }
            });
            $traverser->addVisitor($collector);
            $traverser->traverse($ast);

            foreach ($collector->references() as $ref) {
                $violation = $this->classifier->classify(
                    $ref['name'],
                    $ref['kind'],
                    $file,
                    $ref['line'],
                    false,
                    '',
                    $ref['fallback'] ?? null,
                );
                if ($violation !== null) {
                    $violations[$violation->dedupeKey()] = $violations[$violation->dedupeKey()] ?? $violation;
                }
            }
        }

        return array_values($violations);
    }

    /**
     * @return list<array{name: string, kind: SymbolKind, line: int, fallback?: ?string}>
     */
    private function collectReferences(DiscoveredClass $class): array
    {
        $collector = new ReferenceCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse([$class->node]);

        $refs = $collector->references();

        // Class-level relationships (recorded at the class's own line).
        if ($class->parent !== null) {
            $refs[] = ['name' => $class->parent, 'kind' => SymbolKind::ClassLike, 'line' => $class->line];
        }
        foreach ([...$class->interfaces, ...$class->traits, ...$class->attributes] as $name) {
            $refs[] = ['name' => $name, 'kind' => SymbolKind::ClassLike, 'line' => $class->line];
        }

        return $refs;
    }
}
