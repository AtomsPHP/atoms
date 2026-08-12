<?php

declare(strict_types=1);

namespace Atoms\Runtime;

use Atoms\Atom;

/**
 * Invokes an Atom's protected lifecycle hooks from outside the class. The
 * runtime and the test harness call these at activation/deactivation; they use
 * Closure::bind rather than reflection so the calls type-check cleanly.
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
        (function (): void {
            /** @var Atom<\Atoms\AtomMethods> $this */
            $this->onActivation();
        })->call($atom);
    }

    /**
     * @param Atom<\Atoms\AtomMethods> $atom
     */
    public static function deactivate(Atom $atom): void
    {
        (function (): void {
            /** @var Atom<\Atoms\AtomMethods> $this */
            $this->onDeactivation();
        })->call($atom);
    }

    /**
     * @param Atom<\Atoms\AtomMethods> $atom
     */
    public static function timer(Atom $atom, string $name): void
    {
        (function (string $name): void {
            /** @var Atom<\Atoms\AtomMethods> $this */
            $this->onTimer($name);
        })->call($atom, $name);
    }
}
