<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/** Function declarations in project files and eagerly loaded vendor files. */
final class FunctionIndex
{
    /** @var array<string, array<string, array{eager: bool, offset: int}>> lower-case function => path => availability */
    private array $functions = [];
    /** @var array<string, list<Node>> */
    private array $projectSources = [];
    /** @var array<string, list<array{path: string, offset: int}>> */
    private array $projectIncludes = [];

    public function __construct(private readonly TargetSyntax $syntax = new TargetSyntax())
    {
    }

    /** @return list<string> */
    public function projectFiles(): array
    {
        return array_keys($this->projectSources);
    }

    /** @return list<Node> */
    public function projectSource(string $path): array
    {
        return $this->projectSources[$path] ?? [];
    }

    /**
     * Index function declarations without loading the file.
     *
     * @return list<string>|Violation paths the file includes at load time, or a syntax error
     */
    public function addSource(string $contents, string $path, bool $eager = false): array|Violation
    {
        $ast = $this->syntax->parse($contents, $path);
        if ($ast instanceof Violation) {
            return $ast;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
        try {
            $ast = array_values($traverser->traverse($ast));
        } catch (Error $error) {
            return TargetSyntax::parseFailure($path, $error);
        }
        if (!$eager) {
            $this->projectSources[$path] = $ast;
        }
        $includes = [];
        $this->recordTopLevel($ast, $path, $eager);
        $this->scan($ast, $path, $eager, $includes);
        if (!$eager) {
            $this->projectIncludes[$path] = $includes;
        }
        return array_column($includes, 'path');
    }

    /**
     * @param list<array{name: string, contents: string}> $entries
     * @param list<string> $autoloadFiles
     * @return list<Violation>
     */
    public function addVendorEntries(array $entries, array $autoloadFiles): array
    {
        $files = [];
        foreach ($entries as $entry) {
            $files[$entry['name']] = $entry['contents'];
        }
        $seen = [];
        $queue = $autoloadFiles;
        $violations = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($seen[$path]) || !isset($files[$path])) {
                continue;
            }
            $seen[$path] = true;
            $includes = $this->addSource($files[$path], $path, true);
            if ($includes instanceof Violation) {
                $violations[] = $includes;
            } else {
                array_push($queue, ...$includes);
            }
        }
        return $violations;
    }

    public function has(string $name, string $callingFile, ?int $offset = null): bool
    {
        $providers = $this->functions[strtolower(ltrim($name, '\\'))] ?? [];
        if ($providers === []) {
            return false;
        }
        foreach ($providers as $provider) {
            if ($provider['eager']) {
                return true;
            }
        }
        $queue = [[$callingFile, $offset]];
        $seen = [];
        while ($queue !== []) {
            [$path, $limit] = array_shift($queue);
            if (isset($seen[$path]) || !isset($this->projectSources[$path])) {
                continue;
            }
            $seen[$path] = true;
            if (isset($providers[$path]) && ($limit === null || $providers[$path]['offset'] <= $limit)) {
                return true;
            }
            foreach ($this->projectIncludes[$path] ?? [] as $include) {
                if ($limit === null || $include['offset'] <= $limit) {
                    $queue[] = [$include['path'], null];
                }
            }
        }
        return false;
    }

    /**
     * PHP registers unconditional top-level functions when it compiles a file,
     * even after a return or in a later namespace block.
     *
     * @param list<Node> $statements
     */
    private function recordTopLevel(array $statements, string $path, bool $eager): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->recordTopLevel($statement->stmts, $path, $eager);
            } elseif ($statement instanceof Stmt\Function_) {
                $this->record($statement, $path, $eager, 0);
            }
        }
    }

    /**
     * @param list<Node> $statements
     * @param list<array{path: string, offset: int}> $includes
     * @return bool whether execution may return before the next statement
     */
    private function scan(array $statements, string $path, bool $eager, array &$includes, bool $conditional = false): bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                if ($this->scan($statement->stmts, $path, $eager, $includes)) {
                    return true;
                }
            } elseif ($conditional && $statement instanceof Stmt\Function_) {
                $this->record($statement, $path, $eager, $statement->getEndFilePos() + 1);
            } elseif ($statement instanceof Stmt\If_) {
                if ($this->scanIf($statement, $path, $eager, $includes)) {
                    return true;
                }
            } else {
                $expr = $statement instanceof Stmt\Expression ? $statement->expr
                    : ($statement instanceof Stmt\Return_ ? $statement->expr : null);
                if ($expr instanceof Expr\Include_) {
                    $include = $this->includePath($expr, $path);
                    if ($include !== null) {
                        $includes[] = ['path' => $include, 'offset' => $statement->getEndFilePos() + 1];
                    }
                }
                if ($this->containsReturn([$statement])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<array{path: string, offset: int}> $includes */
    private function scanIf(Stmt\If_ $statement, string $path, bool $eager, array &$includes): bool
    {
        $branches = [[$statement->cond, $statement->stmts]];
        foreach ($statement->elseifs as $elseif) {
            $branches[] = [$elseif->cond, $elseif->stmts];
        }
        foreach ($branches as [$condition, $body]) {
            $value = $this->conditionValue($condition);
            if ($value === false) {
                continue;
            }
            if ($value === true) {
                return $this->scan($body, $path, $eager, $includes, true);
            }
            // If the function already exists, PHP skips its polyfill. Otherwise
            // the branch must reach the declaration before any return.
            if ($condition === $statement->cond && $this->isFunctionExistsGuard($statement)) {
                foreach ($body as $guarded) {
                    if ($this->containsReturn([$guarded])) {
                        break;
                    }
                    if ($guarded instanceof Stmt\Function_ && $this->guardMatches($statement, $guarded)) {
                        $this->record($guarded, $path, $eager, $statement->getEndFilePos() + 1);
                    }
                }
            }
            return $this->ifMayReturn($statement);
        }
        return $statement->else !== null && $this->scan($statement->else->stmts, $path, $eager, $includes, true);
    }

    private function includePath(Expr\Include_ $include, string $path): ?string
    {
        $target = $include->expr;
        if (!$target instanceof Expr\BinaryOp\Concat || !$target->left instanceof Node\Scalar\MagicConst\Dir
            || !$target->right instanceof Node\Scalar\String_) {
            return null;
        }
        $normalized = [];
        foreach (explode('/', dirname($path) . $target->right->value) as $part) {
            if ($part === '..') {
                if ($normalized === []) {
                    return null;
                }
                array_pop($normalized);
            } elseif ($part !== '' && $part !== '.') {
                $normalized[] = $part;
            }
        }
        return implode('/', $normalized);
    }

    private function ifMayReturn(Stmt\If_ $statement): bool
    {
        if ($this->containsReturn($statement->stmts)) {
            return true;
        }
        foreach ($statement->elseifs as $elseif) {
            if ($this->containsReturn($elseif->stmts)) {
                return true;
            }
        }
        return $statement->else !== null && $this->containsReturn($statement->else->stmts);
    }

    /** @param list<Node> $statements */
    private function containsReturn(array $statements): bool
    {
        $visitor = new class extends NodeVisitorAbstract {
            public bool $found = false;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Stmt\Return_) {
                    $this->found = true;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($statements);
        return $visitor->found;
    }

    private function conditionValue(Expr $condition): ?bool
    {
        if (!$condition instanceof Expr\BinaryOp) {
            return null;
        }
        $left = $this->versionOperand($condition->left);
        $right = $this->versionOperand($condition->right);
        if ($left === null || $right === null) {
            return null;
        }
        return match (true) {
            $condition instanceof Expr\BinaryOp\GreaterOrEqual => $left >= $right,
            $condition instanceof Expr\BinaryOp\Greater => $left > $right,
            $condition instanceof Expr\BinaryOp\SmallerOrEqual => $left <= $right,
            $condition instanceof Expr\BinaryOp\Smaller => $left < $right,
            $condition instanceof Expr\BinaryOp\Equal, $condition instanceof Expr\BinaryOp\Identical => $left === $right,
            $condition instanceof Expr\BinaryOp\NotEqual, $condition instanceof Expr\BinaryOp\NotIdentical => $left !== $right,
            default => null,
        };
    }

    private function versionOperand(Expr $operand): ?int
    {
        if ($operand instanceof Node\Scalar\Int_) {
            return $operand->value;
        }
        if ($operand instanceof Expr\ConstFetch && strtoupper($operand->name->toString()) === 'PHP_VERSION_ID') {
            [$major, $minor, $patch] = array_map('intval', explode('.', TargetSyntax::PHP_VERSION));
            return $major * 10000 + $minor * 100 + $patch;
        }
        return null;
    }

    private function isFunctionExistsGuard(Stmt\If_ $statement): bool
    {
        $condition = $statement->cond;
        return $condition instanceof Expr\BooleanNot
            && $condition->expr instanceof Expr\FuncCall
            && $condition->expr->name instanceof Node\Name
            && strtolower($condition->expr->name->toString()) === 'function_exists'
            && count($condition->expr->args) === 1
            && $condition->expr->args[0]->value instanceof Node\Scalar\String_;
    }

    private function guardMatches(Stmt\If_ $statement, Stmt\Function_ $node): bool
    {
        /** @var Expr\BooleanNot $condition */
        $condition = $statement->cond;
        /** @var Expr\FuncCall $call */
        $call = $condition->expr;
        /** @var Node\Scalar\String_ $literal */
        $literal = $call->args[0]->value;
        $name = $node->namespacedName?->toString() ?? $node->name->toString();
        return strtolower(ltrim($literal->value, '\\')) === strtolower($name);
    }

    private function record(Stmt\Function_ $node, string $path, bool $eager, int $offset): void
    {
        $name = $node->namespacedName?->toString() ?? $node->name->toString();
        $key = strtolower($name);
        $existing = $this->functions[$key][$path] ?? null;
        $this->functions[$key][$path] = [
            'eager' => $eager || ($existing['eager'] ?? false),
            'offset' => min($offset, $existing['offset'] ?? $offset),
        ];
    }
}
