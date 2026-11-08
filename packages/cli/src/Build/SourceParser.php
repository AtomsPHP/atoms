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
 * Parse through PHP 8.5 and resolve class names without executing the file.
 * TargetSyntax checks the files that will ship against the selected target.
 */
final class SourceParser
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromString('8.5'));
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
