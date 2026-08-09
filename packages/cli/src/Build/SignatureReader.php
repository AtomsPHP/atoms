<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Extracts method and constructor signatures from a name-resolved class node,
 * shared by the manifest generator and the contract checker.
 */
final class SignatureReader
{
    /**
     * @param list<string> $exclude method names to skip (lower-cased)
     * @return list<MethodSignature> public methods declared on the class
     */
    public static function publicMethods(ClassLike $node, array $exclude = []): array
    {
        $exclude = array_map('strtolower', $exclude);
        $out = [];
        foreach ($node->getMethods() as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            $lower = strtolower($method->name->toString());
            if ($lower === '__construct' || \in_array($lower, $exclude, true)) {
                continue;
            }
            $out[] = self::fromMethod($method);
        }

        return $out;
    }

    public static function constructor(ClassLike $node): ?MethodSignature
    {
        $ctor = $node->getMethod('__construct');

        return $ctor === null ? null : self::fromMethod($ctor);
    }

    /**
     * Promoted constructor properties (Payload/AtomJob state), as {name, type}.
     *
     * @return list<ParameterSignature>
     */
    public static function promotedProperties(ClassLike $node): array
    {
        $ctor = $node->getMethod('__construct');
        if ($ctor === null) {
            return [];
        }

        $out = [];
        foreach ($ctor->params as $param) {
            if ($param->flags === 0) {
                continue; // not promoted
            }
            $out[] = self::fromParam($param);
        }

        return $out;
    }

    private static function fromMethod(ClassMethod $method): MethodSignature
    {
        return new MethodSignature(
            name: $method->name->toString(),
            params: array_map(self::fromParam(...), $method->params),
            return: TypeString::fromNode($method->returnType),
        );
    }

    private static function fromParam(Param $param): ParameterSignature
    {
        $name = $param->var instanceof Expr\Variable && \is_string($param->var->name)
            ? $param->var->name
            : '';

        $hasDefault = $param->default !== null;
        [$captured, $default] = self::literal($param->default);

        return new ParameterSignature(
            name: $name,
            type: TypeString::fromNode($param->type),
            optional: $hasDefault || $param->variadic,
            variadic: $param->variadic,
            hasDefault: $hasDefault && $captured,
            default: $default,
        );
    }

    /**
     * Evaluate a simple literal default; returns [captured, value].
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function literal(?Expr $expr): array
    {
        if ($expr instanceof Scalar\String_) {
            return [true, $expr->value];
        }
        if ($expr instanceof Scalar\Int_) {
            return [true, $expr->value];
        }
        if ($expr instanceof Scalar\Float_) {
            return [true, $expr->value];
        }
        if ($expr instanceof Expr\ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => [true, true],
                'false' => [true, false],
                'null' => [true, null],
                default => [false, null],
            };
        }
        if ($expr instanceof Expr\Array_ && $expr->items === []) {
            return [true, []];
        }

        return [false, null];
    }
}
