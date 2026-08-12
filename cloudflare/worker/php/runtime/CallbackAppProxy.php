<?php

/**
 * The `app()` proxy: reverse RPC into the monolith's Methods class, over the
 * signed `app.call` park op (design doc §7.1).
 *
 * `__call()` refuses inside a transaction before encoding anything
 * (`ctx.storage.transactionSync(cb)` cannot await — design doc §3.1), builds
 * the exact `methods` body the kernel expects with `json_encode()`, crosses,
 * and returns `json_decode($body, true)['result']` verbatim — the decoded
 * wire tree, not hydrated back into Payload DTOs/DateTimeImmutable/BackedEnum
 * (design doc §7.4; documented gap, not a bug).
 *
 * No int64 tagging on this wire: PHP writes a bare JSON number and the
 * kernel's json_decode() reads it back exactly, because the host never
 * parses a callback body (the opaque-body invariant, design doc §1.1). This
 * is the one place the callback wire and the PHP<->JS wire deliberately
 * disagree.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Serialization\Serializer;

final class CallbackAppProxy
{
    /** @var array{type: string, id: string} */
    private $identity;

    /** @var SqlBridge */
    private $bridge;

    /** @var Serializer */
    private $serializer;

    /**
     * @param array{type: string, id: string} $identity
     * @param SqlBridge $bridge the shared instance BridgeDatabase/AtomsPDO hold too,
     *                          so "is a transaction open" has one source of truth
     * @param Serializer $serializer
     */
    public function __construct(array $identity, SqlBridge $bridge, Serializer $serializer)
    {
        $this->identity = $identity;
        $this->bridge = $bridge;
        $this->serializer = $serializer;
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call($name, array $arguments)
    {
        if ($this->bridge->inTransaction()) {
            throw CallbackInTransaction::for((string) $name);
        }

        $atom = $this->identity['type'] . '/' . $this->identity['id'];

        $args = [];
        foreach (array_values($arguments) as $a) {
            $args[] = $this->serializer->normalize($a);
        }

        $body = json_encode([
            'atom' => ['type' => $this->identity['type'], 'id' => $this->identity['id']],
            'method' => (string) $name,
            'args' => $args,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($body === false) {
            throw CallbackFailed::unencodable($atom, (string) $name, json_last_error_msg());
        }

        $reply = host_park_raw(['op' => 'app.call', 'body' => $body]);

        if (!is_array($reply) || $reply['ok'] !== true) {
            $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];

            throw CallbackChannel::exceptionFor($error, $atom, (string) $name);
        }

        $status = isset($reply['status']) ? (int) $reply['status'] : 0;
        $responseBody = isset($reply['body']) && is_string($reply['body']) ? $reply['body'] : '';

        if ($status !== 200) {
            throw CallbackFailed::status($atom, (string) $name, $status, $responseBody);
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw CallbackFailed::malformedResponse($atom, (string) $name, $e->getMessage());
        }

        if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
            throw CallbackFailed::malformedResponse(
                $atom,
                (string) $name,
                'the monolith did not answer with a result envelope'
            );
        }

        return $decoded['result'];
    }
}
