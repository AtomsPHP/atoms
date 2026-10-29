<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/** Settings shared by the persistent nonce stores. */
final class NonceStoreOptions
{
    private function __construct(
        public readonly string $namespace,
        public readonly int $retentionSeconds,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config, int $timestampWindow): self
    {
        $namespace = $config['namespace'] ?? null;
        if (!is_string($namespace) || trim($namespace) === '' || str_contains($namespace, "\0")) {
            throw new NonceStoreConfigurationException('Set a nonempty nonce-store namespace for this application and environment.');
        }

        if ($timestampWindow < 0) {
            throw new NonceStoreConfigurationException('The callback timestamp window must be nonnegative.');
        }

        $allowance = self::integer($config['clock_allowance_seconds'] ?? 60, 'clock_allowance_seconds');
        if ($allowance === PHP_INT_MAX || $timestampWindow > intdiv(PHP_INT_MAX - $allowance - 1, 2)) {
            throw new NonceStoreConfigurationException('The callback timestamp window and clock allowance exceed the supported integer range.');
        }

        $minimum = 2 * $timestampWindow + 1 + $allowance;
        $retention = isset($config['retention_seconds'])
            ? self::integer($config['retention_seconds'], 'retention_seconds')
            : $minimum;
        if ($retention < $minimum) {
            throw new NonceStoreConfigurationException('Nonce retention must cover twice the timestamp window, one boundary second, and the clock allowance.');
        }
        // Redis stores expiration times in signed 64-bit milliseconds.
        $maximumExpiration = PHP_INT_SIZE >= 8 ? intdiv(PHP_INT_MAX, 1000) : PHP_INT_MAX;
        if ($retention > $maximumExpiration - time()) {
            throw new NonceStoreConfigurationException('Nonce retention exceeds the supported expiration range.');
        }

        return new self($namespace, $retention);
    }

    public function key(string $nonce): string
    {
        return hash('sha256', $this->namespace . "\0" . $nonce);
    }

    private static function integer(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value))
            || ($integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])) === false) {
            throw new NonceStoreConfigurationException($field . ' must be a nonnegative integer.');
        }

        return $integer;
    }
}
