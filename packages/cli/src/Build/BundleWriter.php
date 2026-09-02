<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * Stage 7: write the deterministic bundle. The tar is built from the sorted
 * bundle file set; its raw-bytes sha256 is the content hash that both names the
 * archive and is stamped into the manifest. The gzip wrapper is normalized
 * (mtime zeroed, OS byte fixed) so `.tar.gz` bytes are reproducible too.
 */
final class BundleWriter
{
    /**
     * @param array<string, mixed> $manifest without content_hash
     */
    public function write(
        string $outDir,
        BundleFileSet $files,
        array $manifest,
        ValidationResult $validation,
        ?VendorTree $vendor = null,
    ): BuildResult {
        if (!is_dir($outDir) && !@mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Could not create output directory {$outDir}");
        }

        $entries = [];
        foreach ($files->files as $file) {
            // Memoized bytes from validation time: the archive contents, the
            // manifest's content_hash and its scoper_prefix all describe the
            // same snapshot of the tree, even if a file changes on disk while
            // the build runs.
            $entries[] = ['name' => $file['relative'], 'contents' => $files->contentsOf($file)];
        }
        foreach ($vendor === null ? [] : $vendor->entries as $entry) {
            $entries[] = $entry;
        }
        // One global order over customer + vendor entries keeps the archive a
        // pure function of its contents, wherever they came from.
        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $tar = TarWriter::build($entries);
        $contentHash = hash('sha256', $tar);

        $manifest['content_hash'] = $contentHash;

        $bundlePath = rtrim($outDir, '/') . "/bundle-{$contentHash}.tar.gz";
        $manifestPath = rtrim($outDir, '/') . '/manifest.json';

        file_put_contents($bundlePath, self::gzipNormalized($tar));
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );

        return new BuildResult($bundlePath, $manifestPath, $contentHash, $manifest, $validation, $vendor);
    }

    /**
     * gzip with a zeroed MTIME (bytes 4–7) and a fixed OS byte (9 = Unix), so the
     * compressed stream is a pure function of its input.
     */
    private static function gzipNormalized(string $data): string
    {
        $gz = gzencode($data, 9);
        if ($gz === false) {
            throw new \RuntimeException('gzip encoding failed');
        }

        $gz = substr_replace($gz, "\0\0\0\0", 4, 4); // MTIME = 0
        $gz = substr_replace($gz, "\x03", 9, 1);      // OS = Unix

        return $gz;
    }
}
