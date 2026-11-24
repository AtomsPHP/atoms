<?php

declare(strict_types=1);

namespace Atoms\Core\Tests;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCatalogTest extends TestCase
{
    public function testEveryEnumCaseHasAJsonEntry(): void
    {
        $catalog = ErrorCatalog::all();

        foreach (ErrorCode::cases() as $case) {
            self::assertArrayHasKey(
                $case->value,
                $catalog,
                "ErrorCode {$case->value} has no entry in errors.json",
            );
        }
    }

    public function testEveryJsonEntryHasAnEnumCase(): void
    {
        $known = array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases());

        foreach (array_keys(ErrorCatalog::all()) as $code) {
            self::assertContains(
                $code,
                $known,
                "errors.json code {$code} has no ErrorCode enum case",
            );
        }
    }

    public function testGetReturnsPopulatedEntry(): void
    {
        $entry = ErrorCatalog::get(ErrorCode::MonolithClassInAtom);

        self::assertSame(ErrorCode::MonolithClassInAtom, $entry->code);
        self::assertNotSame('', $entry->title);
        self::assertNotSame('', $entry->fix);
        self::assertSame('https://docs.atomsphp.dev/reference/errors/#atoms-e012', $entry->docsUrl);
        self::assertContains($entry->severity, ['error', 'warning']);
        self::assertNotSame('', $entry->phase);
    }

    public function testFormatSubstitutesPlaceholdersAndAppendsFix(): void
    {
        $message = ErrorCatalog::format(
            ErrorCode::MonolithClassInAtom,
            ['symbol' => 'App\\Models\\User'],
        );

        self::assertStringContainsString('ATOMS-E012', $message);
        self::assertStringContainsString('App\\Models\\User', $message);
        self::assertStringNotContainsString('{symbol}', $message);
        self::assertStringContainsString('Fix:', $message);
    }

    public function testAtomsErrorDefaultsMessageToCatalogTitle(): void
    {
        $error = new AtomsError(ErrorCode::TurnDeadlineExceeded);

        self::assertSame(ErrorCode::TurnDeadlineExceeded, $error->errorCode);
        self::assertSame(ErrorCatalog::get(ErrorCode::TurnDeadlineExceeded)->title, $error->getMessage());
    }

    public function testAtomsErrorKeepsExplicitMessageAndPrevious(): void
    {
        $previous = new \RuntimeException('cause');
        $error = new AtomsError(ErrorCode::MigrationFailed, 'custom', $previous);

        self::assertSame('custom', $error->getMessage());
        self::assertSame($previous, $error->getPrevious());
    }
}
