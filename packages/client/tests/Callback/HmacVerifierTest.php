<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\HmacVerifier;
use Atoms\Client\Crypto\KeyDerivation;
use PHPUnit\Framework\TestCase;

final class HmacVerifierTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** A second valid secret: 32 bytes of 0x02. */
    private const OTHER_SECRET = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    private function tag(string $message, string $key): string
    {
        return hash_hmac('sha256', $message, $key, true);
    }

    public function testVerifiesATagUnderTheConfiguredKey(): void
    {
        $key = KeyDerivation::callbackKey(self::SECRET);
        $verifier = new HmacVerifier([$key]);

        $message = "v1\n1755200000\nabc\n{}";

        self::assertTrue($verifier->verify($message, $this->tag($message, $key)));
    }

    public function testRejectsATamperedMessage(): void
    {
        $key = KeyDerivation::callbackKey(self::SECRET);
        $verifier = new HmacVerifier([$key]);

        $message = "v1\n1755200000\nabc\n{}";
        $tag = $this->tag($message, $key);

        self::assertFalse($verifier->verify($message . 'x', $tag));
    }

    public function testRejectsATagFromADifferentKey(): void
    {
        $verifier = new HmacVerifier([KeyDerivation::callbackKey(self::SECRET)]);

        $message = "v1\n1755200000\nabc\n{}";
        $wrongTag = $this->tag($message, KeyDerivation::callbackKey(self::OTHER_SECRET));

        self::assertFalse($verifier->verify($message, $wrongTag));
    }

    /**
     * A tag is 32 bytes. Anything else is refused before any comparison runs.
     */
    public function testRejectsSignaturesThatAreNotThirtyTwoBytes(): void
    {
        $key = KeyDerivation::callbackKey(self::SECRET);
        $verifier = new HmacVerifier([$key]);

        $message = "v1\n1755200000\nabc\n{}";
        $tag = $this->tag($message, $key);

        self::assertFalse($verifier->verify($message, ''));
        self::assertFalse($verifier->verify($message, 'too-short'));
        self::assertFalse($verifier->verify($message, substr($tag, 0, 31)));
        self::assertFalse($verifier->verify($message, $tag . "\x00"));
        self::assertFalse($verifier->verify($message, str_repeat("\x00", 32)));
    }

    /**
     * Rotation: the verifier holds the current key and the previous one, and
     * accepts a tag under either — trying each in turn rather than letting
     * anything in the request select a key.
     */
    public function testAcceptsEitherKeyDuringARotationOverlap(): void
    {
        $current = KeyDerivation::callbackKey(self::SECRET);
        $previous = KeyDerivation::callbackKey(self::OTHER_SECRET);
        $verifier = new HmacVerifier([$current, $previous]);

        $message = "v1\n1755200000\nabc\n{}";

        self::assertTrue($verifier->verify($message, $this->tag($message, $current)));
        self::assertTrue($verifier->verify($message, $this->tag($message, $previous)));
    }

    public function testRejectsAThirdKeyEvenDuringARotationOverlap(): void
    {
        $verifier = new HmacVerifier([
            KeyDerivation::callbackKey(self::SECRET),
            KeyDerivation::callbackKey(self::OTHER_SECRET),
        ]);

        $message = "v1\n1755200000\nabc\n{}";
        $stranger = $this->tag($message, str_repeat("\x09", 32));

        self::assertFalse($verifier->verify($message, $stranger));
    }

    public function testKeysComeFromTheSecretsInOrder(): void
    {
        $keys = KeyDerivation::callbackKeys(self::SECRET, self::OTHER_SECRET);

        self::assertSame([
            KeyDerivation::callbackKey(self::SECRET),
            KeyDerivation::callbackKey(self::OTHER_SECRET),
        ], $keys);
    }
}
