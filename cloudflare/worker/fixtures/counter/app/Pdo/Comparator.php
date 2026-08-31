<?php

declare(strict_types=1);

namespace App\Pdo;

/**
 * The differential harness's ground truth (design §2.3): an in-guest
 * `new \PDO('sqlite::memory:')` — the SAME php-wasm PHP build as everything
 * under test, so no measured-on-8.4 value is ever an assertion target.
 *
 * `Comparator::sanity()` is the design's answer to "the comparator could be
 * your own shim": five structural gates, all required, three of which
 * (`FETCH_NAMED` grouping, `getColumnMeta()`, `PDORow`) are things
 * `Atoms\Cf\AtomsPDO` cannot produce even in principle.
 */
final class Comparator
{
    private function __construct()
    {
    }

    /**
     * A fresh native PDO with the same attributes AtomsPDO guarantees
     * (ERRMODE_EXCEPTION), schema and seed applied. The default fetch mode
     * is deliberately left ALONE — never set here — so that
     * `pdo.attr.default_fetch_mode` measures AtomsPDO's real
     * `ATTR_DEFAULT_FETCH_MODE` default against real pdo_sqlite's own
     * unmodified default, with nothing on either side forcing them to agree.
     * AtomsPDO's default is
     * FETCH_BOTH (design §3 F-30, matching real pdo_sqlite's own measured
     * default). Probe::differential()
     * does not force-set `ATTR_DEFAULT_FETCH_MODE` on `$ours` either,
     * so whatever divergence exists, if any, SURFACES in the
     * matrix and gets pinned, rather than being papered over on either side.
     */
    public static function build(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        Schema::applySchema($pdo);
        Schema::applySeed($pdo);

        return $pdo;
    }

    /**
     * @return array{ok: bool, gates: array{S1: bool, S2: bool, S3: bool, S4: bool, S5: bool}}
     */
    public static function sanity(\PDO $c): array
    {
        $gates = [
            'S1' => self::gateS1($c),
            'S2' => self::gateS2($c),
            'S3' => self::gateS3($c),
            'S4' => self::gateS4($c),
            'S5' => self::gateS5($c),
        ];

        return [
            'ok' => !in_array(false, $gates, true),
            'gates' => $gates,
        ];
    }

    /** get_class() EXACTLY, not instanceof — an AtomsPDO IS a \PDO. An impostor comparator would be a subclass. */
    private static function gateS1(\PDO $c): bool
    {
        return get_class($c) === 'PDO';
    }

    /** AtomsPDO::getAttribute() throws for this attribute, permanently (design §3 F-22). */
    private static function gateS2(\PDO $c): bool
    {
        try {
            $v = $c->getAttribute(\PDO::ATTR_CLIENT_VERSION);

            return is_string($v) && $v !== '' && preg_match('/^\d+\.\d+/', $v) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Our wire has already collapsed duplicate columns before PHP sees them — this grouped pair is unreproducible on our side by construction. */
    private static function gateS3(\PDO $c): bool
    {
        try {
            $row = $c->query('SELECT 1 AS a, 2 AS a')->fetch(\PDO::FETCH_NAMED);

            return $row === ['a' => [1, 2]];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** getColumnMeta() throws permanently on AtomsStatement. */
    private static function gateS4(\PDO $c): bool
    {
        try {
            $stmt = $c->prepare('SELECT 1 AS one');
            $stmt->execute();
            $meta = $stmt->getColumnMeta(0);

            return is_array($meta) && ($meta['name'] ?? null) === 'one';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** PDORow is an internal class bound to a real statement; nothing in userland can construct it. */
    private static function gateS5(\PDO $c): bool
    {
        try {
            $row = $c->query('SELECT 1')->fetch(\PDO::FETCH_LAZY);

            return is_object($row) && get_class($row) === 'PDORow';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
