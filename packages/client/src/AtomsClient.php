<?php

declare(strict_types=1);

namespace Atoms\Client;

use Atoms\Client\Exception\AtomNotDeployed;
use Atoms\Client\Exception\AtomsException;
use Atoms\Client\Exception\AtomsRequestFailed;
use Atoms\Client\Exception\CapacityRefused;
use Atoms\Client\Exception\InvalidRequest;
use Atoms\Client\Exception\PlatformUnavailable;
use Atoms\Client\Exception\RemoteAtomException;
use Atoms\Client\Exception\TurnDeadlineExceeded;
use Atoms\Client\Manifest\Manifest;
use Atoms\Client\Manifest\ManifestLoader;
use Atoms\Serialization\Serializer;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The monolith-side RPC client. Turns typed method calls into signed HTTP
 * invocations of the platform's `POST /v1/{customer}/invoke/...` contract,
 * mapping platform error frames to the {@see Exception} taxonomy and retrying
 * only where the contract says it is safe.
 */
final class AtomsClient
{
    private readonly Serializer $serializer;

    private ?Manifest $manifest = null;

    private ?string $traceparent = null;

    /** @var callable(int): string */
    private $idGenerator;

    /** @var callable(int): void */
    private $sleep;

    /**
     * Platform wire codes the contract marks retryable, excluding
     * turn_deadline_exceeded which is opt-in per call site.
     */
    private const RETRYABLE_CODES = [
        'rate_limited',
        'capacity_refused',
        'directory_unavailable',
        'machine_unavailable',
        'deploy_in_progress',
        'internal',
    ];

    /**
     * @param callable(int): void|null   $sleep       Receives a delay in milliseconds; defaults to usleep().
     * @param callable(int): string|null $idGenerator Receives a byte count, returns that many random bytes; for tests.
     */
    public function __construct(
        private readonly AtomsConfig $config,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?LoggerInterface $logger = null,
        ?callable $sleep = null,
        ?callable $idGenerator = null,
        ?Serializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new Serializer();
        $this->idGenerator = $idGenerator ?? static fn (int $bytes): string => random_bytes($bytes);
        $this->sleep = $sleep ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };

        if ($this->config->manifestPath !== null && is_file($this->config->manifestPath)) {
            try {
                $this->manifest = (new ManifestLoader())->load($this->config->manifestPath);
            } catch (\Throwable $e) {
                $this->logger?->warning('Failed to load Atoms manifest', [
                    'path' => $this->config->manifestPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Override the W3C traceparent for subsequent calls (e.g. to stitch into an
     * inbound APM trace). Pass null to resume auto-generation per call.
     */
    public function setTraceparent(?string $traceparent): void
    {
        $this->traceparent = $traceparent;
    }

    /**
     * Return a proxy bound to $atomClass and $id whose method calls become RPC
     * invocations. The wire {type} is the class basename.
     *
     * @param class-string $atomClass
     */
    public function get(string $atomClass, string $id): AtomProxy
    {
        return new AtomProxy($this, $atomClass, self::basename($atomClass), $id);
    }

    /**
     * Invoke $method on the Atom ($type, $id) with positional $args.
     *
     * @param list<mixed>       $args
     * @param class-string|null $atomClass When given and the method has a usable
     *                                     declared return type, the result is
     *                                     denormalized to that type.
     */
    public function call(
        string $type,
        string $id,
        string $method,
        array $args = [],
        ?string $atomClass = null,
        bool $retryTurnDeadline = false,
    ): mixed {
        $normalized = [];
        foreach (array_values($args) as $arg) {
            $normalized[] = $this->serializer->normalize($arg);
        }

        $body = json_encode(['args' => $normalized], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $uri = sprintf(
            '%s/v1/%s/invoke/%s/%s/%s',
            $this->config->baseUrl(),
            rawurlencode($this->config->customer),
            rawurlencode($type),
            rawurlencode($id),
            rawurlencode($method),
        );

        $request = $this->baseRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Idempotency-Key', bin2hex(($this->idGenerator)(16)))
            ->withBody($this->streamFactory->createStream($body));

        $decoded = $this->execute($request, $retryTurnDeadline, [
            'type' => $type,
            'id' => $id,
            'method' => $method,
        ]);

        $result = array_key_exists('result', $decoded) ? $decoded['result'] : null;

        if ($atomClass !== null) {
            $returnType = $this->declaredReturnType($atomClass, $method);
            if ($returnType !== null) {
                return $this->serializer->denormalize($result, $returnType);
            }
        }

        return $result;
    }

    /**
     * Explicitly destroy an Atom. Idempotent: returns false if it did not exist.
     */
    public function destroy(string $type, string $id): bool
    {
        $uri = sprintf(
            '%s/v1/%s/atoms/%s/%s',
            $this->config->baseUrl(),
            rawurlencode($this->config->customer),
            rawurlencode($type),
            rawurlencode($id),
        );

        $decoded = $this->execute($this->baseRequest('DELETE', $uri), false, [
            'type' => $type,
            'id' => $id,
            'method' => '',
        ]);

        return (bool) ($decoded['destroyed'] ?? false);
    }

    /**
     * Send a request with the contract's retry policy and return the decoded
     * success body.
     *
     * @param array{type: string, id: string, method: string} $ctx
     * @return array<array-key, mixed>
     */
    private function execute(RequestInterface $request, bool $retryTurnDeadline, array $ctx): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                if ($attempt < $this->config->maxAttempts) {
                    $this->backoff($attempt);
                    continue;
                }

                throw new PlatformUnavailable(
                    'Transport failure talking to the Atoms platform: ' . $e->getMessage(),
                    'transport',
                    0,
                    $e,
                );
            } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
                throw new AtomsRequestFailed(
                    'Transport failure talking to the Atoms platform: ' . $e->getMessage(),
                    'transport',
                    false,
                    0,
                    $e,
                );
            }

            $status = $response->getStatusCode();
            $decoded = $this->decodeBody((string) $response->getBody());

            if ($status >= 200 && $status < 300) {
                if (isset($decoded['error']) && is_array($decoded['error'])) {
                    // 200-with-error-frame (e.g. a remote Atom exception).
                    throw $this->mapError($status, $decoded, null, $ctx);
                }

                return $decoded;
            }

            $retryAfter = $this->retryAfterSeconds($response);
            $exception = $this->mapError($status, $decoded, $retryAfter, $ctx);

            $code = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['code'] ?? '') : '';
            $isRetryable = $code === 'turn_deadline_exceeded' ? $retryTurnDeadline : $exception->retryable;

            if ($isRetryable && $attempt < $this->config->maxAttempts) {
                $this->logger?->info('Retrying Atoms invocation', [
                    'attempt' => $attempt,
                    'status' => $status,
                    'code' => $code,
                ]);
                $this->backoff($attempt, $retryAfter === null ? null : $retryAfter * 1000);
                continue;
            }

            throw $exception;
        }
    }

