<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate\Tests;

use Atoms\DatabaseIlluminate\AtomConnection;
use Atoms\DatabaseIlluminate\Tests\Support\RecordingPdo;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class AtomConnectionTest extends TestCase
{
    private function connection(?RecordingPdo $pdo = null, array $config = []): AtomConnection
    {
        return new AtomConnection($pdo ?? new RecordingPdo(), 'atom', '', $config + [
            'driver' => 'sqlite',
            'name' => 'atom',
            'database' => 'atom',
        ]);
    }

    public function testServerVersionDefaultsToTheDocumentedFloor(): void
    {
        self::assertSame(AtomConnection::DEFAULT_SERVER_VERSION, $this->connection()->getServerVersion());
    }

    public function testServerVersionComesFromConfigWhenGiven(): void
    {
        $conn = $this->connection(null, ['server_version' => '3.51.0']);

        self::assertSame('3.51.0', $conn->getServerVersion());
    }

    public function testSchemaBuilderIsRefusedWithTheCatalogCode(): void
    {
        $conn = $this->connection();

        try {
            $conn->getSchemaBuilder();
            self::fail('getSchemaBuilder() should refuse');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::SchemaBuilderUnavailable, $e->errorCode);
            self::assertStringContainsString('ATOMS-E106', $e->getMessage());
            self::assertStringContainsString('getSchemaBuilder()', $e->getMessage());
        }
    }

    public function testSchemaStateIsRefusedWithTheCatalogCode(): void
    {
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E106/');

        $this->connection()->getSchemaState();
    }

    public function testTransactionsOpenThroughPdoBeginTransactionNeverThroughLiteralBegin(): void
    {
        $pdo = new RecordingPdo();
        $conn = $this->connection($pdo);
        $conn->statement('CREATE TABLE t (v TEXT)');

        $conn->transaction(function (AtomConnection $c): void {
            $c->table('t')->insert(['v' => 'one']);
        });

        self::assertSame(1, $pdo->beginTransactionCalls);
        foreach ($pdo->execStatements as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*BEGIN\b/i', $sql);
        }
    }

    public function testNestedTransactionsIssueNoSavepointsAndCommitTheOuterWriteSet(): void
    {
        $pdo = new RecordingPdo();
        $conn = $this->connection($pdo);
        $conn->statement('CREATE TABLE t (v TEXT)');

        $result = $conn->transaction(function (AtomConnection $c) {
            $c->table('t')->insert(['v' => 'outer']);

            return $c->transaction(function (AtomConnection $inner) {
                $inner->table('t')->insert(['v' => 'inner']);

                return 'inner ran';
            });
        });

        self::assertSame('inner ran', $result);
        self::assertSame(0, $conn->transactionLevel());
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(
            ['inner', 'outer'],
            $conn->table('t')->orderBy('v')->pluck('v')->all(),
        );
        foreach ($pdo->execStatements as $sql) {
            self::assertStringNotContainsStringIgnoringCase('SAVEPOINT', $sql);
        }
    }

    public function testAThrowFromTheInnerCallbackRollsBackTheWholeTransaction(): void
    {
        $conn = $this->connection();
        $conn->statement('CREATE TABLE t (v TEXT)');

        try {
            $conn->transaction(function (AtomConnection $c): void {
                $c->table('t')->insert(['v' => 'outer']);
                $c->transaction(function (): void {
                    throw new \RuntimeException('inner failure');
                });
            });
            self::fail('the inner throw should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('inner failure', $e->getMessage());
        }

        self::assertSame(0, $conn->transactionLevel());
        self::assertSame(0, $conn->table('t')->count());
    }

    /**
     * Without performRollBack()'s override, this sequence commits all three
     * writes: the inner rollBack() is a counter decrement, the inner wrapper
     * then sees level 1 and physically commits mid-outer-callback, and the
     * outer throw has nothing left to roll back.
     */
    public function testAnExplicitRollBackInsideANestedTransactionDiscardsEverythingLoudly(): void
    {
        $conn = $this->connection();
        $conn->statement('CREATE TABLE t (v TEXT)');

        try {
            $conn->transaction(function (AtomConnection $c): void {
                $c->table('t')->insert(['v' => 'outer-before']);
                $c->transaction(function (AtomConnection $inner): void {
                    $inner->table('t')->insert(['v' => 'inner']);
                    $inner->rollBack();
                });
                $c->table('t')->insert(['v' => 'outer-after']);
                throw new \RuntimeException('force outer failure');
            });
            self::fail('the desynchronized wrapper commit must fail loudly');
        } catch (\PDOException $e) {
            // The inner wrapper's commit finds the transaction already rolled
            // back — loud, and before 'outer-after' ever runs.
        }

        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($conn->getPdo()->inTransaction());
        self::assertSame(0, $conn->table('t')->count(), 'no "rolled back" write may survive');
    }

    public function testAnExplicitRollBackAtLevelOneStillBehavesNormally(): void
    {
        $conn = $this->connection();
        $conn->statement('CREATE TABLE t (v TEXT)');

        $conn->beginTransaction();
        $conn->table('t')->insert(['v' => 'doomed']);
        $conn->rollBack();

        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($conn->getPdo()->inTransaction());
        self::assertSame(0, $conn->table('t')->count());
    }

    public function testTheGrammarReportsNoSavepointSupport(): void
    {
        self::assertFalse($this->connection()->getQueryGrammar()->supportsSavepoints());
    }
}
