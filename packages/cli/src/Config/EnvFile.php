<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

/**
 * Reads and upserts a single `KEY=VALUE` line in a dotenv-style file.
 *
 * One parser for both files `atoms dev` keeps in sync — the Worker's
 * `.dev.vars` (via {@see \Atoms\Cli\Cloudflare\DevVars}) and the app's `.env`
 * — so a secret copied between them cannot change shape in transit.
 *
 * Not a full dotenv implementation: no interpolation, `export` prefixes, or
 * multi-line values. Anything it does not understand is left alone.
 */
final class EnvFile
{
    /** The value of $key, or null when the file or the key is missing. */
    public static function read(string $path, string $key): ?string
    {
        $contents = @file_get_contents($path);
        if (!\is_string($contents)) {
            return null;
        }

        if (preg_match(self::pattern($key), $contents, $m) !== 1) {
            return null;
        }

        $value = self::unquote(trim(rtrim($m[2], "\r")));

        return $value === '' ? null : $value;
    }

    /**
     * Set $key to $value, replacing an existing line where it sits (ordering
     * and comments survive) or appending one. A file created here gets mode
     * 0600 — every caller is writing a secret.
     *
     * @param string $comment comment lines (no leading `#`) written above a
     *                        newly appended key
     *
     * @return bool true when this call created the file
     */
    public static function write(string $path, string $key, string $value, string ...$comment): bool
    {
        $existing = @file_get_contents($path);
        $isNew = !\is_string($existing);
        $line = $key . '=' . $value;

        if (!$isNew && preg_match(self::pattern($key), $existing) === 1) {
            $replaced = preg_replace(self::pattern($key), '$1' . self::escapeReplacement($line), $existing, 1);
            if (\is_string($replaced)) {
                file_put_contents($path, $replaced, \LOCK_EX);

                return false;
            }
        }

        $prefix = $isNew || $existing === '' || str_ends_with((string) $existing, "\n") ? '' : "\n";
        $block = $prefix
            . ($isNew ? '' : "\n")
            . implode('', array_map(static fn (string $c): string => '# ' . $c . "\n", $comment))
            . $line . "\n";

        file_put_contents($path, $block, \FILE_APPEND | \LOCK_EX);

        if ($isNew) {
            chmod($path, 0600);
        }

        return $isNew;
    }

    /** Anchored per-line, so a key never matches inside another's value. */
    private static function pattern(string $key): string
    {
        // Group 1 is the line's indentation, kept so a rewrite lands where the
        // line already sat; group 2 is the value.
        return '/^([ \t]*)' . preg_quote($key, '/') . '[ \t]*=[ \t]*(.*)$/m';
    }

    private static function unquote(string $value): string
    {
        if (\strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /** `\` and `$` are backreference syntax in a preg_replace replacement. */
    private static function escapeReplacement(string $literal): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $literal);
    }
}
