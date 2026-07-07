<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Serialization\Serializer;
use Atoms\Testing\Internal\Boundary;

/**
 * The harness's `app()` proxy: reverse-RPC into a real Methods (World B)
 * instance, in-process. Every call round-trips its arguments and return value
 * through {@see Serializer} exactly as the production callback path would, so
 * a Methods method that isn't boundary-safe fails the test the same way it
 * would fail against the real platform.
 */
final class FakeAppProxy
{
    public function __construct(
        private readonly ?object $methods,
        private readonly Serializer $serializer,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($this->methods === null || !method_exists($this->methods, $name)) {
            $on = $this->methods === null ? 'no Methods class' : $this->methods::class;

            throw new \BadMethodCallException(
                "Call to undefined Methods method {$name}() ({$on} resolved for this harness).",
            );
        }

        $reflection = new \ReflectionMethod($this->methods, $name);
        $coerced = Boundary::roundTripArgs($arguments, $reflection, $this->serializer);
        $result = $reflection->invoke($this->methods, ...$coerced);

        return Boundary::roundTripReturn($result, $reflection->getReturnType(), $this->serializer);
    }
}
