<?php

declare(strict_types=1);

namespace App\Pdo;

/** Strict-call-boundary cases shared by the managed, SQLite and native Atom drivers. */
final class QuoteCases
{
    /** @return array<string, array{string, \Closure(\PDO): mixed}> */
    public static function all(): array
    {
        return [
            'pdo.quote.plain' => [
                'a plain string',
                static fn (\PDO $p) => $p->quote('hello'),
            ],
            'pdo.quote.apostrophe' => [
                'a string containing an apostrophe is doubled, not backslash-escaped',
                static fn (\PDO $p) => $p->quote("O'Reilly"),
            ],
            'pdo.quote.empty' => [
                'the empty string',
                static fn (\PDO $p) => $p->quote(''),
            ],
            'pdo.quote.param_int' => [
                'quote() with PARAM_INT',
                static fn (\PDO $p) => $p->quote('42', \PDO::PARAM_INT),
            ],
            'pdo.quote.param_bool' => [
                'quote() with PARAM_BOOL',
                static fn (\PDO $p) => $p->quote(true, \PDO::PARAM_BOOL),
            ],
            'pdo.quote.param_null' => [
                'quote() with PARAM_NULL',
                static fn (\PDO $p) => $p->quote(null, \PDO::PARAM_NULL),
            ],
            'pdo.quote.param_lob' => [
                'quote() with PARAM_LOB',
                static fn (\PDO $p) => $p->quote('x', \PDO::PARAM_LOB),
            ],
            'pdo.quote.unknown_type' => [
                'quote() with an unrecognized $type',
                static fn (\PDO $p) => $p->quote('x', 999),
            ],
            'pdo.quote.nul_byte' => [
                'quote() on a value containing a NUL byte',
                static fn (\PDO $p) => $p->quote("a\0b"),
            ],
            'pdo.quote.utf8' => [
                'multi-byte UTF-8 content survives byte for byte',
                static fn (\PDO $p) => $p->quote('héllo €'),
            ],
        ];
    }
}
