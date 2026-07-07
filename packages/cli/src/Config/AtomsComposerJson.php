<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * atoms-composer.json — a normal composer.json restricted to `require` and
 * `repositories`. Every required package must appear on the beta allowlist
 * (resources/allowed-packages.json); anything else is ATOMS-E071.
 */
final class AtomsComposerJson
{
    /**
     * @param array<string, string> $require      package name => version constraint
     * @param list<mixed>           $repositories
     */
    private function __construct(
        public readonly array $require,
        public readonly array $repositories,
    ) {
    }

    /**
     * @return list<string> the approved namespace prefixes contributed by the
     *                      required packages (used by the symbol classifier)
     */
    public function approvedNamespaces(AllowedPackages $allowed): array
    {
        $out = [];
        foreach (array_keys($this->require) as $package) {
            foreach ($allowed->namespacesFor($package) as $prefix) {
                $out[] = ltrim($prefix, '\\');
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function requiredPackages(): array
    {
        return array_keys($this->require);
    }

    /**
     * Load atoms-composer.json from the given repo root. A missing file is
     * treated as "no dependencies" (an empty require set).
     *
     * @throws AtomsError E071
     */
    public static function locate(string $rootDir, ?AllowedPackages $allowed = null): self
    {
        $path = rtrim($rootDir, '/') . '/atoms-composer.json';
        if (!is_file($path)) {
            return new self([], []);
        }

        return self::load($path, $allowed ?? AllowedPackages::load());
    }

    /**
     * @throws AtomsError E071
     */
    public static function load(string $path, AllowedPackages $allowed): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw self::invalid("could not read {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalid('atoms-composer.json is not valid JSON: ' . $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw self::invalid('atoms-composer.json must be a JSON object');
        }

        foreach (array_keys($decoded) as $key) {
            if ($key !== 'require' && $key !== 'repositories') {
                throw self::invalid("atoms-composer.json may only contain \"require\" and \"repositories\"; found \"{$key}\"");
            }
        }

        $require = [];
        $rawRequire = $decoded['require'] ?? [];
        if (!\is_array($rawRequire)) {
            throw self::invalid('"require" must be an object');
        }
        foreach ($rawRequire as $package => $constraint) {
            if (!\is_string($package) || !\is_string($constraint)) {
                throw self::invalid('"require" entries must be string => string');
            }
            // php / ext-* pseudo-packages are always fine.
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                $require[$package] = $constraint;
                continue;
            }
            if (!$allowed->isAllowed($package)) {
                throw self::invalid("package {$package} is not on the approved Atoms package list");
            }
            $require[$package] = $constraint;
        }

        $repositories = [];
        if (isset($decoded['repositories'])) {
            if (!\is_array($decoded['repositories'])) {
                throw self::invalid('"repositories" must be an array');
            }
            $repositories = array_values($decoded['repositories']);
        }

        return new self($require, $repositories);
    }

    private static function invalid(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::AtomsComposerJsonInvalid,
            ErrorCatalog::format(ErrorCode::AtomsComposerJsonInvalid, ['reason' => $reason]),
        );
    }
}
