<?php

declare(strict_types=1);

namespace Atoms\Client\Tests;

use Atoms\Client\AtomsConfig;
use PHPUnit\Framework\TestCase;

/**
 * The api key has three states and they are NOT interchangeable: a real key,
 * an explicit null ("this Worker runs with ATOMS_APP_KEY unset, auth is off"),
 * and an empty string (a misconfiguration). fromArray() must carry that
 * distinction through from loose framework config without flattening it.
 */
final class AtomsConfigTest extends TestCase
{
    public function testRealApiKeySurvivesFromArray(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'apiKey' => 'atoms_v1_real',
        ]);

        self::assertSame('atoms_v1_real', $config->apiKey);
        self::assertTrue($config->isAuthenticated());
    }

    public function testSnakeCaseApiKeyIsAccepted(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'api_key' => 'atoms_v1_real',
        ]);

        self::assertSame('atoms_v1_real', $config->apiKey);
    }

    public function testAbsentApiKeyMeansUnauthenticated(): void
    {
        $config = AtomsConfig::fromArray(['endpoint' => 'https://atoms.example.workers.dev']);

        self::assertNull($config->apiKey);
        self::assertFalse($config->isAuthenticated());
    }

    public function testExplicitNullApiKeyMeansUnauthenticated(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'apiKey' => null,
        ]);

        self::assertNull($config->apiKey);
        self::assertFalse($config->isAuthenticated());
    }

    public function testEmptyStringApiKeyThrowsFromArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty string/');

        AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev',
            'apiKey' => '',
        ]);
    }

    public function testEmptyStringApiKeyThrowsFromTheConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AtomsConfig('https://atoms.example.workers.dev', '');
    }

    public function testNullApiKeyIsAcceptedByTheConstructor(): void
    {
        $config = new AtomsConfig('https://atoms.example.workers.dev', null);

        self::assertNull($config->apiKey);
    }

    public function testBaseUrlStripsTrailingSlash(): void
    {
        $config = AtomsConfig::fromArray([
            'endpoint' => 'https://atoms.example.workers.dev/',
            'apiKey' => null,
        ]);

        self::assertSame('https://atoms.example.workers.dev', $config->baseUrl());
        self::assertSame(
            'https://atoms.example.workers.dev',
            (new AtomsConfig('https://atoms.example.workers.dev/', null))->baseUrl(),
        );
    }
}
