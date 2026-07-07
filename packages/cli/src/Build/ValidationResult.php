<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * The outcome of `atoms validate`: the generated manifest plus every finding,
 * split into errors (fail the build) and warnings (informational).
 */
final class ValidationResult
{
    /** @var list<Violation> */
    public readonly array $errors;

    /** @var list<Violation> */
    public readonly array $warnings;

    /**
     * @param array<string, mixed> $manifest
     * @param list<Violation>      $violations
     */
    public function __construct(
        public readonly array $manifest,
        public readonly BundleFileSet $bundleFiles,
        array $violations,
    ) {
        $errors = [];
        $warnings = [];
        foreach ($violations as $violation) {
            if ($violation->isError()) {
                $errors[] = $violation;
            } else {
                $warnings[] = $violation;
            }
        }
        $this->errors = $errors;
        $this->warnings = $warnings;
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function manifestHash(): string
    {
        return ManifestHash::of($this->manifest);
    }
}
