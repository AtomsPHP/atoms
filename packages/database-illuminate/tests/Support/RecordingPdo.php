<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate\Tests\Support;

/**
 * A real in-memory sqlite PDO that records every statement handed to exec()
 * and every beginTransaction() call, so tests can assert not just what a
 * connection did but which door it went through — the Cloudflare runtime
 * refuses SAVEPOINT and literal BEGIN statements, so "no such string ever
 * reached exec()" is the property that transfers from this fake to the
 * deployed guest.
 */
final class RecordingPdo extends \PDO
{
    /** @var list<string> */
    public array $execStatements = [];

    public int $beginTransactionCalls = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function exec(string $statement): int|false
    {
        $this->execStatements[] = $statement;

        return parent::exec($statement);
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;

        return parent::beginTransaction();
    }
}
