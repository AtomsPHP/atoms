<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\ErrorCode;

final class Validator
{
    public function __construct(
        private readonly Discovery $discovery = new Discovery(),
    ) {
    }

    /**
     * `atoms validate` reads the local dependency cache. `atoms build` passes
     * a resolver so it can check the vendor files it will bundle.
     *
     * @param (\Closure(AtomsComposerJson): VendorTree)|null $resolveVendor
     */
    public function validate(AtomsJson $config, ?\Closure $resolveVendor = null): ValidationResult
    {
        $allowed = AllowedPackages::load();
        $composer = AtomsComposerJson::locate($config->rootDir, $allowed);

        $discovered = $this->discovery->discover($config);

        $violations = $discovered->violations;

        // Stage 4: Atom↔Methods / Atom↔AtomJob contracts.
        $methodsResolver = new MethodsResolver($discovered);
        $contractChecker = new ContractChecker($discovered, $methodsResolver);
        foreach ($discovered->ofKind(ClassKind::Atom) as $atom) {
            $violations = [...$violations, ...$contractChecker->check($atom)];
        }

        // Stage 5: migrations.
        $migrations = (new MigrationScanner())->scan($discovered);
        $violations = [...$violations, ...$migrations->violations()];

        $bundleFiles = BundleFileSet::collect($config, $discovered);
        $functions = new FunctionIndex();
        /** @var array<string, list<\PhpParser\Node>> $fileAsts */
        $fileAsts = [];
        foreach ($bundleFiles->files as $file) {
            $path = $file['relative'];
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $parsed = $functions->addSource($bundleFiles->contentsOf($file), $path);
            if ($parsed instanceof Violation) {
                $violations[] = $parsed;
            } else {
                $fileAsts[$path] = $parsed;
            }
        }

        $vendor = null;
        $needsVendor = $composer->requiredPackages() !== [];
        if ($needsVendor) {
            $vendor = (new VendorStage(new SymfonyProcessRunner()))->cached($config->rootDir);
            if ($vendor !== null) {
                $violations = [...$violations, ...$functions->addVendorEntries($vendor->entries, $vendor->autoloadFiles)];
            }
        }

        // Stages 2–3: closure walk + symbol classification.
        $classifier = new SymbolClassifier($discovered, $allowed, $composer->approvedNamespaces($allowed), functions: $functions);
        $walkViolations = (new ClosureWalker($discovered, $classifier, $fileAsts))->walk();

        if ($needsVendor && $vendor === null && $resolveVendor !== null) {
            // Only invoke Composer when no other blocking errors exist; a vendor
            // polyfill can satisfy UnavailableWorkerFunction findings.
            $blocking = array_filter(
                [...$violations, ...$walkViolations],
                static fn (Violation $v): bool => $v->isError() && $v->code !== ErrorCode::UnavailableWorkerFunction,
            );
            if ($blocking === []) {
                $vendor = $resolveVendor($composer);
                $violations = [...$violations, ...$functions->addVendorEntries($vendor->entries, $vendor->autoloadFiles)];
                $walkViolations = array_values(array_filter(
                    $walkViolations,
                    static fn (Violation $v): bool => $v->code !== ErrorCode::UnavailableWorkerFunction
                        || $v->symbol === null
                        || !$functions->has($v->symbol),
                ));
            }
        }

        $violations = [...$violations, ...$walkViolations];

        // Stage 6: manifest.
        $manifest = (new ManifestGenerator($config, $discovered, $methodsResolver, $migrations))
            ->generate($bundleFiles->scoperPrefix(), $classifier->usedExtensions());

        return new ValidationResult($manifest, $bundleFiles, $violations, $vendor);
    }
}
