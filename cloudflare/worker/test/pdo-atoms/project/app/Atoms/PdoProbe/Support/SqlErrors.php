<?php

declare(strict_types=1);

namespace App\Atoms\PdoProbe\Support;

use PDO;
use PDOException;
use PDOStatement;

final class SqlErrors
{
    public static function run(int $mode): array
    {
        $pdo = new PDO('atoms:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);

        $exec = self::observe($pdo, fn () => $pdo->exec('DELETE FROM missing_probe_table'));
        $pdo->exec('DELETE FROM probe_rows WHERE 0');
        $execReset = $pdo->errorInfo();

        // query() prepares and executes internally. Its diagnostics belong to PDO.
        $query = self::observe($pdo, fn () => $pdo->query('SELECT * FROM missing_probe_table'));
        $pdo->query('SELECT 1');
        $queryReset = $pdo->errorInfo();

        $statement = $pdo->prepare('INSERT INTO probe_rows (key, value) VALUES (?, 7)');
        $execute = self::observe($statement, fn () => $statement->execute(['control']));
        $connectionAfterStatementError = $pdo->errorInfo();
        $statement->execute(['error-recovery']);
        $statementReset = $statement->errorInfo();
        $pdo->exec("DELETE FROM probe_rows WHERE key = 'error-recovery'");

        return [
            'exec' => $exec,
            'query' => $query,
            'execute' => $execute,
            'resets' => [$execReset, $queryReset, $statementReset],
            'connectionAfterStatementError' => $connectionAfterStatementError,
        ];
    }

    private static function observe(PDO|PDOStatement $source, callable $operation): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        }, E_WARNING);
        $exception = null;
        try {
            $result = $operation();
        } catch (PDOException $e) {
            $result = null;
            $exception = ['code' => $e->getCode(), 'info' => $e->errorInfo, 'message' => $e->getMessage()];
        } finally {
            restore_error_handler();
        }

        return [
            'result' => $result,
            'warnings' => $warnings,
            'exception' => $exception,
            'code' => $source->errorCode(),
            'info' => $source->errorInfo(),
        ];
    }
}
