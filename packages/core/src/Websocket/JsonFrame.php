<?php

declare(strict_types=1);

namespace Atoms\Websocket;

use Atoms\Serialization\Serializer;

/**
 * The one encoder for structured WebSocket frames.
 *
 * {@see Connection::sendJson()}, {@see Message::json()} and the runtime's
 * `broadcast()` all pass through here, so a structured frame is normalized and
 * encoded identically whichever call produced it. The rule is deliberately
 * narrow and pinned by tests on both sides of the boundary:
 *
 * - {@see Serializer::normalize()} first, so the serialization type algebra
 *   applies to a frame exactly as it applies to an RPC argument;
 * - `json_encode()` with `JSON_UNESCAPED_SLASHES` and the default depth.
 *
 * Note what this class does NOT decide: the broadcast envelope. `broadcast()`
 * wraps its payload in `{"kind":"broadcast","channel":...,"payload":...}` before
 * encoding, because a socket on more than one channel needs to tell two
 * broadcasts apart; `sendJson()` has no channel and emits the object bare. The
 * asymmetry is the runtime's, not the encoder's — see
 * cloudflare/docs/mvp-spec.md §The three client-facing frame formats.
 *
 * Public because a customer with a bespoke framing can still reach it:
 * `$conn->send(MyEnvelope::wrap(JsonFrame::encode($payload)))` keeps their own
 * wrapper while inheriting the normalization rules.
 */
final class JsonFrame
{
    /**
     * Encode a structured frame.
     *
     * An empty array encodes as `{}`, not `[]`, so a frame built from an empty
     * map still decodes through {@see self::decode()}. A *nested* empty array is
     * still `[]` — `JSON_FORCE_OBJECT` would corrupt every nested list, so the
     * edge is documented rather than papered over.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \Atoms\Serialization\SerializationException if a value is outside the type algebra
     * @throws \JsonException                              if the normalized tree cannot be encoded (invalid UTF-8, depth)
     */
    public static function encode(array $payload, ?Serializer $serializer = null): string
    {
        $serializer ??= new Serializer();

        /** @var array<string, mixed> $normalized */
        $normalized = $serializer->normalize($payload);

        if ($normalized === []) {
            return '{}';
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode a structured frame into an array.
     *
     * Only a JSON **object** is a frame. A top-level list, scalar or `null` is
     * refused with the same exception type as malformed JSON, so one
     * `catch (\JsonException)` covers every way an inbound frame can be
     * unusable — which is the whole point of not inventing a second error type
     * for a condition the customer's own handler decides what to do about.
     *
     * Integers are decoded by `json_decode()`'s ordinary rules, so a value past
     * 2^53-1 arrives as a float. Carry such a value as a string.
     *
     * @return array<array-key, mixed> a numeric-string key such as `{"0":"a"}` decodes to an int key
     *
     * @throws \JsonException if the payload is not a JSON object
     */
    public static function decode(string $frame): array
    {
        $probe = json_decode($frame, false, flags: JSON_THROW_ON_ERROR);

        if (!$probe instanceof \stdClass) {
            throw new \JsonException(sprintf(
                'A structured WebSocket frame must be a JSON object, got %s.',
                get_debug_type($probe),
            ));
        }

        /** @var array<array-key, mixed> $decoded */
        $decoded = json_decode($frame, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
