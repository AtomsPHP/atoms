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
use Atoms\Client\Internal\ErrorFrame;
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
 * The monolith-side RPC client. Turns typed method calls into HTTP invocations
 * of the Worker's `POST /invoke/{type}/{id}/{method}` route, mapping error
 * frames to the {@see Exception} taxonomy and retrying only where the contract
 * says it is safe.
 *
 * The deployed Worker is single-tenant — one Cloudflare account, one Worker,
 * one set of Atoms — so routes carry no tenant prefix. Every
 * call carries `Authorization: Bearer` with {@see AtomsConfig::bearerToken()},
 * derived from the shared secret; the Worker derives the same value from its
 * own copy and compares it.
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
     * Platform wire codes the contract marks retryable that have no exception of
     * their own, so retryability has to be decided when the fallback
     * {@see AtomsRequestFailed} is constructed.
     *
     * Deliberately shorter than the contract's full retryable list: every other
     * retryable code reaches an explicit arm of {@see self::mapError()}'s switch
     * and never this one, and the exception that arm builds already carries
     * `retryable: true` (`rate_limited`/`capacity_refused` → {@see CapacityRefused},
     * `machine_unavailable`/`directory_unavailable` → {@see PlatformUnavailable},
     * `turn_deadline_exceeded` → {@see TurnDeadlineExceeded}, which the caller
     * opts into per call site). Listing them here as well would state the same
     * fact in a second place, where nothing reads it.
     */
    private const RETRYABLE_UNMAPPED_CODES = [
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
     * Declared return type is `object` so the `@return T` below can stand without
     * claiming `AtomProxy` is a subtype of every Atom class; the value really is an
     * {@see AtomProxy}.
     *
     * @template T of object
     *
     * @param class-string<T> $atomClass
     *
     * @return T
     */
    public function get(string $atomClass, string $id, ?CallOptions $options = null): object
    {
        /** @var T $proxy */
        $proxy = $this->newProxy($atomClass, $id, $options);

        return $proxy;
    }

    /**
     * Declared `object` rather than `AtomProxy` for the same reason as
     * {@see self::get()}.
     *
     * @param class-string $atomClass
     */
    private function newProxy(string $atomClass, string $id, ?CallOptions $options): object
    {
        return new AtomProxy($this, $atomClass, self::wireType($atomClass), $id, $options);
    }

    /**
     * The WebSocket URL for one Atom, derived from the configured endpoint.
     *
     * The ticket is passed in rather than minted here, so an issuance failure
     * stays visible at the call site. A `channels` value given as a list is joined
     * with commas (the form the Worker parses); every other key passes through as
     * a connection param into `onConnect()`'s `$params`.
     *
     * @param class-string                        $atomClass
     * @param array<string, string|int|float|bool|list<string>> $query
     */
    public function wsUrl(string $atomClass, string $id, array $query = []): string
    {
        $url = sprintf(
            '%s/ws/%s/%s',
            $this->config->wsBaseUrl(),
            rawurlencode(self::wireType($atomClass)),
            rawurlencode($id),
        );

        if ($query === []) {
            return $url;
        }

        $flat = [];
        foreach ($query as $key => $value) {
            $flat[$key] = is_array($value) ? implode(',', $value) : $value;
        }

        return $url . '?' . http_build_query($flat, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Invoke $method on the Atom ($type, $id) with positional $args.
     *
     * @param list<mixed>       $args
     * @param class-string|null $atomClass When given and the method has a usable declared
     *                                     return type, the result is denormalized to that type.
     * @param bool              $retryTurnDeadline Kept separate from $options: renaming or retyping it
     *                                             would break existing named-argument callers.
     * @param CallOptions|null  $options  When given, wins for every field it carries.
     */
    public function call(
        string $type,
        string $id,
        string $method,
        array $args = [],
        ?string $atomClass = null,
        bool $retryTurnDeadline = false,
        ?CallOptions $options = null,
    ): mixed {
        $retryTurnDeadline = $options->retryTurnDeadline ?? $retryTurnDeadline;
        $normalized = [];
        foreach (array_values($args) as $arg) {
            $normalized[] = $this->serializer->normalize($arg);
        }

        $body = json_encode(['args' => $normalized], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $uri = sprintf(
            '%s/invoke/%s/%s/%s',
            $this->config->baseUrl(),
            rawurlencode($type),
            rawurlencode($id),
            rawurlencode($method),
        );

        $request = $this->baseRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Idempotency-Key', $options->idempotencyKey ?? bin2hex(($this->idGenerator)(16)))
            ->withBody($this->streamFactory->createStream($body));

        if ($options !== null && $options->traceparent !== null) {
            $request = $request->withHeader('traceparent', $options->traceparent);
        }

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
     *
     * NOTE: the Cloudflare Worker runtime does not implement `DELETE
     * /atoms/{type}/{id}` yet — it answers `not_found`, which surfaces here as
     * an {@see AtomsRequestFailed}. The method is kept because the route shape
     * is settled; do not read a successful call as proof the Atom was removed
     * until the Worker grows the route.
     */
    public function destroy(string $type, string $id): bool
    {
        $uri = sprintf(
            '%s/atoms/%s/%s',
            $this->config->baseUrl(),
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
            $frame = ErrorFrame::fromBody($decoded);

            if ($status >= 200 && $status < 300) {
                if ($frame->present) {
                    // 200-with-error-frame (e.g. a remote Atom exception).
                    throw $this->mapError($status, $frame, null, $ctx);
                }

                return $decoded;
            }

            $retryAfter = $this->retryAfterSeconds($response);
            $exception = $this->mapError($status, $frame, $retryAfter, $ctx);

            // Retryability is whatever the mapped exception says, with one
            // exception of its own: a turn deadline is retryable at the platform
            // level but only auto-retried when the call site opted in. Asking the
            // exception rather than the frame is what makes a frame carrying both
            // `remote_class` and `turn_deadline_exceeded` non-retryable — it maps
            // to a RemoteAtomException, and re-running code that threw cannot help.
            $isRetryable = $exception instanceof TurnDeadlineExceeded
                ? $retryTurnDeadline
                : $exception->retryable;

            if ($isRetryable && $attempt < $this->config->maxAttempts) {
                $this->logger?->info('Retrying Atoms invocation', [
                    'attempt' => $attempt,
                    'status' => $status,
                    'code' => $frame->code,
                ]);
                $this->backoff($attempt, $retryAfter === null ? null : $retryAfter * 1000);
                continue;
            }

            throw $exception;
        }
    }

    /**
     * Map one already-destructured error frame onto the exception taxonomy. The
     * returned exception is the single source of truth for retryability — see
     * {@see self::execute()}, which asks it rather than re-reading the frame.
     *
     * @param ErrorFrame                                     $frame      The response body's `error` object, parsed once.
     * @param int|null                                       $retryAfter Retry-After in seconds, if present.
     * @param array{type: string, id: string, method: string} $ctx
     */
    private function mapError(int $status, ErrorFrame $frame, ?int $retryAfter, array $ctx): AtomsException
    {
        $code = $frame->code;
        $message = $frame->message;

        $type = $ctx['type'];
        $id = $ctx['id'];
        $method = $ctx['method'];

        if ($frame->remoteClass !== null) {
            return new RemoteAtomException(
                $type,
                $id,
                $method,
                $frame->remoteClass,
                $message,
                $frame->remoteTrace === null ? null : $this->sanitizeTrace($frame->remoteTrace),
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
            case 'misconfigured':
                // The Worker answers this on every route but /healthz while its
                // own ATOMS_SHARED_SECRET (and ATOMS_SHARED_SECRET_PREVIOUS,
                // when set) do not decode to 32 bytes of base64. Nothing the
                // app can retry, so say what to fix.
                return new AtomsRequestFailed(
                    sprintf(
                        'The Atoms Worker is not configured to serve requests%s Set ATOMS_SHARED_SECRET on the '
                        . 'Worker to the same base64-encoded 32 bytes this app holds '
                        . '(`wrangler secret put ATOMS_SHARED_SECRET`).',
                        $message === '' ? '.' : ': ' . rtrim($message, '.') . '.',
                    ),
                    $code,
                    false,
                    $status,
                );
            default:
                $retryable = in_array($code, self::RETRYABLE_UNMAPPED_CODES, true)
                    || $frame->platformRetryable;

                return new AtomsRequestFailed($message, $code, $retryable, $status);
        }
    }

    private function baseRequest(string $method, string $uri): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('traceparent', $this->traceparent ?? $this->generateTraceparent())
            ->withHeader('Authorization', 'Bearer ' . $this->config->bearerToken());

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

    /**
     * The wire `{type}` for an Atom class: its basename. Public because
     * {@see self::wsUrl()}, the Laravel manager and the testing fake need the same
     * rule without going through {@see self::get()}.
     */
    public static function wireType(string $atomClass): string
    {
        $pos = strrpos($atomClass, '\\');

        return $pos === false ? $atomClass : substr($atomClass, $pos + 1);
    }
}
