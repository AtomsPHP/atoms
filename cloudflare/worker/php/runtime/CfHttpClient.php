<?php

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;
use Atoms\Http\HttpClient;
use Atoms\Http\HttpRequest;
use Atoms\Http\HttpResponse;

final class CfHttpClient implements HttpClient
{
    public function __construct(
        private readonly SqlBridge $bridge,
        private readonly int $maxRequestBytes,
        private readonly int $maxResponseBytes,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if ($this->bridge->inTransaction()) {
            throw HttpRequest::failure(ErrorCode::HttpInTransaction);
        }

        $pairs = HttpRequest::pairs($method, $url, $headers, $body, $this->maxRequestBytes);
        $reply = host_park_raw([
            'op' => 'http.fetch',
            'method' => $method,
            'url' => $url,
            'headers' => $pairs,
            'body' => $body === null ? null : base64_encode($body),
        ]);
        if (!$reply['ok']) {
            $error = $reply['error'];
            if ($error['code'] === 'turn_deadline_exceeded') {
                throw TurnDeadlineExceeded::create($error['elapsed_ms'], $error['budget_ms']);
            }
            $code = $error['code'] === 'tx_state' ? ErrorCode::HttpInTransaction : ErrorCode::tryFrom($error['code']);
            throw HttpRequest::failure($code ?? ErrorCode::HttpTransport, $error['origin'] ?? null);
        }

        $remaining = $this->maxResponseBytes - strlen((string) $reply['status']);
        foreach ($reply['headers'] as $name => $values) {
            foreach ($values as $value) {
                $remaining -= strlen((string) $name) + strlen($value);
            }
        }
        if ($remaining < 0 || strlen($reply['body']) > 4 * ceil($remaining / 3)) {
            throw HttpRequest::failure(ErrorCode::HttpSizeLimit);
        }
        $bytes = base64_decode($reply['body'], true);
        if ($bytes === false) {
            throw HttpRequest::failure(ErrorCode::HttpTransport);
        }
        if (strlen($bytes) > $remaining) {
            throw HttpRequest::failure(ErrorCode::HttpSizeLimit);
        }
        return new HttpResponse($reply['status'], $reply['headers'], $bytes);
    }
}
