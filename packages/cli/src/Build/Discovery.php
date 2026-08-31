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
 * Atoms path and classify it as an Atom, Methods, AtomJob, Shared DTO, a
 * Support class (Atom-side helper under an Atom's `support/` directory), or an
 * unclassifiable file (ATOMS-E001). Two files declaring one FQCN is
 * ATOMS-E002. Purely static — files are parsed, never included.
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
        /** @var array<string, DiscoveredClass> $raw the FQCN index, last declaration wins */
        $raw = [];
        /** @var list<Violation> $duplicates */
        $duplicates = [];

        foreach ($this->phpFiles($atomsDir) as $absolute) {
            $relative = self::relativePath($rootDir, $absolute);
            $file = $this->parser->parse($absolute, $relative);
            $fileClasses = [];
            foreach ($file->classes as $node) {
                $discovered = $this->toDiscovered($node, $absolute, $relative);
                // Two files declaring one FQCN is a collision the index cannot
                // hold, so it is reported here rather than inferred later from
                // the shape of what survived it. E002 is an error: a bundle
                // carries both files and the guest fatals on the second.
                $first = $raw[$discovered->fqcn] ?? null;
                if ($first !== null) {
                    $duplicates[] = new Violation(
                        \Atoms\Errors\ErrorCode::DuplicateClassDeclaration,
                        $discovered->relativePath,
                        $discovered->line,
                        [
                            'class' => $discovered->fqcn,
                            'first' => $first->relativePath,
                            'second' => $discovered->relativePath,
                        ],
                        $discovered->fqcn,
                    );
                }
                $raw[$discovered->fqcn] = $discovered;
                $fileClasses[] = $discovered;
            }
            $parsed[] = ['file' => $file, 'classes' => $fileClasses];
        }

        // Classify now that every discovered FQCN is known (transitive parents).
        // Driven from $parsed, not $raw: a class a later file re-declared is
        // gone from the index but is still one of the classes the warning pass
        // below reads, and an unclassified one there reads as Unknown and
        // convicts its file of ATOMS-E001.
        $supportDirs = $this->supportDirs($parsed, $raw);
        foreach ($parsed as $entry) {
            foreach ($entry['classes'] as $class) {
                $class->classifyAs($this->classify($class, $raw, $sharedDir, $supportDirs));
            }
        }

        // Deterministic ordering by FQCN for stable manifests.
        ksort($raw, SORT_STRING);

        $violations = $duplicates;
        foreach ($parsed as $entry) {
            $file = $entry['file'];
            if ($entry['classes'] === []) {
                continue;
            }
            $allUnknown = true;
            foreach ($entry['classes'] as $c) {
                if ($c->kind() !== ClassKind::Unknown) {
                    $allUnknown = false;
                    break;
                }
            }
            if ($allUnknown) {
                $violations[] = new Violation(
                    \Atoms\Errors\ErrorCode::UnclassifiableFile,
                    $file->relativePath,
                    $entry['classes'][0]->line,
                    ['file' => $file->relativePath],
                );
            }
        }

        return new DiscoveryResult(array_values($raw), $violations);
    }

    /**
     * @param array<string, DiscoveredClass> $all
     * @param list<string>                   $supportDirs
     */
    private function classify(DiscoveredClass $class, array $all, string $sharedDir, array $supportDirs): ClassKind
    {
        if ($this->extendsBase($class, self::ATOM_BASE, $all)) {
            return ClassKind::Atom;
        }

        $underSupport = false;
        foreach ($supportDirs as $dir) {
            if (str_starts_with($class->absolutePath, $dir)) {
                $underSupport = true;
                break;
            }
        }

        // The Methods.php filename convention does not reach into support
        // directories: a support file named Methods.php is support code.
        $isMethodsByConvention = !$underSupport && basename($class->absolutePath) === 'Methods.php';
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

        if ($underSupport) {
            return ClassKind::Support;
        }

        return ClassKind::Unknown;
    }

    /**
     * The `support/` directories of every discovered Atom — the sanctioned home
     * for Atom-side helper classes (an Eloquent model, a value object, a pure
     * service) that ship with the Atom but are not Atoms themselves. Sibling to
     * the Atom's migrations directory: `app/Atoms/GameRoom/support/` for
     * `app/Atoms/GameRoom.php`. Computed before the classification pass because
     * an Atom is recognizable from its parent chain alone.
     *
     * @param list<array{file: PhpFile, classes: list<DiscoveredClass>}> $parsed
     * @param array<string, DiscoveredClass>                             $all
     * @return list<string> absolute directory prefixes, trailing slash included
     */
    private function supportDirs(array $parsed, array $all): array
    {
        $dirs = [];
        foreach ($parsed as $entry) {
            foreach ($entry['classes'] as $class) {
                if ($this->extendsBase($class, self::ATOM_BASE, $all)) {
                    $dirs[] = \dirname($class->absolutePath) . '/'
                        . basename($class->absolutePath, '.php') . '/support/';
                }
            }
        }

        return $dirs;
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
