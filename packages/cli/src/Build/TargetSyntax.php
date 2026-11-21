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

/** Checks syntax against the Worker's PHP version without executing the source. */
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
        $parsed = $this->parse($source, $file);

        return $parsed instanceof Violation ? $parsed : null;
    }

    /** @return list<Node\Stmt>|Violation */
    public function parse(string $source, string $file): array|Violation
    {
        try {
            $ast = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            return self::parseFailure($file, $error);
        }

        // PHP-Parser 5.8 accepts a few PHP 8.4 constructs in 8.3 mode (property
        // hooks, final/abstract properties, and unparenthesized `new` dereferencing).
        $newer = (new NodeFinder())->findFirst($ast, static fn (Node $node): bool => self::requiresNewerPhp($node));
        if ($newer !== null) {
            return self::violation($file, $newer->getStartLine(), 'This syntax requires PHP 8.4 or later.');
        }

        return $ast;
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

    private static function requiresNewerPhp(Node $node): bool
    {
        if ($node instanceof Node\PropertyHook) {
            return true;
        }

        if ($node instanceof Node\Stmt\Property || $node instanceof Node\Param) {
            $php83Flags = Modifiers::PUBLIC | Modifiers::PROTECTED | Modifiers::PRIVATE
                | Modifiers::STATIC | Modifiers::READONLY;
            if (($node->flags & ~$php83Flags) !== 0) {
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

        return $receiver instanceof Node\Expr\New_ && $node->getStartFilePos() === $receiver->getStartFilePos();
    }
}
