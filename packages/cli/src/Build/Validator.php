<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * Shared static checks for validate and build. Standalone validation can
 * inspect a cached dependency tree. Only build can resolve a new tree.
 */
final class Validator
{
    public function __construct(
        private readonly Discovery $discovery = new Discovery(),
    ) {
    }

    /**
     * Standalone validation uses only the current on-disk Atom dependency cache.
     * Build supplies the tree it will archive, resolving dependencies on a cache miss.
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
            // Discover local errors before running Composer. Only an unresolved
            // function can acquire a provider at this stage. Attribute extensions
            // to the final resolution, where namespace fallback may differ.
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
