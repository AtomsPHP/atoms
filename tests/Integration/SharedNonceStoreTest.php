<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Client\Callback\NonceStoreOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedNonceStoreTest extends TestCase
{
    public static function backends(): iterable
    {
        yield 'Laravel' => ['laravel'];
        yield 'Symfony' => ['symfony'];
    }

    #[DataProvider('backends')]
    public function testConcurrentProcessesClaimANonceOnce(string $backend): void
    {
        $database = tempnam(sys_get_temp_dir(), 'atoms-concurrent-nonce-');
        $pdo = new \PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE atoms_callback_nonces (nonce_hash VARCHAR(64) NOT NULL UNIQUE, expires_at BIGINT NOT NULL)');
        $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $process = proc_open([
                    PHP_BINARY, __DIR__ . '/Support/nonce-claim.php',
                    dirname(__DIR__, 2) . '/vendor/autoload.php', $backend, $database,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 10);
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($processes as [$process, $pipes]) {
                fwrite($pipes[0], "claim\n");
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $results[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
            sort($results);
            self::assertSame(['accepted', 'replay'], $results);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM atoms_callback_nonces')->fetchColumn());
            $options = NonceStoreOptions::fromArray(['namespace' => 'concurrency:test'], 300);
            self::assertSame($options->key('same-signed-callback'), $pdo->query('SELECT nonce_hash FROM atoms_callback_nonces')->fetchColumn());
        } finally {
            foreach ($processes as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            $pdo = null;
            unlink($database);
        }
    }
}
