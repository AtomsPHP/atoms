<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsJson;

/**
 * Stage 6: assemble the manifest — the single contract artifact for everything
 * downstream — from the discovered classes, resolved signatures, migrations, and
 * toolchain fingerprint. Emits the exact schema in conventions.md (minus
 * `content_hash`, which the BundleWriter adds after hashing the tarball).
 */
final class ManifestGenerator
{
    private const WS_HANDLERS = ['onConnect', 'onMessage', 'onDisconnect'];
    private const CORE_VERSION = '0.1.0';

    public function __construct(
        private readonly AtomsJson $config,
        private readonly DiscoveryResult $discovery,
        private readonly MethodsResolver $methodsResolver,
        private readonly MigrationScanner $migrations,
    ) {
    }

    /**
     * @param list<string> $extensions
     * @return array<string, mixed>
     */
    public function generate(string $scoperPrefix, array $extensions): array
    {
        return [
            'schema' => 1,
            'project' => $this->config->project,
            'atoms' => $this->atoms(),
            'methods' => $this->methods(),
            'jobs' => $this->jobs(),
            'shared' => $this->shared(),
            'toolchain' => [
                'core_version' => self::CORE_VERSION,
                'php' => $this->config->php,
                'extensions' => $extensions,
                'scoper_prefix' => $scoperPrefix,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function atoms(): array
    {
        $out = [];
        foreach ($this->discovery->ofKind(ClassKind::Atom) as $atom) {
            $methods = SignatureReader::publicMethods($atom->node, self::WS_HANDLERS);
            $out[] = [
                'type' => $atom->basename(),
                'class' => $atom->fqcn,
                'methods' => array_map(static fn (MethodSignature $m): array => $m->toManifest(), $methods),
                'websocket' => $this->overridesWebsocket($atom),
                'migrations' => $this->migrations->forAtom($atom->fqcn),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function methods(): array
    {
        $out = [];
        foreach ($this->discovery->ofKind(ClassKind::Atom) as $atom) {
            $methodsClass = $this->methodsResolver->resolve($atom);
            if ($methodsClass === null) {
                continue;
            }
            $signatures = SignatureReader::publicMethods($methodsClass->node);
            $out[] = [
                'atom_type' => $atom->basename(),
                'class' => $methodsClass->fqcn,
                'methods' => array_map(static fn (MethodSignature $m): array => $m->toManifest(), $signatures),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobs(): array
    {
        $out = [];
        foreach ($this->discovery->ofKind(ClassKind::Job) as $job) {
            $ctor = SignatureReader::constructor($job->node);
            $params = $ctor === null
                ? []
                : array_map(static fn (ParameterSignature $p): array => $p->toManifest(), $ctor->params);
            $out[] = ['class' => $job->fqcn, 'params' => $params];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shared(): array
    {
        $out = [];
        foreach ($this->discovery->ofKind(ClassKind::Shared) as $shared) {
            $props = [];
            foreach (SignatureReader::promotedProperties($shared->node) as $prop) {
                $props[] = ['name' => $prop->name, 'type' => $prop->type];
            }
            $out[] = ['class' => $shared->fqcn, 'properties' => $props];
        }

        return $out;
    }

    private function overridesWebsocket(DiscoveredClass $atom): bool
    {
        foreach ($atom->node->getMethods() as $method) {
            if (\in_array($method->name->toString(), self::WS_HANDLERS, true)) {
                return true;
            }
        }

        return false;
    }
}
