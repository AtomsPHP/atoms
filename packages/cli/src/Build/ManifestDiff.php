<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * Compares two manifests and labels each change additive / contracting /
 * breaking (integration-plan §7.3), so deploy ordering can be enforced: additive
 * Atom changes deploy Atoms-first; contractions deploy monolith-first.
 *
 * @phpstan-type Change array{label: string, detail: string}
 */
final class ManifestDiff
{
    public const ADDITIVE = 'additive';
    public const CONTRACTING = 'contracting';
    public const BREAKING = 'breaking';

    /**
     * @param array<string, mixed> $from previously-deployed manifest
     * @param array<string, mixed> $to   current tree manifest
     * @return list<Change>
     */
    public static function compare(array $from, array $to): array
    {
        $fromAtoms = self::index($from);
        $toAtoms = self::index($to);

        $changes = [];

        foreach ($toAtoms as $type => $methods) {
            if (!isset($fromAtoms[$type])) {
                $changes[] = ['label' => self::ADDITIVE, 'detail' => "new atom type {$type}"];
                continue;
            }
            foreach ($methods as $name => $signature) {
                if (!isset($fromAtoms[$type][$name])) {
                    $changes[] = ['label' => self::ADDITIVE, 'detail' => "new method {$type}::{$name}()"];
                } elseif ($fromAtoms[$type][$name] !== $signature) {
                    $changes[] = ['label' => self::BREAKING, 'detail' => "changed signature {$type}::{$name}()"];
                }
            }
        }

        foreach ($fromAtoms as $type => $methods) {
            if (!isset($toAtoms[$type])) {
                $changes[] = ['label' => self::CONTRACTING, 'detail' => "removed atom type {$type}"];
                continue;
            }
            foreach (array_keys($methods) as $name) {
                if (!isset($toAtoms[$type][$name])) {
                    $changes[] = ['label' => self::CONTRACTING, 'detail' => "removed method {$type}::{$name}()"];
                }
            }
        }

        return $changes;
    }

    /**
     * type => (method name => canonical signature string)
     *
     * @param array<string, mixed> $manifest
     * @return array<string, array<string, string>>
     */
    private static function index(array $manifest): array
    {
        $out = [];
        $atoms = $manifest['atoms'] ?? [];
        if (!\is_array($atoms)) {
            return $out;
        }

        foreach ($atoms as $atom) {
            if (!\is_array($atom) || !\is_string($atom['type'] ?? null)) {
                continue;
            }
            $type = $atom['type'];
            $out[$type] = [];
            $methods = $atom['methods'] ?? [];
            if (!\is_array($methods)) {
                continue;
            }
            foreach ($methods as $method) {
                if (!\is_array($method) || !\is_string($method['name'] ?? null)) {
                    continue;
                }
                $out[$type][$method['name']] = json_encode([
                    'params' => $method['params'] ?? [],
                    'return' => $method['return'] ?? 'mixed',
                ], JSON_THROW_ON_ERROR);
            }
        }

        return $out;
    }
}
