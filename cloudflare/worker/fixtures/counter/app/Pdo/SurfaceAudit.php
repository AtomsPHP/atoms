<?php

declare(strict_types=1);

namespace App\Pdo;

use Atoms\Cf\AtomsNotSupported;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The reflection tripwire: asserts every public member of
 * \PDO / \PDOStatement is genuinely declared on `Atoms\Cf\AtomsPDO` /
 * `Atoms\Cf\AtomsStatement`, rather than falling through to the inert
 * `sqlite::memory:` carrier connection — the bug class this whole file
 * exists to catch.
 *
 * Every rule enumerates the RUNTIME parent classes via reflection; nothing
 * here is a hardcoded member list, so a php-wasm bump that adds a member
 * turns this red instead of silently degrading.
 *
 * Fail-closed by construction: `run()` performs no try/catch around the
 * enumeration rules (R1-R5, R7). R6 is a deliberate exception — it drives
 * every pinned FETCH_* value through a REAL, live `AtomsStatement` (the
 * genuine `Atoms\Cf\` dispatcher a customer's `fetch()`/`fetchAll()` call
 * actually reaches, not an internal helper) and classifies the outcome,
 * which is the whole point of that rule.
 */
final class SurfaceAudit
{
    /** Parent class => our subclass. */
    private const TARGETS = [
        \PDO::class => \Atoms\Cf\AtomsPDO::class,
        \PDOStatement::class => \Atoms\Cf\AtomsStatement::class,
    ];

    /**
     * Driver-namespaced constants vary with whatever extensions are loaded
     * (measured: 29 on the local mysql+pgsql+sqlite build; the guest has
     * only pdo_sqlite) and are excluded from R5/R6 for that reason.
     */
    private const DRIVER_PREFIX = '/^(MYSQL|PGSQL|SQLITE|ODBC|OCI|SQLSRV|FB|DBLIB)_/';

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
        self::auditFetchBehaviour($pdo, $violations);

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
     * Measured: statics ARE declarable in a subclass, so
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
            // instance alike) — the measurement is that statics get
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
     * R6 (behavioural constant check). Runs every pinned FETCH_* value
     * through the REAL dispatcher a customer actually calls,
     * `AtomsStatement::fetch()`/`fetchAll()`'s `hydrateOneRow()` — not the
     * small INTERNAL `Atoms\Cf\FetchMode` helper, which implements only a
     * HARDCODED subset (ASSOC/NUM/BOTH/OBJ/COLUMN/KEY_PAIR).
     * `hydrateOneRow()` handles many more modes
     * directly (FETCH_BOUND, FETCH_NAMED, FETCH_CLASS, FETCH_INTO, the
     * FETCH_GROUP/FETCH_UNIQUE/FETCH_CLASSTYPE/FETCH_PROPS_LATE flags,
     * FETCH_FUNC) BEFORE ever falling through to FetchMode. Auditing
     * FetchMode alone would leave every one of those modes invisible to
     * R6: `FetchMode::assertSupported()` throws AtomsNotSupported for all
     * of them, so the audit would "pass" via the refusal branch even
     * though AtomsStatement genuinely ANSWERS most of them — deleting the
     * FETCH_CLASS arm from `hydrateOneRow()` would not turn this
     * red.
     *
     * So: for every pinned FETCH_* value, execute a REAL fetch through a
     * live `AtomsStatement` obtained from the `$pdo` passed in (the SAME
     * connection {@see Probe::surfaceAudit()} hands this method — real
     * `db()->pdo()`, real `SqlBridge`, real Durable Object SQL, not a
     * fake), over a fresh two-column seeded result set, driving each mode
     * through its OWN documented calling form (`bindColumn()` first for
     * FETCH_BOUND, `setFetchMode()` first for FETCH_CLASS/FETCH_INTO/
     * FETCH_PROPS_LATE, `fetchAll()` for FETCH_FUNC/FETCH_KEY_PAIR/
     * FETCH_GROUP/FETCH_UNIQUE, a class-name-bearing row for
     * FETCH_CLASSTYPE — see {@see fetchModeExercises()}). Each mode lands
     * in exactly one of three buckets: ANSWERED (a value came back),
     * REFUSED (`AtomsNotSupported`, a legitimate checked outcome), or
     * VIOLATION (anything else — the classic silent-coercion failure this
     * rule exists to catch). The observed ANSWERED set is then compared,
     * by NAME-SET EQUALITY BOTH WAYS, against
     * `PinnedConstants::expectedAnsweredFetchModes()` — so deleting a
     * `hydrateOneRow()` arm (a mode that should answer stops answering)
     * and a newly-answering mode that should still refuse (a regression in
     * the OTHER direction) are both violations, not just one.
     *
     * @param list<array{rule: string, member: string, detail: string}> $violations
     */
    private static function auditFetchBehaviour(\PDO $pdo, array &$violations): void
    {
        $answered = [];

        foreach (self::fetchModeExercises() as $name => $exercise) {
            $member = 'PDO::' . $name;

            try {
                $exercise($pdo);
            } catch (AtomsNotSupported $e) {
                continue; // a refusal is a legitimate, checked outcome
            } catch (\Throwable $e) {
                $violations[] = [
                    'rule' => 'R6',
                    'member' => $member,
                    'detail' => sprintf(
                        'exercising %s through the real AtomsStatement dispatcher (its own documented '
                            . 'calling form) raised %s instead of answering or raising AtomsNotSupported: %s',
                        $name,
                        get_class($e),
                        $e->getMessage()
                    ),
                ];
                continue;
            }

            $answered[] = $name;
        }

        sort($answered);
        $expected = PinnedConstants::expectedAnsweredFetchModes();
        sort($expected);

        if ($answered !== $expected) {
            $violations[] = [
                'rule' => 'R6',
                'member' => 'PDO::FETCH_* (answered set)',
                'detail' => sprintf(
                    'the set of FETCH_* modes AtomsStatement genuinely ANSWERS does not match '
                        . 'PinnedConstants::expectedAnsweredFetchModes(): missing=%s extra=%s',
                    json_encode(array_values(array_diff($expected, $answered))),
                    json_encode(array_values(array_diff($answered, $expected)))
                ),
            ];
        }
    }

    /**
     * One exercise closure per pinned FETCH_* name, each driving a fresh
     * statement through that mode's OWN documented calling form, as R6
     * requires. Every closure either returns (answered,
     * regardless of what it returns — only whether it threw matters here)
     * or throws; `AtomsNotSupported` is a legitimate refusal, anything else
     * is a violation, both handled by the caller.
     *
     * @return array<string, \Closure(\PDO): mixed>
     */
    private static function fetchModeExercises(): array
    {
        // A fresh single-row, two-UNIQUE-column result set — self-contained
        // (no dependency on Probe's own tables or seed data, so this audit
        // cannot perturb, or be perturbed by, anything Probe::differential()
        // does with probe_rows).
        $row = static function (\PDO $pdo): \PDOStatement {
            return $pdo->query('SELECT 1 AS a, 2 AS b');
        };
        // Two rows, for the modes that are only meaningful across more than one.
        $rows = static function (\PDO $pdo): \PDOStatement {
            return $pdo->query("SELECT 'x' AS a, 1 AS b UNION ALL SELECT 'y', 2");
        };

        return [
            'FETCH_DEFAULT' => static function (\PDO $pdo) use ($row) {
                $stmt = $row($pdo);
                $stmt->setFetchMode(\PDO::FETCH_BOTH);

                return $stmt->fetch(\PDO::FETCH_DEFAULT);
            },
            'FETCH_LAZY' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_LAZY);
            },
            'FETCH_ASSOC' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC);
            },
            'FETCH_NUM' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_NUM);
            },
            'FETCH_BOTH' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_BOTH);
            },
            'FETCH_OBJ' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_OBJ);
            },
            // FETCH_BOUND's documented form: bindColumn() BEFORE fetch().
            'FETCH_BOUND' => static function (\PDO $pdo) use ($row) {
                $stmt = $row($pdo);
                $stmt->bindColumn(1, $bound);

                return [$stmt->fetch(\PDO::FETCH_BOUND), $bound];
            },
            'FETCH_COLUMN' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_COLUMN);
            },
            // FETCH_CLASS's documented form: setFetchMode(FETCH_CLASS, $class) BEFORE fetch().
            'FETCH_CLASS' => static function (\PDO $pdo) use ($row) {
                $stmt = $row($pdo);
                $stmt->setFetchMode(\PDO::FETCH_CLASS, \stdClass::class);

                return $stmt->fetch();
            },
            // FETCH_INTO's documented form: setFetchMode(FETCH_INTO, $obj) BEFORE fetch().
            'FETCH_INTO' => static function (\PDO $pdo) use ($row) {
                $stmt = $row($pdo);
                $target = new \stdClass();
                $stmt->setFetchMode(\PDO::FETCH_INTO, $target);

                return $stmt->fetch();
            },
            // FETCH_FUNC's documented form: fetchAll(), never bare fetch().
            'FETCH_FUNC' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetchAll(\PDO::FETCH_FUNC, static fn ($a, $b) => [$a, $b]);
            },
            'FETCH_NAMED' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_NAMED);
            },
            // FETCH_KEY_PAIR's documented form: fetchAll(), never bare fetch()
            // (AtomsStatement's fetch() path falls through to
            // FetchMode::shape(), which refuses it for a single row).
            'FETCH_KEY_PAIR' => static function (\PDO $pdo) use ($rows) {
                return $rows($pdo)->fetchAll(\PDO::FETCH_KEY_PAIR);
            },
            // FETCH_ORI_* are cursorOrientation values, not modes — driven as
            // fetch()'s SECOND argument, over the mandatory FETCH_ASSOC base.
            'FETCH_ORI_NEXT' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_NEXT);
            },
            'FETCH_ORI_PRIOR' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_PRIOR);
            },
            'FETCH_ORI_FIRST' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_FIRST);
            },
            'FETCH_ORI_LAST' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_LAST);
            },
            'FETCH_ORI_ABS' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_ABS, 0);
            },
            'FETCH_ORI_REL' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_REL, 0);
            },
            // FETCH_GROUP/FETCH_UNIQUE are OR-able flags, meaningful only
            // combined with a base mode, and only via fetchAll().
            'FETCH_GROUP' => static function (\PDO $pdo) use ($rows) {
                return $rows($pdo)->fetchAll(\PDO::FETCH_ASSOC | \PDO::FETCH_GROUP);
            },
            'FETCH_UNIQUE' => static function (\PDO $pdo) use ($rows) {
                return $rows($pdo)->fetchAll(\PDO::FETCH_ASSOC | \PDO::FETCH_UNIQUE);
            },
            // FETCH_CLASSTYPE consumes the FIRST column as the class name —
            // driven directly (not via setFetchMode(), which would demand an
            // explicit class name FETCH_CLASSTYPE deliberately does not take).
            'FETCH_CLASSTYPE' => static function (\PDO $pdo) {
                $stmt = $pdo->query("SELECT 'stdClass' AS c, 5 AS v");

                return $stmt->fetch(\PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE);
            },
            'FETCH_SERIALIZE' => static function (\PDO $pdo) use ($row) {
                return $row($pdo)->fetch(\PDO::FETCH_SERIALIZE);
            },
            // FETCH_PROPS_LATE's documented form: combined with FETCH_CLASS
            // via setFetchMode() BEFORE fetch().
            'FETCH_PROPS_LATE' => static function (\PDO $pdo) use ($row) {
                $stmt = $row($pdo);
                $stmt->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, \stdClass::class);

                return $stmt->fetch();
            },
        ];
    }

    /**
     * R7 (enumeration integrity) is not re-validated as a violation here —
     * `counts` and `members_checked` are returned as data, and conformance
     * check 26 asserts the floors directly, the same way
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
     * Deliberately NOT a filter of runtime constants
     * DOWN to an allowlist of known prefixes (`FETCH_`, `ATTR_`, `PARAM_`,
     * ...) before comparing against PinnedConstants — that would mean a
     * brand new NON-driver constant, in a namespace nobody had thought to
     * list yet, was excluded from BOTH directions of the check and simply
     * never seen. Instead, every \PDO constant is included UNLESS it is
     * driver-prefixed (the one deliberate exclusion — driver-loaded
     * constants vary by extension, not by PHP version).
     * `auditConstants()`'s both-directions name-set-and-value
     * equality against PinnedConstants does the real work: any new
     * non-driver constant this build adds shows up in `$runtime` but not in
     * `PinnedConstants`, and Direction 1 flags it as an R5 violation —
     * exactly the "a new member surfaces as RED, never as a silent hole"
     * guarantee this tripwire makes for constants, not just methods.
     *
     * @return array<string, int|string> every \PDO constant, driver-namespaced ones excluded
     */
    private static function runtimePinnedConstants(): array
    {
        $all = (new ReflectionClass(\PDO::class))->getConstants();
        $out = [];

        foreach ($all as $name => $value) {
            if (preg_match(self::DRIVER_PREFIX, $name) === 1) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }
}
