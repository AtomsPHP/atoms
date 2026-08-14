<?php

/**
 * `Atoms\Runtime\AtomContext` for the Cloudflare MVP — everything the customer's
 * Atom is allowed to reach, and the exact boundary of what the MVP implements.
 *
 * `db()`, `config()`, `app()`, `dispatch()`, `broadcast()` and `timers()` are
 * all real (M2 waves 1-3).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Runtime\AtomContext;
use Atoms\Serialization\Serializer;
use Atoms\Timers\Timers;

final class CfAtomContext implements AtomContext
{
    /** @var BridgeDatabase */
    private $db;

    /** @var SqlBridge */
    private $bridge;

    /** @var array{type: string, id: string} */
    private $identity;

    /** @var Serializer */
    private $serializer;

    /** @var CallbackAppProxy|null lazily built; one per Atom, like db()->pdo() */
    private $appProxy = null;

    /** @var CfTimers|null lazily built; one per Atom, like db()->pdo() and $appProxy */
    private $timers = null;

    /**
     * @param BridgeDatabase $db
     * @param SqlBridge $bridge the same instance $db shares with AtomsPDO, so
     *                          app()'s transaction guard and dispatch()'s
     *                          buffer-on-commit both observe one source of
     *                          truth for "is a transaction open"
     * @param array{type: string, id: string} $identity
     */
    public function __construct(BridgeDatabase $db, SqlBridge $bridge, array $identity)
    {
        $this->db = $db;
        $this->bridge = $bridge;
        $this->identity = $identity;
        $this->serializer = new Serializer();
    }

    /**
     * One database per Atom, shared by every turn of this residency.
     */
    public function db(): Database
    {
        return $this->db;
    }

    /**
     * Resolved by the host from an allowlisted view of the Worker's `env`
     * (mvp-spec.md §Sync ops). Unknown keys are null, not an error — same as the
     * platform runtime.
     */
    public function config(string $key): mixed
    {
        $reply = host_sync(['op' => 'config.get', 'key' => $key]);

        if (!array_key_exists('value', $reply)) {
            return null;
        }

        return int64_decode($reply['value']);
    }

    /**
     * Reverse RPC into the monolith's Methods class, over the signed
     * `app.call` park op. See {@see CallbackAppProxy} for the wire shape, the
     * transaction guard, and the documented result-hydration gap.
     */
    public function app(): object
    {
        if ($this->appProxy === null) {
            $this->appProxy = new CallbackAppProxy($this->identity, $this->bridge, $this->serializer);
        }

        return $this->appProxy;
    }

    /**
     * Encode the job from its promoted public constructor properties and
     * cross on the sync `dispatch.enqueue` op — dual to
     * `Atoms\Client\Callback\CallbackKernel::constructJob()`. Buffered on
     * commit / dropped on rollback when a transaction is open; delivered
     * immediately (fire-and-forget) otherwise.
     *
     * Implemented for ABI completeness, but unreachable from a deployed Atom:
     * the caller needs an INSTANCE, and an AtomJob's source does not ship in
     * the bundle, so `new SomeJob(...)` in Atom code is a build error
     * (`ATOMS-E104`) pointing at {@see dispatchJob()}. Kept because the ABI is
     * frozen and because a job class can legitimately be loaded in-guest by a
     * host that bundles differently.
     */
    public function dispatch(AtomJob $job): void
    {
        $class = get_class($job);
        $reflection = new \ReflectionClass($job);
        $ctor = $reflection->getConstructor();

        $args = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $name = $param->getName();

                if (!$reflection->hasProperty($name)) {
                    throw JobNotEncodable::missingProperty($class, $name);
                }

                $property = $reflection->getProperty($name);
                if (!$property->isPublic() || $property->isStatic()) {
                    throw JobNotEncodable::notPublic($class, $name);
                }

                $args[$name] = $this->serializer->normalize($property->getValue($job));
            }
        }

        $this->enqueueJob($class, $args);
    }

    /**
     * Dispatch by class name — the form Atom code uses, because an AtomJob's
     * source never ships to the platform. `SomeJob::class` is resolved by the
     * compiler, so this needs neither the class nor an instance: the arguments
     * arrive already separated from it, keyed by constructor parameter name,
     * which is the same map {@see dispatch()} builds by reflection and the same
     * one `CallbackKernel::constructJob()` reads on the monolith side. The wire
     * body is byte-identical between the two paths.
     *
     * @param string $job
     * @param array<string, mixed> $args
     */
    public function dispatchJob(string $job, array $args = []): void
    {
        $class = ltrim(trim($job), '\\');

        if ($class === '') {
            throw JobNotEncodable::unencodable($job, 'the job class name is empty');
        }

        $normalized = [];
        foreach ($args as $name => $value) {
            if (!is_string($name)) {
                throw JobNotEncodable::unencodable($class, sprintf(
                    'argument %s is positional; dispatchJob() takes constructor arguments by name',
                    var_export($name, true)
                ));
            }

            $normalized[$name] = $this->serializer->normalize($value);
        }

        $this->enqueueJob($class, $normalized);
    }

    /**
     * The one place the `{"job":FQCN,"args":{...}}` frame is built and crossed,
     * shared by both dispatch forms. `$args` is already normalized.
     *
     * @param string $class
     * @param array<string, mixed> $args
     */
    private function enqueueJob($class, array $args): void
    {
        $body = json_encode(
            ['job' => $class, 'args' => $args === [] ? new \stdClass() : $args],
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($body === false) {
            throw JobNotEncodable::unencodable($class, json_last_error_msg());
        }

        $reply = host_sync_raw(['op' => 'dispatch.enqueue', 'body' => $body, 'job' => $class]);

        if (!is_array($reply) || $reply['ok'] !== true) {
            $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];
            $atom = $this->identity['type'] . '/' . $this->identity['id'];

            throw CallbackChannel::exceptionFor($error, $atom, $class);
        }
    }

    /**
     * The GUEST builds the entire wire frame — `json_encode()` here, never a
     * structured payload handed to JS for `JSON.stringify()` — because that is
     * what keeps a wide integer inside `$payload` exact all the way to the
     * client. The host never parses or re-encodes `$frame`: it is a string in,
     * the same string out, fanned to every socket tagged for `$channel`
     * (mvp-spec.md's int64 rule).
     *
     * Wire shape, pinned: `{"kind":"broadcast","channel":...,"payload":...}`
     * — deliberately asymmetric with `Connection::send(string $payload)`,
     * which sends exactly the bytes the customer framed. `broadcast()` takes
     * a structure, so the runtime must serialize it, and once it does it must
     * also say which channel it came from: a socket on more than one channel
     * has no other way to tell two broadcasts apart.
     *
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, array $payload): void
    {
        $frame = json_encode(
            [
                'kind' => 'broadcast',
                'channel' => $channel,
                'payload' => $this->serializer->normalize($payload),
            ],
            JSON_UNESCAPED_SLASHES
        );

        if ($frame === false) {
            throw new \RuntimeException(sprintf(
                'Atoms: could not encode the broadcast payload for channel %s: %s.',
                $channel,
                json_last_error_msg()
            ));
        }

        // A sync op ('!' door): broadcasting does not park, so it works
        // identically from an invoke turn, a ws turn, or (later) an
        // alarm/queue turn — it needs no request context.
        // An over-cap fan-out is a refusal (ws_fanout_limit -> \RuntimeException
        // via host_sync()), never a truncated send.
        host_sync(['op' => 'ws.broadcast', 'channel' => $channel, 'frame' => $frame]);
    }

    /**
     * Named one-shot timers backed by the host's `__atoms_timers` table and
     * a single multiplexed Durable Object alarm (M2 wave 3).
     */
    public function timers(): Timers
    {
        if ($this->timers === null) {
            $this->timers = new CfTimers($this->identity);
        }

        return $this->timers;
    }
}
