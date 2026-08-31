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
     * @param list<string>                                $prunedDataFiles
     *        bundle-relative vendor files that LOOK like runtime data
     *        (.json/.txt/.csv/…) but were pruned by the ship rule (.php +
     *        LICENSE only) — surfaced so a package that reads one at runtime
     *        fails with a named cause at build output, not a bare guest error
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $packages,
        public readonly bool $wroteLock,
        public readonly array $prunedDataFiles = [],
    ) {
    }
}
