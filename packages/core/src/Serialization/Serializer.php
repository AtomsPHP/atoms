<?php

declare(strict_types=1);

namespace Atoms\Serialization;

use Atoms\Errors\ErrorCode;

/**
 * Implements the Atoms serialization type algebra (docs/conventions.md).
 *
 * Legal boundary types: null, bool, int, float, string; lists/maps of legal
 * types; {@see Payload} DTOs (promoted constructor properties, nesting allowed);
 * \DateTimeImmutable ⇄ RFC 3339; \BackedEnum ⇄ its backed value.
 *
 * Illegal (rejected at runtime with a {@see SerializationException}): closures,
 * resources, \DateTime (mutable), generic objects, non-hydratable Payloads.
 */
final class Serializer
{
    /**
     * RFC 3339 with microseconds preserved and an explicit numeric offset.
     */
    public const DATETIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Convert a value into a JSON-safe tree.
     *
     * @throws SerializationException if the value is outside the algebra
     */
    public function normalize(mixed $value): mixed
    {
        if (is_resource($value)) {
            throw new SerializationException(ErrorCode::UnserializableValue, 'A resource cannot cross the Atoms boundary.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new SerializationException(ErrorCode::UnserializableValue, 'A non-finite float cannot cross the Atoms boundary.');
            }

            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->normalize($item);
            }

            return $out;
        }

        if (is_object($value)) {
            return $this->normalizeObject($value);
        }

