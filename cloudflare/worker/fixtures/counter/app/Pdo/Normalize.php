<?php

declare(strict_types=1);

namespace App\Pdo;

/**
 * The differential harness's normal form (M1 design §2.5): every value or
 * throwable either side of a case produces is reduced to a tagged tree
 * compared with PHP `===` — recursive, type- and order-sensitive. Nothing is
 * compared shape-only anywhere; the only two exclusions from strict
 * comparison in the whole harness are exception MESSAGES and
 * `rowCount()` after a SELECT (handled by {@see Differential}, not here).
 */
final class Normalize
{
    private function __construct()
    {
    }

    /**
     * @return mixed the tagged tree
     */
    public static function value(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }

        if (is_bool($v)) {
            return ['b', $v];
        }

        if (is_int($v)) {
            // Decimal string so an int64 value survives the JSON hop to the
            // conformance runner without floating-point rounding.
            return ['i', (string) $v];
        }

        if (is_float($v)) {
            if (is_nan($v)) {
                return ['f', 'NAN'];
            }
            if (is_infinite($v)) {
                return ['f', $v > 0 ? 'INF' : '-INF'];
            }

            // 1.0 normalizes to ['f','1'], int 1 to ['i','1'] — deliberately
            // NOT the same tree, so a REAL read back as an int (the workerd
            // wire behaviour this milestone exists to surface) shows up as a
            // real inequality rather than being normalized away.
            return ['f', sprintf('%.17G', $v)];
        }

        if (is_string($v)) {
            return self::isValidUtf8($v)
                ? ['s', $v]
                : ['s64', base64_encode($v)];
        }

        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                // Key order and key TYPE both survive: value() on the key
                // distinguishes int 0 from string "0".
                $out[] = [self::value($k), self::value($item)];
            }

            return ['a', $out];
        }

        if ($v instanceof \Throwable) {
            return self::throwable($v);
        }

        if (is_object($v)) {
            return self::object($v);
        }

        // Resources, closures: nothing in the matrix should ever produce
        // one (design §2.4). Throwing here — rather than coercing — is what
        // makes a case that DOES produce one classify as 'error' (harness
        // breakage, never pinnable) instead of silently comparing garbage.
        throw new \RuntimeException(sprintf(
            'Normalize::value() cannot normalize a %s — the matrix must never produce one',
            gettype($v)
        ));
    }

    /**
     * Declared properties (including private/protected, read via reflection
     * — real PDO writes them directly, design §3 F-4/measured E13) in
     * declaration order, then dynamic properties in insertion order.
     *
     * @return array{0: 'o', 1: class-string, 2: list<array{0: string, 1: mixed}>}
     */
    private static function object(object $o): array
    {
        $props = [];
        $seen = [];

        $class = new \ReflectionClass($o);
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $property->setAccessible(true);

            try {
                if (!$property->isInitialized($o)) {
                    continue;
                }
                $value = $property->getValue($o);
            } catch (\Throwable $e) {
                continue;
            }

            $props[] = [$name, self::value($value)];
        }

        foreach (get_object_vars($o) as $name => $value) {
            if (isset($seen[$name])) {
                continue;
            }
            $props[] = [$name, self::value($value)];
        }

        return ['o', get_class($o), $props];
    }

    /**
     * @return array{0: 'e', 1: string, 2: string|null, 3: int|string|null}
     */
    public static function throwable(\Throwable $e): array
    {
        return ['e', self::family($e), self::sqlstate($e), self::driverCode($e)];
    }

    /**
     * The comparison unit for a refusal (design §2.4): messages are the
     * engine's own wording and version-specific, so family (+SQLSTATE when
     * flagged `sqlstate_strict`) carries the contract instead.
     */
    public static function family(\Throwable $e): string
    {
        if ($e instanceof \PDOException) {
            return 'PDOException'; // AtomsNotSupported IS one
        }
        if ($e instanceof \ValueError) {
            return 'ValueError';
        }
        if ($e instanceof \ArgumentCountError) {
            return 'ArgumentCountError';
        }
        if ($e instanceof \TypeError) {
            return 'TypeError';
        }
        if ($e instanceof \Error) {
            return 'Error';
        }

        return 'Exception';
    }

    /**
     * From `errorInfo[0]` when present, else a `SQLSTATE[...]` prefix on the
     * message, else null (design §2.4).
     */
    public static function sqlstate(\Throwable $e): ?string
    {
        if (
            $e instanceof \PDOException
            && is_array($e->errorInfo)
            && isset($e->errorInfo[0])
            && $e->errorInfo[0] !== ''
        ) {
            return (string) $e->errorInfo[0];
        }

        if (preg_match('/^SQLSTATE\[([^\]]+)\]/', $e->getMessage(), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return int|string|null
     */
    public static function driverCode(\Throwable $e)
    {
        if ($e instanceof \PDOException && is_array($e->errorInfo) && array_key_exists(1, $e->errorInfo)) {
            return $e->errorInfo[1];
        }

        return null;
    }

    /**
     * JSON of the normalized tree, truncated to 512 characters — human
     * evidence in a failure message, never the basis of the comparison
     * (design §2.10).
     */
    public static function render(mixed $tree): string
    {
        $json = json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json)) {
            $json = '(unrenderable: ' . json_last_error_msg() . ')';
        }

        if (strlen($json) > 512) {
            $extra = strlen($json) - 512;
            $json = substr($json, 0, 512) . "…(+{$extra})";
        }

        return $json;
    }

    private static function isValidUtf8(string $s): bool
    {
        return $s === '' || preg_match('//u', $s) === 1;
    }
}
