<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Tickets;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\InvalidTicketClaims;
use Atoms\Client\Tickets\TicketIssuer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WebSocket ticket wire format against the reference vectors in
 * docs/ws-ticket-protocol.md. The Worker's verifier reproduces the same
 * base64url payload, HMAC-SHA256 signature and byte limits independently, and
 * the conformance suite asserts the same vectors on that side — these
 * expectations are a cross-language contract, not a restatement of
 * TicketIssuer's own logic, and must change only alongside both.
 */
final class TicketIssuerTest extends TestCase
{
    /** The reference secret: bytes 0x00..0x1f, base64-encoded. */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** Fixed clock, epoch milliseconds. */
    private const CLOCK_MS = 1755200000000;

    private const VECTOR_1 = 'v1.eyJ0IjoiUm9vbSIsImkiOiJ2ZWN0b3ItMSIsImV4cCI6MTc1NTIwMDA2MDAwMCwianRpIjoiMDAwMTAyMDMwNDA1'
        . 'MDYwNzA4MDkwYTBiMGMwZDBlMGYiLCJjbGFpbXMiOnsiY2xpZW50X2lkIjoidS00MiIsIm5hbWUiOiJab8OrIOKcqCIsInBhdGgiOiJhL2Ii'
        . 'fX0.p3PJLrBSNdsUUEiq4nL3zvnKq7iiozRibGPGd87zgyM';

    private const VECTOR_2 = 'v1.eyJ0IjoiUm9vbSIsImkiOiJ2ZWN0b3ItMiIsImV4cCI6MTc1NTIwMDA2MDAwMCwianRpIjoiMDAwMTAyMDMwNDA1'
        . 'MDYwNzA4MDkwYTBiMGMwZDBlMGYiLCJjbGFpbXMiOnt9fQ.1C0xNRHM-ev1U6yv8G0pEPLcO0jhGhv5YItL6Yku9-o';

    private function issuer(array $configOverrides = []): TicketIssuer
    {
        $config = AtomsConfig::fromArray($configOverrides + [
            'endpoint' => 'https://atoms.example.workers.dev',
            'sharedSecret' => self::SECRET,
        ]);

        return new TicketIssuer(
            $config,
            static fn (): int => self::CLOCK_MS,
            static fn (int $n): string => substr(implode('', array_map('chr', range(0, 15))), 0, $n),
        );
    }

    /** @return array<string, mixed> the decoded JSON payload */
    private static function decodePayload(string $ticket): array
    {
        [, $payloadSegment] = explode('.', $ticket);

        $json = base64_decode(strtr($payloadSegment, '-_', '+/'), true);
        self::assertIsString($json, 'payload segment must decode as base64url');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function payloadJson(string $ticket): string
    {
        [, $payloadSegment] = explode('.', $ticket);

        $json = base64_decode(strtr($payloadSegment, '-_', '+/'), true);
        self::assertIsString($json);

        return $json;
    }

    public function testVector1MatchesTheReferenceTicketExactly(): void
    {
        $ticket = $this->issuer()->issue('Room', 'vector-1', [
            'client_id' => 'u-42',
            'name' => "Zoë \u{2728}",
            'path' => 'a/b',
        ]);

        self::assertSame(self::VECTOR_1, $ticket->ticket);
        self::assertSame(1755200060000, $ticket->expiresAtMs);
    }

    public function testVector2WithNoClaimsMatchesTheReferenceTicketExactly(): void
    {
        $ticket = $this->issuer()->issue('Room', 'vector-2', []);

        self::assertSame(self::VECTOR_2, $ticket->ticket);

        $decoded = self::decodePayload($ticket->ticket);
        self::assertSame([], $decoded['claims']);
        self::assertStringContainsString('"claims":{}', self::payloadJson($ticket->ticket));
    }

    public function testPerCallTtlOverridesTheConfiguredDefault(): void
    {
        $ticket = $this->issuer()->issue('Room', 'vector-1', [], 5000);

        self::assertSame(1755200005000, $ticket->expiresAtMs);
    }

    public function testJtiIsThirtyTwoLowercaseHexCharacters(): void
    {
        $ticket = $this->issuer()->issue('Room', 'x');
        $decoded = self::decodePayload($ticket->ticket);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $decoded['jti']);
        self::assertSame('000102030405060708090a0b0c0d0e0f', $decoded['jti']);
    }

