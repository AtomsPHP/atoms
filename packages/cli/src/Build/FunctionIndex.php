<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/** Flat case-insensitive index of functions declared in bundled project and eager vendor files. */
final class FunctionIndex
{
    /** @var array<string, true> lower-case fully-qualified function names */
    private array $functions = [];

    public function __construct(private readonly TargetSyntax $syntax = new TargetSyntax())
    {
    }

    /**
     * Parse a bundled PHP file against the Worker syntax, resolve names, and
     * record every function declaration in it without executing the file.
     *
     * @return list<Node>|Violation resolved AST, or a Worker syntax error
     */
    public function addSource(string $contents, string $path): array|Violation
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

        foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\Function_::class) as $function) {
            $name = $function->namespacedName?->toString() ?? $function->name->toString();
            $this->functions[strtolower($name)] = true;
        }

        return $ast;
    }

    /**
     * Index functions declared in Composer's eager `autoload.files` entries
     * and any literal `__DIR__`-relative files they include.
     *
     * @param list<array{name: string, contents: string}> $entries
     * @param list<string>                                $autoloadFiles
     * @return list<Violation>
     */
    public function addVendorEntries(array $entries, array $autoloadFiles): array
    {
        /** @var array<string, string> $files */
        $files = [];
        foreach ($entries as $entry) {
            $files[$entry['name']] = $entry['contents'];
        }

        /** @var array<string, bool> $visited */
        $visited = [];
        /** @var list<array{path: string, mandatory: bool}> $queue */
        $queue = [];
        foreach ($autoloadFiles as $path) {
            $queue[] = ['path' => $path, 'mandatory' => true];
        }
        $violations = [];
        $finder = new NodeFinder();
        while ($queue !== []) {
            $current = array_shift($queue);
            $path = $current['path'];
            $mandatory = $current['mandatory'];
            if (!isset($files[$path]) || (isset($visited[$path]) && (!$mandatory || $visited[$path]))) {
                continue;
            }
            $visited[$path] = $mandatory;
            $ast = $this->addSource($files[$path], $path);
            if ($ast instanceof Violation) {
                if ($mandatory) {
                    $violations[] = $ast;
                }
                continue;
            }

            $forwarded = $mandatory ? self::forwardedRequirePath($ast, $path) : null;
            if ($forwarded !== null) {
                if (!isset($visited[$forwarded]) || !$visited[$forwarded]) {
                    $queue[] = ['path' => $forwarded, 'mandatory' => true];
                }
                continue;
            }

            foreach ($finder->findInstanceOf($ast, Node\Expr\Include_::class) as $include) {
                $target = self::includePath($include, $path);
                if ($target !== null && !isset($visited[$target])) {
                    $queue[] = ['path' => $target, 'mandatory' => false];
                }
            }
        }

        return $violations;
    }

    public function has(string $name): bool
    {
        return isset($this->functions[strtolower(ltrim($name, '\\'))]);
    }

    /**
     * When a file's sole executable statement is a literal `require` or
     * `require_once` (directly or via `return`), return its resolved path.
     *
     * @param list<Node> $ast
     */
    private static function forwardedRequirePath(array $ast, string $path): ?string
    {
        $statements = self::executableStatements($ast);
        if (\count($statements) === 1 && $statements[0] instanceof Stmt\Namespace_) {
            $statements = self::executableStatements($statements[0]->stmts);
        }
        if (\count($statements) !== 1) {
            return null;
        }

        $sole = $statements[0];
        $expr = match (true) {
            $sole instanceof Stmt\Expression => $sole->expr,
            $sole instanceof Stmt\Return_ => $sole->expr,
            default => null,
        };
        if (!$expr instanceof Node\Expr\Include_
            || ($expr->type !== Node\Expr\Include_::TYPE_REQUIRE
                && $expr->type !== Node\Expr\Include_::TYPE_REQUIRE_ONCE)) {
            return null;
        }

        return self::includePath($expr, $path);
    }

    /**
     * @param array<Node> $nodes
     * @return list<Node>
     */
    private static function executableStatements(array $nodes): array
    {
        $statements = [];
        foreach ($nodes as $node) {
            if (($node instanceof Stmt\Declare_ && $node->stmts === null)
                || $node instanceof Stmt\Nop
                || $node instanceof Stmt\Use_
                || $node instanceof Stmt\GroupUse) {
                continue;
            }
            $statements[] = $node;
        }

        return $statements;
    }

    private static function includePath(Node\Expr\Include_ $include, string $path): ?string
    {
        $target = $include->expr;
        if (!$target instanceof Node\Expr\BinaryOp\Concat
            || !$target->left instanceof Node\Scalar\MagicConst\Dir
            || !$target->right instanceof Node\Scalar\String_) {
            return null;
        }
        $dir = \dirname($path);
        $raw = ($dir === '.' ? '' : $dir) . $target->right->value;
        $normalized = [];
        foreach (explode('/', $raw) as $part) {
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
}
