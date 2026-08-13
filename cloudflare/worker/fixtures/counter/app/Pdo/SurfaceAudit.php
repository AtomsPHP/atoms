<?php

declare(strict_types=1);

namespace App\Pdo;

use Atoms\Cf\AtomsNotSupported;
use Atoms\Cf\FetchMode;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The reflection tripwire (M1 design §1): asserts every public member of
 * \PDO / \PDOStatement is genuinely declared on `Atoms\Cf\AtomsPDO` /
 * `Atoms\Cf\AtomsStatement`, rather than falling through to the inert
 * `sqlite::memory:` carrier connection — the bug class this whole file
 * exists to catch.
 *
 * Every rule enumerates the RUNTIME parent classes via reflection; nothing
 * here is a hardcoded member list, so a php-wasm bump that adds a member
 * turns this red instead of silently degrading (design §1.4).
 *
 * Fail-closed by construction: `run()` performs no try/catch around the
 * enumeration rules (R1-R5, R7). R6 is a deliberate exception — it
 * EXECUTES `FetchMode::assertSupported()`/`shape()` and classifies the
 * outcome, which is the whole point of that rule (design §1.2).
 */
final class SurfaceAudit
{
    /** Parent class => our subclass, per design §1.2. */
    private const TARGETS = [
        \PDO::class => \Atoms\Cf\AtomsPDO::class,
        \PDOStatement::class => \Atoms\Cf\AtomsStatement::class,
    ];

    /**
     * Driver-namespaced constants vary with whatever extensions are loaded
     * (measured: 29 on the local mysql+pgsql+sqlite build; the guest has
     * only pdo_sqlite) and are excluded from R5/R6 for that reason — see
     * design §1.2.
     */
    private const DRIVER_PREFIX = '/^(MYSQL|PGSQL|SQLITE|ODBC|OCI|SQLSRV|FB|DBLIB)_/';

    /** The pinned constant namespaces, matched by prefix (ERR_ only ever matches ERR_NONE). */
    private const PINNED_PREFIXES = ['FETCH_', 'ATTR_', 'PARAM_', 'ERRMODE_', 'CASE_', 'NULL_', 'CURSOR_', 'ERR_'];

    private function __construct()
    {
    }

    /**
     * @return array{
     *     ok: bool,
     *     php: string,
     *     violations: list<array{rule: string, member: string, detail: string}>,
     *     counts: array<string, int>,
     *     members_checked: list<string>,
     *     allowlist: list<array{id: string, asserted: bool}>
     * }
     */
    public static function run(\PDO $pdo): array
    {
        $violations = [];
        $membersChecked = [];
        $counts = [
            'pdo_methods' => 0,
            'pdo_statics' => 0,
            'stmt_methods' => 0,
            'properties' => 0,
            'interfaces' => 0,
            'pinned_fetch' => 0,
            'pinned_attr' => 0,
            'pinned_param' => 0,
        ];

        $allowlistIndex = self::indexAllowlist();

        foreach (self::TARGETS as $parentClass => $childClass) {
            $parent = new ReflectionClass($parentClass);
            $child = new ReflectionClass($childClass);

            self::auditMethods($parent, $child, $parentClass, $childClass, $violations, $membersChecked, $counts);
            self::auditProperties($parent, $parentClass, $allowlistIndex, $violations, $membersChecked, $counts);
            self::auditInterfaces($parent, $child, $parentClass, $childClass, $violations, $counts);
        }

        self::auditConstants($violations, $membersChecked, $counts);
        self::auditFetchBehaviour($violations);

        $allowlist = self::runAllowlist($pdo, $violations);

        return [
            'ok' => $violations === [],
            'php' => PHP_VERSION,
            'violations' => $violations,
            'counts' => $counts,
            'members_checked' => $membersChecked,
            'allowlist' => $allowlist,
        ];
    }

