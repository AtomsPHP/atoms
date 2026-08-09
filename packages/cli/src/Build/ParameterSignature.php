<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * One parameter of a Methods/Atom method or an AtomJob constructor, rendered for
 * the manifest and used for static arity checks.
 */
final class ParameterSignature
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $optional,
        public readonly bool $variadic,
        public readonly bool $hasDefault,
        public readonly mixed $default,
    ) {
    }

    /**
     * @return array{name: string, type: string, optional: bool, default?: mixed}
     */
    public function toManifest(): array
    {
        $out = [
            'name' => $this->name,
            'type' => $this->type,
            'optional' => $this->optional,
        ];
        if ($this->hasDefault) {
            $out['default'] = $this->default;
        }

        return $out;
    }
}
