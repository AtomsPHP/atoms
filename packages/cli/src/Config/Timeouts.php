<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

/**
 * The CLI's configuration layer for subprocess deadlines: each one reads an
 * environment variable with a default, so no capacity number lives in
 * implementation code.
 */
final class Timeouts
{
    private const COMPOSER_INSTALL_DEFAULT_SECONDS = 600.0;

    private function __construct()
    {
    }

    /**
     * Budget for the vendor stage's `composer install`, which may hit the
     * network. ATOMS_COMPOSER_TIMEOUT, seconds.
     *
     * @throws \InvalidArgumentException on a set but unusable value — a
     *         misconfigured deadline is refused, never silently defaulted
     */
    public static function composerInstall(): float
    {
        $env = getenv('ATOMS_COMPOSER_TIMEOUT');
        if ($env === false || $env === '') {
            return self::COMPOSER_INSTALL_DEFAULT_SECONDS;
        }

        $seconds = is_numeric($env) ? (float) $env : null;
        if ($seconds === null || !is_finite($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException(sprintf(
                'ATOMS_COMPOSER_TIMEOUT must be a positive number of seconds; got %s.',
                var_export($env, true),
            ));
        }

        return $seconds;
    }
}
