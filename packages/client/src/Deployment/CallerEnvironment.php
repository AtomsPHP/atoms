<?php

declare(strict_types=1);

namespace Atoms\Client\Deployment;

/**
 * The environment as it was when the process started — before any framework
 * bootstrap had a chance to add to it.
 *
 * ## The problem this exists for
 *
 * Atoms deployment configuration resolves in one order: an explicit CLI flag,
 * then the caller's environment, then `.env.atoms.<environment>`, then
 * atoms.json. That order is correct, and it is defeated by an entry point that
 * quietly widens what "the caller's environment" means.
 *
 * `php artisan atoms:deploy --env production` is exactly that entry point.
 * Laravel loads the application's `.env` during bootstrap, long before the
 * Artisan command runs; the `atoms` child process it then starts inherits the
 * result. A developer with `ATOMS_CALLBACK_URL=http://localhost:8000/...` in
 * their local `.env` — a perfectly ordinary thing to have — would deploy that
 * URL to production, outranking the committed one, with nothing to see.
 *
 * The precedence rule is not what is wrong there. The inputs are: application
 * configuration became a deployment input by accident. Which sources
 * participate and which source wins are two separate decisions, and this class
 * settles the first one.
 *
 * ## The mechanism
 *
 * A `files` autoload entry in this package's composer.json calls
 * {@see self::capture()} while `vendor/autoload.php` is still being included —
 * before `bootstrap/app.php`, before any kernel, before any dotenv loader,
 * because every one of those is itself autoloaded. Whatever is in the
 * environment at that moment came from the shell, from CI, or from a process
 * supervisor, and nothing else.
 *
 * {@see self::restoration()} then turns the snapshot into the environment map
 * a child process should be given, and the adapter packages' `atoms:*` console
 * wrappers hand it to the process they start. Values the caller supplied
 * survive; values a bootstrap added are removed. This package cannot name
 * those wrappers — it sits below them — but they are the only callers, and
 * their own docblocks point back here.
 *
 * Comparing against the application's dotenv files afterwards would not do:
 * an identical value might legitimately have been exported by the shell, and
 * removing it would break the override the contract promises.
 *
 * When this class was never captured — the standalone `vendor/bin/atoms`
 * binary, where the process environment *is* the caller's environment —
 * {@see self::restoration()} returns an empty map and the child inherits
 * normally. There is nothing to correct.
 */
final class CallerEnvironment
{
    /** @var array<string, string>|null */
    private static ?array $captured = null;

    /**
     * Record the environment. The first call wins: a second one would capture
     * a bootstrap that has already run, which is the very thing this guards
     * against.
     */
    public static function capture(): void
    {
        self::$captured ??= self::current();
    }

    public static function isCaptured(): bool
    {
        return self::$captured !== null;
    }

    /**
     * The captured environment, or null when nothing captured one.
     *
     * @return array<string, string>|null
     */
    public static function all(): ?array
    {
        return self::$captured;
    }

    /**
     * The environment map for a child process: every name restored to the
     * value the caller supplied, and every name a bootstrap has added since
     * set to `false`, which is how Symfony's Process spells "unset".
     *
     * Empty when nothing was captured, so an inheriting child stays
     * inheriting.
     *
     * @return array<string, string|false>
     */
    public static function restoration(): array
    {
        if (self::$captured === null) {
            return [];
        }

        // Every name visible now starts as "remove"; the snapshot then puts
        // back what the caller actually supplied. A name the caller supplied
        // and a bootstrap has since removed comes back too.
        $removals = array_fill_keys(array_keys(self::current()), false);

        return [...$removals, ...self::$captured];
    }

    /** Test seam: forget the snapshot so a test can capture its own. */
    public static function forget(): void
    {
        self::$captured = null;
    }

    /**
     * Test seam: capture a specific environment rather than this process's.
     *
     * @param array<string, string> $values
     */
    public static function captureFor(array $values): void
    {
        self::$captured = $values;
    }

    /**
     * The environment as a child process would receive it.
     *
     * `getenv()` is what a child inherits, and `$_ENV` is where PHP dotenv
     * loaders write — Symfony's Process merges the second over the first when
     * it builds a child's environment, so both are part of "the environment"
     * and both are what a bootstrap adds to. `$_SERVER` contributes no names
     * of its own, there and here alike: in CLI it also carries `argv`, `argc`
     * and request-shaped keys that were never environment variables.
     *
     * @return array<string, string>
     */
    private static function current(): array
    {
        $values = [];

        foreach (getenv() as $name => $value) {
            $values[(string) $name] = $value;
        }
        foreach ($_ENV as $name => $value) {
            $values[(string) $name] = $value;
        }

        return $values;
    }
}
