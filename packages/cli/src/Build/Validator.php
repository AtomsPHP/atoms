<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;

/**
 * `atoms validate`: stages 1–3 + 5 of the pipeline plus manifest generation, in
 * seconds and without touching the network or running composer. The build
 * service and `atoms build` run the identical checks, so a bundle that validates
 * locally cannot be rejected for a reason validate would not have caught.
 */
final class Validator
{
    public function __construct(
        private readonly Discovery $discovery = new Discovery(),
    ) {
    }

    public function validate(AtomsJson $config): ValidationResult
    {
        $allowed = AllowedPackages::load();
        $composer = AtomsComposerJson::locate($config->rootDir, $allowed);

        $discovered = $this->discovery->discover($config);

        $classifier = new SymbolClassifier(
            $discovered,
            $allowed,
            $composer->approvedNamespaces($allowed),
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
        $manifest = (new ManifestGenerator($config, $discovered, $methodsResolver, $migrations))
            ->generate($bundleFiles->scoperPrefix(), $classifier->usedExtensions());

        return new ValidationResult($manifest, $bundleFiles, $violations);
    }
}