        throw new SerializationException(
            ErrorCode::UnserializableValue,
            'A value of type ' . gettype($value) . ' cannot cross the Atoms boundary.',
        );
    }

    private function normalizeObject(object $value): mixed
    {
        if ($value instanceof \Closure) {
            throw new SerializationException(ErrorCode::UnserializableValue, 'A Closure cannot cross the Atoms boundary.');
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::DATETIME_FORMAT);
        }

        if ($value instanceof \DateTime) {
            throw new SerializationException(
                ErrorCode::UnserializableValue,
                '\DateTime (mutable) cannot cross the Atoms boundary; use \DateTimeImmutable.',
            );
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Payload) {
            return $this->normalizePayload($value);
        }

        throw new SerializationException(
            ErrorCode::UnserializableValue,
            'An instance of ' . $value::class . ' cannot cross the Atoms boundary.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(Payload $value): array
    {
        $reflection = new \ReflectionClass($value);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $out = [];
        foreach ($constructor->getParameters() as $param) {
            if (!$param->isPromoted()) {
                throw new SerializationException(
                    ErrorCode::PayloadNotHydratable,
                    'Payload class ' . $value::class . ' must declare all state as promoted constructor properties.',
                );
            }

            $property = $reflection->getProperty($param->getName());
            $out[$param->getName()] = $this->normalize($property->getValue($value));
        }

        return $out;
    }

    /**
     * Hydrate a wire value into a PHP value of the given declared type.
     *
     * @param string $type PHP type syntax: 'int','float','bool','string','null',
     *                     'mixed','array', a nullable '?T', a \DateTimeImmutable
     *                     FQCN, a \BackedEnum FQCN, or a {@see Payload} FQCN.
     * @throws SerializationException on a type/value mismatch
     */
    public function denormalize(mixed $data, string $type): mixed
    {
        $nullable = false;
        if (str_starts_with($type, '?')) {
            $nullable = true;
            $type = substr($type, 1);
        }

        if ($type === 'null') {
            if ($data !== null) {
                throw $this->mismatch($data, 'null');
            }

            return null;
        }

        if ($type === 'mixed') {
            return $data;
        }

        if ($nullable && $data === null) {
            return null;
        }

        switch ($type) {
            case 'int':
                if (is_int($data)) {
                    return $data;
                }
                throw $this->mismatch($data, 'int');
            case 'float':
                if (is_float($data)) {
                    return $data;
                }
                if (is_int($data)) {
                    return (float) $data; // int→float widening
                }
                throw $this->mismatch($data, 'float');
            case 'bool':
                if (is_bool($data)) {
                    return $data;
                }
                throw $this->mismatch($data, 'bool');
            case 'string':
                if (is_string($data)) {
                    return $data;
                }
                throw $this->mismatch($data, 'string');
            case 'array':
                if (is_array($data)) {
                    return $data;
                }
                throw $this->mismatch($data, 'array');
        }

        $class = ltrim($type, '\\');

        if (is_a($class, \DateTimeImmutable::class, true)) {
            return $this->denormalizeDateTime($data);
        }

        if (is_a($class, \BackedEnum::class, true)) {
            return $this->denormalizeEnum($data, $class);
        }

        if (is_a($class, Payload::class, true)) {
            return $this->denormalizePayload($data, $class);
        }

        throw $this->mismatch($data, $type);
    }

    private function denormalizeDateTime(mixed $data): \DateTimeImmutable
    {
        if (!is_string($data)) {
            throw $this->mismatch($data, \DateTimeImmutable::class);
        }

        $dt = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $data);
        if ($dt === false) {
            throw $this->mismatch($data, \DateTimeImmutable::class);
        }

        return $dt;
    }

    /**
     * @param class-string<\BackedEnum> $class
     */
    private function denormalizeEnum(mixed $data, string $class): \BackedEnum
    {
        if (!is_int($data) && !is_string($data)) {
            throw $this->mismatch($data, $class);
        }

        $case = $class::tryFrom($data);
        if ($case === null) {
            throw $this->mismatch($data, $class);
        }

        return $case;
    }

    /**
     * @param class-string $class
     */
    private function denormalizePayload(mixed $data, string $class): object
    {
        if (!is_array($data)) {
            throw $this->mismatch($data, $class);
        }

        $reflection = new \ReflectionClass($class);

        return $reflection->newInstanceArgs($this->denormalizeNamedArguments($class, $data));
    }

    /**
     * Bind wire-form named arguments to a constructor, in declaration order.
     *
     * The dispatched-job envelope (`{"job": FQCN, "args": {...}}`) and Payload
     * hydration share one algebra: each parameter takes the wire value under
     * its own name, denormalized against its declared type; an absent parameter
     * falls back to its declared default, then to null when it is nullable;
     * anything else absent is a boundary failure. Wire keys matching no
     * parameter are ignored.
     *
     * @param class-string $class
     * @param array<array-key, mixed> $wireArgs keyed by constructor parameter name
     * @return list<mixed> positional constructor arguments
     * @throws SerializationException on a type/value mismatch or a missing required argument
     */
    public function denormalizeNamedArguments(string $class, array $wireArgs): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $wireArgs)) {
                $args[] = $this->denormalize($wireArgs[$name], $this->parameterType($param));
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new SerializationException(
                ErrorCode::BoundaryTypeMismatch,
                "Missing required argument {$name} constructing {$class}.",
            );
        }

        return $args;
    }

    /**
     * Denormalize positional arguments against a function/method signature. Used
     * by client and testing to coerce RPC args. Untyped parameters pass through.
     *
     * @param list<mixed> $args
     * @return list<mixed>
     */
    public function denormalizeArguments(array $args, \ReflectionFunctionAbstract $fn): array
    {
        $params = $fn->getParameters();
        $result = [];

        foreach (array_values($args) as $i => $value) {
            $param = $params[$i] ?? null;

            if ($param === null || ($param->isVariadic() && !isset($params[$i]))) {
                $param = $this->variadicParam($params);
            }

            if ($param === null) {
                $result[] = $value;
                continue;
            }

            $type = $this->parameterType($param);
            $result[] = $type === 'mixed' ? $value : $this->denormalize($value, $type);
        }

        return $result;
    }

    /**
     * @param list<\ReflectionParameter> $params
     */
    private function variadicParam(array $params): ?\ReflectionParameter
    {
        $last = end($params);

        return ($last !== false && $last->isVariadic()) ? $last : null;
    }

    /**
     * Render a parameter's declared type in the syntax denormalize() understands.
     */
    private function parameterType(\ReflectionParameter $param): string
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();

        if ($name === 'mixed') {
            return 'mixed';
        }

        return ($type->allowsNull() && $name !== 'null') ? '?' . $name : $name;
    }

    private function mismatch(mixed $data, string $type): SerializationException
    {
        return new SerializationException(
            ErrorCode::BoundaryTypeMismatch,
            sprintf('Cannot denormalize %s into %s.', $this->describe($data), $type),
        );
    }

    private function describe(mixed $data): string
    {
        if (is_object($data)) {
            return $data::class;
        }

        if (is_array($data)) {
            return 'array';
        }

        if (is_string($data)) {
            return "'" . $data . "'";
        }

        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }

        if ($data === null) {
            return 'null';
        }

        return (string) $data;
    }
}
