<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Controller;

use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Controller\CallbackController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end: a Symfony HttpFoundation Request carrying a real Ed25519
 * signature, routed through the fully DI-wired CallbackController, must come
 * back out as a Symfony Response — proving the manual Request<->PSR-7
 * conversion and the callback stack's signature verification both work
 * together, with no psr/http-message-bridge involved.
 */
final class CallbackControllerTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    }

    private function controller(): CallbackController
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new AtomsBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), [
            'project' => 'acme-games',
            'endpoint' => 'https://edge.atoms.test',
            'api_key' => 'atoms_v1_test_key',
            'platform_public_key' => base64_encode($this->publicKey),
        ]);
        $container->compile();

        $controller = $container->get(CallbackController::class);
        self::assertInstanceOf(CallbackController::class, $controller);

        return $controller;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signedRequest(
        string $kind,
        array $payload,
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $signatureOverride = null,
    ): Request {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = $signatureOverride ?? base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));

        $request = Request::create('https://app.test/atoms/callback', 'POST', content: $body);
        $request->headers->set('X-Atoms-Kind', $kind);
        $request->headers->set('X-Atoms-Timestamp', $ts);
        $request->headers->set('X-Atoms-Nonce', $nonce);
        $request->headers->set('X-Atoms-Signature', $signature);

        return $request;
    }

    public function testSignedMethodsRequestExecutesFixtureAndReturns200(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => \Atoms\Symfony\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = ($this->controller())($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 5], json_decode((string) $response->getContent(), true));
    }

    public function testBadSignatureIsRejectedWith401(): void
    {
        $request = $this->signedRequest(
            'methods',
            [
                'atom' => ['type' => \Atoms\Symfony\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
                'method' => 'add',
                'args' => [2, 3],
            ],
            signatureOverride: base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_BYTES)),
        );

        $response = ($this->controller())($request);

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('ATOMS-E064', $body['error']['code']);
    }
}
