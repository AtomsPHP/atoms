<?php

declare(strict_types=1);

namespace Atoms\Core\Tests;

use Atoms\Attributes\MethodsFor;
use Atoms\Attributes\SharedWithAtoms;
use Atoms\Core\Tests\Fixtures\PlayerSnapshot;
use PHPUnit\Framework\TestCase;

#[SharedWithAtoms]
#[MethodsFor(PlayerSnapshot::class)]
final class AttributesTest extends TestCase
{
    public function testSharedWithAtomsIsReadableViaReflection(): void
    {
        $attributes = (new \ReflectionClass(self::class))->getAttributes(SharedWithAtoms::class);

        self::assertCount(1, $attributes);
        self::assertInstanceOf(SharedWithAtoms::class, $attributes[0]->newInstance());
    }

    public function testMethodsForCarriesAtomClass(): void
    {
        $attributes = (new \ReflectionClass(self::class))->getAttributes(MethodsFor::class);

        self::assertCount(1, $attributes);
        $instance = $attributes[0]->newInstance();
        self::assertInstanceOf(MethodsFor::class, $instance);
        self::assertSame(PlayerSnapshot::class, $instance->atomClass);
    }

    public function testAttributesTargetClassOnly(): void
    {
        foreach ([SharedWithAtoms::class, MethodsFor::class] as $attributeClass) {
            $attr = (new \ReflectionClass($attributeClass))->getAttributes(\Attribute::class);
            self::assertCount(1, $attr, "{$attributeClass} must be an #[Attribute]");
            self::assertSame(\Attribute::TARGET_CLASS, $attr[0]->newInstance()->flags);
        }
    }
}
