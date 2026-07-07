<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

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
     * @return array{0: string, 1: string} [prefix, name]
     */
    private static function splitName(string $name): array
    {
        if (\strlen($name) <= 100) {
            return ['', $name];
        }

        $split = strrpos(substr($name, 0, 155), '/');
        if ($split === false) {
            // Cannot split cleanly; truncate the name field (best effort).
            return ['', substr($name, 0, 100)];
        }

        return [substr($name, 0, $split), substr($name, $split + 1)];
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
