<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\AtomJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\SerializationException;
use Atoms\Serialization\Serializer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-15 handler for the inbound platform → monolith callback channel: the
 * server side of `$this->app()` (reverse RPC into a Methods class) and
 * `$this->dispatch()` (an AtomJob handed to the app's queue).
 *
 * Every request is authenticated before any customer code runs: detached
 * Ed25519 signature over `"v1\n{ts}\n{nonce}\n{body}"`, a timestamp-skew window,
 * and single-use nonce replay protection. Failures return the platform error
 * envelope `{"error":{"code":"ATOMS-E0##","message":"..."}}`.
 */
final class CallbackKernel implements RequestHandlerInterface
{
    private readonly Serializer $serializer;

    public function __construct(
        private readonly Ed25519Verifier $verifier,
        private readonly NonceStore $nonceStore,
        private readonly MethodsResolver $resolver,
        private readonly QueueBridge $queueBridge,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly int $timestampWindow = 300,
        private readonly ?ContainerInterface $container = null,
        ?Serializer $serializer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->serializer = $serializer ?? new Serializer();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $timestamp = $request->getHeaderLine('X-Atoms-Timestamp');
        $nonce = $request->getHeaderLine('X-Atoms-Nonce');
        $signature = base64_decode($request->getHeaderLine('X-Atoms-Signature'), true);

        $signedMessage = "v1\n" . $timestamp . "\n" . $nonce . "\n" . $body;

        if ($signature === false || !$this->verifier->verify($signedMessage, $signature)) {
            return $this->error(401, ErrorCode::CallbackSignatureInvalid);
        }

        if (!$this->timestampFresh($timestamp)) {
            return $this->error(401, ErrorCode::CallbackReplayDetected);
        }

        if ($nonce === '' || $this->nonceStore->seen($nonce)) {
            return $this->error(401, ErrorCode::CallbackReplayDetected);
        }

        $kind = $request->getHeaderLine('X-Atoms-Kind');

        try {
            $payload = $this->decode($body);
        } catch (\JsonException) {
            return $this->error(400, ErrorCode::CallbackSignatureInvalid, 'Callback body was not valid JSON.');
        }

        return match ($kind) {
            'methods' => $this->handleMethods($payload),
            'job' => $this->handleJob($payload),
            default => $this->error(422, ErrorCode::NoMethodsClassForCallback, "Unknown callback kind '{$kind}'."),
        };
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function handleMethods(array $payload): ResponseInterface
    {
        $atom = is_array($payload['atom'] ?? null) ? $payload['atom'] : [];
        $type = (string) ($atom['type'] ?? '');
        $method = (string) ($payload['method'] ?? '');
        $args = is_array($payload['args'] ?? null) ? array_values($payload['args']) : [];

        $methodsClass = $this->resolver->resolve($type);
        if ($methodsClass === null) {
            return $this->error(422, ErrorCode::NoMethodsClassForCallback, ErrorCatalog::format(
                ErrorCode::NoMethodsClassForCallback,
                ['atomType' => $type, 'expectedClass' => $this->resolver->expectedMethodsClass($type)],
            ));
        }

        if (!method_exists($methodsClass, $method)) {
            return $this->error(422, ErrorCode::UnknownMethodsMethod, ErrorCatalog::format(
                ErrorCode::UnknownMethodsMethod,
                ['atom' => $type, 'method' => $method, 'methodsClass' => $methodsClass],
            ));
        }

        $instance = $this->instantiate($methodsClass);
        $reflection = new \ReflectionMethod($instance, $method);

        try {
            $callArgs = $this->serializer->denormalizeArguments($args, $reflection);
        } catch (SerializationException $e) {
            return $this->error(422, $e->errorCode, $e->getMessage());
        }

        try {
            /** @var mixed $result */
            $result = $reflection->invokeArgs($instance, $callArgs);
            $normalized = $this->serializer->normalize($result);
        } catch (SerializationException $e) {
            return $this->error(422, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger?->error('Callback Methods invocation threw', [
                'type' => $type,
                'method' => $method,
                'exception' => $e::class,
            ]);

            return $this->error(500, null, $this->sanitize($e), 'internal');
        }

        return $this->json(200, ['result' => $normalized]);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function handleJob(array $payload): ResponseInterface
    {
        $class = (string) ($payload['job'] ?? '');
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, AtomJob::class)) {
            return $this->error(422, ErrorCode::NotAnAtomJob, ErrorCatalog::format(
                ErrorCode::NotAnAtomJob,
                ['atom' => 'callback', 'class' => $class === '' ? '(none)' : $class],
            ));
        }

        try {
            $job = $this->constructJob($class, $args);
        } catch (SerializationException $e) {
            return $this->error(422, $e->errorCode, $e->getMessage());
        }

        try {
            $this->queueBridge->enqueue($job);
        } catch (AtomsError $e) {
            $this->logger?->error('Callback job enqueue failed', [
                'job' => $class,
                'code' => $e->errorCode->value,
            ]);

            return $this->error(500, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger?->error('Callback job enqueue failed', [
                'job' => $class,
                'exception' => $e::class,
            ]);

            return $this->error(500, null, $this->sanitize($e), 'internal');
        }

        return $this->json(200, ['queued' => true]);
    }

    /**
     * Reconstruct an AtomJob from named, wire-form constructor arguments.
     *
     * @param class-string<AtomJob> $class
     * @param array<array-key, mixed> $args
     */
    private function constructJob(string $class, array $args): AtomJob
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $callArgs = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $args)) {
                $type = $this->parameterType($param);
                $callArgs[] = $type === 'mixed' ? $args[$name] : $this->serializer->denormalize($args[$name], $type);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $callArgs[] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $callArgs[] = null;
                continue;
            }

            throw new SerializationException(
                ErrorCode::BoundaryTypeMismatch,
                "Missing required argument {$name} reconstructing {$class}.",
            );
        }

        return $reflection->newInstanceArgs($callArgs);
    }

    /**
     * @param class-string $class
     */
    private function instantiate(string $class): object
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var object $resolved */
            $resolved = $this->container->get($class);

            return $resolved;
        }

        return new $class();
    }

    private function parameterType(\ReflectionParameter $param): string
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();

        if ($name === 'mixed') {
            return 'mixed';
        }

        return ($type->allowsNull() && $name !== 'null') ? '?' . $name : $name;
    }

    private function timestampFresh(string $timestamp): bool
    {
        if ($timestamp === '' || !ctype_digit(ltrim($timestamp, '-'))) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $this->timestampWindow;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function sanitize(\Throwable $e): string
    {
        $message = str_replace(["\n", "\r", "\0"], ' ', $e->getMessage());

        return sprintf('%s: %s', $e::class, $message);
    }

    private function error(int $status, ?ErrorCode $code, ?string $message = null, string $fallbackCode = 'invalid_request'): ResponseInterface
    {
        $codeString = $code !== null ? $code->value : $fallbackCode;
        $message ??= $code !== null ? ErrorCatalog::format($code) : 'Callback rejected.';

        return $this->json($status, ['error' => ['code' => $codeString, 'message' => $message]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): ResponseInterface
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
