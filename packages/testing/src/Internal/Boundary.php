<?php

declare(strict_types=1);

namespace Atoms\Testing\Internal;

use Atoms\Serialization\Serializer;

/**
 * Shared boundary-round-trip helper used by {@see \Atoms\Testing\AtomHarness} and
 * {@see \Atoms\Testing\FakeAppProxy}. Every call across a simulated boundary
 * (Atom method invocation, app() proxy call, dispatched-job reconstruction) goes
 * through the exact same normalize → json → denormalize pipeline the production
 * runtime uses, so serialization violations throw exactly as they would in
 * production.
 *
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
        $normalized = $serializer->normalize(array_values($args));
        $json = json_encode($normalized, JSON_THROW_ON_ERROR);

        /** @var list<mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $serializer->denormalizeArguments($decoded, $fn);
    }

    /**
     * Round-trip a return value against a declared return type. Class types
     * (Payload/DateTimeImmutable/BackedEnum) are denormalized back into that
     * type; scalars/array/mixed/void pass through the decoded JSON value.
     */
    public static function roundTripReturn(mixed $value, ?\ReflectionType $returnType, Serializer $serializer): mixed
    {
        $normalized = $serializer->normalize($value);
        $json = json_encode($normalized, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

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
