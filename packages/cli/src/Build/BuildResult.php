<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * The product of `atoms build`: the written bundle + manifest paths, the content
 * hash that addresses the bundle, and the finalized manifest (with content_hash).
 */
final class BuildResult
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly string $bundlePath,
        public readonly string $manifestPath,
        public readonly string $contentHash,
        public readonly array $manifest,
        public readonly ValidationResult $validation,
        public readonly ?VendorTree $vendor = null,
    ) {
    }

    public function manifestHash(): string
    {
        return ManifestHash::of($this->manifest);
    }
}
