<?php

declare(strict_types=1);

namespace Atoms\Client\Tests;

use Atoms\Client\AtomsConfig;
use PHPUnit\Framework\TestCase;

/**
 * The shared secret is required and validated at construction: base64 of
 * exactly 32 bytes, whitespace trimmed. Every key on the boundary is derived
 * from it, so a malformed value is a configuration error (ATOMS-E105).
 */
final class AtomsConfigTest extends TestCase
{
    /** The reference vector's secret: bytes 0x00..0x1f. */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    public function testValidSecretSurvivesFromArray(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'sharedSecret' => self::SECRET,
        ]);

        self::assertSame(self::SECRET, $config->sharedSecret);
        self::assertNull($config->sharedSecretPrevious);
    }

    public function testSnakeCaseKeysAreAccepted(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'shared_secret' => self::SECRET,
            'shared_secret_previous' => self::SECRET,
        ]);

        self::assertSame(self::SECRET, $config->sharedSecret);
        self::assertSame(self::SECRET, $config->sharedSecretPrevious);
    }

    public function testMissingSecretThrowsE105(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        AtomsConfig::fromArray(['endpoint' => 'https://atoms.example.workers.dev']);
    }

    public function testEmptySecretThrowsE105(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        new AtomsConfig('https://atoms.example.workers.dev', '');
    }

    public function testNonBase64SecretThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        new AtomsConfig('https://atoms.example.workers.dev', 'not base64 at all!!');
    }

    public function testThirtyOneByteSecretThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AtomsConfig('https://atoms.example.workers.dev', base64_encode(str_repeat("\x01", 31)));
    }

    public function testThirtyThreeByteSecretThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AtomsConfig('https://atoms.example.workers.dev', base64_encode(str_repeat("\x01", 33)));
    }

    /**
     * Secrets arrive from .env files and CI panels, so surrounding whitespace
     * is trimmed before decoding.
     */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $config = new AtomsConfig('https://atoms.example.workers.dev', "  \n" . self::SECRET . "\t\n");

        self::assertSame('Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=', $config->bearerToken());
    }

    public function testPreviousSecretIsValidatedToTheSameRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS_SHARED_SECRET_PREVIOUS/');

        new AtomsConfig(
            'https://atoms.example.workers.dev',
            self::SECRET,
            sharedSecretPrevious: 'still-not-base64!!',
        );
    }

    public function testValidPreviousSecretIsAccepted(): void
    {
        $previous = base64_encode(str_repeat("\x02", 32));

        $config = new AtomsConfig(
            'https://atoms.example.workers.dev',
            self::SECRET,
            sharedSecretPrevious: $previous,
        );

        self::assertSame($previous, $config->sharedSecretPrevious);
        self::assertCount(2, $config->callbackKeys());
    }

    public function testBearerTokenIsMemoizedAndStable(): void
    {
        $config = new AtomsConfig('https://atoms.example.workers.dev', self::SECRET);

        $first = $config->bearerToken();

        self::assertSame($first, $config->bearerToken());
        self::assertSame(44, strlen($first));
    }

    public function testCallbackKeysAreMemoizedAndRawThirtyTwoByteValues(): void
    {
        $config = new AtomsConfig('https://atoms.example.workers.dev', self::SECRET);

        $keys = $config->callbackKeys();

        self::assertSame($keys, $config->callbackKeys());
        self::assertCount(1, $keys);
        self::assertSame(32, strlen($keys[0]));
    }

    /**
     * The previous secret widens callback acceptance only; the bearer this
     * app sends always comes from the current secret.
     */
    public function testPreviousSecretDoesNotChangeTheEmittedBearer(): void
    {
        $current = new AtomsConfig('https://atoms.example.workers.dev', self::SECRET);
        $rotating = new AtomsConfig(
            'https://atoms.example.workers.dev',
            self::SECRET,
            sharedSecretPrevious: base64_encode(str_repeat("\x02", 32)),
        );

        self::assertSame($current->bearerToken(), $rotating->bearerToken());
        self::assertSame($current->callbackKeys()[0], $rotating->callbackKeys()[0]);
    }

    public function testBaseUrlStripsTrailingSlash(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev/',
            'sharedSecret' => self::SECRET,
        ]);

        self::assertSame('https://atoms.example.workers.dev', $config->baseUrl());
        self::assertSame(
            'https://atoms.example.workers.dev',
            (new AtomsConfig('https://atoms.example.workers.dev/', self::SECRET))->baseUrl(),
        );
    }

    public function testWsBaseUrlSwapsTheSchemeAndKeepsEverythingElse(): void
    {
        $cases = [
            'https://atoms.example.workers.dev' => 'wss://atoms.example.workers.dev',
            'http://127.0.0.1:8787' => 'ws://127.0.0.1:8787',
            // A trailing slash is already stripped by baseUrl(); a path prefix
            // is not, because a Worker can be mounted under one.
            'https://example.test/atoms/' => 'wss://example.test/atoms',
            'https://example.test:8443/edge' => 'wss://example.test:8443/edge',
        ];

        foreach ($cases as $endpoint => $expected) {
            $config = new AtomsConfig($endpoint, self::SECRET);

            self::assertSame($expected, $config->wsBaseUrl(), $endpoint);
        }
    }

    public function testWsBaseUrlRefusesAnEndpointWithNoHttpScheme(): void
    {
        $config = new AtomsConfig('atoms.example.workers.dev', self::SECRET);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no http:// or https:// scheme');

        $config->wsBaseUrl();
    }
}
