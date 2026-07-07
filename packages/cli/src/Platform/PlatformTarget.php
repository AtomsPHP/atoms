<?php

declare(strict_types=1);

namespace Atoms\Cli\Platform;

use Atoms\Cli\Config\AtomsJson;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The resolved coordinates of one deploy call: the environment endpoint, the
 * project slug, and the bearer credential.
 */
final class PlatformTarget
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $project,
        public readonly string $apiKey,
        public readonly string $environment,
    ) {
    }

    /**
     * Resolve from atoms.json + an explicit/env API key.
     *
     * @throws AtomsError E070 (unknown environment) or E072 (no credential)
     */
    public static function resolve(AtomsJson $config, string $environment, ?string $apiKey): self
    {
        $env = $config->environment($environment);

        $key = $apiKey ?? getenv('ATOMS_API_KEY');
        if (!\is_string($key) || $key === '') {
            throw new AtomsError(
                ErrorCode::DeployCredentialsMissing,
                ErrorCatalog::format(ErrorCode::DeployCredentialsMissing, ['environment' => $environment]),
            );
        }

        return new self($env['endpoint'], $config->project, $key, $environment);
    }

    public function url(string $path): string
    {
        return $this->endpoint . '/v1/' . rawurlencode($this->project) . $path;
    }
}
