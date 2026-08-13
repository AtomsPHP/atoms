<?php

declare(strict_types=1);

namespace App\Pdo;

/**
 * The reflection tripwire's allowlist (M1 design §1.3): the members that
 * cannot be enumerated-and-declared like every other member of \PDO /
 * \PDOStatement, each with a written justification AND a runtime assertion
 * that SurfaceAudit actually runs.
 *
 * An entry without an `assert` closure is itself a violation — see
 * SurfaceAudit's R3 handling. That is the whole answer to "an allowlist is
 * just a list of things you decided not to check": nothing here is merely
 * asserted in prose.
 *
 * Kept deliberately short. Conformance check 26 asserts the exact id SET,
 * not just a length cap, so a new entry here is a design decision that must
 * come with a suite edit in the same change.
 *
 * @return list<array{
 *     id: string,
 *     kind: 'property'|'static'|'other',
 *     parent: class-string,
 *     member: string,
 *     why: string,
 *     assert: callable(array{pdo: \PDO}): (string|null)
 * }>
 */
final class Allowlist
{
    private function __construct()
    {
    }

    public static function entries(): array
    {
        return [
            [
                'id' => 'PDOStatement::$queryString',
                'kind' => 'property',
                'parent' => \PDOStatement::class,
                'member' => 'queryString',
                'why' => 'It is the only public property on either parent (measured: \\PDO has none, '
                    . '\\PDOStatement has exactly one, non-readonly). PHP 8.3 has no property hooks, so a '
                    . 'subclass cannot intercept reads; the only available guarantee is that we assign it. '
                    . 'Measured on 8.4: the assignment succeeds on a directly-constructed subclass and is '
                    . 'rejected on a real driver statement ("Error: Property queryString is read only"), i.e. '
                    . 'our object is strictly more writable than PDO\'s — a pinned deviation '
                    . '(`stmt.queryString.is_writable`) for the differential harness, not a gap here.',
                'assert' => static function (array $ctx): ?string {
                    $pdo = $ctx['pdo'];
                    $stmt = $pdo->prepare('SELECT 1 AS one');

                    if (!isset($stmt->queryString)) {
                        return 'queryString is not set on a fresh AtomsStatement';
                    }

                    if ($stmt->queryString !== 'SELECT 1 AS one') {
                        return sprintf(
                            'queryString is %s, expected the prepared SQL verbatim',
                            var_export($stmt->queryString, true)
                        );
                    }

                    // :name placeholders must survive too — the property is the
                    // SQL AS PREPARED, not a rewritten/positional form.
                    $named = $pdo->prepare('SELECT :a AS a, :b AS b');
                    if ($named->queryString !== 'SELECT :a AS a, :b AS b') {
                        return sprintf(
                            'queryString with named placeholders is %s, expected them intact',
                            var_export($named->queryString, true)
                        );
                    }

                    return null;
                },
            ],
        ];
    }
}
