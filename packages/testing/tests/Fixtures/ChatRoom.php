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
        $this->dispatch(RecordResult::class, [
            'user' => $username,
            'points' => $points,
            'score' => $score,
            'recordedAt' => $recordedAt,
        ]);
    }

    /**
     * Dispatches the same job without every required argument, so the harness
     * can prove reconstruction refuses it with the catalog code.
     */
    public function recordPartialScore(string $username): void
    {
        $this->dispatch(RecordResult::class, ['user' => $username]);
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
        if (str_starts_with($msg->payload(), '{') || str_starts_with($msg->payload(), '[')) {
            try {
                $conn->sendJson(['kind' => 'echo', 'frame' => $msg->json()]);
            } catch (\JsonException $e) {
                $conn->sendJson(['kind' => 'error', 'reason' => $e->getMessage()]);
            }

            return;
        }

        $conn->send('echo:' . $msg->payload());
    }

    public function onDisconnect(Connection $conn): void
    {
        $conn->send('bye');
    }

    protected function onActivation(): void
    {
        $this->db()->execute('INSERT INTO messages (username, body) VALUES (?, ?)', ['system', 'activated']);

        // Escape hatch for the re-entrancy tests: activation is the one point
        // where the harness runs user code while its own boot() is still on
        // the stack, and only a test supplying this key reaches it.
        $hook = $this->config('boot_hook');

        if ($hook instanceof \Closure) {
            $hook();
        }
    }

    protected function onDeactivation(): void
    {
        $this->deactivated = true;
    }
}
