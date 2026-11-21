<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Discovers classes in both monolith and Worker source. Resolves every class
 * name to its fully qualified name without executing the file.
 */
final class SourceParser
{
    private readonly Parser $parser;
    private readonly Parser $workerParser;

    public function __construct()
    {
        $factory = new ParserFactory();
        $this->parser = $factory->createForNewestSupportedVersion();
        $this->workerParser = $factory->createForVersion(PhpVersion::fromString(TargetSyntax::PHP_SERIES));
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

        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (Error) {
            // Newer grammars can reserve tokens that the Worker still permits
            // as identifiers, such as __PROPERTY__.
            $ast = $this->workerParser->parse($code) ?? [];
        }

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
