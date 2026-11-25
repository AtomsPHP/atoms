<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Jobs\Notify;
use Atoms\Atom;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * Destructible — dedicated conformance fixture for `DELETE /atoms/:type/:id`.
 *
 * Separate from Counter/Vault/Room/Scheduler/Boot so existing residency,
 * turn-count, and callback-count assertions remain undisturbed.
 *
 * Supports:
 *   - Durable state + event log with migrations (verifies wipe & fresh reuse)
 *   - Optional independent callback audit (`audit_label`) that dispatches
 *     App\Jobs\Notify from onActivation/onDeactivation/onTimer so conformance
 *     checks observe hooks externally even after local SQLite is wiped
 *   - Partial first activation failure (IDs prefixed with `partial-fail-`) that
 *     persists customer state before throwing in onActivation()
 *   - Deliberately held invoke (`holdAndMutate`) via `$this->app()->hold()`
 *   - Hostile `onDeactivation()` mode that writes SQLite, schedules a timer,
 *     and throws — proving cleanup runs strictly after the hook settles
 *   - WebSockets (`onConnect`/`onMessage`/`onDisconnect` + `broadcastText`) and
 *     timers (`arm`/`scheduledMs`/`onTimer`) on the same Atom for the release
 *     gate's immediate same-ID reuse test.
 */
final class Destructible extends Atom
{
    private int $turnsThisResidency = 0;

    private string $deactMode = 'normal';

    private string $auditLabel = '';

    private ?Connection $lastConnection = null;

    protected function onActivation(): void
    {
        $row = $this->readStateRow();
        $this->deactMode = (string) ($row['deact_mode'] ?? 'normal');
        $this->auditLabel = (string) ($row['audit_label'] ?? '');

        $this->db()->execute(
            'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
            ['activate', $this->id]
        );

        if ($this->auditLabel !== '') {
            $this->dispatch(Notify::class, [
                'atomId' => $this->id,
                'note' => 'activate:' . $this->auditLabel,
            ]);
        }

        if (str_starts_with($this->id, 'partial-fail-')) {
            $this->db()->execute(
                'UPDATE destructible_state SET tag = ?, counter = 77 WHERE id = 1',
                ['partial-before-throw']
            );
            throw new \RuntimeException('deliberate partial activation failure');
        }
    }

    protected function onDeactivation(): void
    {
        if ($this->auditLabel !== '') {
            $this->dispatch(Notify::class, [
                'atomId' => $this->id,
                'note' => 'deactivate:' . $this->auditLabel,
            ]);
        }

        if ($this->deactMode === 'hostile') {
            // Attempt to override the host's destruction close frame (1000 /
            // "Atom destroyed") with a guest-chosen code/reason; destruction
            // must own the close handshake so the client still sees 1000.
            $this->lastConnection?->close(4001, 'guest deactivation');

            $this->db()->execute(
                'UPDATE destructible_state SET tag = ?, counter = 999 WHERE id = 1',
                ['deact-residue']
            );
            $this->db()->execute(
                'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
                ['deact_write', 'should-not-survive']
            );

            $at = \DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%.6f', microtime(true) + 0.1)
            );
            $this->timers()->schedule('deact-timer', $at);

            throw new \RuntimeException('hostile onDeactivation explosion');
        }
    }

    protected function onTimer(string $name): void
    {
        $this->db()->execute(
            'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
            ['timer', $name]
        );

        if ($this->auditLabel !== '') {
            $this->dispatch(Notify::class, [
                'atomId' => $this->id,
                'note' => 'timer:' . $this->auditLabel . ':' . $name,
            ]);
        }
    }

    public function onConnect(Connection $conn, array $params): void
    {
        $this->lastConnection = $conn;

        $this->db()->execute(
            'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
            ['connect', $conn->id()]
        );

        $row = $this->readStateRow();
        $conn->sendJson([
            'kind' => 'welcome',
            'conn' => $conn->id(),
            'tag' => (string) ($row['tag'] ?? 'initial'),
        ]);
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        $payload = $msg->payload();
        $this->db()->execute(
            'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
            ['message', $conn->id() . ':' . $payload]
        );
        $conn->send('echo:' . $payload);
    }

    public function onDisconnect(Connection $conn): void
    {
        $this->db()->execute(
            'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
            ['disconnect', $conn->id()]
        );
    }

    public function setTag(string $tag): array
    {
        $this->turnsThisResidency++;
        $this->db()->execute(
            'UPDATE destructible_state SET tag = ?, counter = counter + 1 WHERE id = 1',
            [$tag]
        );

        return $this->stateSnapshot();
    }

    public function configureDeactivation(string $mode, string $auditLabel = ''): array
    {
        $this->turnsThisResidency++;
        $this->deactMode = $mode;
        $this->auditLabel = $auditLabel;

        $this->db()->execute(
            'UPDATE destructible_state SET deact_mode = ?, audit_label = ? WHERE id = 1',
            [$mode, $auditLabel]
        );

        return ['mode' => $this->deactMode, 'auditLabel' => $this->auditLabel];
    }

    public function holdAndMutate(string $tag): array
    {
        $this->turnsThisResidency++;
        $ack = $this->app()->hold($tag);

        $this->db()->execute(
            'UPDATE destructible_state SET tag = ?, counter = counter + 1 WHERE id = 1',
            [$tag]
        );
        $this->db()->execute(
            'INSERT INTO destructible_events (kind, detail) VALUES (?, ?)',
            ['held_mutate', $tag]
        );

        return ['ack' => $ack, 'state' => $this->stateSnapshot()];
    }

    public function arm(string $name, int $delayMs): void
    {
        $this->turnsThisResidency++;
        $at = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6f', microtime(true) + $delayMs / 1000)
        );
        $this->timers()->schedule($name, $at);
    }

    public function scheduledMs(string $name): ?int
    {
        $this->turnsThisResidency++;
        $at = $this->timers()->scheduledAt($name);

        return $at === null ? null : (int) $at->format('Uv');
    }

    public function broadcastText(string $channel, string $text): void
    {
        $this->turnsThisResidency++;
        $this->broadcast($channel, ['text' => $text]);
    }

    public function state(): array
    {
        $this->turnsThisResidency++;

        return $this->stateSnapshot();
    }

    /**
     * @return array<string, mixed>
     */
    private function readStateRow(): array
    {
        $rows = $this->db()->query('SELECT tag, counter, deact_mode, audit_label FROM destructible_state WHERE id = 1');

        return $rows[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshot(): array
    {
        $row = $this->readStateRow();
        $events = $this->db()->query('SELECT kind, detail FROM destructible_events ORDER BY id ASC');

        return [
            'tag' => (string) ($row['tag'] ?? 'initial'),
            'counter' => (int) ($row['counter'] ?? 0),
            'deactMode' => (string) ($row['deact_mode'] ?? 'normal'),
            'auditLabel' => (string) ($row['audit_label'] ?? ''),
            'turnsThisResidency' => $this->turnsThisResidency,
            'events' => array_map(
                static fn (array $e): array => ['kind' => (string) $e['kind'], 'detail' => (string) $e['detail']],
                $events
            ),
        ];
    }
}
