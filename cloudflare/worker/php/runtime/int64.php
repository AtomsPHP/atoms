<?php

/**
 * The int64 tagging codec, PHP side (mvp-spec.md §Int64 tagging).
 *
 * JSON numbers are exact only through 2^53−1. Any integer outside
 * ±(2^53−1) crosses the PHP↔JS boundary as
 *
 *     {"$atoms_int64": "<decimal string>"}
 *
 * in SQL bindings, result rows, `last_insert_rowid`, method args and method
 * results. JS holds those as BigInt; PHP decodes them to native ints (this
 * php-wasm build is 64-bit-int capable). A tagged value outside int64 range is
 * an error, never a truncation.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

/** The wire key that marks a tagged 64-bit integer. */
const INT64_TAG = '$atoms_int64';

/** 2^53−1: the largest integer JSON can carry losslessly. */
const JSON_SAFE_INT = 9007199254740991;

/**
 * Recursively replace out-of-JSON-range ints with their tagged form.
 *
 * @param mixed $value
 * @return mixed
 */
function int64_encode($value)
{
    if (is_int($value)) {
        if ($value > JSON_SAFE_INT || $value < -JSON_SAFE_INT) {
            return [INT64_TAG => (string) $value];
        }

        return $value;
    }

    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = int64_encode($item);
        }

        return $out;
    }

    return $value;
}

/**
 * Recursively replace tagged integers with native ints.
 *
 * @param mixed $value
 * @return mixed
 * @throws \RuntimeException when a tag payload is not a canonical decimal
 *                           integer inside int64 range
 */
function int64_decode($value)
{
    if (!is_array($value)) {
        return $value;
    }

    if (count($value) === 1 && array_key_exists(INT64_TAG, $value)) {
        return int64_parse($value[INT64_TAG]);
    }

    $out = [];
    foreach ($value as $key => $item) {
        $out[$key] = int64_decode($item);
    }

    return $out;
}

/**
 * Parse a tag payload into a native int, refusing anything lossy.
 *
 * @param mixed $decimal
 * @return int
 * @throws \RuntimeException
 */
function int64_parse($decimal)
{
    if (!is_string($decimal) || !preg_match('/^-?(0|[1-9][0-9]*)$/', $decimal)) {
        throw new \RuntimeException(sprintf(
            'Atoms: %s payload must be a canonical decimal string, got %s.',
            INT64_TAG,
            var_export($decimal, true)
        ));
    }

    $native = (int) $decimal;

    // (int) saturates at PHP_INT_MIN/PHP_INT_MAX instead of overflowing, so a
    // round-trip comparison is an exact range check.
    if ((string) $native !== $decimal) {
        throw new \RuntimeException(sprintf(
            'Atoms: %s payload "%s" is outside the signed 64-bit range.',
            INT64_TAG,
            $decimal
        ));
    }

    return $native;
}
