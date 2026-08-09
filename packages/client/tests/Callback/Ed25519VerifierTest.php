<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\Ed25519Verifier;
use Atoms\Client\Callback\InMemoryNonceStore;
use PHPUnit\Framework\TestCase;

final class Ed25519VerifierTest extends TestCase
{
    public function testVerifiesGoodSignatureAndRejectsTampered(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair);
        $secret = sodium_crypto_sign_secretkey($keypair);

        $message = 'v1\n123\nabc\n{}';
        $signature = sodium_crypto_sign_detached($message, $secret);

        $verifier = new Ed25519Verifier(base64_encode($public));
        self::assertTrue($verifier->verify($message, $signature));
        self::assertFalse($verifier->verify($message . 'x', $signature));
        self::assertFalse($verifier->verify($message, str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES)));
        self::assertFalse($verifier->verify($message, 'too-short'));
    }

    public function testAcceptsRawKey(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair);

        self::assertInstanceOf(Ed25519Verifier::class, new Ed25519Verifier($public));
    }

    public function testRejectsMalformedKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ed25519Verifier('not-a-real-key');
    }

    public function testNonceStoreDetectsReplayAndEvictsLru(): void
    {
        $store = new InMemoryNonceStore(2);

        self::assertFalse($store->seen('a'));
        self::assertTrue($store->seen('a'));

        self::assertFalse($store->seen('b'));
        self::assertFalse($store->seen('c')); // evicts 'a' (oldest)
        self::assertFalse($store->seen('a'), 'a was evicted so it reads as new again');
    }
}