    /**
     * R1 (public instance methods) and R2 (public static methods): every
     * public member of the parent must be DECLARED on our subclass — not
     * merely inherited, and not merely present via a parent no-op.
     * Measured: statics ARE declarable in a subclass (design §0.2a), so
     * they get the identical rule; the allowlist escape hatch some code
     * bases reach for here does not exist in this design.
     *
     * @param list<array{rule: string, member: string, detail: string}> $violations
     * @param list<string> $membersChecked
     * @param array<string, int> $counts
     */
    private static function auditMethods(
        ReflectionClass $parent,
        ReflectionClass $child,
        string $parentClass,
        string $childClass,
        array &$violations,
        array &$membersChecked,
        array &$counts
    ): void {
        $isPdo = $parentClass === \PDO::class;

        foreach ($parent->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $member = $parentClass . '::' . $method->getName();
            $membersChecked[] = $member;

            // pdo_methods/stmt_methods count EVERY public method (static and
            // instance alike) — measurement (design §0.2a) is that statics get
            // the identical declaring-class rule as instance methods, so they
            // are not a separate bucket here either. pdo_statics is an
            // informational subcount of pdo_methods, not an alternative to it.
            if ($isPdo) {
                $counts['pdo_methods']++;
            } else {
                $counts['stmt_methods']++;
            }
            if ($method->isStatic()) {
                $counts['pdo_statics']++; // only PDO has static public methods, measured
            }

            $rule = $method->isStatic() ? 'R2' : 'R1';

            if (!$child->hasMethod($method->getName())) {
                $violations[] = ['rule' => $rule, 'member' => $member, 'detail' => "not declared on {$childClass}"];
                continue;
            }

            $declaring = (new ReflectionMethod($childClass, $method->getName()))->getDeclaringClass()->getName();
            if ($declaring !== $childClass) {
                $violations[] = [
                    'rule' => $rule,
                    'member' => $member,
                    'detail' => "declared by {$declaring}, not {$childClass}",
                ];
            }
        }
    }

    /**
     * R3 (public properties): a property cannot be intercepted by a
     * subclass on PHP 8.3 (no property hooks), so the ONLY members allowed
     * to skip the "declared on the subclass" bar are properties, and only
     * via an allowlist entry that carries a runtime assertion.
     *
     * @param array<string, array<string, mixed>> $allowlistIndex keyed by "Parent::member"
     * @param list<array{rule: string, member: string, detail: string}> $violations
     * @param list<string> $membersChecked
     * @param array<string, int> $counts
     */
    private static function auditProperties(
        ReflectionClass $parent,
        string $parentClass,
        array $allowlistIndex,
        array &$violations,
        array &$membersChecked,
        array &$counts
    ): void {
        foreach ($parent->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $member = $parentClass . '::$' . $property->getName();
            $membersChecked[] = $member;
            $counts['properties']++;

            $key = $parentClass . '::' . $property->getName();
            if (!isset($allowlistIndex[$key])) {
                $violations[] = [
                    'rule' => 'R3',
                    'member' => $member,
                    'detail' => 'public property is not covered by the allowlist (and cannot be intercepted '
                        . 'by a subclass on PHP 8.3)',
                ];
            }
        }
    }

    /**
     * R4 (interfaces): the subclass must implement everything the parent
     * declares, and every method that interface requires must itself pass
     * the R1 declaring-class bar — checked directly here too, as a defense
     * in depth independent of the R1 pass over the parent's own methods.
     *
     * @param list<array{rule: string, member: string, detail: string}> $violations
     * @param array<string, int> $counts
     */
    private static function auditInterfaces(
        ReflectionClass $parent,
        ReflectionClass $child,
        string $parentClass,
        string $childClass,
        array &$violations,
        array &$counts
    ): void {
        $parentInterfaces = class_implements($parentClass);
        $childInterfaces = class_implements($childClass);

        foreach ($parentInterfaces as $interfaceName) {
            $counts['interfaces']++;

            if (!isset($childInterfaces[$interfaceName])) {
                $violations[] = [
                    'rule' => 'R4',
                    'member' => $parentClass . ' implements ' . $interfaceName,
                    'detail' => "{$childClass} does not implement {$interfaceName}",
                ];
                continue;
            }

            foreach ((new ReflectionClass($interfaceName))->getMethods(ReflectionMethod::IS_PUBLIC) as $ifaceMethod) {
                $member = $interfaceName . '::' . $ifaceMethod->getName();

                if (!$child->hasMethod($ifaceMethod->getName())) {
                    $violations[] = [
                        'rule' => 'R4',
                        'member' => $member,
                        'detail' => "not declared on {$childClass} (required by {$interfaceName})",
                    ];
                    continue;
                }

                $declaring = (new ReflectionMethod($childClass, $ifaceMethod->getName()))
                    ->getDeclaringClass()
                    ->getName();
                if ($declaring !== $childClass) {
                    $violations[] = [
                        'rule' => 'R4',
                        'member' => $member,
                        'detail' => "declared by {$declaring}, not {$childClass} (required by {$interfaceName})",
                    ];
                }
            }
        }
    }

