<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures;

use Atoms\Atom;

/**
 * Minimal Atom fixture for the adapter conformance suite. It exists only so
 * {@see Fixtures\GameRoom\Methods}'s `#[MethodsFor(GameRoom::class)]` has a
 * real Atom class-string to resolve, and so
 * {@see \Atoms\Tests\Integration\Adapters\Host\AdapterHost::service()}-based
 * client cases (S1/S2) have a wire type to invoke against. It is never
 * instantiated by the callback path itself — {@see \Atoms\Client\Callback\CallbackKernel}
 * only ever instantiates the Methods class, never the Atom — so nothing here
 * needs to override the base class.
 */
final class GameRoom extends Atom
{
}
