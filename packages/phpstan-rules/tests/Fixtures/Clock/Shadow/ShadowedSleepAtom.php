<?php

declare(strict_types=1);

// Namespace deliberately distinct from Fixtures\Clock (SleepAtom.php etc.):
// PHP function resolution is name-based, not file-based — once a namespaced
// function is declared anywhere in the process, every unqualified call to
// that name from that same namespace resolves to it. Isolating the shadow
// functions in their own namespace keeps this fixture from ever leaking into
// — or being leaked into by — the MUST-FAIL fixtures that share a namespace.
namespace Atoms\PHPStan\Tests\Fixtures\Clock\Shadow;

use Atoms\Atom;

/**
 * WORLD_A Atom in a namespace that defines its own sleep()/usleep()/
 * time_nanosleep()/time_sleep_until() functions below and calls every one of
 * them unqualified. PHP resolves an unqualified call to a namespace-local
 * function of the same name before ever falling back to the global built-in
 * (see AtomSleepCallRule's docblock), so none of these calls reach the
 * dangerous global sleep-family function. Asserted to produce zero
 * AtomSleepCallRule errors — this is the ATOMS-E101 false-positive this
 * fixture exists to prove fixed.
 *
 * Requires the corresponding test to `require_once` this file before calling
 * `analyse()`: PHPStan's test-harness reflection locates a user-defined
 * function by asking the real PHP runtime's `function_exists()`/
 * `ReflectionFunction`, unlike class reflection, which it can find from a
 * PSR-4 path alone. See AtomSleepCallRuleTest::testShadowedSleepFamilyHasNoViolations().
 */
final class ShadowedSleepAtom extends Atom
{
    public function callsShadowedSleepFamily(): void
    {
        sleep(1);
        usleep(100);
        time_nanosleep(0, 100);
        time_sleep_until(1.0);
    }
}

function sleep(int $seconds): void
{
    // Namespace-local override — never the dangerous global. A call to
    // sleep() unqualified from within this namespace resolves here.
}

function usleep(int $microseconds): void
{
    // Namespace-local override — never the dangerous global.
}

function time_nanosleep(int $seconds, int $nanoseconds): bool
{
    // Namespace-local override — never the dangerous global.
    return true;
}

function time_sleep_until(float $timestamp): bool
{
    // Namespace-local override — never the dangerous global.
    return true;
}
