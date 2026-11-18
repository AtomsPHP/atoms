<?php

declare(strict_types=1);

namespace Atoms\Runtime;

use Atoms\Atom;

/**
 * Invokes an Atom's protected lifecycle hooks from outside the class. The
 * runtime and tests use these helpers for activation, deactivation, and timers.
 * Reflection supports AOT runtimes that cannot rebind a closure's object or scope.
 */
final class LifecycleInvoker
{
    private function __construct()
    {
    }

    /**
     * @param Atom<\Atoms\AtomMethods> $atom
     */
    public static function activate(Atom $atom): void
    {
        (new \ReflectionMethod($atom, 'onActivation'))->invoke($atom);
    }

    /**
     * @param Atom<\Atoms\AtomMethods> $atom
     */
    public static function deactivate(Atom $atom): void
    {
        (new \ReflectionMethod($atom, 'onDeactivation'))->invoke($atom);
    }

    /**
     * @param Atom<\Atoms\AtomMethods> $atom
     */
    public static function timer(Atom $atom, string $name): void
    {
        (new \ReflectionMethod($atom, 'onTimer'))->invoke($atom, $name);
    }
}