    /**
     * R5 (constants): the pinned FETCH_* / ATTR_* / PARAM_* / ERRMODE_* / CASE_*
     * / NULL_* / CURSOR_* / ERR_NONE constants must name-set-equal (BOTH
     * directions) and value-equal PinnedConstants — against \PDO, the
     * runtime parent, never a hardcoded copy of what was true on some
     * other PHP build.
     *
     * @param list<array{rule: string, member: string, detail: string}> $violations
     * @param list<string> $membersChecked
     * @param array<string, int> $counts
     */
    private static function auditConstants(array &$violations, array &$membersChecked, array &$counts): void
    {
        $runtime = self::runtimePinnedConstants();
        $pinned = PinnedConstants::all();

        foreach ($runtime as $name => $value) {
            $membersChecked[] = 'PDO::' . $name;
        }

        // Direction 1: every runtime constant must be pinned, with the same value.
        foreach ($runtime as $name => $value) {
            if (!array_key_exists($name, $pinned)) {
                $violations[] = [
                    'rule' => 'R5',
                    'member' => 'PDO::' . $name,
                    'detail' => sprintf('runtime constant %s = %s is not in PinnedConstants', $name, var_export($value, true)),
                ];
                continue;
            }

            if ($pinned[$name] !== $value) {
                $violations[] = [
                    'rule' => 'R5',
                    'member' => 'PDO::' . $name,
                    'detail' => sprintf(
                        'runtime value %s does not match pinned value %s',
                        var_export($value, true),
                        var_export($pinned[$name], true)
                    ),
                ];
            }
        }

        // Direction 2: every pinned constant must exist at runtime — catches a
        // pin that grew stale (a name renamed or removed, e.g. a deprecated
        // FETCH_SERIALIZE dropped entirely).
        foreach ($pinned as $name => $value) {
            if (!array_key_exists($name, $runtime)) {
                $violations[] = [
                    'rule' => 'R5',
                    'member' => 'PDO::' . $name,
                    'detail' => 'pinned constant is not present on the runtime \\PDO (removed, renamed, or never real)',
                ];
            }
        }

        $counts['pinned_fetch'] = count(PinnedConstants::fetch());
        $counts['pinned_attr'] = count(PinnedConstants::attr());
        $counts['pinned_param'] = count(PinnedConstants::param());
    }

