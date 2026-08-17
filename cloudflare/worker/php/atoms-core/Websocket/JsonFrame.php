<?php

declare(strict_types=1);

namespace Atoms\Websocket;

use Atoms\Serialization\Serializer;

/**
 * The single encoder for structured WebSocket frames: {@see Connection::sendJson()},
 * {@see Message::json()} and the runtime's `broadcast()` all pass through it, so a
 * frame is normalized and encoded identically whichever call produced it.
 *
 * Public so a customer with bespoke framing can reach it
 * (`$conn->send(MyEnvelope::wrap(JsonFrame::encode($payload)))`); the envelope is
 * the caller's to add, not this class's.
 */
final class JsonFrame
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws \Atoms\Serialization\SerializationException if a value is outside the type algebra
     * @throws \JsonException                              if the normalized tree cannot be encoded
     */
    public static function encode(array $payload, ?Serializer $serializer = null): string
    {
        $serializer ??= new Serializer();

        /** @var array<string, mixed> $normalized */
        $normalized = $serializer->normalize($payload);

        // An empty array would encode as `[]`; force `{}` so an empty map round-trips
        // through decode(). A *nested* empty array stays `[]` — JSON_FORCE_OBJECT would
        // corrupt every nested list.
        if ($normalized === []) {
            return '{}';
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A frame's payload must decode to a JSON object; a top-level list, scalar or
     * `null` is rejected with `\JsonException` — the same exception type malformed
     * JSON raises, so one catch covers every unusable frame. Integers decode by
     * `json_decode()`'s ordinary rules, so a value past 2^53-1 arrives as a float;
     * carry it as a string.
     *
     * @return array<array-key, mixed> a numeric-string key such as `{"0":"a"}` decodes to an int key
     *
     * @throws \JsonException if the payload does not decode to a JSON object
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
