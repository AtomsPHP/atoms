<?php

declare(strict_types=1);

namespace Atoms\Testing\Internal;

use Atoms\Serialization\Serializer;

/**
 * @internal
 */
final class Boundary
{
    private function __construct()
    {
    }

    /**
     * Round-trip a positional argument list against a function/method signature.
     *
     * @param list<mixed> $args
     * @return list<mixed>
     */
    public static function roundTripArgs(array $args, \ReflectionFunctionAbstract $fn, Serializer $serializer): array
    {
        /** @var list<mixed> $decoded */
        $decoded = self::wireValue(array_values($args), $serializer);

        return $serializer->denormalizeArguments($decoded, $fn);
    }

    public static function wireValue(mixed $value, Serializer $serializer): mixed
    {
        $json = json_encode($serializer->normalize($value), JSON_THROW_ON_ERROR);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Round-trip a return value against a declared return type. Class types
     * (Payload/DateTimeImmutable/BackedEnum) are denormalized back into that
     * type; scalars/array/mixed/void pass through the decoded JSON value.
     */
    public static function roundTripReturn(mixed $value, ?\ReflectionType $returnType, Serializer $serializer): mixed
    {
        $decoded = self::wireValue($value, $serializer);

        if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return $decoded;
        }

        $name = $returnType->getName();

        if ($name === 'void' || $name === 'never') {
            return null;
        }

        $type = ($returnType->allowsNull() && $name !== 'null') ? '?' . $name : $name;

        return $serializer->denormalize($decoded, $type);
    }
}
