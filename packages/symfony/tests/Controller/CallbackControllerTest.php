<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Controller;

use Atoms\Client\Callback\CallbackKernelFactory;
use Atoms\Client\Crypto\KeyDerivation;
use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Controller\CallbackController;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end: a Symfony HttpFoundation Request carrying a real HMAC-SHA256
 * signature, routed through the fully DI-wired CallbackController, must come
 * back out as a Symfony Response — proving the manual Request<->PSR-7
 * conversion and the callback stack's signature verification both work
 * together, with no psr/http-message-bridge involved.
 */
final class CallbackControllerTest extends TestCase
{
    /** The reference vector's secret (docs/shared-secret.md). */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** A second valid secret: 32 bytes of 0x02. */
    private const PREVIOUS_SECRET = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    /**
     * @param array<string, mixed> $extraConfig
     */
    private function controller(array $extraConfig = []): CallbackController
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new AtomsBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), array_merge([
            'endpoint' => 'https://atoms.example.workers.dev',
            'shared_secret' => self::SECRET,
        ], $extraConfig));
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
        string $secret = self::SECRET,
    ): Request {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = $signatureOverride
            ?? base64_encode(hash_hmac('sha256', $message, KeyDerivation::callbackKey($secret), true));

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
            signatureOverride: base64_encode(str_repeat("\x01", 32)),
        );

        $response = ($this->controller())($request);

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('ATOMS-E064', $body['error']['code']);
    }

    /**
     * Rotation: with the overlap configured, the DI-wired controller accepts a
     * callback signed under either secret.
     */
    public function testPreviousSecretIsAcceptedWhileTheOverlapIsConfigured(): void
    {
        $controller = $this->controller([
            'shared_secret' => self::PREVIOUS_SECRET,
            'shared_secret_previous' => self::SECRET,
        ]);

        $payload = [
            'atom' => ['type' => \Atoms\Symfony\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ];

        self::assertSame(200, $controller($this->signedRequest('methods', $payload, secret: self::PREVIOUS_SECRET))->getStatusCode());
        self::assertSame(200, $controller($this->signedRequest('methods', $payload, secret: self::SECRET))->getStatusCode());
    }

    /**
     * The bundle isn't the only way to build this controller: its constructor
     * takes plain PSR-17 interfaces, so any implementation — here Guzzle's,
     * the bundle's own Guzzle-backed default and a root dev dependency —
     * wires up directly, with no DI container involved at all.
     */
    public function testControllerConstructsDirectlyFromAnyPsr17Implementation(): void
    {
        $factory = new HttpFactory();
        $kernel = CallbackKernelFactory::create(self::SECRET, $factory, $factory);
        $controller = new CallbackController($kernel, $factory, $factory);

        $request = $this->signedRequest('methods', [
            'atom' => ['type' => \Atoms\Symfony\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 5], json_decode((string) $response->getContent(), true));
    }
}
