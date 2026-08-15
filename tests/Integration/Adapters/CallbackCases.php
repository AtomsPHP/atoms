<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods as GameRoomMethods;
use Atoms\Tests\Integration\Adapters\Fixtures\RecordScoreJob;
use Atoms\Tests\Integration\Adapters\Host\HostOptions;
use Atoms\Tests\Integration\Adapters\Host\HostRequest;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;

/**
 * The envelope table: ONE set of callback cases, run unmodified against
 * every host. Expected bodies/codes here are the CONTRACT — verified against
 * the real {@see \Atoms\Client\Callback\CallbackKernel} via BareKernelHost
 * while this suite was built (and re-verified whenever a row changes: run
 * BareKernelHost through {@see self::all()} and diff its output against
 * these constants — the bare kernel is the reference). If a host's actual
 * output ever disagrees with a row, that is a real finding about the host,
 * not a reason to edit the row.
 *
 * Every row's body is asserted EXACTLY (byte for byte), never by status +
 * error.code + a message substring. That distinction is load-bearing: a
 * mutation that appends garbage to every message CallbackKernel::error()
 * builds was caught mutation-testing this table specifically because a
 * status+code(+substring) check on 11 of the 15 rows let it through. Rows
 * whose message the kernel builds from the error catalog build their
 * expected body with the exact same {@see ErrorCatalog::format()} call the
 * kernel makes (same code, same context args) — so a catalog wording change
 * doesn't spuriously break this suite, while a change to the kernel's OWN
 * envelope construction (extra text, dropped field, wrong structure) still
 * fails byte-exactly. Rows whose message is NOT catalog-formatted (a literal
 * string CallbackKernel builds inline, or a SerializationException's own
 * message, or sanitize()'s "{class}: {message}") hardcode that literal —
 * there is no catalog machinery to reuse for those, so exactness is the only
 * available guard.
 *
 * @see docs/conventions.md §Callback signing for the wire shapes these rows encode.
 */
final class CallbackCases
{
    private function __construct()
    {
    }

    /**
     * @return list<CallbackCase>
     */
    public static function all(): array
    {
        return [
            self::methodsHappyAdd(),
            self::payloadReturn(),
            self::tamperedSignature(),
            self::staleTimestamp(),
            self::replayedNonce(),
            self::unknownKind(),
            self::unknownType(),
            self::unknownMethod(),
            self::argMismatch(),
            self::jobHappy(),
            self::jobClassNotAJob(),
            self::customerExceptionBoom(),
            self::malformedJsonBody(),
            self::noSigningHeaders(),
            self::byteRoundTrip(),
        ];
    }

    /**
     * Build a fully signed HostRequest — the same mechanics every case below
     * uses — exposed publicly so dedicated tests (M2/M4/S1/S4/S5/S7) and
     * future hosts never have to re-derive the envelope shape.
     */
    public static function signedRequest(
        CallbackSigner $signer,
        HostOptions $options,
        string $kind,
        string $body,
        ?int $timestampOffset = null,
        ?string $nonce = null,
        ?string $signatureOverride = null,
    ): HostRequest {
        $timestamp = (string) (time() + ($timestampOffset ?? 0));
        $nonce ??= $signer->newNonce();
        $headers = $signer->sign($timestamp, $nonce, $body, $kind);

        if ($signatureOverride !== null) {
            $headers['X-Atoms-Signature'] = $signatureOverride;
        }

        return new HostRequest('POST', $options->callbackPath, $headers, $body);
    }

