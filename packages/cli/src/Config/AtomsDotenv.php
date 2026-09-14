<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The optional per-target environment file: `.env.atoms.<environment>`, beside
 * atoms.json.
 *
 * ## Why Atoms owns a dotenv convention at all
 *
 * The deployment inputs must be the same whichever entry point a user reaches
 * them through — `vendor/bin/atoms deploy`, `php artisan atoms:deploy`, or a
 * Symfony console wrapper. An application's own `.env` cannot serve that
 * purpose: a framework loads it for the *application's* environment, which is
 * a different axis from the Atoms deployment target, and a local value in it
 * would then decide a production deploy. So Atoms reads one file it selects
 * itself, from the target the user named.
 *
 * ## Selection
 *
 * The target is chosen first — `--env production` — and only then is
 * `.env.atoms.production` looked for, beside atoms.json, so every entry point
 * lands on the same file. There is deliberately **no** fallback: not to the
 * application's `.env`, not to another target's file, not to a generic
 * `.env.atoms`, and not to framework variants like `.env.local`. A file that
 * is absent simply contributes nothing; resolution continues with the caller's
 * environment and atoms.json. Shared non-secret defaults already have a home
 * in atoms.json, and shared credentials belong to CI or a secret manager.
 *
 * ## Layering
 *
 * This is a source consulted *below* the caller's own environment, not an
 * export: nothing here is putenv'd, so a value the shell or CI already
 * supplied keeps winning, and nothing in this file leaks into the Wrangler
 * child process except by way of a setting Atoms resolves and passes
 * deliberately.
 *
 * ## Syntax
 *
 * `KEY=value` per line, optionally `export KEY=value`. Blank lines and lines
 * whose first non-blank character is `#` are ignored. A value may be
 * single-quoted (taken literally), double-quoted (`\n`, `\t`, `\"`, `\\`
 * unescape), or bare — a bare value ends at an unquoted ` #`, and is trimmed.
 * There is no interpolation and no multi-line value: this file supplies
 * values, it does not compute them. A later line with the same key replaces an
 * earlier one, as every dotenv tool does. Anything else is ATOMS-E109 naming
 * the file and the line.
 *
 * @see EnvFile for the single-key upsert `atoms dev` uses on `.env`/`.dev.vars`;
 *      this class never writes.
 */
final class AtomsDotenv
{
    /** `.env.atoms.production` for the `production` target. */
    public const FILE_PREFIX = '.env.atoms.';

    /**
     * @param array<string, string> $values
     */
    private function __construct(
        public readonly ?string $path,
        public readonly array $values,
    ) {
    }

    /** The file name this target would use, whether or not it exists. */
    public static function fileName(string $environment): string
    {
        return self::FILE_PREFIX . $environment;
    }

    /**
     * Load the file for $environment from $rootDir, or an empty set when there
     * is none.
     *
     * @throws AtomsError E109 when the file exists but cannot be read or parsed
     */
    public static function forTarget(string $rootDir, string $environment): self
    {
        $path = rtrim($rootDir, '/') . '/' . self::fileName($environment);
        if (!is_file($path)) {
            return new self(null, []);
        }

        $raw = @file_get_contents($path);
        if (!\is_string($raw)) {
            throw self::invalid($path, 'the file exists but could not be read');
        }

        return new self($path, self::parse($raw, $path));
    }

    /** The value of $name, or null when this file does not set it or sets it blank. */
    public function value(string $name): ?string
    {
        $value = $this->values[$name] ?? null;
        // Blank means unset here exactly as it does in the process
        // environment and in atoms.json; every source must agree on what "no
        // value" is, or the answer depends on which one supplied it.
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * @return array<string, string>
     * @throws AtomsError E109
     */
    private static function parse(string $raw, string $path): array
    {
        $values = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $raw)) as $index => $line) {
            $number = $index + 1;
            $line = rtrim($line, "\r");
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $split = strpos($trimmed, '=');
            if ($split === false) {
                throw self::invalid($path, "line {$number} is not a KEY=VALUE assignment");
            }

            $key = rtrim(substr($trimmed, 0, $split));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                throw self::invalid($path, "line {$number} has an invalid variable name '{$key}'");
            }

            $values[$key] = self::parseValue(ltrim(substr($trimmed, $split + 1)), $path, $number);
        }

        return $values;
    }

    /**
     * @throws AtomsError E109
     */
    private static function parseValue(string $value, string $path, int $number): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if ($quote !== '"' && $quote !== "'") {
            // Bare: a ` #` outside quotes starts a trailing comment, matching
            // what every dotenv reader does. A `#` with no leading space is
            // part of the value — passwords and fragments contain them.
            $comment = preg_split('/\s+#/', $value, 2);
            return rtrim($comment[0]);
        }

        $closed = false;
        $out = '';
        for ($i = 1, $len = \strlen($value); $i < $len; $i++) {
            $char = $value[$i];
            if ($quote === '"' && $char === '\\' && $i + 1 < $len) {
                $next = $value[++$i];
                $out .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '"', '\\', '$' => $next,
                    default => '\\' . $next,
                };
                continue;
            }
            if ($char === $quote) {
                $closed = true;
                $rest = trim(substr($value, $i + 1));
                if ($rest !== '' && !str_starts_with($rest, '#')) {
                    throw self::invalid($path, "line {$number} has trailing characters after the closing {$quote}");
                }
                break;
            }
            $out .= $char;
        }

        if (!$closed) {
            throw self::invalid($path, "line {$number} opens a {$quote} quote that is never closed "
                . '(multi-line values are not supported)');
        }

        return $out;
    }

    private static function invalid(string $path, string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::AtomsEnvFileInvalid,
            ErrorCatalog::format(ErrorCode::AtomsEnvFileInvalid, [
                'file' => $path,
                'reason' => $reason,
            ]),
        );
    }
}
