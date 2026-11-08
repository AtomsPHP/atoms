<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\PhpTarget;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/** Static syntax checks for files that ship, including PHP migrations and vendor files. */
final class TargetSyntax
{
    public function __construct(private readonly PhpTarget $target)
    {
    }

    public function check(string $source, string $file): void
    {
        try {
            $ast = (new ParserFactory())->createForVersion(PhpVersion::fromString($this->target->php))->parse($source) ?? [];
            // PHP-Parser accepts newer syntax on older targets. Check the
            // post-8.3 constructs separately instead of using the CLI's lexer.
            if ($this->target->php === '8.3') {
                $newer = (new NodeFinder())->findFirst($ast, static function (Node $node): bool {
                    if ($node instanceof Node\PropertyHook
                        || $node instanceof Node\Expr\BinaryOp\Pipe
                        || $node instanceof Node\Expr\Cast\Void_
                        || $node instanceof Node\Scalar\MagicConst\Property
                        || ($node instanceof Node\Stmt\Const_ && $node->attrGroups !== [])) {
                        return true;
                    }
                    if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                        && \in_array(strtolower($node->name->toString()), ['clone', 'exit', 'die'], true)) {
                        return true; // Older operator forms have dedicated AST nodes.
                    }
                    $receiver = match (true) {
                        $node instanceof Node\Expr\MethodCall, $node instanceof Node\Expr\NullsafeMethodCall,
                        $node instanceof Node\Expr\PropertyFetch, $node instanceof Node\Expr\NullsafePropertyFetch,
                        $node instanceof Node\Expr\ArrayDimFetch => $node->var,
                        $node instanceof Node\Expr\StaticCall, $node instanceof Node\Expr\StaticPropertyFetch,
                        $node instanceof Node\Expr\ClassConstFetch => $node->class,
                        $node instanceof Node\Expr\FuncCall => $node->name,
                        default => null,
                    };
                    if ($receiver instanceof Node\Expr\New_ && $node->getStartFilePos() === $receiver->getStartFilePos()) {
                        return true; // Dereferencing new without parentheses requires 8.4.
                    }
                    $constant = match (true) {
                        $node instanceof Node\Const_ => $node->value,
                        $node instanceof Node\PropertyItem, $node instanceof Node\Param => $node->default,
                        $node instanceof Node\Attribute => $node->args,
                        default => null,
                    };
                    if ($constant !== null && (new NodeFinder())->findFirst($constant, static fn (Node $value): bool =>
                        $value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction
                        || ($value instanceof Node\Expr\CallLike && $value->isFirstClassCallable())) !== null) {
                        return true; // Closures in constant expressions require 8.5.
                    }
                    if ($node instanceof Node\Stmt\Property || $node instanceof Node\Param) {
                        return ($node->flags & (Modifiers::PUBLIC_SET | Modifiers::PROTECTED_SET
                            | Modifiers::PRIVATE_SET | Modifiers::FINAL | Modifiers::ABSTRACT)) !== 0;
                    }
                    return false;
                });
                if ($newer !== null) {
                    throw new \PhpParser\Error('Syntax requires PHP 8.4 or later', $newer->getAttributes());
                }
            }
        } catch (\PhpParser\Error $error) {
            throw new AtomsError(ErrorCode::BundleRejected, ErrorCatalog::format(ErrorCode::BundleRejected, [
                'reason' => $file . ' targets PHP ' . $this->target->version . ': ' . $error->getMessage(),
            ]));
        }
    }
}
