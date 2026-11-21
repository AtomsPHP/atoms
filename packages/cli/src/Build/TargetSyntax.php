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

/** Checks source against the pinned php-wasm Worker without running it. */
final class TargetSyntax
{
    public const PHP_SERIES = '8.3';
    public const PHP_VERSION = '8.3.32';

    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString(self::PHP_SERIES));
    }

    public function check(string $source, string $file): ?Violation
    {
        try {
            $ast = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            return self::parseFailure($file, $error);
        }

        // PHP-Parser's version selection emulates tokenization and certain
        // grammar changes, but can still accept forms from a later version.
        $tokens = $this->parser->getTokens();
        $newer = (new NodeFinder())->findFirst($ast, static fn (Node $node): bool => self::requiresNewerPhp($node, $tokens));
        if ($newer !== null) {
            return self::violation($file, $newer->getStartLine(), 'This syntax requires PHP 8.4 or later.');
        }

        return null;
    }

    public static function parseFailure(string $file, Error $error): Violation
    {
        return self::violation($file, $error->getStartLine(), $error->getRawMessage() . '.');
    }

    private static function violation(string $file, int $line, string $reason): Violation
    {
        return new Violation(ErrorCode::IncompatibleWorkerSyntax, $file, max(1, $line), [
            'target' => self::PHP_VERSION,
            'reason' => $reason,
        ]);
    }

    /** @param list<Token> $tokens */
    private static function requiresNewerPhp(Node $node, array $tokens): bool
    {
        if ($node instanceof Node\PropertyHook
            || ($node instanceof Node\Stmt\Const_ && ($node->attrGroups ?? []) !== [])) {
            return true;
        }

        if ($node instanceof Node\Stmt\Property || $node instanceof Node\Param) {
            // These flags are available on the minimum supported PHP-Parser
            // version. Set visibility modifiers were added later.
            $php83Flags = Modifiers::PUBLIC | Modifiers::PROTECTED | Modifiers::PRIVATE
                | Modifiers::STATIC | Modifiers::READONLY;
            if (($node->flags & ~$php83Flags) !== 0) {
                return true;
            }
        }

        // Earlier operator forms have dedicated nodes. Qualified names are
        // ordinary calls, whose availability is checked separately.
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->isUnqualified()
            && \in_array(strtolower($node->name->toString()), ['clone', 'exit', 'die'], true)) {
            return true;
        }
        if ($node instanceof Node\Expr\Exit_ && $tokens[$node->getEndTokenPos()]->text === ')') {
            $pos = $node->getEndTokenPos() - 1;
            while ($tokens[$pos]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                --$pos;
            }
            if ($tokens[$pos]->text === ',') {
                return true;
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
            return true; // Dereferencing new without parentheses requires PHP 8.4.
        }

        $constant = match (true) {
            $node instanceof Node\Const_ => $node->value,
            $node instanceof Node\PropertyItem, $node instanceof Node\Param => $node->default,
            $node instanceof Node\Attribute => $node->args,
            default => null,
        };

        // Casts, closures and first-class callables in constant expressions
        // need PHP 8.5. Ordinary expressions already support these forms.
        return $constant !== null && (new NodeFinder())->findFirst($constant, static fn (Node $value): bool =>
            $value instanceof Node\Expr\Cast
            || $value instanceof Node\Expr\Closure
            || $value instanceof Node\Expr\ArrowFunction
            || ($value instanceof Node\Expr\CallLike && $value->isFirstClassCallable())) !== null;
    }
}
