<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects every symbol a class references — parents, interfaces, traits,
 * attributes, `new`/static/`instanceof` targets, catch types, function calls,
 * and typed signatures — with file:line, for the closure walk (§3.1 stage 2).
 *
 * @phpstan-type Reference array{name: string, kind: SymbolKind, line: int}
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    /** @var list<Reference> */
    private array $references = [];

    /**
     * @return list<Reference>
     */
    public function references(): array
    {
        return $this->references;
    }

    public function enterNode(Node $node): null
    {
        match (true) {
            $node instanceof Expr\New_ => $this->addClassName($node->class, $node->getStartLine()),
            $node instanceof Expr\StaticCall => $this->addClassName($node->class, $node->getStartLine()),
            $node instanceof Expr\ClassConstFetch => $this->addClassName($node->class, $node->getStartLine()),
            $node instanceof Expr\StaticPropertyFetch => $this->addClassName($node->class, $node->getStartLine()),
            $node instanceof Expr\Instanceof_ => $this->addClassName($node->class, $node->getStartLine()),
            $node instanceof Expr\FuncCall => $this->addFunctionName($node->name, $node->getStartLine()),
            $node instanceof Attribute => $this->addName($node->name, $node->getStartLine()),
            $node instanceof Stmt\Catch_ => $this->addNames($node->types, $node->getStartLine()),
            $node instanceof Param => $this->addType($node->type, $node->getStartLine()),
            $node instanceof Stmt\Property => $this->addType($node->type, $node->getStartLine()),
            $node instanceof Stmt\ClassMethod => $this->addType($node->returnType, $node->getStartLine()),
            $node instanceof Expr\Closure => $this->addType($node->returnType, $node->getStartLine()),
            $node instanceof Expr\ArrowFunction => $this->addType($node->returnType, $node->getStartLine()),
            default => null,
        };

        return null;
    }

    /**
     * @param Node\Expr|Name $class
     */
    private function addClassName(Node $class, int $line): void
    {
        if ($class instanceof Name) {
            $this->addName($class, $line);
        }
    }

    private function addFunctionName(Node $name, int $line): void
    {
        if ($name instanceof Name) {
            $this->references[] = ['name' => $name->toString(), 'kind' => SymbolKind::FunctionCall, 'line' => $line];
        }
    }

    private function addName(Name $name, int $line): void
    {
        $this->references[] = ['name' => $name->toString(), 'kind' => SymbolKind::ClassLike, 'line' => $line];
    }

    /**
     * @param array<Name> $names
     */
    private function addNames(array $names, int $line): void
    {
        foreach ($names as $name) {
            $this->addName($name, $line);
        }
    }

    private function addType(?Node $type, int $line): void
    {
        if ($type === null || $type instanceof Identifier) {
            return;
        }
        if ($type instanceof Name) {
            $this->addName($type, $line);

            return;
        }
        if ($type instanceof NullableType) {
            $this->addType($type->type, $line);

            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $inner) {
                $this->addType($inner, $line);
            }

            return;
        }
        if ($type instanceof ComplexType) {
            return;
        }
    }
}