    public function testTwoTicketsIssuedWithRealRandomnessGetDifferentJti(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'sharedSecret' => self::SECRET,
        ]);
        $issuer = new TicketIssuer($config, static fn (): int => self::CLOCK_MS);

        $a = self::decodePayload($issuer->issue('Room', 'x')->ticket);
        $b = self::decodePayload($issuer->issue('Room', 'x')->ticket);

        self::assertNotSame($a['jti'], $b['jti']);
    }

    public function testExactlySixteenClaimsSucceeds(): void
    {
        $claims = [];

        for ($i = 0; $i < TicketIssuer::MAX_CLAIMS; $i++) {
            $claims["k$i"] = 'v';
        }

        $ticket = $this->issuer()->issue('Room', 'x', $claims);

        self::assertCount(TicketIssuer::MAX_CLAIMS, self::decodePayload($ticket->ticket)['claims']);
    }

    public function testSeventeenClaimsThrows(): void
    {
        $claims = [];

        for ($i = 0; $i < TicketIssuer::MAX_CLAIMS + 1; $i++) {
            $claims["k$i"] = 'v';
        }

        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('Room', 'x', $claims);
    }

    public function testExactlyTheClaimByteLimitSucceeds(): void
    {
        // One claim: key 'k' (1 byte) + value padded to MAX_CLAIM_BYTES - 1 bytes.
        $value = str_repeat('a', TicketIssuer::MAX_CLAIM_BYTES - 1);
        $ticket = $this->issuer()->issue('Room', 'x', ['k' => $value]);

        self::assertSame($value, self::decodePayload($ticket->ticket)['claims']['k']);
    }

    public function testOneByteOverTheClaimByteLimitThrows(): void
    {
        $value = str_repeat('a', TicketIssuer::MAX_CLAIM_BYTES);

        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('Room', 'x', ['k' => $value]);
    }

    public function testReservedClaimKeyTicketThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('Room', 'x', ['ticket' => 'nope']);
    }

    public function testReservedClaimKeyChannelsThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('Room', 'x', ['channels' => 'nope']);
    }

    public function testNonStringClaimValueThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        // @phpstan-ignore-next-line argument.type (exercising the runtime guard behind the array<string,string> phpdoc)
        $this->issuer()->issue('Room', 'x', ['n' => 123]);
    }

    public function testInvalidUtf8InAClaimValueThrowsWrappingAJsonException(): void
    {
        try {
            $this->issuer()->issue('Room', 'x', ['bad' => "\xB1\x31"]);
            self::fail('expected InvalidTicketClaims');
        } catch (InvalidTicketClaims $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testATicketOverTheOverallByteLimitThrows(): void
    {
        $longId = str_repeat('a', TicketIssuer::MAX_TICKET_BYTES);

        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('Room', $longId);
    }

    public function testInvalidTypeStartingWithADigitThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('9bad', 'x');
    }

    public function testInvalidTypeWithADashThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('has-dash', 'x');
    }

    public function testEmptyTypeThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('', 'x');
    }

    public function testEmptyIdThrows(): void
    {
        $this->expectException(InvalidTicketClaims::class);
        $this->issuer()->issue('Room', '');
    }

    public function testPayloadKeyOrderIsExactlyTypeIdExpJtiClaims(): void
    {
        $ticket = $this->issuer()->issue('Room', 'x', ['a' => 'b']);
        $json = self::payloadJson($ticket->ticket);

        self::assertStringStartsWith('{"t":', $json);

        $posT = strpos($json, '"t":');
        $posI = strpos($json, '"i":');
        $posExp = strpos($json, '"exp":');
        $posJti = strpos($json, '"jti":');
        $posClaims = strpos($json, '"claims":');

        self::assertNotFalse($posT);
        self::assertNotFalse($posI);
        self::assertNotFalse($posExp);
        self::assertNotFalse($posJti);
        self::assertNotFalse($posClaims);

        self::assertTrue($posT < $posI);
        self::assertTrue($posI < $posExp);
        self::assertTrue($posExp < $posJti);
        self::assertTrue($posJti < $posClaims);
    }

    public function testNumericStringClaimKeySerializesAsAnObjectMember(): void
    {
        $ticket = $this->issuer()->issue('Room', 'x', ['0' => 'zero']);
        $json = self::payloadJson($ticket->ticket);

        self::assertStringContainsString('"claims":{"0":"zero"}', $json);
    }

    public function testExceptionMessageCarriesTheCatalogCodeAndAFix(): void
    {
        try {
            $this->issuer()->issue('', 'x');
            self::fail('expected InvalidTicketClaims');
        } catch (InvalidTicketClaims $e) {
            self::assertStringContainsString('ATOMS-E068', $e->getMessage());
            self::assertStringContainsString('Fix:', $e->getMessage());
        }
    }

    public function testTicketCastsToStringAndTheExceptionExposesTypeAndId(): void
    {
        $ticket = $this->issuer()->issue('Room', 'vector-2', []);
        self::assertSame($ticket->ticket, (string) $ticket);

        try {
            $this->issuer()->issue('Ghost', 'g-1', ['ticket' => 'x']);
            self::fail('expected InvalidTicketClaims');
        } catch (InvalidTicketClaims $e) {
            self::assertSame('Ghost', $e->type);
            self::assertSame('g-1', $e->id);
        }
    }
}