    /**
     * @param list<mixed> $args
     */
    public static function methodsBody(string $atomType, string $atomId, string $method, array $args): string
    {
        return (string) json_encode([
            'atom' => ['type' => $atomType, 'id' => $atomId],
            'method' => $method,
            'args' => $args,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function jobBody(string $jobClass, array $args): string
    {
        return (string) json_encode([
            'job' => $jobClass,
            'args' => $args,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The exact `{"error":{"code":...,"message":...}}` envelope
     * CallbackKernel::json() would encode for that (code, message) pair —
     * same shape, same JSON flags (JSON_UNESCAPED_SLASHES; unicode stays
     * \u-escaped either way), so a row's expected body is only ever wrong if
     * the code or message itself is wrong.
     */
    public static function errorBody(string $code, string $message): string
    {
        return (string) json_encode(
            ['error' => ['code' => $code, 'message' => $message]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Case 1: methods happy path — add(2, 3).
     */
    private static function methodsHappyAdd(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'methods-happy-add',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 200,
            expectedBody: '{"result":5}',
        );
    }

    /**
     * Case 2: a Methods return value that is itself a Payload normalizes to
     * a JSON object keyed by promoted-constructor-parameter order.
     */
    private static function payloadReturn(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'snapshot', []);

        return new CallbackCase(
            key: 'payload-return',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 200,
            expectedBody: '{"result":{"name":"ada","score":7}}',
        );
    }

    /**
     * Case 3: a tampered signature is rejected before any customer code
     * runs. CallbackKernel::handle() calls `$this->error(401,
     * ErrorCode::CallbackSignatureInvalid)` with no explicit message, so the
     * body carries ErrorCatalog::format() with no context (the catalog
     * message for E064 has no placeholders).
     */
    private static function tamperedSignature(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'tampered-signature',
            kind: 'methods',
            build: self::builder(
                'methods',
                $body,
                signatureOverride: base64_encode(str_repeat("\x01", CallbackSigner::TAG_LENGTH_BYTES)),
            ),
            expectedStatus: 401,
            expectedBody: self::errorBody(
                ErrorCode::CallbackSignatureInvalid->value,
                ErrorCatalog::format(ErrorCode::CallbackSignatureInvalid),
            ),
        );
    }

    /**
     * Case 4: a timestamp far outside the skew window is rejected. Same
     * error() shape as case 3, ErrorCode::CallbackReplayDetected instead.
     */
    private static function staleTimestamp(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'stale-timestamp',
            kind: 'methods',
            build: self::builder('methods', $body, timestampOffset: -4000),
            expectedStatus: 401,
            expectedBody: self::errorBody(
                ErrorCode::CallbackReplayDetected->value,
                ErrorCatalog::format(ErrorCode::CallbackReplayDetected),
            ),
        );
    }

    /**
     * Case 5: the same signed request sent twice — the second send is a
     * replay. primeFirst tells the test runner to send the one built
     * HostRequest through handle() twice, so both sends share one nonce.
     * Rejected via the same `$this->error(401, ErrorCode::CallbackReplayDetected)`
     * call as case 4 — identical expected body.
     */
    private static function replayedNonce(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'replayed-nonce',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 401,
            expectedBody: self::errorBody(
                ErrorCode::CallbackReplayDetected->value,
                ErrorCatalog::format(ErrorCode::CallbackReplayDetected),
            ),
            primeFirst: true,
        );
    }

    /**
     * Case 6: a kind the kernel does not recognize. The message here is
     * CallbackKernel::handle()'s own inline literal ("Unknown callback kind
     * '{$kind}'.") — NOT run through ErrorCatalog::format() — so it is
     * hardcoded exactly as the kernel builds it, not derived from the
     * catalog.
     */
    private static function unknownKind(): CallbackCase
    {
        return new CallbackCase(
            key: 'unknown-kind',
            kind: 'raw',
            build: self::builder('bogus', '{}'),
            expectedStatus: 422,
            expectedBody: self::errorBody(
                ErrorCode::NoMethodsClassForCallback->value,
                "Unknown callback kind 'bogus'.",
            ),
        );
    }

    /**
     * Case 7: an Atom type with no resolvable Methods class.
     * MethodsResolver::resolve('NoSuchRoom') returns null (it is not a real
     * class and nothing registers it into the type map), so
     * expectedMethodsClass('NoSuchRoom') falls through to
     * `$type . '\Methods'` — 'NoSuchRoom\Methods'.
     */
    private static function unknownType(): CallbackCase
    {
        $type = 'NoSuchRoom';
        $body = self::methodsBody($type, 'x', 'add', []);

        return new CallbackCase(
            key: 'unknown-type',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 422,
            expectedBody: self::errorBody(
                ErrorCode::NoMethodsClassForCallback->value,
                ErrorCatalog::format(ErrorCode::NoMethodsClassForCallback, [
                    'atomType' => $type,
                    'expectedClass' => $type . '\\Methods',
                ]),
            ),
        );
    }

    /**
     * Case 8: a resolvable Methods class with no such method.
     */
    private static function unknownMethod(): CallbackCase
    {
        $type = 'GameRoom';
        $method = 'nope';
        $body = self::methodsBody($type, 'g-1', $method, []);

        return new CallbackCase(
            key: 'unknown-method',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 422,
            expectedBody: self::errorBody(
                ErrorCode::UnknownMethodsMethod->value,
                ErrorCatalog::format(ErrorCode::UnknownMethodsMethod, [
                    'atom' => $type,
                    'method' => $method,
                    'methodsClass' => GameRoomMethods::class,
                ]),
            ),
        );
    }

    /**
     * Case 9: argument type mismatch against the declared signature. The
     * message is Serializer's own SerializationException text
     * (`sprintf('Cannot denormalize %s into %s.', ...)`), not catalog-
     * formatted (no "ATOMS-E024:" prefix, no "Fix:" suffix) — hardcoded
     * exactly as Serializer::denormalize() builds it for a string 'x'
     * denormalized as int.
     */
    private static function argMismatch(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', ['x', 3]);

        return new CallbackCase(
            key: 'arg-mismatch',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 422,
            expectedBody: self::errorBody(
                ErrorCode::BoundaryTypeMismatch->value,
                "Cannot denormalize 'x' into int.",
            ),
        );
    }

    /**
     * Case 10: job happy path — reconstructed and handed to the queue bridge.
     */
    private static function jobHappy(): CallbackCase
    {
        $body = self::jobBody(RecordScoreJob::class, ['playerId' => 'p1', 'score' => 100]);

        return new CallbackCase(
            key: 'job-happy',
            kind: 'job',
            build: self::builder('job', $body),
            expectedStatus: 200,
            expectedBody: '{"queued":true}',
            appliesTo: ['queue'],
            expectQueuedJob: true,
        );
    }

    /**
     * Case 11: a job class that is not an AtomJob subclass.
     */
    private static function jobClassNotAJob(): CallbackCase
    {
        $class = \stdClass::class;
        $body = self::jobBody($class, []);

        return new CallbackCase(
            key: 'job-class-not-a-job',
            kind: 'job',
            build: self::builder('job', $body),
            expectedStatus: 422,
            expectedBody: self::errorBody(
                ErrorCode::NotAnAtomJob->value,
                ErrorCatalog::format(ErrorCode::NotAnAtomJob, [
                    'atom' => 'callback',
                    'class' => $class,
                ]),
            ),
        );
    }

    /**
     * Case 12: customer code throws — sanitized 500, plus an error log entry
     * (S5 re-drives this to check the log record's context keys). The 500
     * body is `sanitize($e)` — "{class}: {message}" with control characters
     * stripped — under the non-catalog fallback wire code "internal", NOT an
     * ATOMS-E### catalog code. {@see Fixtures\GameRoom\Methods::boom()}
     * throws `new \RuntimeException('boom')`, so the exact message is fixed:
     * "RuntimeException: boom". This is unaffected by the queue-bridge
     * Throwable path's opaque-message change in CallbackKernel::handleJob()
     * — that is a different catch block (job enqueue, not a Methods call)
     * with no row in this table.
     */
    private static function customerExceptionBoom(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'boom', []);

        return new CallbackCase(
            key: 'customer-exception-boom',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 500,
            expectedBody: self::errorBody('internal', 'RuntimeException: boom'),
            expectLogError: true,
        );
    }

    /**
     * Case 13: a correctly signed but syntactically invalid JSON body. The
     * message is CallbackKernel::handle()'s own inline literal, reusing
     * ErrorCode::CallbackSignatureInvalid (E064) as its wire code even
     * though this isn't a signature failure — that reuse is existing kernel
     * behavior, verified against BareKernelHost, not something this row
     * invents.
     */
    private static function malformedJsonBody(): CallbackCase
    {
        return new CallbackCase(
            key: 'malformed-json-body',
            kind: 'raw',
            build: self::builder('methods', '{'),
            expectedStatus: 400,
            expectedBody: self::errorBody(
                ErrorCode::CallbackSignatureInvalid->value,
                'Callback body was not valid JSON.',
            ),
        );
    }

    /**
     * Case 14: no signing headers at all — empty signature header fails to
     * base64-decode, so this hits the exact same `$this->error(401,
     * ErrorCode::CallbackSignatureInvalid)` call (no explicit message) as
     * case 3 — identical expected body.
     */
    private static function noSigningHeaders(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'no-signing-headers',
            kind: 'raw',
            build: static fn (CallbackSigner $signer, HostOptions $options): HostRequest =>
                new HostRequest('POST', $options->callbackPath, [], $body),
            expectedStatus: 401,
            expectedBody: self::errorBody(
                ErrorCode::CallbackSignatureInvalid->value,
                ErrorCatalog::format(ErrorCode::CallbackSignatureInvalid),
            ),
        );
    }

    /**
     * Case 15: byte-preservation proof. The body is built by hand (not via
     * json_encode's defaults) so it carries a raw '/', a raw 'é', and a
     * trailing newline — all inside the (unread) `atom.id` field — and is
     * signed over those EXACT bytes. If a host re-encoded the body anywhere
     * on its way to the kernel, the signature would no longer verify and
     * this would fail with 401 ATOMS-E064 instead of 200.
     */
    private static function byteRoundTrip(): CallbackCase
    {
        $body = '{"atom":{"type":"GameRoom","id":"room/é"},"method":"add","args":[2,3]}' . "\n";

        return new CallbackCase(
            key: 'byte-round-trip',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 200,
            expectedBody: '{"result":5}',
        );
    }

    private static function builder(
        string $kind,
        string $body,
        ?int $timestampOffset = null,
        ?string $signatureOverride = null,
    ): \Closure {
        return static fn (CallbackSigner $signer, HostOptions $options): HostRequest =>
            self::signedRequest($signer, $options, $kind, $body, $timestampOffset, null, $signatureOverride);
    }
}
