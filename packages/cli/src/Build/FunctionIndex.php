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

        /** @var array<string, true> $visited */
        $visited = [];
        $queue = $autoloadFiles;
        $violations = [];
        $finder = new NodeFinder();
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($visited[$path]) || !isset($files[$path])) {
                continue;
            }
            $visited[$path] = true;
            $ast = $this->addSource($files[$path], $path);
            if ($ast instanceof Violation) {
                $violations[] = $ast;
                continue;
            }
            foreach ($finder->findInstanceOf($ast, Node\Expr\Include_::class) as $include) {
                $target = self::includePath($include, $path);
                if ($target !== null && !isset($visited[$target])) {
                    $queue[] = $target;
                }
            }
        }

        return $violations;
    }

    public function has(string $name): bool
    {
        return isset($this->functions[strtolower(ltrim($name, '\\'))]);
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