    /**
     * @param array<array-key, mixed>                        $decoded
     * @param int|null                                       $retryAfter Retry-After in seconds, if present.
     * @param array{type: string, id: string, method: string} $ctx
     */
    private function mapError(int $status, array $decoded, ?int $retryAfter, array $ctx): AtomsException
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = (string) ($error['code'] ?? '');
        $message = (string) ($error['message'] ?? '');

        $type = (string) ($ctx['type'] ?? '');
        $id = (string) ($ctx['id'] ?? '');
        $method = (string) ($ctx['method'] ?? '');

        if (isset($error['remote_class'])) {
            return new RemoteAtomException(
                $type,
                $id,
                $method,
                (string) $error['remote_class'],
                $message,
                isset($error['remote_trace']) ? $this->sanitizeTrace((string) $error['remote_trace']) : null,
                $status,
            );
        }

        switch ($code) {
            case 'unknown_atom_type':
                return new AtomNotDeployed($type, $status);
            case 'turn_deadline_exceeded':
                return new TurnDeadlineExceeded($status);
            case 'capacity_refused':
            case 'rate_limited':
                return new CapacityRefused($message, $code, $status, $retryAfter);
            case 'machine_unavailable':
            case 'directory_unavailable':
                return new PlatformUnavailable($message, $code, $status);
            case 'invalid_request':
                return new InvalidRequest($message, $status);
            default:
                $retryable = in_array($code, self::RETRYABLE_CODES, true)
                    || ($error['retryable'] ?? false) === true;

                return new AtomsRequestFailed($message, $code, $retryable, $status);
        }
    }

    private function baseRequest(string $method, string $uri): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Authorization', 'Bearer ' . $this->config->apiKey)
            ->withHeader('traceparent', $this->traceparent ?? $this->generateTraceparent());

        if ($this->manifest !== null) {
            $request = $request->withHeader('X-Atoms-Manifest-Hash', $this->manifest->hash());
        }

        return $request;
    }

    private function backoff(int $attempt, ?int $retryAfterMs = null): void
    {
        if ($retryAfterMs !== null && $retryAfterMs > 0) {
            ($this->sleep)($retryAfterMs);

            return;
        }

        $base = $this->config->backoffBaseMs * (2 ** ($attempt - 1));
        $base = max(1, $base);

        $delay = $this->config->backoffJitter ? random_int((int) ceil($base / 2), $base) : $base;

        ($this->sleep)($delay);
    }

    /**
     * The Retry-After header value in seconds (integer form only), or null.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        if (!$response->hasHeader('Retry-After')) {
            return null;
        }

        $value = trim($response->getHeaderLine('Retry-After'));

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Resolve the declared return type of $atomClass::$method into the syntax the
     * serializer understands, or null when it is absent/unusable (void, never,
     * mixed, self/static, or a union/intersection).
     *
     * @param class-string $atomClass
     */
    private function declaredReturnType(string $atomClass, string $method): ?string
    {
        if (!class_exists($atomClass) || !method_exists($atomClass, $method)) {
            return null;
        }

        $type = (new \ReflectionMethod($atomClass, $method))->getReturnType();

        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();

        if (in_array($name, ['void', 'never', 'mixed', 'self', 'static', 'parent'], true)) {
            return null;
        }

        return ($type->allowsNull() && $name !== 'null') ? '?' . $name : $name;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeBody(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function sanitizeTrace(string $trace): string
    {
        $trace = str_replace(["\0"], '', $trace);

        return mb_strlen($trace) > 16384 ? mb_substr($trace, 0, 16384) : $trace;
    }

    private function generateTraceparent(): string
    {
        $traceId = bin2hex(($this->idGenerator)(16));
        $parentId = bin2hex(($this->idGenerator)(8));

        return sprintf('00-%s-%s-01', $traceId, $parentId);
    }

    private static function basename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
