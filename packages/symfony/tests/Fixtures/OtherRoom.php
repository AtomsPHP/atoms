<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

/**
 * Deliberately has no `OtherRoom\Methods` sibling — only resolvable via the
 * #[MethodsFor] override on {@see CustomOtherRoomMethods}, which the bundle
 * config's `methods_classes` entry must register for this to work. Proves
 * the DI wiring (not just the naming convention) drives resolution.
 */
final class OtherRoom
{
}
