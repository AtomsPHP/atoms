<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\Attributes\MethodsFor;
use Atoms\Client\Manifest\Manifest;

/**
 * Resolves the AtomMethods class that serves callbacks for a given Atom type.
 *
 * Resolution order for a wire type (Atom class basename, e.g. `GameRoom`):
 *
 *  1. an explicit {@see self::map()} entry keyed by the type or its Atom FQCN;
 *  2. a class registered with {@see self::registerMethodsClass()} that carries
 *     `#[MethodsFor(Atom::class)]`;
 *  3. the namespace convention `App\Atoms\GameRoom` → `App\Atoms\GameRoom\Methods`.
 *
 * A wire type resolves to an Atom FQCN either because it is already a FQCN, or
 * via a {@see self::registerTypeMap()} basename → FQCN entry — which
 * {@see self::registerManifest()} fills in from a build manifest.
 */
final class MethodsResolver
{
    /** @var array<string, class-string> explicit type|atomFqcn → methods class */
    private array $explicit = [];

    /** @var array<class-string, class-string> atomFqcn → methods class (from #[MethodsFor]) */
    private array $methodsFor = [];

    /** @var array<string, class-string> type basename → Atom FQCN */
    private array $typeMap = [];

    /**
     * Explicit overrides keyed by Atom class-string OR wire type basename.
     *
     * @param array<string, class-string> $map
     */
    public function map(array $map): self
    {
        foreach ($map as $key => $methodsClass) {
            $this->explicit[$key] = $methodsClass;
        }

        return $this;
    }

    /**
     * Register basename → Atom FQCN so wire types can resolve to a class.
     *
     * @param array<string, class-string> $map
     */
    public function registerTypeMap(array $map): self
    {
        foreach ($map as $type => $atomFqcn) {
            $this->typeMap[$type] = $atomFqcn;
            $this->typeMap[self::basename($atomFqcn)] = $atomFqcn;
        }

        return $this;
    }

    /**
     * Register every Atom in a build manifest, so wire types resolve without
     * the host having to name each Atom class itself.
     *
     * The manifest supplies the entries ({@see Manifest::typeMap()}); this is
     * the seam an adapter calls once it has loaded one, so no adapter has to
     * know either the manifest's shape or that {@see self::registerTypeMap()}
     * is where the result belongs. Loading the manifest — finding its path,
     * and deciding what an unreadable one means — stays with the adapter.
     */
    public function registerManifest(Manifest $manifest): self
    {
        return $this->registerTypeMap($manifest->typeMap());
    }

    /**
     * Register a Methods class whose `#[MethodsFor(Atom::class)]` attribute(s)
     * declare which Atom types it serves.
     *
     * @param class-string $class
     */
    public function registerMethodsClass(string $class): self
    {
        if (!class_exists($class)) {
            return $this;
        }

        foreach ((new \ReflectionClass($class))->getAttributes(MethodsFor::class) as $attribute) {
            /** @var MethodsFor $instance */
            $instance = $attribute->newInstance();
            /** @var class-string $atomClass */
            $atomClass = $instance->atomClass;
            $this->methodsFor[$atomClass] = $class;
            $this->typeMap[self::basename($atomClass)] = $atomClass;
        }

        return $this;
    }

    /**
     * @return class-string|null
     */
    public function resolve(string $type): ?string
    {
        if (isset($this->explicit[$type]) && class_exists($this->explicit[$type])) {
            return $this->explicit[$type];
        }

        $atomFqcn = $this->atomFqcn($type);

        if ($atomFqcn !== null) {
            if (isset($this->explicit[$atomFqcn]) && class_exists($this->explicit[$atomFqcn])) {
                return $this->explicit[$atomFqcn];
            }

            if (isset($this->methodsFor[$atomFqcn]) && class_exists($this->methodsFor[$atomFqcn])) {
                return $this->methodsFor[$atomFqcn];
            }

            $convention = $atomFqcn . '\\Methods';
            if (class_exists($convention)) {
                return $convention;
            }
        }

        return null;
    }

    /**
     * The Methods class the convention would expect, for error messaging.
     */
    public function expectedMethodsClass(string $type): string
    {
        return ($this->atomFqcn($type) ?? $type) . '\\Methods';
    }

    /**
     * @return class-string|null
     */
    private function atomFqcn(string $type): ?string
    {
        if (class_exists($type)) {
            /** @var class-string $type */
            return $type;
        }

        if (isset($this->typeMap[$type])) {
            return $this->typeMap[$type];
        }

        $basename = self::basename($type);

        return $this->typeMap[$basename] ?? null;
    }

    private static function basename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
