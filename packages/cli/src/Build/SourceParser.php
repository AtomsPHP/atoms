<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Wraps nikic/php-parser: parses a source file for the newest supported PHP
 * version and runs the NameResolver so every class-name reference is fully
 * qualified. Never includes or executes the file — pure static analysis.
 */
final class SourceParser
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @throws \PhpParser\Error on a syntax error
     */
    public function parse(string $absolutePath, string $relativePath): PhpFile
    {
        $code = @file_get_contents($absolutePath);
        if ($code === false) {
            throw new \RuntimeException("Could not read {$absolutePath}");
        }

        $ast = $this->parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, [
            'preserveOriginalNames' => true,
            'replaceNodes' => true,
        ]));
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder();
        /** @var list<ClassLike> $classes */
        $classes = [];
        foreach ($finder->find($ast, static fn (Node $n): bool => $n instanceof ClassLike) as $node) {
            /** @var ClassLike $node */
            if ($node->namespacedName === null) {
                continue; // anonymous class
            }
            $classes[] = $node;
        }

        return new PhpFile($absolutePath, $relativePath, $classes);
    }
}
