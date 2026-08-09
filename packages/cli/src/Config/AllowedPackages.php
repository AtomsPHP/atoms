<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

/**
 * The beta package allowlist (resources/allowed-packages.json). Maps each
 * approved Composer package to the PSR-4 namespace prefixes it ships, so the
 * build classifier can tell an approved vendor symbol from a stray import.
 */
final class AllowedPackages
{
    private const RESOURCE = __DIR__ . '/../../resources/allowed-packages.json';

    /** @var array<string, list<string>> package name => namespace prefixes */
    private array $packages;

    /**
     * @param array<string, list<string>> $packages
     */
    private function __construct(array $packages)
    {
        $this->packages = $packages;
    }

    public static function load(?string $path = null): self
    {
        $path ??= self::RESOURCE;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read allowed-packages.json at {$path}");
        }

        /** @var array{packages?: list<array{name: string, namespaces: list<string>}>} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $packages = [];
        foreach ($decoded['packages'] ?? [] as $entry) {
            $packages[$entry['name']] = array_values($entry['namespaces']);
        }

        return new self($packages);
    }

    public function isAllowed(string $package): bool
    {
        return isset($this->packages[$package]);
    }

    /**
     * @return list<string>
     */
    public function namespacesFor(string $package): array
    {
        return $this->packages[$package] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->packages;
    }

    /**
     * Find the package whose namespace prefix matches $symbol, or null.
     */
    public function packageForSymbol(string $symbol): ?string
    {
        $normalized = ltrim($symbol, '\\');
        foreach ($this->packages as $package => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($normalized, ltrim($prefix, '\\'))) {
                    return $package;
                }
            }
        }

        return null;
    }
}
