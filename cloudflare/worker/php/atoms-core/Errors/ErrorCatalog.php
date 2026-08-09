<?php

declare(strict_types=1);

namespace Atoms\Errors;

/**
 * Loads and caches packages/core/resources/errors.json — the single source of
 * truth for the ATOMS-E### catalog — and renders canonical error messages.
 */
final class ErrorCatalog
{
    private const CATALOG_PATH = __DIR__ . '/../../resources/errors.json';

    private const DOCS_BASE = 'https://docs.atoms.cloud/errors#';

    /** @var array<string, CatalogEntry>|null keyed by code value */
    private static ?array $entries = null;

    private function __construct()
    {
    }

    public static function get(ErrorCode $code): CatalogEntry
    {
        $entries = self::load();

        if (!isset($entries[$code->value])) {
            throw new \RuntimeException("No catalog entry for error code {$code->value}.");
        }

        return $entries[$code->value];
    }

    /**
     * @return array<string, CatalogEntry> keyed by code value (e.g. "ATOMS-E012")
     */
    public static function all(): array
    {
        return self::load();
    }

    /**
     * Canonical rendering used by every package: the code, the message with
     * {placeholders} substituted from $context, and the catalog fix line.
     *
     * @param array<string, scalar|\Stringable> $context
     */
    public static function format(ErrorCode $code, array $context = []): string
    {
        $entry = self::get($code);

        $message = $entry->message;
        foreach ($context as $key => $value) {
            $message = str_replace('{' . $key . '}', (string) $value, $message);
        }

        return sprintf('%s: %s Fix: %s', $entry->code->value, $message, $entry->fix);
    }

    /**
     * @return array<string, CatalogEntry>
     */
    private static function load(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $raw = @file_get_contents(self::CATALOG_PATH);
        if ($raw === false) {
            throw new \RuntimeException('Could not read error catalog at ' . self::CATALOG_PATH);
        }

        /** @var array{errors: list<array{code: string, title: string, message: string, fix: string, severity: string, phase: string}>} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $entries = [];
        foreach ($decoded['errors'] as $row) {
            $entries[$row['code']] = new CatalogEntry(
                code: ErrorCode::from($row['code']),
                title: $row['title'],
                message: $row['message'],
                fix: $row['fix'],
                docsUrl: self::DOCS_BASE . strtolower($row['code']),
                severity: $row['severity'],
                phase: $row['phase'],
            );
        }

        return self::$entries = $entries;
    }
}
