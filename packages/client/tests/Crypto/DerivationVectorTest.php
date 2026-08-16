<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Crypto;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Crypto\KeyDerivation;
use PHPUnit\Framework\TestCase;

/**
 * Pins the cross-language reference vector from docs/shared-secret.md. The
 * Worker derives the same keys from the same secret with WebCrypto, so these
 * expectations are a contract between two implementations, not a restatement
 * of this one: change them only alongside the Worker and the conformance
 * suite.
 */
final class DerivationVectorTest extends TestCase
{
    /** The reference secret: bytes 0x00..0x1f, base64-encoded. */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const BEARER = 'Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=';

    private const CALLBACK_KEY = 'o5hmDR6tAEEoECTVtZm/BT1yzFkGWZYcDXXI/V1cYSM=';

    /** Signs WebSocket connection tickets — derived and used by this app, and re-derived by the Worker to verify. */
    private const TICKET_KEY = 'oAhR1o7PQdNULciqv8FZkgnlJ89a48C5wpdSEMXHBoA=';

    public function testBearerMatchesTheReferenceVector(): void
    {
        self::assertSame(self::BEARER, KeyDerivation::bearerToken(self::SECRET));
    }

    public function testCallbackKeyMatchesTheReferenceVector(): void
    {
        self::assertSame(self::CALLBACK_KEY, base64_encode(KeyDerivation::callbackKey(self::SECRET)));
    }

    /**
     * Both production paths to the ticket key — {@see KeyDerivation::ticketKey()}
     * directly, and {@see AtomsConfig::ticketKey()}'s memoized call to it —
     * must reproduce the vector the Worker derives independently: that
     * equality is what proves the two languages agree.
     */
    public function testTicketKeyMatchesTheReferenceVector(): void
    {
        self::assertSame(self::TICKET_KEY, base64_encode(KeyDerivation::ticketKey(self::SECRET)));

        $config = new AtomsConfig('https://atoms.example.workers.dev', self::SECRET);
        self::assertSame(self::TICKET_KEY, base64_encode($config->ticketKey()));
    }

    public function testConfigEmitsTheReferenceBearer(): void
    {
        $config = new AtomsConfig('https://atoms.example.workers.dev', self::SECRET);

        self::assertSame(self::BEARER, $config->bearerToken());
        self::assertSame(44, strlen($config->bearerToken()));
    }

    public function testEachPurposeGetsADistinctKey(): void
    {
        self::assertNotSame(self::BEARER, self::CALLBACK_KEY);
        self::assertNotSame(self::BEARER, base64_encode(KeyDerivation::ticketKey(self::SECRET)));
        self::assertNotSame(self::CALLBACK_KEY, base64_encode(KeyDerivation::ticketKey(self::SECRET)));
    }

    public function testDecodedSecretIsTheKeyMaterial(): void
    {
        self::assertSame(
            implode('', array_map(static fn (int $b): string => chr($b), range(0, 31))),
            KeyDerivation::decodeSecret(self::SECRET),
        );
    }
}
