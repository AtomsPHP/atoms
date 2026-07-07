<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Renders a php-parser type node as a PHP-syntax type string for the manifest
 * (`string`, `?int`, `App\Atoms\Shared\PlayerSnapshot`, `A|B`). An absent type
 * becomes `mixed`, matching conventions.md's manifest type rules.
 */
final class TypeString
{
    public static function fromNode(?Node $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return $type->toString();
        }

        if ($type instanceof NullableType) {
            return '?' . self::innerName($type->type);
        }

        if ($type instanceof UnionType) {
            return implode('|', array_map(self::innerName(...), $type->types));
        }

        if ($type instanceof IntersectionType) {
            return implode('&', array_map(self::innerName(...), $type->types));
        }

        return 'mixed';
    }

    /**
     * @param Identifier|Name|IntersectionType|ComplexType $type
     */
    private static function innerName(Node $type): string
    {
        if ($type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof Name) {
            return $type->toString();
        }

        return self::fromNode($type);
    }
}
