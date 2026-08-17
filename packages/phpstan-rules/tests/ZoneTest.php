<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests;

use Atoms\PHPStan\Zone;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the normalization Zone now performs once, in its constructor —
 * previously three separate, divergent normalizers inside LayeringRule.
 * LayeringRuleTest already covers the rule end to end; these are the
 * unit-level guards for the individual clauses that were easy to drop while
 * consolidating.
 */
final class ZoneTest extends TestCase
{
    public function testForbiddenPrefixMatchesANamespacedSymbolUnderIt(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['Illuminate'], allow: []);

        self::assertTrue($zone->isForbidden('Illuminate\Support\Str'));
    }

    /**
     * The `strlen`-prefix-as-data clause: a symbol that IS just the prefix
     * plus a separator is namespace-prefix data, not a reference.
     */
    public function testBarePrefixWithNothingAfterTheSeparatorIsData(): void
    {
        $zone = new Zone(paths: ['packages/cli/src'], forbid: ['Illuminate'], allow: []);

        self::assertFalse($zone->isForbidden('Illuminate\\'));
        self::assertFalse($zone->isForbidden('Illuminate'));
        self::assertTrue($zone->isForbidden('Illuminate\A'));
    }

    /**
     * A bare prefix must never match prose that merely contains it as a
     * substring — the separator is a backslash, never a forward slash.
     */
    public function testPrefixDoesNotMatchAForwardSlashedSpelling(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['Laravel'], allow: []);

        self::assertFalse($zone->isForbidden('Laravel/Symfony'));
        self::assertFalse($zone->isForbidden('LaravelThing\Foo'));
    }

    public function testConfiguredPrefixesAreTrimmedOfStrayBackslashes(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['\Illuminate\\'], allow: []);

        self::assertTrue($zone->isForbidden('\Illuminate\Support\Str'));
        self::assertTrue($zone->isForbidden('Illuminate\Support\Str'));
    }

    public function testEmptyPrefixEntriesAreDropped(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['', '\\', 'Illuminate'], allow: ['', '\\']);

        self::assertFalse($zone->isForbidden('Atoms\Core\Thing'));
        self::assertTrue($zone->isForbidden('Illuminate\Support\Str'));
    }

    public function testAllowPrefixRescuesASymbolUnderAForbiddenPrefix(): void
    {
        $zone = new Zone(
            paths: ['packages/phpstan-rules/src'],
            forbid: ['Illuminate'],
            allow: ['Illuminate\Support\Facades'],
        );

        self::assertFalse($zone->isForbidden('Illuminate\Support\Facades\Facade'));
        self::assertTrue($zone->isForbidden('Illuminate\Support\Str'));
    }

    public function testSymbolsMentionedInFindsNamespacedMentionsInProse(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['Illuminate', 'Doctrine'], allow: []);

        $found = $zone->symbolsMentionedIn('/** @return \Illuminate\Support\Collection|Doctrine\ORM\Query */');

        self::assertSame(['Illuminate\Support\Collection', 'Doctrine\ORM\Query'], $found);
    }

    public function testSymbolsMentionedInIgnoresForwardSlashedProse(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['Laravel', 'Symfony'], allow: []);

        self::assertSame([], $zone->symbolsMentionedIn('/** Works under Laravel/Symfony alike. */'));
    }

    public function testSymbolsMentionedInDeduplicatesRepeatedMentions(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['Illuminate'], allow: []);

        $found = $zone->symbolsMentionedIn('Illuminate\Support\Str and again Illuminate\Support\Str');

        self::assertSame(['Illuminate\Support\Str'], $found);
    }

    /**
     * Prefixes are regex-quoted before they become an alternation, so a
     * configured prefix is matched literally rather than as a pattern.
     */
    public function testPrefixesAreRegexQuotedInTheDocblockScan(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: ['A.C'], allow: []);

        self::assertSame(['A.C\Thing'], $zone->symbolsMentionedIn('A.C\Thing'));
        self::assertSame([], $zone->symbolsMentionedIn('ABC\Thing'));
    }

    public function testZoneWithNoForbiddenPrefixesScansForNothing(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: [], allow: []);

        self::assertSame([], $zone->symbolsMentionedIn('Illuminate\Support\Str'));
        self::assertFalse($zone->isForbidden('Illuminate\Support\Str'));
    }

    public function testForbidsFrameworkGlobalsOnlyWhenTheFrameworkItselfIsForbidden(): void
    {
        $forbidding = new Zone(paths: ['packages/core/src'], forbid: ['\Illuminate\\'], allow: []);
        $viaSecondName = new Zone(paths: ['packages/core/src'], forbid: ['Doctrine', 'Laravel'], allow: []);
        $notForbidding = new Zone(paths: ['packages/laravel/src'], forbid: ['Doctrine'], allow: []);

        self::assertTrue($forbidding->forbidsFrameworkGlobals());
        self::assertTrue($viaSecondName->forbidsFrameworkGlobals());
        self::assertFalse($notForbidding->forbidsFrameworkGlobals());
    }

    public function testCoversMatchesOnNormalizedPathSegments(): void
    {
        $zone = new Zone(paths: ['packages/core/src'], forbid: [], allow: []);

        self::assertTrue($zone->covers('/home/someone/atoms/packages/core/src/Atom.php'));
        self::assertFalse($zone->covers('/home/someone/atoms/packages/client/src/Client.php'));
    }

    public function testFromArrayBuildsTheSameZoneAsTheConstructor(): void
    {
        $zone = Zone::fromArray([
            'paths' => ['packages/core/src'],
            'forbid' => ['Illuminate'],
            'allow' => ['Illuminate\Support\Facades'],
        ]);

        self::assertSame(['packages/core/src'], $zone->paths());
        self::assertTrue($zone->isForbidden('Illuminate\Support\Str'));
        self::assertFalse($zone->isForbidden('Illuminate\Support\Facades\Facade'));
    }
}
