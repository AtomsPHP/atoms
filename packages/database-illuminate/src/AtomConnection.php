<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Filesystem\Filesystem;

/**
 * An Illuminate SQLite connection wrapped around the \PDO an Atom already
 * holds (`$this->db()->pdo()`), instead of one Laravel connected itself.
 *
 * There is no DSN path to an Atom's durable database — on the Cloudflare
 * runtime the PDO is a bridge to the Durable Object's own SQLite — so the
 * connectors are bypassed entirely and four members are overridden, each for
 * a measured reason (see the Eloquent spike report):
 *
 *  - the grammar reports no savepoint support ({@see SavepointlessSQLiteGrammar});
 *  - transactions always open through PDO::beginTransaction(), never through
 *    a literal `BEGIN ... TRANSACTION` statement (which the runtime refuses
 *    exactly like SAVEPOINT — the stock connection emits one on PHP >= 8.4);
 *  - getServerVersion() answers from configuration: the runtime's PDO refuses
 *    ATTR_SERVER_VERSION (two different SQLite builds share the seam), and
 *    workerd denies `select sqlite_version()` ("not authorized to use
 *    function");
 *  - the schema builder is refused (ATOMS-E106): Atoms migrations own DDL.
 */
class AtomConnection extends SQLiteConnection
{
    /**
     * The version reported when the connection config carries none. Not a
     * capacity constant — a compatibility floor for Illuminate's own
     * version_compare() feature gates, every one of which (3.25 group limit,
     * 3.35 returning, 3.37 strict tables) is far below any SQLite the
     * Durable Object runtime has ever shipped. Override with the
     * `server_version` config key.
     */
    public const DEFAULT_SERVER_VERSION = '3.45.0';

    public function getServerVersion(): string
    {
        return (string) ($this->getConfig('server_version') ?? self::DEFAULT_SERVER_VERSION);
    }

    protected function getDefaultQueryGrammar()
    {
        return new SavepointlessSQLiteGrammar($this);
    }

    protected function executeBeginTransactionStatement()
    {
        $this->getPdo()->beginTransaction();
    }

    /**
     * There are no savepoints here, so rolling back "to a level" rolls back
     * the whole physical transaction. The parent's version is a pure counter
     * decrement for $toLevel > 0, which desynchronizes the counter from the
     * PDO: an explicit rollBack() inside a nested transaction() would let
     * the inner wrapper commit every "rolled back" write.
     *
     * The inTransaction() guard keeps exception unwinding intact — outer
     * levels find the transaction already closed and roll back as a no-op.
     * An explicit inner rollBack() discards the whole write set, and the
     * enclosing wrapper's commit then fails loudly on the closed
     * transaction. Prefer throwing over calling rollBack() by hand.
     */
    protected function performRollBack($toLevel)
    {
        $pdo = $this->getPdo();

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public function getSchemaBuilder(): never
    {
        throw self::schemaRefused(__FUNCTION__);
    }

    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): never
    {
        throw self::schemaRefused(__FUNCTION__);
    }

    private static function schemaRefused(string $member): AtomsError
    {
        return new AtomsError(
            ErrorCode::SchemaBuilderUnavailable,
            ErrorCatalog::format(ErrorCode::SchemaBuilderUnavailable, [
                'member' => sprintf('%s::%s()', self::class, $member),
            ]),
        );
    }
}