    /**
     * R6 (behavioural constant check): every pinned FETCH_* value is run
     * through `FetchMode::assertSupported()`. It must either land in
     * `FetchMode::supported()` — and, for a row-shaped mode, `shape()` must
     * succeed on a sample row — or raise `AtomsNotSupported`. Anything else
     * (a different exception type, or a supported mode whose shape() fails)
     * is the classic silent-coercion failure this rule exists to catch.
     *
     * `FETCH_KEY_PAIR` is supported but is fetchAll()-only by design
     * (`FetchMode::shape()` legitimately throws `AtomsNotSupported` for a
     * single row), so it is excluded from the shape() half of this check.
     *
     * @param list<array{rule: string, member: string, detail: string}> $violations
     */
    private static function auditFetchBehaviour(array &$violations): void
    {
        $sampleRow = ['id' => 1, 'name' => 'probe'];

        foreach (PinnedConstants::fetch() as $name => $value) {
            $member = 'PDO::' . $name;

            try {
                $normalized = FetchMode::assertSupported($value, 'R6 audit: ' . $name);
            } catch (AtomsNotSupported $e) {
                continue; // a refusal is a legitimate, checked outcome
            } catch (\Throwable $e) {
                $violations[] = [
                    'rule' => 'R6',
                    'member' => $member,
                    'detail' => sprintf(
                        'assertSupported() raised %s instead of returning or raising AtomsNotSupported: %s',
                        get_class($e),
                        $e->getMessage()
                    ),
                ];
                continue;
            }

            if ($normalized === \PDO::FETCH_KEY_PAIR) {
                continue; // fetchAll()-only; shape() correctly refuses a single row
            }

            try {
                FetchMode::shape($sampleRow, $normalized);
            } catch (\Throwable $e) {
                $violations[] = [
                    'rule' => 'R6',
                    'member' => $member,
                    'detail' => sprintf(
                        'FetchMode::supported() claims mode %d but shape() raised %s: %s',
                        $normalized,
                        get_class($e),
                        $e->getMessage()
                    ),
                ];
            }
        }
    }

    /**
     * R7 (enumeration integrity) is not re-validated as a violation here —
     * `counts` and `members_checked` are returned as data, and conformance
     * check 26 asserts the floors directly (design §1.5), the same way
     * check 24 asserts `fired_this_residency` exactly rather than folding
     * the assertion into the audited subject itself.
     */

    /**
     * Run every allowlist entry's assertion and report `asserted`. An entry
     * without a callable `assert` is a violation (R3) rather than silently
     * skipped — the same discipline as check 24's exact-count rule: an
     * allowlist is not allowed to be a hole with extra steps.
     *
     * @param list<array{rule: string, member: string, detail: string}> $violations
     * @return list<array{id: string, asserted: bool}>
     */
    private static function runAllowlist(\PDO $pdo, array &$violations): array
    {
        $report = [];

        foreach (Allowlist::entries() as $entry) {
            $id = isset($entry['id']) ? (string) $entry['id'] : '(unnamed allowlist entry)';
            $assert = $entry['assert'] ?? null;

            if (!is_callable($assert)) {
                $violations[] = [
                    'rule' => 'R3',
                    'member' => $id,
                    'detail' => 'allowlist entry has no assertion closure',
                ];
                $report[] = ['id' => $id, 'asserted' => false];
                continue;
            }

            $failure = $assert(['pdo' => $pdo]);

            if ($failure !== null) {
                $violations[] = ['rule' => 'R3', 'member' => $id, 'detail' => (string) $failure];
                $report[] = ['id' => $id, 'asserted' => false];
                continue;
            }

            $report[] = ['id' => $id, 'asserted' => true];
        }

        return $report;
    }

    /**
     * @return array<string, array{id: string, kind: string, parent: string, member: string}>
     *     keyed by "Parent::member" for R3's lookup
     */
    private static function indexAllowlist(): array
    {
        $index = [];

        foreach (Allowlist::entries() as $entry) {
            if (!isset($entry['parent'], $entry['member'])) {
                continue;
            }

            $index[$entry['parent'] . '::' . $entry['member']] = $entry;
        }

        return $index;
    }

    /**
     * @return array<string, int|string> runtime \PDO constants in the
     *     pinned namespaces, driver-namespaced constants excluded
     */
    private static function runtimePinnedConstants(): array
    {
        $all = (new ReflectionClass(\PDO::class))->getConstants();
        $out = [];

        foreach ($all as $name => $value) {
            if (preg_match(self::DRIVER_PREFIX, $name) === 1) {
                continue;
            }

            foreach (self::PINNED_PREFIXES as $prefix) {
                if (strncmp($name, $prefix, strlen($prefix)) === 0) {
                    $out[$name] = $value;
                    break;
                }
            }
        }

        return $out;
    }
}
