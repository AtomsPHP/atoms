<?php

declare(strict_types=1);

namespace Atoms;

/**
 * World B base class. A Methods class holds callback methods that run inside the
 * customer's monolith with full framework access, reached from an Atom via
 * {@see Atom::app()} (reverse RPC). This marker base carries no behavior — the
 * toolchain classifies subclasses by extension.
 */
abstract class AtomMethods
{
}
