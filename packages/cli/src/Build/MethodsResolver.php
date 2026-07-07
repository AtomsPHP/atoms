<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;

/**
 * Resolves the Methods class for an Atom, per conventions.md: a
 * `#[MethodsFor(GameRoom::class)]` attribute wins; otherwise the naming
 * convention `App\Atoms\GameRoom` → `App\Atoms\GameRoom\Methods`.
 */
final class MethodsResolver
{
    private const METHODS_FOR = 'Atoms\\Attributes\\MethodsFor';

    /** @var array<string, DiscoveredClass> atom FQCN => Methods class */
    private array $explicit = [];

    public function __construct(private readonly DiscoveryResult $discovery)
    {
        foreach ($discovery->ofKind(ClassKind::Methods) as $methods) {
            $atom = $this->methodsForTarget($methods);
            if ($atom !== null) {
                $this->explicit[ltrim($atom, '\\')] = $methods;
            }
        }
    }

    public function resolve(DiscoveredClass $atom): ?DiscoveredClass
    {
        if (isset($this->explicit[$atom->fqcn])) {
            return $this->explicit[$atom->fqcn];
        }

        return $this->discovery->get($atom->fqcn . '\\Methods');
    }

    private function methodsForTarget(DiscoveredClass $methods): ?string
    {
        foreach ($methods->node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->toString() !== self::METHODS_FOR) {
                    continue;
                }
                foreach ($attr->args as $arg) {
                    if ($arg->value instanceof ClassConstFetch && $arg->value->class instanceof Name) {
                        return $arg->value->class->toString();
                    }
                }
            }
        }

        return null;
    }
}
