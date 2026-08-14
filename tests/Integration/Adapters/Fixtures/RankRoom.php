<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures;

use Atoms\Atom;

/**
 * S6's Atom fixture, playing the same role {@see GameRoom} plays for
 * {@see GameRoom\Methods}: it exists only so
 * {@see RankRoom\MethodsWithDependency}'s `#[MethodsFor(RankRoom::class)]`
 * has a real Atom class-string to resolve. Never instantiated by the
 * callback path itself — {@see \Atoms\Client\Callback\CallbackKernel} only
 * ever instantiates the Methods class.
 */
final class RankRoom extends Atom
{
}
