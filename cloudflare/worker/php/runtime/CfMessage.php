<?php

/**
 * `Atoms\Websocket\Message` for the Cloudflare runtime: the decoded bytes of one
 * inbound frame, delivered to `Atom::onMessage()`.
 *
 * Immutable and host-constructed only — `turn_loop()` builds one per
 * `ws.message` turn from the envelope the host already decoded (base64 for a
 * binary frame, verbatim for text). `payload()` returns raw bytes either
 * way: PHP strings are byte-safe, so this honours `payload(): string`
 * exactly, binary or not.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Websocket\JsonFrame;
use Atoms\Websocket\Message;

final class CfMessage implements Message
{
    /** @var string */
    private $payload;

    /** @var bool */
    private $binary;

    /**
     * @param string $payload decoded bytes
     * @param bool $binary
     */
    public function __construct($payload, $binary)
    {
        $this->payload = $payload;
        $this->binary = $binary;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * Decodes the raw {@see self::payload()} — see {@see JsonFrame::decode()} for
     * what the payload must decode to. A decoder, not a content-type check: it
     * works whether this message is binary or text; consult {@see self::isBinary()}
     * if that distinction matters.
     */
    public function json(): array
    {
        return JsonFrame::decode($this->payload);
    }

    public function isBinary(): bool
    {
        return $this->binary;
    }
}
