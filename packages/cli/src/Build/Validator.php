<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
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
        foreach ($bundleFiles->files as $file) {
            $path = $file['relative'];
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $result = $functions->addSource($bundleFiles->contentsOf($file), $path);
            if ($result instanceof Violation) {
                $violations[] = $result;
            }
        }

        $classifier = new SymbolClassifier($discovered, $allowed, $composer->approvedNamespaces($allowed), functions: $functions);
        $vendor = null;
        if ($composer->requiredPackages() !== []) {
            $vendor = $this->vendorTree($config, $composer, $resolveVendor, $discovered, $classifier, $violations);
            if ($vendor !== null) {
                $violations = [...$violations, ...$functions->addVendorEntries($vendor->entries, $vendor->autoloadFiles)];
            }
        }
        $violations = [...$violations, ...(new ClosureWalker($discovered, $classifier))->walk()];

        // Stage 6: manifest.
        $manifest = (new ManifestGenerator($config, $discovered, $methodsResolver, $migrations))
            ->generate($bundleFiles->scoperPrefix(), $classifier->usedExtensions());

        return new ValidationResult($manifest, $bundleFiles, $violations, $vendor);
    }

    /**
     * @param (\Closure(AtomsComposerJson): VendorTree)|null $resolveVendor
     * @param list<Violation> $violations
     */
    private function vendorTree(
        AtomsJson $config,
        AtomsComposerJson $composer,
        ?\Closure $resolveVendor,
        DiscoveryResult $discovered,
        SymbolClassifier $classifier,
        array $violations,
    ): ?VendorTree {
        if ($resolveVendor !== null) {
            // A dependency may define a missing function. Check other errors before
            // running Composer. Use a copy so only the final pass records extensions.
            $preflight = (new ClosureWalker($discovered, clone $classifier))->walk();
            $blocking = array_filter([...$violations, ...$preflight], static fn (Violation $v): bool =>
                $v->isError() && $v->code !== ErrorCode::UnavailableWorkerFunction);
            if ($blocking === []) {
                try {
                    return $resolveVendor($composer);
                } catch (AtomsError $error) {
                    if ($error->errorCode !== ErrorCode::VendorResolutionFailed) {
                        throw $error;
                    }
                    $messages = [$error->getMessage()];
                    foreach ($preflight as $finding) {
                        if ($finding->code === ErrorCode::UnavailableWorkerFunction) {
                            $messages[] = $finding->locatedMessage();
                        }
                    }
                    throw new AtomsError($error->errorCode, implode("\n", $messages), $error);
                }
            }
        }

        return (new VendorStage(new SymfonyProcessRunner()))->cached($config->rootDir);
    }
}
