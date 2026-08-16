<?php

declare(strict_types=1);

namespace Atoms\Client\Tickets;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\InvalidTicketClaims;

/**
 * Issues WebSocket connection tickets, locally.
 *
 * A browser's `new WebSocket(url)` cannot set an `Authorization` header, so it
 * presents a ticket as `?ticket=` on the `/ws/{type}/{id}` upgrade instead.
 * This class mints that ticket: your server calls {@see self::issue()}, hands
 * the string to the browser, and the Worker verifies it at the edge.
 *
 * Issuance is pure local computation — no HTTP, no round trip. The signing key
 * is HKDF-derived from `ATOMS_SHARED_SECRET`, which this application already
 * holds, so asking the Worker to sign on its behalf would have been a network
 * hop to compute something already computable here.
 *
 * The wire format, its limits, and the expiry rule are a cross-language
 * protocol shared with the Worker's verifier: docs/ws-ticket-protocol.md is
 * normative, and the vectors pinned there are asserted on both sides.
 *
 *     $ticket = $issuer->issue('Room', 'lobby', ['client_id' => (string) $user->id]);
 *     // → v1.<base64url payload>.<base64url HMAC-SHA256>
 *
 * Claims are the point of the thing: they are merged **over** the browser's
 * own query parameters on connect, server wins, so `onConnect` code reading
 * `$params['client_id']` gets a value the browser could not forge.
 *
 * A ticket is reusable until it expires — reconnecting inside the TTL can
 * retry the same URL — and the short lifetime is the whole defence against a
 * leaked URL. On any connection failure, issue a fresh one: a browser cannot
 * read why an upgrade failed.
 *
 * Whether an atom type is deployed, and whether it accepts WebSockets, is not
 * checked here: a locally bundled manifest can lag the deployed Worker, so the
 * upgrade itself stays authoritative and answers `unknown_atom_type` (404) or
 * `not_supported` (501).
 */
final class TicketIssuer
{
    /** Format version: the leading segment, and the first line of the signing input. */
    public const VERSION = 'v1';

    /** Claim entries one ticket may carry. */
    public const MAX_CLAIMS = 16;

    /** Total UTF-8 bytes of claim keys plus values in one ticket. */
    public const MAX_CLAIM_BYTES = 2048;

    /** Longest ticket string the Worker will even look at. */
    public const MAX_TICKET_BYTES = 8192;

    /**
     * Claim keys the protocol reserves.
     *
     * `channels` would desync the claims from actual channel membership, which
     * is fixed from the upgrade's query string; `ticket` is the query key the
     * ticket itself travels in.
     *
     * @var list<string>
     */
    public const RESERVED_CLAIM_KEYS = ['ticket', 'channels'];

    /** Atom types the Worker will accept, matching its own ATOM_TYPE_RE. */
    private const TYPE_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var callable(): int */
    private $clock;

    /** @var callable(int): string */
    private $randomBytes;

    /**
     * @param callable(): int|null       $clock       Returns the current time in epoch milliseconds; defaults to the system clock. For tests.
     * @param callable(int): string|null $randomBytes Receives a byte count, returns that many random bytes; defaults to random_bytes(). For tests.
     */
    public function __construct(
        private readonly AtomsConfig $config,
        ?callable $clock = null,
        ?callable $randomBytes = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
        $this->randomBytes = $randomBytes ?? static fn (int $bytes): string => random_bytes($bytes);
    }

    /**
     * Issue a ticket scoped to one atom.
     *
     * @param array<string, string> $claims Flat string→string map merged over the browser's query params on connect.
     * @param int|null              $ttlMs  Lifetime for this ticket; defaults to {@see AtomsConfig::$wsTicketTtlMs}.
     *
     * @throws InvalidTicketClaims when the scope or claims do not fit the protocol (ATOMS-E068).
     */
    public function issue(string $type, string $id, array $claims = [], ?int $ttlMs = null): Ticket
    {
        if (preg_match(self::TYPE_RE, $type) !== 1) {
            throw new InvalidTicketClaims($type, $id, sprintf(
                'the atom type %s is not a valid type name (letters, digits and underscores, not starting with a digit)',
                var_export($type, true),
            ));
        }

        if ($id === '') {
            throw new InvalidTicketClaims($type, $id, 'the atom id must not be empty');
        }

        $ttlMs ??= $this->config->wsTicketTtlMs;

        if ($ttlMs <= 0) {
            throw new InvalidTicketClaims($type, $id, sprintf(
                'the ticket lifetime must be a positive number of milliseconds, got %d',
                $ttlMs,
            ));
        }

        $payload = [
            't' => $type,
            'i' => $id,
            'exp' => ($this->clock)() + $ttlMs,
            'jti' => bin2hex(($this->randomBytes)(16)),
            // Cast, so an empty map serializes as {} rather than [], and a
            // numeric-string claim key cannot turn the map into a JSON list.
            'claims' => (object) $this->validateClaims($type, $id, $claims),
        ];

        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new InvalidTicketClaims($type, $id, 'a claim is not valid UTF-8', $e);
        }

        $payloadSegment = self::base64UrlEncode($json);
        $signature = hash_hmac(
            'sha256',
            self::VERSION . "\n" . $payloadSegment,
            $this->config->ticketKey(),
            true,
        );

        $ticket = self::VERSION . '.' . $payloadSegment . '.' . self::base64UrlEncode($signature);

        if (strlen($ticket) > self::MAX_TICKET_BYTES) {
            throw new InvalidTicketClaims($type, $id, sprintf(
                'the assembled ticket is %d bytes, over the %d-byte limit the Worker will read',
                strlen($ticket),
                self::MAX_TICKET_BYTES,
            ));
        }

        /** @var int $expiresAt */
        $expiresAt = $payload['exp'];

        return new Ticket($ticket, $expiresAt);
    }

    /**
     * @param array<string, string> $claims
     *
     * @return array<array-key, string>
     *
     * @throws InvalidTicketClaims
     */
    private function validateClaims(string $type, string $id, array $claims): array
    {
        if (count($claims) > self::MAX_CLAIMS) {
            throw new InvalidTicketClaims($type, $id, sprintf(
                'a ticket carries at most %d claims, got %d',
                self::MAX_CLAIMS,
                count($claims),
            ));
        }

        $bytes = 0;

        foreach ($claims as $key => $value) {
            // PHP turns a numeric-string array key into an int; the protocol
            // says keys are strings, so compare and measure the string form.
            $key = (string) $key;

            if (!is_string($value)) {
                throw new InvalidTicketClaims($type, $id, sprintf(
                    'claim %s must be a string, got %s',
                    var_export($key, true),
                    get_debug_type($value),
                ));
            }

            if (in_array($key, self::RESERVED_CLAIM_KEYS, true)) {
                throw new InvalidTicketClaims($type, $id, sprintf(
                    'claim key %s is reserved',
                    var_export($key, true),
                ));
            }

            $bytes += strlen($key) + strlen($value);
        }

        if ($bytes > self::MAX_CLAIM_BYTES) {
            throw new InvalidTicketClaims($type, $id, sprintf(
                'claim keys and values total %d bytes, over the %d-byte limit',
                $bytes,
                self::MAX_CLAIM_BYTES,
            ));
        }

        return $claims;
    }

    /** Canonical, unpadded base64url (RFC 4648 §5) — the verifier rejects anything else. */
    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
