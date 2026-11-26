<?php

declare(strict_types=1);

namespace Atoms\Http;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/** @internal Shared boundary validation for the guest and offline client. */
final class HttpRequest
{
    public static function failure(ErrorCode $code, ?string $origin = null): AtomsError
    {
        $message = ErrorCatalog::format($code);
        return new AtomsError($code, $origin === null ? $message : $message . ' Origin: ' . $origin);
    }

    /**
     * @param array<mixed> $headers
     * @return list<array{string, string}>
     */
    public static function pairs(string $method, string $url, array $headers, ?string $body, int $maxBytes): array
    {
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            || ($body !== null && ($method === 'GET' || $method === 'HEAD'))) {
            throw self::failure(ErrorCode::HttpInvalidRequest);
        }

        $size = strlen($method) + strlen($url) + strlen($body ?? '');
        if ($size > $maxBytes) {
            throw self::failure(ErrorCode::HttpSizeLimit);
        }

        $parts = parse_url($url);
        if (!preg_match('~^https://~i', $url) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('~[\x00-\x20\x7f#\\\\]~', $url) || !preg_match('//u', $url)) {
            throw self::failure(ErrorCode::HttpInvalidRequest);
        }

        $pairs = [];
        foreach ($headers as $name => $values) {
            $name = (string) $name; // PHP converts decimal header names to integer array keys.
            if (!preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)) {
                throw self::failure(ErrorCode::HttpInvalidRequest);
            }
            if (self::reservedHeader(strtolower($name))) {
                throw self::failure(ErrorCode::HttpHeaderDenied);
            }
            $values = is_string($values) ? [$values] : $values;
            if (!is_array($values) || !array_is_list($values)) {
                throw self::failure(ErrorCode::HttpInvalidRequest);
            }
            foreach ($values as $value) {
                if (!is_string($value) || !preg_match('//u', $value) || preg_match('/\p{Cc}/u', $value)) {
                    throw self::failure(ErrorCode::HttpInvalidRequest);
                }
                $size += strlen($name) + strlen($value);
                if ($size > $maxBytes) {
                    throw self::failure(ErrorCode::HttpSizeLimit);
                }
                $pairs[] = [$name, $value];
            }
        }
        return $pairs;
    }

    private static function reservedHeader(string $name): bool
    {
        foreach (['cf-', 'x-atoms-', 'proxy-', 'sec-', 'x-forwarded-', 'x-envoy-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return in_array($name, [
            'host', 'content-length', 'connection', 'keep-alive', 'te', 'trailer', 'transfer-encoding',
            'upgrade', 'http2-settings', 'forwarded', 'via', 'cdn-loop', 'x-forwarded', 'x-real-ip',
            'true-client-ip', 'x-original-url', 'x-original-host', 'x-original-uri', 'x-rewrite-url',
            'x-http-method-override', 'x-http-method', 'x-method-override',
        ], true);
    }
}
