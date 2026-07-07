<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * A callable signature: an ordered parameter list and a return type string.
 */
final class MethodSignature
{
    /**
     * @param list<ParameterSignature> $params
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $return,
    ) {
    }

    public function requiredCount(): int
    {
        $count = 0;
        foreach ($this->params as $param) {
            if ($param->optional) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    public function hasVariadic(): bool
    {
        foreach ($this->params as $param) {
            if ($param->variadic) {
                return true;
            }
        }

        return false;
    }

    public function totalCount(): int
    {
        return \count($this->params);
    }

    /**
     * Is $argc acceptable for this signature (required ≤ argc ≤ total)?
     */
    public function acceptsArgCount(int $argc): bool
    {
        if ($argc < $this->requiredCount()) {
            return false;
        }
        if ($this->hasVariadic()) {
            return true;
        }

        return $argc <= $this->totalCount();
    }

    /**
     * @return array{name: string, params: list<array<string, mixed>>, return: string}
     */
    public function toManifest(): array
    {
        return [
            'name' => $this->name,
            'params' => array_map(static fn (ParameterSignature $p): array => $p->toManifest(), $this->params),
            'return' => $this->return,
        ];
    }
}
