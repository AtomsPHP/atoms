<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Errors\ErrorCode;
use PhpParser\Error;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\Token;

/** Checks syntax against the Worker's PHP version without executing the source. */
final class TargetSyntax
{
    public const PHP_SERIES = '8.3';
    public const PHP_VERSION = '8.3.32';

    private readonly Parser $parser;

    public function __construct(public readonly string $version = self::PHP_VERSION)
    {
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString($this->version));
    }

    public function check(string $source, string $file): ?Violation
    {
        $parsed = $this->parse($source, $file);

        return $parsed instanceof Violation ? $parsed : null;
    }

    /** @return list<Node\Stmt>|Violation */
    public function parse(string $source, string $file): array|Violation
    {
        try {
            $ast = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            return self::parseFailure($file, $error, $this->version);
        }

        // The parser accepts some constructs introduced after the requested grammar.
        $tokens = $this->parser->getTokens();
        $newer = (new NodeFinder())->findFirst($ast, fn (Node $node): bool =>
            version_compare(self::minimumVersion($node, $tokens), $this->version, '>'));
        if ($newer !== null) {
            $minimum = self::minimumVersion($newer, $tokens);
            return self::violation($file, $newer->getStartLine(), "This syntax requires PHP {$minimum} or later.", $this->version);
        }

        return $ast;
    }

    public static function parseFailure(string $file, Error $error, string $version = self::PHP_VERSION): Violation
    {
        return self::violation($file, $error->getStartLine(), $error->getRawMessage() . '.', $version);
    }

    private static function violation(string $file, int $line, string $reason, string $version): Violation
    {
        return new Violation(ErrorCode::IncompatibleWorkerSyntax, $file, max(1, $line), [
            'target' => $version,
            'reason' => $reason,
        ]);
    }

    /** @param list<Token> $tokens */
    private static function minimumVersion(Node $node, array $tokens): string
    {
        if ($node instanceof Node\Expr\BinaryOp\Pipe || $node instanceof Node\Expr\Cast\Void_
            || ($node instanceof Node\Stmt\Const_ && $node->attrGroups !== [])
            || ($node instanceof Node\Stmt\Property && $node->isStatic() && ($node->flags & Modifiers::VISIBILITY_SET_MASK))
            || ($node instanceof Node\Param && ($node->flags & Modifiers::FINAL))) {
            return '8.5';
        }
        // PHP-Parser gives the PHP 8.3 forms of clone, exit and die their own
        // nodes; unqualified FuncCall forms require PHP 8.4/8.5.
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->isUnqualified()
            && \in_array(strtolower($node->name->toString()), ['clone', 'exit', 'die'], true)) {
            return strtolower($node->name->toString()) === 'clone' ? '8.5' : '8.4';
        }
        if ($node instanceof Node\Expr\Exit_ && $tokens[$node->getEndTokenPos()]->text === ')') {
            $pos = $node->getEndTokenPos() - 1;
            while ($pos >= 0 && $tokens[$pos]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                --$pos;
            }
            if ($pos >= 0 && $tokens[$pos]->text === ',') {
                return '8.4';
            }
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
            return '8.4';
        }

        $constant = match (true) {
            $node instanceof Node\Const_ => $node->value,
            $node instanceof Node\PropertyItem, $node instanceof Node\Param => $node->default,
            $node instanceof Node\Attribute => $node->args,
            $node instanceof Node\Stmt\EnumCase => $node->expr,
            default => null,
        };

        // Casts, closures and first-class callables in constant expressions
        // require PHP 8.5.
        if ($constant !== null && (new NodeFinder())->findFirst($constant, static fn (Node $value): bool =>
            $value instanceof Node\Expr\Cast
            || $value instanceof Node\Expr\Closure
            || $value instanceof Node\Expr\ArrowFunction
            || ($value instanceof Node\Expr\CallLike && $value->isFirstClassCallable())) !== null) {
            return '8.5';
        }

        if ($node instanceof Node\PropertyHook) {
            return '8.4';
        }
        if ($node instanceof Node\Stmt\Property || $node instanceof Node\Param) {
            $php83Flags = Modifiers::PUBLIC | Modifiers::PROTECTED | Modifiers::PRIVATE
                | Modifiers::STATIC | Modifiers::READONLY;
            if (($node->flags & ~$php83Flags) !== 0) {
                return '8.4';
            }
        }

        return '8.3';
    }
}
