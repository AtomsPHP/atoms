<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsJson;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUse;

/**
 * Stage 1 of the extraction pipeline: enumerate every `.php` class under the
 * Atoms path and classify it as an Atom, Methods, AtomJob, Shared DTO, or an
 * unclassifiable file (ATOMS-E001). Purely static — files are parsed, never
 * included.
 */
final class Discovery
{
    public const ATOM_BASE = 'Atoms\\Atom';
    public const METHODS_BASE = 'Atoms\\AtomMethods';
    public const JOB_BASE = 'Atoms\\AtomJob';
    public const SHARED_ATTRIBUTE = 'Atoms\\Attributes\\SharedWithAtoms';

    public function __construct(
        private readonly SourceParser $parser = new SourceParser(),
    ) {
    }

    public function discover(AtomsJson $config): DiscoveryResult
    {
        $atomsDir = $config->atomsDir();
        $sharedDir = $config->sharedDir();
        $rootDir = $config->rootDir;

        /** @var list<array{file: PhpFile, classes: list<DiscoveredClass>}> $parsed */
        $parsed = [];
        /** @var array<string, DiscoveredClass> $raw indexed by fqcn (kind Unknown initially) */
        $raw = [];

        foreach ($this->phpFiles($atomsDir) as $absolute) {
            $relative = self::relativePath($rootDir, $absolute);
            $file = $this->parser->parse($absolute, $relative);
            $fileClasses = [];
            foreach ($file->classes as $node) {
                $discovered = $this->toDiscovered($node, $absolute, $relative);
                $raw[$discovered->fqcn] = $discovered;
                $fileClasses[] = $discovered;
            }
            $parsed[] = ['file' => $file, 'classes' => $fileClasses];
        }

        // Classify now that every discovered FQCN is known (transitive parents).
        foreach ($raw as $class) {
            $class->kind = $this->classify($class, $raw, $sharedDir);
        }

        // Deterministic ordering by FQCN for stable manifests.
        ksort($raw, SORT_STRING);

        $warnings = [];
        foreach ($parsed as $entry) {
            $file = $entry['file'];
            if ($entry['classes'] === []) {
                continue;
            }
            $allUnknown = true;
            foreach ($entry['classes'] as $c) {
                if ($c->kind !== ClassKind::Unknown) {
                    $allUnknown = false;
                    break;
                }
            }
            if ($allUnknown) {
                $warnings[] = new Violation(
                    \Atoms\Errors\ErrorCode::UnclassifiableFile,
                    $file->relativePath,
                    $entry['classes'][0]->line,
                    ['file' => $file->relativePath],
                );
            }
        }

        return new DiscoveryResult(array_values($raw), $warnings);
    }

    /**
     * @param array<string, DiscoveredClass> $all
     */
    private function classify(DiscoveredClass $class, array $all, string $sharedDir): ClassKind
    {
        if ($this->extendsBase($class, self::ATOM_BASE, $all)) {
            return ClassKind::Atom;
        }

        $isMethodsByConvention = basename($class->absolutePath) === 'Methods.php';
        if ($isMethodsByConvention || $this->extendsBase($class, self::METHODS_BASE, $all)) {
            return ClassKind::Methods;
        }

        if ($this->extendsBase($class, self::JOB_BASE, $all)) {
            return ClassKind::Job;
        }

        $underShared = str_starts_with($class->absolutePath, rtrim($sharedDir, '/') . '/');
        if ($underShared || \in_array(self::SHARED_ATTRIBUTE, $class->attributes, true)) {
            return ClassKind::Shared;
        }

        return ClassKind::Unknown;
    }

    /**
     * Does $class transitively extend $base, following parent links through the
     * discovered class set (alias-aware because names are already resolved)?
     *
     * @param array<string, DiscoveredClass> $all
     */
    private function extendsBase(DiscoveredClass $class, string $base, array $all): bool
    {
        $seen = [];
        $parent = $class->parent;
        while ($parent !== null) {
            $parent = ltrim($parent, '\\');
            if ($parent === $base) {
                return true;
            }
            if (isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $parent = isset($all[$parent]) ? $all[$parent]->parent : null;
        }

        return false;
    }

    private function toDiscovered(ClassLike $node, string $absolute, string $relative): DiscoveredClass
    {
        /** @var \PhpParser\Node\Name $namespacedName */
        $namespacedName = $node->namespacedName;
        $fqcn = $namespacedName->toString();

        $parent = null;
        $interfaces = [];
        if ($node instanceof Class_) {
            if ($node->extends !== null) {
                $parent = $node->extends->toString();
            }
            foreach ($node->implements as $iface) {
                $interfaces[] = $iface->toString();
            }
        }

        $traits = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $t) {
                    $traits[] = $t->toString();
                }
            }
        }

        $attributes = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = $attr->name->toString();
            }
        }

        return new DiscoveredClass(
            fqcn: $fqcn,
            kind: ClassKind::Unknown,
            absolutePath: $absolute,
            relativePath: $relative,
            line: $node->getStartLine(),
            parent: $parent,
            interfaces: $interfaces,
            traits: $traits,
            attributes: $attributes,
            node: $node,
        );
    }

    /**
     * @return list<string> absolute paths of *.php files, excluding migration dirs
     */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile() || $info->getExtension() !== 'php') {
                continue;
            }
            $path = $info->getPathname();
            if (str_contains($path, '/migrations/')) {
                continue;
            }
            $files[] = $path;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public static function relativePath(string $rootDir, string $absolute): string
    {
        $root = rtrim($rootDir, '/') . '/';
        if (str_starts_with($absolute, $root)) {
            return substr($absolute, \strlen($root));
        }

        return $absolute;
    }
}
