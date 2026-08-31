<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * `atoms build`: validate, resolve and bundle the approved vendor packages
 * ({@see VendorStage}), then write a deterministic content-addressed bundle.
 * `--fast` skips the vendor stage (the pre-commit / no-dependencies path); a
 * project with no approved dependencies also skips it because there is
 * nothing to resolve.
 */
final class Builder
{
    public function __construct(
        private readonly Validator $validator = new Validator(),
        private readonly ?ProcessRunner $runner = null,
    ) {
    }

    public function build(AtomsJson $config, string $outDir, bool $fast = false): BuildResult
    {
        $validation = $this->validator->validate($config);
        if (!$validation->ok()) {
            throw new AtomsError(
                ErrorCode::BundleRejected,
                'Build aborted: validation found ' . \count($validation->errors) . ' error(s). Run `atoms validate`.',
            );
        }

        $composer = AtomsComposerJson::locate($config->rootDir);
        $vendor = null;
        if (!$fast && $composer->requiredPackages() !== []) {
            $stage = new VendorStage($this->runner ?? new SymfonyProcessRunner());
            $vendor = $stage->resolve($config->rootDir);
        }

        $manifest = $validation->manifest;
        if ($vendor !== null) {
            // Additive schema-1 field: where the bundled vendor autoloader
            // lives (bundle-relative, like atoms[].file) and what resolved
            // into the tree. docs/conventions.md §Manifest schema.
            $manifest['vendor'] = [
                'autoload' => VendorStage::AUTOLOAD_PATH,
                'packages' => $vendor->packages,
            ];
        }

        return (new BundleWriter())->write($outDir, $validation->bundleFiles, $manifest, $validation, $vendor);
    }
}
