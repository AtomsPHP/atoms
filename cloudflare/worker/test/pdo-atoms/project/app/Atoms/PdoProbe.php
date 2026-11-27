<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\PdoProbe\Support\SqlErrors;
use Atoms\Atom;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

// The Worker requires the Atom source before binding its activation window.
try {
    new PDO('atoms:');
    define('PDO_PROBE_BEFORE_ACTIVATION_REJECTED', false);
} catch (PDOException) {
    define('PDO_PROBE_BEFORE_ACTIVATION_REJECTED', true);
}

final class PdoProbe extends Atom
{
    private PDO $activationPdo;
    private PDOStatement $activationCursor;
    private int $cacheReads = 0;
    private ?object $lazyRow = null;
    private ?PDO $retained = null;
    private ?PDOStatement $cursor = null;
    private ?PDOStatement $secondCursor = null;
    private array $guardedHandles = [];

    protected function onActivation(): void
    {
        $this->activationPdo = new PDO('atoms:');
        $this->activationCursor = $this->activationPdo->query("SELECT 'activation row'");
    }

    public function registration(): array
    {
        $invalid = [
            ['atoms:other'],
            ["atoms:\0other"],
            ['atoms:', 'user'],
            ['atoms:', "\0user"],
            ['atoms:', null, 'password'],
            ['atoms:', null, null, [PDO::ATTR_PERSISTENT => true]],
        ];
        $rejected = [];
        foreach ($invalid as $args) {
            try {
                new PDO(...$args);
                $rejected[] = false;
            } catch (PDOException) {
                $rejected[] = true;
            }
        }

        return [
            'php' => PHP_VERSION,
            'drivers' => PDO::getAvailableDrivers(),
            'activation' => $this->activationPdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'activationCursorClosed' => $this->activationCursor->fetchColumn() === false,
            'turn' => (new PDO('atoms:'))->getAttribute(PDO::ATTR_DRIVER_NAME),
            'beforeActivationRejected' => PDO_PROBE_BEFORE_ACTIVATION_REJECTED,
            'invalidRejected' => $rejected,
        ];
    }

