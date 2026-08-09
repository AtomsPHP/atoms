<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests\Fixtures;

use Atoms\Atom;
use Atoms\Testing\Tests\Fixtures\ChatRoom\Methods;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * Fixture Atom exercising every AtomHarness deliverable: db()/migrations,
 * app() proxy round-tripping, dispatch(), broadcast(), a serialization
 * violation (badReturn), WebSocket handlers, config(), and lifecycle hooks.
 *
 * @extends Atom<Methods>
 */
final class ChatRoom extends Atom
{
    public bool $deactivated = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function join(string $username): array
    {
        $this->db()->execute('INSERT INTO messages (username, body) VALUES (?, ?)', [$username, "{$username} joined"]);

        return $this->db()->query('SELECT username, body FROM messages WHERE username = ?', [$username]);
    }

    public function screenAndPost(string $text): string
    {
        $clean = $this->app()->screen($text);

        $this->broadcast('room', ['text' => $clean]);

        return $clean;
    }

    public function describeScore(Score $score, \DateTimeImmutable $at): string
    {
        return $this->app()->describe($score, $at);
    }

    public function recordScore(string $username, int $points, Score $score, \DateTimeImmutable $recordedAt): void
    {
        $this->dispatch(new RecordResult($username, $points, $score, $recordedAt));
    }

    public function badReturn(): object
    {
        return new \stdClass();
    }

    public function callUnknownAppMethod(): void
    {
        // @phpstan-ignore-next-line intentionally calling an undeclared Methods method
        $this->app()->thisMethodDoesNotExistOnMethods();
    }

    public function readConfig(string $key): mixed
    {
        return $this->config($key);
    }

    public function onConnect(Connection $conn, array $params): void
    {
        $conn->send('connected:' . ($params['token'] ?? ''));
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        $conn->send('echo:' . $msg->payload());
    }

    public function onDisconnect(Connection $conn): void
    {
        $conn->send('bye');
    }

    protected function onActivation(): void
    {
        $this->db()->execute('INSERT INTO messages (username, body) VALUES (?, ?)', ['system', 'activated']);
    }

    protected function onDeactivation(): void
    {
        $this->deactivated = true;
    }
}
