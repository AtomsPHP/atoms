<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Release\RuntimeVersion;
use PhpParser\Node;

/**
 * Stage 6: assemble the manifest — the single contract artifact for everything
 * downstream — from the discovered classes, resolved signatures, migrations, and
 * toolchain fingerprint. Emits the exact schema in conventions.md (minus
 * `content_hash`, which the BundleWriter adds after hashing the tarball).
 */
final class ManifestGenerator
{
    private const WS_HANDLERS = ['onConnect', 'onMessage', 'onDisconnect'];

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
                'core_version' => RuntimeVersion::CORE_VERSION,
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
            $entry = [
                'type' => $atom->basename(),
                'class' => $atom->fqcn,
                // Bundle-relative path of the file declaring the class. A
                // consumer that loads the bundle — the Cloudflare Worker —
                // must `require` exactly this file; re-deriving it by scanning
                // the tarball for a class declaration would duplicate, in
                // another language, work the build already did correctly.
                'file' => $atom->relativePath,
                'methods' => array_map(static fn (MethodSignature $m): array => $m->toManifest(), $methods),
            ];

            $websocket = $this->websocketFlag($atom);
            if ($websocket !== null) {
                $entry['websocket'] = $websocket;
            }

            $entry['migrations'] = $this->migrations->forAtom($atom->fqcn);

            $out[] = $entry;
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

    /**
     * The manifest's `websocket` flag, or **null to omit the key entirely**.
     *
     * The runtime reads this as: absent => allowed, `true` => allowed,
     * `false` => refuse `GET /ws/:type/:id` with 501 before any Durable Object
     * is touched. `false` is therefore a claim, not a default, and this
     * generator may only make it when it can actually see the whole class:
     *
     * - the class declares a handler or calls broadcast() on itself => `true`;
     * - the class extends `Atoms\Atom` DIRECTLY, uses no traits, and does
     *   neither => `false`;
     * - otherwise (an intermediate parent or traits) => **omitted**.
     *
     * That last case is the one this exists for. Discovery parses files, it
     * does not load classes, so for `final class Room extends BaseRoom` it
     * cannot see whether `BaseRoom` (possibly in a vendor package, possibly
     * itself extending something else) overrides `onMessage` or broadcasts.
     * Traits can also supply either capability, so they omit the key unless
     * a declared method already establishes it. Emitting `false`
     * there produced a wrongful 501 on a type whose handlers work perfectly —
     * a build-time guess breaking a runtime that would have been correct. When
     * the generator cannot see the hierarchy it declines to answer, and the
     * runtime's own dispatch decides.
     *
     * Handler names are matched case-insensitively, because PHP method names
     * are: a class declaring `onmessage()` really does override
     * `Atom::onMessage()`.
     */
    private function websocketFlag(DiscoveredClass $atom): ?bool
    {
        foreach ($atom->node->getMethods() as $method) {
            foreach (self::WS_HANDLERS as $handler) {
                if (strcasecmp($method->name->toString(), $handler) === 0) {
                    return true;
                }
            }
            if ($this->callsBroadcast($method)) {
                return true;
            }
        }

        $parent = $atom->parent === null ? null : ltrim($atom->parent, '\\');

        return $parent === Discovery::ATOM_BASE && $atom->traits === [] ? false : null;
    }

    /** Detect named calls on this Atom without executing customer code. */
    private function callsBroadcast(Node $node): bool
    {
        // These introduce another scope; closures and arrow functions can
        // retain this Atom's $this and must still be inspected.
        if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
            return false;
        }

        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
            && strcasecmp($node->name->toString(), 'broadcast') === 0) {
            return true;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && \in_array(strtolower($node->class->toString()), ['self', 'parent', 'static'], true)
            && $node->name instanceof Node\Identifier
            && strcasecmp($node->name->toString(), 'broadcast') === 0) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            foreach (\is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->callsBroadcast($child)) {
                    return true;
                }
            }
        }

        return false;
    }
}
