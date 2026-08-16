<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * A minimal, deterministic ustar tar writer. PharData is deliberately avoided —
 * it stamps timestamps and orders entries non-deterministically. Every header
 * here pins mtime=0, uid/gid=0, and a fixed mode, so identical trees produce
 * byte-identical archives (conventions.md "Determinism").
 *
 * @phpstan-type TarEntry array{name: string, contents: string, mode?: int}
 */
final class TarWriter
{
    private const BLOCK = 512;

    /**
     * @param list<TarEntry> $entries in the exact order they should appear
     */
    public static function build(array $entries): string
    {
        $tar = '';
        foreach ($entries as $entry) {
            $mode = $entry['mode'] ?? 0644;
            $tar .= self::header($entry['name'], \strlen($entry['contents']), $mode);
            $tar .= $entry['contents'];
            $tar .= self::pad(\strlen($entry['contents']));
        }

        // Two zero blocks terminate the archive.
        $tar .= str_repeat("\0", self::BLOCK * 2);

        return $tar;
    }

    private static function header(string $name, int $size, int $mode): string
    {
        [$prefix, $shortName] = self::splitName($name);

        $header = '';
        $header .= self::field($shortName, 100);
        $header .= sprintf("%07o\0", $mode & 0777);
        $header .= sprintf("%07o\0", 0);            // uid
        $header .= sprintf("%07o\0", 0);            // gid
        $header .= sprintf("%011o\0", $size);
        $header .= sprintf("%011o\0", 0);           // mtime
        $header .= '        ';                        // checksum placeholder (8 spaces)
        $header .= '0';                              // typeflag: regular file
        $header .= self::field('', 100);            // linkname
        $header .= "ustar\0";                        // magic
        $header .= '00';                            // version
        $header .= self::field('', 32);             // uname
        $header .= self::field('', 32);             // gname
        $header .= self::field('', 8);              // devmajor
        $header .= self::field('', 8);              // devminor
        $header .= self::field($prefix, 155);
        $header .= self::field('', 12);             // padding to 512

        return self::withChecksum($header);
    }

    private static function withChecksum(string $header): string
    {
        $sum = 0;
        for ($i = 0, $len = \strlen($header); $i < $len; ++$i) {
            $sum += \ord($header[$i]);
        }

        $checksum = sprintf("%06o\0 ", $sum);

        return substr_replace($header, $checksum, 148, 8);
    }

    /**
     * Split a path across ustar's `prefix` (155 bytes) and `name` (100 bytes)
     * fields, which together address a path as `prefix . "/" . name`.
     *
     * A path that cannot be split is **refused**, never truncated. A truncated
     * entry goes into the archive under a name the manifest does not agree
     * with — a bundle invalid by construction, from a build that reported
     * success — and nothing downstream can recover the real path. The only
     * honest options are to encode it or refuse it, and refusing is the one
     * that does not invent a tar extension the reader will not implement.
     *
     * Splitting at the last `/` within the first 155 bytes is not sufficient
     * on its own: a path with a long tail splits "cleanly" there and still
     * loses bytes in the 100-byte name field, so what remains is checked too.
     *
     * @return array{0: string, 1: string} [prefix, name]
     * @throws AtomsError E078 when the path cannot be represented
     */
    private static function splitName(string $name): array
    {
        if (\strlen($name) <= 100) {
            return ['', $name];
        }

        // Scan right-to-left from the prefix limit for a separator that leaves
        // a representable name; the first one found gives the shortest tail.
        for ($i = min(\strlen($name) - 1, 155); $i > 0; $i--) {
            if ($name[$i] !== '/') {
                continue;
            }

            $prefix = substr($name, 0, $i);
            $tail = substr($name, $i + 1);

            if (\strlen($tail) <= 100) {
                return [$prefix, $tail];
            }
        }

        throw new AtomsError(
            ErrorCode::BundlePathTooLong,
            ErrorCatalog::format(ErrorCode::BundlePathTooLong, [
                'path' => $name,
                'bytes' => (string) \strlen($name),
            ]),
        );
    }

    private static function field(string $value, int $length): string
    {
        return str_pad(substr($value, 0, $length), $length, "\0");
    }

    private static function pad(int $size): string
    {
        $remainder = $size % self::BLOCK;

        return $remainder === 0 ? '' : str_repeat("\0", self::BLOCK - $remainder);
    }
}
