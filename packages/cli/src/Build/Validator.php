<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Config\PhpTarget;

/**
 * Validate source, dependency declarations and the lock without running
 * Composer or loading application code. Builder also checks resolved vendor PHP.
 */
final class Validator
{
    public function __construct(
        private readonly Discovery $discovery = new Discovery(),
    ) {
    }

    public function validate(AtomsJson $config): ValidationResult
    {
        $target = PhpTarget::forPhp($config->php);
        $allowed = AllowedPackages::load();
        $composer = AtomsComposerJson::locate($config->rootDir, $allowed);

        $composerPath = $config->rootDir . '/atoms-composer.json';
        $lockPath = $config->rootDir . '/atoms-composer.lock';
        if (is_file($composerPath)) {
            VendorStage::assertPhpRequirements((string) file_get_contents($composerPath),
                is_file($lockPath) ? (string) file_get_contents($lockPath) : null, $target->version);
        }

        $discovered = $this->discovery->discover($config);

        $classifier = new SymbolClassifier(
            $discovered,
            $allowed,
            $composer->approvedNamespaces($allowed),
            php: $target->php,
        );

        $violations = $discovered->violations;

        // Stages 2–3: closure walk + symbol classification.
        $violations = [...$violations, ...(new ClosureWalker($discovered, $classifier))->walk()];

        // Stage 4: Atom↔Methods / Atom↔AtomJob contracts.
        $methodsResolver = new MethodsResolver($discovered);
        $contractChecker = new ContractChecker($discovered, $methodsResolver);
        foreach ($discovered->ofKind(ClassKind::Atom) as $atom) {
            $violations = [...$violations, ...$contractChecker->check($atom)];
        }

        // Stage 5: migrations.
        $migrations = (new MigrationScanner())->scan($discovered);
        $violations = [...$violations, ...$migrations->violations()];

        // Stage 6: manifest.
        $bundleFiles = BundleFileSet::collect($config, $discovered);
        $syntax = new TargetSyntax($target);
        foreach ($bundleFiles->files as $file) {
            if (str_ends_with($file['relative'], '.php')) {
                $syntax->check($bundleFiles->contentsOf($file), $file['relative']);
            }
        }
        $manifest = (new ManifestGenerator($config, $discovered, $methodsResolver, $migrations))
            ->generate($bundleFiles->scoperPrefix(), $classifier->usedExtensions());

        return new ValidationResult($manifest, $bundleFiles, $violations);
    }
}