    public function namedArguments(): array
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING];
        $handles = [
            new PDO(dsn: 'atoms:', options: $options),
            new PDO(dsn: 'atoms:', password: '', options: $options),
        ];
        if (PHP_VERSION_ID >= 80400) {
            $handles[] = PDO::connect(dsn: 'atoms:', options: $options);
            $handles[] = PDO::connect(dsn: 'atoms:', password: '', options: $options);
        }

        return array_map(static fn (PDO $pdo): array => [
            $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            $pdo->getAttribute(PDO::ATTR_ERRMODE),
        ], $handles);
    }

    public function sqlErrors(): array
    {
        return [
            'silent' => SqlErrors::run(PDO::ERRMODE_SILENT),
            'warning' => SqlErrors::run(PDO::ERRMODE_WARNING),
            'exception' => SqlErrors::run(PDO::ERRMODE_EXCEPTION),
        ];
    }

    private function insert(PDO $pdo, string $key): void
    {
        $stmt = $pdo->prepare('INSERT INTO probe_rows (key, value) VALUES (?, ?)');
        $stmt->bindValue(1, $key, PDO::PARAM_STR);
        $stmt->bindValue(2, 5000000000, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function pending(): array
    {
        return $this->db()->query('SELECT value FROM probe_rows WHERE key = ?', ['pending']);
    }

    public function commitControl(): bool
    {
        $pdo = new PDO('atoms:');
        $pdo->beginTransaction();
        $this->insert($pdo, 'control');

        return $pdo->commit();
    }

    public function rows(): array
    {
        return $this->db()->query('SELECT key, value FROM probe_rows ORDER BY key');
    }

    public function retain(): string
    {
        $this->retained = new PDO('atoms:');
        $this->cursor = $this->retained->prepare('SELECT ? AS marker UNION ALL SELECT ?');
        $this->cursor->execute(['first-turn row', 'unread first-turn row']);
        $this->lazyRow = $this->cursor->fetch(PDO::FETCH_LAZY);
        $this->secondCursor = $this->activationPdo->query('SELECT * FROM probe_columns');
        foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING] as $mode) {
            $this->guardedHandles[] = new PDO('atoms:', options: [PDO::ATTR_ERRMODE => $mode]);
        }

        return $this->lazyRow->marker;
    }

    public function readSavedCursor(): array
    {
        $before = [$this->lazyRow->marker, $this->lazyRow[0]];
        $unread = [$this->cursor->fetchColumn(), $this->secondCursor->fetchColumn()];
        $closedColumnCount = $this->secondCursor->columnCount();
        $this->activationPdo->exec('ALTER TABLE probe_columns ADD COLUMN extra INTEGER DEFAULT 42');
        $this->secondCursor->execute();
        $this->cursor->execute(['second-turn row', 'unread second-turn row']);
        $beforeFetch = [$this->lazyRow->marker, $this->lazyRow[0]];
        $row = $this->cursor->fetch(PDO::FETCH_LAZY);

        return [
            'unread' => $unread,
            'closedColumnCount' => $closedColumnCount,
            'newColumnCount' => $this->secondCursor->columnCount(),
            'newColumns' => $this->secondCursor->fetch(PDO::FETCH_ASSOC),
            'oldValue' => $before,
            'oldValueAfterExecute' => $beforeFetch,
            'newValue' => $row->marker,
            'savedViewAfterFetch' => $this->lazyRow->marker,
        ];
    }

    public function rollbackPending(): array
    {
        $pdo = new PDO('atoms:');
        $pdo->beginTransaction();
        $this->insert($pdo, 'pending');
        $before = $this->pending();
        $guard = false;
        try {
            $this->app()->ping();
        } catch (Throwable $error) {
            $guard = str_contains($error->getMessage(), 'ATOMS-E082');
        }
        $cachedReads = [];
        foreach ($this->guardedHandles as $handle) {
            $stmt = $handle->prepare('SELECT ?');
            $stmt->execute(['cached']);
            $cachedReads[] = [$handle->getAttribute(PDO::ATTR_ERRMODE), $stmt->fetchColumn()];
        }
        unset($handle, $stmt);
        $this->guardedHandles = [];
        $this->lazyRow = null;
        $this->cursor = null;
        $this->secondCursor = null;
        $this->retained = null;
        $other = new PDO('atoms:');
        $otherOwns = $other->inTransaction();
        unset($other);
        $afterDrop = $this->pending();
        $owns = $pdo->inTransaction();
        $pdo->rollBack();

        return [
            'beforeDrop' => $before,
            'afterDrop' => $afterDrop,
            'callbackGuard' => $guard,
            'otherOwns' => $otherOwns,
            'ownerOwns' => $owns,
            'afterRollback' => $this->pending(),
            'cachedReads' => $cachedReads,
        ];
    }

    public function destroyOwner(): array
    {
        $pdo = new PDO('atoms:');
        $pdo->beginTransaction();
        $this->insert($pdo, 'destroyed');
        unset($pdo);

        return $this->rows();
    }

    public function abandon(): void
    {
        // Keep the owner alive past this method, so only turn cleanup can roll back.
        $this->retained = new PDO('atoms:');
        $this->retained->beginTransaction();
        $this->insert($this->retained, 'abandoned');
        $this->cursor = $this->retained->query("SELECT key FROM probe_rows WHERE key = 'abandoned'");
    }

    public function reuseAbandonedOwner(): array
    {
        $ownedAtStart = $this->retained->inTransaction();
        $unread = $this->cursor->fetchColumn();

        $other = new PDO('atoms:');
        $other->beginTransaction();
        $this->insert($other, 'other-owner');
        try {
            $this->retained->rollBack();
            $ownerRejected = false;
        } catch (PDOException) {
            $ownerRejected = true;
        }
        $otherOwns = $other->inTransaction();
        $other->rollBack();

        $this->retained->beginTransaction();
        $this->insert($this->retained, 'owner-reused');
        $pending = $this->db()->query("SELECT value FROM probe_rows WHERE key = 'owner-reused'");
        $this->retained->rollBack();

        return [
            'ownedAtStart' => $ownedAtStart,
            'unread' => $unread,
            'nonownerRollbackRejected' => $ownerRejected,
            'otherOwns' => $otherOwns,
            'reusedOwnerWrite' => $pending,
            'rows' => $this->rows(),
        ];
    }

    public function cachedValues(?int $value = null): array
    {
        if ($value !== null) {
            $write = $this->activationPdo->prepare("INSERT INTO probe_rows (key, value) VALUES ('identity', ?)");
            $write->bindValue(1, $value, PDO::PARAM_INT);
            $write->execute();
        }

        return [
            'id' => $this->id,
            'read' => ++$this->cacheReads,
            'objectIds' => [spl_object_id($this->activationPdo)],
            'nativeValue' => $this->activationPdo->query("SELECT value FROM probe_rows WHERE key = 'identity'")->fetchColumn(),
        ];
    }
}
