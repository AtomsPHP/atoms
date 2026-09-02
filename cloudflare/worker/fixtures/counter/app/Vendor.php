<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

/**
 * Vendor — the fixture Atom for the bundle's vendor tree (runtime-spec.md §Bundle
 * format, the `vendor.autoload` manifest field; conformance check 45).
 *
 * A SEPARATE type, like Room/Boot/Scheduler/Probe before it, so the exact
 * turnsThisResidency and listener-record assertions of checks 3/11/12/16/17
 * are undisturbed.
 */
final class Vendor extends Atom
{
    /**
     * Touches both halves of the vendor autoloader in one turn: the classmap
     * (Greeter is declared in a shape the line-scanning autoloader cannot
     * index, so only the classmap can load it) and the eager function files.
     *
     * @return array{class: string, function: string, function_was_preloaded: bool}
     */
    public function viaVendor(): array
    {
        // Probed before the class is touched: the function file must have
        // been required at activation, not as a side effect of this turn.
        $preloaded = \function_exists('acme_greeting');

        return [
            'class' => \Acme\Greeter\Greeter::greet(),
            'function' => \acme_greeting(),
            'function_was_preloaded' => $preloaded,
        ];
    }
}
