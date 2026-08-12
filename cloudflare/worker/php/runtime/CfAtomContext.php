<?php

/**
 * `Atoms\Runtime\AtomContext` for the Cloudflare MVP — everything the customer's
 * Atom is allowed to reach, and the exact boundary of what the MVP implements.
 *
 * `db()`, `config()`, `app()` and `dispatch()` are real. `broadcast()` and
 * `timers()` are out of scope for this wave and throw {@see AtomsNotSupported}
 * naming the limitation (mvp-spec.md §Scope: "These are explicit stubs, never
 * silent no-ops"). A customer Atom that calls them fails its turn with a clear
 * `atom_exception` envelope rather than appearing to have sent something.
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
     * `Atoms\Client\Callback\CallbackKernel::constructJob()` (design doc
     * §7.5). Buffered on commit / dropped on rollback when a transaction is
     * open; delivered immediately (fire-and-forget) otherwise.
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
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, array $payload): void
    {
        throw new AtomsNotSupported(
            'Atom::broadcast()',
            sprintf(
                'WebSockets are out of scope for the Cloudflare MVP, so there is nothing subscribed to "%s".',
                $channel
            )
        );
    }

    // Replaced by the timers wave.
    public function timers(): Timers
    {
        throw new AtomsNotSupported(
            'Atom::timers()',
            'M2 wave 0 adds Atoms\Timers\Timers to the AtomContext ABI so atoms-core '
            . 'vendors cleanly, but a later M2 wave is what actually schedules timers '
            . 'on this Worker runtime.'
        );
    }
}
