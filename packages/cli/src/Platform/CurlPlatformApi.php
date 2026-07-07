<?php

declare(strict_types=1);

namespace Atoms\Cli\Platform;

/**
 * ext-curl implementation of the deploy API (conventions.md: the CLI speaks the
 * deploy endpoints itself, never via atoms/client). Not exercised by the test
 * suite — tests inject a fake PlatformApi.
 */
final class CurlPlatformApi implements PlatformApi
{
    public function deploy(PlatformTarget $target, string $gzipPath): HttpResponse
    {
        $body = @file_get_contents($gzipPath);
        if ($body === false) {
            throw new \RuntimeException("Could not read bundle at {$gzipPath}");
        }

        return $this->request('POST', $target->url('/deploys'), $target->apiKey, $body, 'application/gzip');
    }

    public function deploys(PlatformTarget $target): HttpResponse
    {
        return $this->request('GET', $target->url('/deploys'), $target->apiKey);
    }

    public function rollback(PlatformTarget $target, ?string $version): HttpResponse
    {
        $payload = $version === null ? '{}' : json_encode(['version' => $version], JSON_THROW_ON_ERROR);

        return $this->request('POST', $target->url('/rollback'), $target->apiKey, $payload, 'application/json');
    }

    public function setSecret(PlatformTarget $target, string $key, string $value): HttpResponse
    {
        $payload = json_encode(['key' => $key, 'value' => $value], JSON_THROW_ON_ERROR);

        return $this->request('POST', $target->url('/secrets'), $target->apiKey, $payload, 'application/json');
    }

    public function listSecrets(PlatformTarget $target): HttpResponse
    {
        return $this->request('GET', $target->url('/secrets'), $target->apiKey);
    }

    private function request(
        string $method,
        string $url,
        string $apiKey,
        ?string $body = null,
        ?string $contentType = null,
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialise curl');
        }

        $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_TIMEOUT, 120);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException("Deploy request failed: {$error}");
        }
        $rawString = \is_string($raw) ? $raw : '';

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = json_decode($rawString, true);
        /** @var array<string, mixed> $json */
        $json = \is_array($decoded) ? $decoded : [];

        return new HttpResponse($status, $json, $rawString);
    }
}
