<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\Serializer;
use Atoms\Testing\Internal\Boundary;

/**
 * Invokes the Methods handler with typed arguments and returns its decoded JSON result.
 */
final class FakeAppProxy
{
    public function __construct(
        private readonly ?object $methods,
        private readonly Serializer $serializer,
        private readonly ?\PDO $pdo = null,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($this->pdo?->inTransaction()) {
            $code = ErrorCode::CallbackInTransaction;

            throw new AtomsError($code, ErrorCatalog::format($code, ['method' => $name]));
        }

        if ($this->methods === null || !method_exists($this->methods, $name)) {
            $on = $this->methods === null ? 'no Methods class' : $this->methods::class;

            throw new \BadMethodCallException(
                "Call to undefined Methods method {$name}() ({$on} resolved for this harness).",
            );
        }

        $reflection = new \ReflectionMethod($this->methods, $name);
        $coerced = Boundary::roundTripArgs($arguments, $reflection, $this->serializer);
        $result = $reflection->invoke($this->methods, ...$coerced);

        return Boundary::wireValue($result, $this->serializer);
    }
}
