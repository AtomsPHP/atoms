<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * `atoms build`: validate, resolve and bundle the approved vendor packages
 * ({@see VendorStage}), then write a deterministic content-addressed bundle.
 * `--fast` skips the vendor stage — legal only for a project with no
 * atoms-composer.json dependencies (there is nothing to resolve); with
 * dependencies declared it refuses (ATOMS-E107) rather than emit a bundle
 * that would fatal in the guest.
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
        $resolveVendor = function (AtomsComposerJson $composer) use ($config, $fast): VendorTree {
            // A --fast bundle with declared dependencies would deploy cleanly
            // and fatal in the guest on the first vendor class an Atom
            // touches — a runtime failure for a fact known right here. Refuse
            // instead; the vendor cache makes the full build cheap anyway.
            if ($fast) {
                throw new AtomsError(
                    ErrorCode::FastBuildWithDependencies,
                    ErrorCatalog::format(ErrorCode::FastBuildWithDependencies, [
                        'count' => (string) \count($composer->requiredPackages()),
                    ]),
                );
            }

            $stage = new VendorStage($this->runner ?? new SymfonyProcessRunner());

            return $stage->resolve($config->rootDir);
        };
        $validation = $this->validator->validate($config, $resolveVendor);
        if (!$validation->ok()) {
            $messages = array_map(static fn (Violation $error): string => $error->locatedMessage(), $validation->errors);
            throw new AtomsError(ErrorCode::BundleRejected, implode("\n", $messages));
        }

        $vendor = $validation->vendor;
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
