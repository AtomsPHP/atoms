<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Errors\AtomsError;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

final class HttpProbe extends Atom
{
    private const ORIGIN = 'https://api.example.com';

    public function exchange(string $method, string $url, array $headers = [], ?string $base64 = null): array
    {
        $response = $this->http()->request($method, $url, $headers, $base64 === null ? null : base64_decode($base64, true));
        return [$response->status, $response->headers, base64_encode($response->body)];
    }

    public function requests(array $steps): array
    {
        $results = [];
        foreach ($steps as $step) {
            try {
                $results[] = in_array($step, ['app', 'slowApp', 'delayedApp'], true)
                    ? $this->app()->probe($step)
                    : $this->http()->request('GET', self::ORIGIN . '/' . $step)->status;
            } catch (\Atoms\Cf\TurnDeadlineExceeded $error) {
                $results[] = 'ATOMS-E061';
            } catch (AtomsError $error) {
                $results[] = $error->errorCode->value;
            }
        }
        return $results;
    }

    public function transaction(string $kind): void
    {
        $request = fn() => $this->http()->request('GET', self::ORIGIN . '/transaction');
        if ($kind === 'helper') {
            $this->db()->transaction($request);
            return;
        }
        $pdo = $this->db()->pdo();
        if ($kind === 'sql') {
            $pdo->exec('BEGIN');
        } else {
            $pdo->beginTransaction();
        }
        try {
            $request();
        } finally {
            $pdo->rollBack();
        }
    }

    public function writeThenFetch(): void
    {
        $this->db()->execute('INSERT INTO events VALUES (1)');
        $this->http()->request('GET', self::ORIGIN . '/stall');
    }

    public function writes(): int
    {
        return (int) $this->db()->query('SELECT count(*) AS n FROM events')[0]['n'];
    }

    protected function onActivation(): void
    {
        $this->db()->execute('CREATE TABLE IF NOT EXISTS events (n INTEGER)');
        if (str_starts_with($this->id, 'lifecycle')) {
            $this->http()->request('GET', self::ORIGIN . '/activate');
        }
    }

    public function arm(): void
    {
        $this->timers()->schedule('http', new \DateTimeImmutable());
    }

    protected function onTimer(string $name): void
    {
        $this->http()->request('GET', self::ORIGIN . '/timer');
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        $conn->send((string) $this->http()->request('GET', self::ORIGIN . '/socket')->status);
    }

    protected function onDeactivation(): void
    {
        $this->http()->request('GET', self::ORIGIN . '/deactivate');
    }
}
