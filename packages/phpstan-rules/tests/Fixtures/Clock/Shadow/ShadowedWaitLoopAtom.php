<?php

declare(strict_types=1);

// Namespace deliberately distinct from Fixtures\Clock (WaitLoopAtom.php
// etc.) for the same reason as ShadowedSleepAtom.php — see its header
// comment.
namespace Atoms\PHPStan\Tests\Fixtures\Clock\Shadow;

use Atoms\Atom;

/**
 * ATOM_SIDE Atom in a namespace that defines its own time()/microtime()/
 * hrtime()/gettimeofday() functions and its own DateTimeImmutable class
 * below, then reads every one of them unqualified in the same wait-loop
 * shapes WaitLoopAtom uses to trigger ATOMS-E102.
 *
 * For the functions, PHP resolves an unqualified call to a namespace-local
 * function of the same name before ever falling back to the global built-in
 * (see AtomTimeWaitLoopRule's docblock), so none of these reads reach the
 * dangerous global clock function. Requires the corresponding test to
 * `require_once` this file before calling `analyse()` — see
 * ShadowedSleepAtom.php's docblock for why.
 *
 * For `new DateTimeImmutable()`, PHP resolves an unqualified class name
 * entirely at compile time — to the current namespace unless a `use` import
 * says otherwise, with no runtime fallback to the global class — so this
 * always instantiates the local stub below, never \DateTimeImmutable. This
 * half needs no `require_once`: it is resolved by php-parser's NameResolver
 * while the fixture is parsed, not by reflection.
 *
 * Asserted to produce zero AtomTimeWaitLoopRule errors — this is the
 * ATOMS-E102 false-positive this fixture exists to prove fixed.
 */
final class ShadowedWaitLoopAtom extends Atom
{
    public function waitsOnShadowedWhileCondition(int $deadline): void
    {
        while (time() < $deadline) {
            $noop = 1;
        }
    }

    public function waitsOnShadowedDoWhileCondition(int $deadline): void
    {
        do {
            $noop = 1;
        } while (microtime() < $deadline);
    }

    public function waitsOnShadowedForCondition(int $deadline): void
    {
        for ($i = 0; hrtime() < $deadline; $i++) {
            $noop = $i;
        }
    }

    public function waitsUnconditionallyOnShadowedBodyRead(int $deadline): void
    {
        while (true) {
            if (gettimeofday() > $deadline) {
                break;
            }
        }
    }

    public function waitsOnShadowedDateTimeImmutableCondition(int $deadline): void
    {
        while ((new DateTimeImmutable())->timestamp < $deadline) {
            $noop = 1;
        }
    }
}

function time(): int
{
    // Namespace-local override — never the dangerous global.
    return 0;
}

function microtime(): int
{
    // Namespace-local override — never the dangerous global.
    return 0;
}

function hrtime(): int
{
    // Namespace-local override — never the dangerous global.
    return 0;
}

function gettimeofday(): int
{
    // Namespace-local override — never the dangerous global.
    return 0;
}

/**
 * Namespace-local stub — never \DateTimeImmutable. An unqualified
 * `new DateTimeImmutable()` written anywhere else in this namespace resolves
 * here at compile time, not to the global class.
 */
final class DateTimeImmutable
{
    public int $timestamp = 0;
}
