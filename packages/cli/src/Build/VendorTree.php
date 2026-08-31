<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * The product of the vendor stage: the files to add to the bundle, and the
 * provenance the manifest records about them.
 */
final class VendorTree
{
    /**
     * @param list<array{name: string, contents: string}> $entries bundle-relative
     *        vendor files, sorted by name; includes the generated autoload file
     * @param array<string, string>                       $packages resolved
     *        package => version, from Composer's own installed.json
     * @param bool                                        $wroteLock whether this
     *        resolution wrote atoms-composer.lock back next to atoms-composer.json
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $packages,
        public readonly bool $wroteLock,
    ) {
    }
}
