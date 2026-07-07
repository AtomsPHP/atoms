<?php

declare(strict_types=1);

namespace Atoms\Cli\Ai;

use Atoms\Cli\Config\AtomsJson;
use Atoms\Errors\ErrorCatalog;

/**
 * `atoms ai:install`: write the four agent skills into `.claude/skills/` (and a
 * marked section into AGENTS.md), regenerating only the content between
 * `<!-- atoms:generated -->` markers so hand edits outside them survive. Three
 * skills come from static templates with a generated block (the error catalog,
 * project atoms, environments); atoms-project-context is generated wholesale from
 * the manifest.
 */
final class SkillInstaller
{
    public const MARKER_START = '<!-- atoms:generated -->';
    public const MARKER_END = '<!-- /atoms:generated -->';

    private const TEMPLATE_DIR = __DIR__ . '/../../resources/skills';

    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly AtomsJson $config,
        private readonly array $manifest,
    ) {
    }

    /**
     * @return list<string> paths written
     */
    public function install(): array
    {
        $written = [];
        $written[] = $this->installFromTemplate('atoms-authoring', $this->errorCatalogTable());
        $written[] = $this->installFromTemplate('atoms-testing', $this->projectAtoms());
        $written[] = $this->installFromTemplate('atoms-operating', $this->environmentsTable());
        $written[] = $this->installProjectContext();
        $written[] = $this->updateAgentsMd();

        return $written;
    }

    private function installFromTemplate(string $skill, string $generated): string
    {
        $target = $this->skillPath($skill);
        $existing = @file_get_contents($target);

        if ($existing !== false && $this->hasMarkers($existing)) {
            $content = $this->spliceMarkers($existing, $generated);
        } else {
            $template = @file_get_contents(self::TEMPLATE_DIR . '/' . $skill . '/SKILL.md');
            if ($template === false) {
                throw new \RuntimeException("Missing skill template for {$skill}");
            }
            $content = $this->spliceMarkers($template, $generated);
        }

        $this->put($target, $content);

        return $target;
    }

    private function installProjectContext(): string
    {
        $skill = 'atoms-project-context';
        $target = $this->skillPath($skill);
        $generated = $this->projectContextBody();

        $existing = @file_get_contents($target);
        if ($existing !== false && $this->hasMarkers($existing)) {
            $content = $this->spliceMarkers($existing, $generated);
        } else {
            $content = $this->projectContextTemplate($generated);
        }

        $this->put($target, $content);

        return $target;
    }

    private function updateAgentsMd(): string
    {
        $path = $this->rootDir . '/AGENTS.md';
        $section = self::MARKER_START . "\n" . $this->agentsSection() . "\n" . self::MARKER_END;

        $existing = @file_get_contents($path);
        if ($existing !== false && $this->hasMarkers($existing)) {
            $content = $this->spliceMarkers($existing, $this->agentsSection());
        } elseif ($existing !== false) {
            $content = rtrim($existing) . "\n\n## Atoms\n\n" . $section . "\n";
        } else {
            $content = "# AGENTS.md\n\n## Atoms\n\n" . $section . "\n";
        }

        $this->put($path, $content);

        return $path;
    }

    // ---- generated content -------------------------------------------------

    private function errorCatalogTable(): string
    {
        $entries = ErrorCatalog::all();
        uksort($entries, 'strcmp');

        $rows = ["| Code | Title | Fix |", "| --- | --- | --- |"];
        foreach ($entries as $entry) {
            $rows[] = sprintf(
                '| `%s` | %s | %s |',
                $entry->code->value,
                self::cell($entry->title),
                self::cell($entry->fix),
            );
        }

        return implode("\n", $rows);
    }

    private function projectAtoms(): string
    {
        $atoms = $this->manifest['atoms'] ?? [];
        if (!\is_array($atoms) || $atoms === []) {
            return "_No Atom types defined yet — run `atoms make:atom GameRoom` to start._";
        }

        $lines = ['Atom types available to `AtomHarness` in this project:', ''];
        foreach ($atoms as $atom) {
            if (!\is_array($atom)) {
                continue;
            }
            $type = \is_string($atom['type'] ?? null) ? $atom['type'] : '?';
            $methods = self::methodNames($atom['methods'] ?? []);
            $lines[] = sprintf('- `%s`%s', $type, $methods === '' ? '' : ' — methods: ' . $methods);
        }

        return implode("\n", $lines);
    }

    private function environmentsTable(): string
    {
        if ($this->config->environments === []) {
            return "_No environments configured in atoms.json._";
        }

        $rows = ['| Environment | Endpoint | Region |', '| --- | --- | --- |'];
        foreach ($this->config->environments as $name => $env) {
            $rows[] = sprintf('| `%s` | %s | %s |', $name, $env['endpoint'], $env['region']);
        }

        return implode("\n", $rows);
    }

    private function projectContextBody(): string
    {
        $lines = ['## Project: `' . $this->config->project . '`', ''];

        $atoms = $this->manifest['atoms'] ?? [];
        if (!\is_array($atoms) || $atoms === []) {
            $lines[] = '_No Atoms are defined in this project yet._ Run `atoms make:atom` to create one, then re-run `atoms ai:install`.';

            return implode("\n", $lines);
        }

        $lines[] = '### Atom types';
        $lines[] = '';
        foreach ($atoms as $atom) {
            if (!\is_array($atom)) {
                continue;
            }
            $type = \is_string($atom['type'] ?? null) ? $atom['type'] : '?';
            $ws = ($atom['websocket'] ?? false) === true ? ' (websocket)' : '';
            $head = \is_array($atom['migrations'] ?? null) ? ($atom['migrations']['head'] ?? 0) : 0;
            $lines[] = sprintf('- **%s**%s — migrations head: %s', $type, $ws, \is_scalar($head) ? (string) $head : '0');
            foreach (self::methodList($atom['methods'] ?? []) as $sig) {
                $lines[] = '    - `' . $sig . '`';
            }
        }

        $lines = [...$lines, ...$this->contextSection('Methods contracts', $this->manifest['methods'] ?? [], 'atom_type')];
        $lines = [...$lines, ...$this->contextSection('AtomJobs', $this->manifest['jobs'] ?? [], null)];
        $lines = [...$lines, ...$this->sharedSection($this->manifest['shared'] ?? [])];

        $lines[] = '';
        $lines[] = '### Environments';
        $lines[] = '';
        $lines[] = $this->environmentsTable();

        return implode("\n", $lines);
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function contextSection(string $heading, mixed $items, ?string $labelKey): array
    {
        if (!\is_array($items) || $items === []) {
            return [];
        }
        $lines = ['', '### ' . $heading, ''];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $class = \is_string($item['class'] ?? null) ? $item['class'] : '?';
            $label = $labelKey !== null && \is_string($item[$labelKey] ?? null) ? ' (' . $item[$labelKey] . ')' : '';
            $lines[] = sprintf('- `%s`%s', $class, $label);
            $methods = $item['methods'] ?? null;
            if (\is_array($methods)) {
                foreach (self::methodList($methods) as $sig) {
                    $lines[] = '    - `' . $sig . '`';
                }
            }
            $params = $item['params'] ?? null;
            if (\is_array($params) && $params !== []) {
                $lines[] = '    - `(' . self::paramList($params) . ')`';
            }
        }

        return $lines;
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function sharedSection(mixed $items): array
    {
        if (!\is_array($items) || $items === []) {
            return [];
        }
        $lines = ['', '### Shared DTOs', ''];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $class = \is_string($item['class'] ?? null) ? $item['class'] : '?';
            $props = [];
            foreach ($item['properties'] ?? [] as $prop) {
                if (\is_array($prop) && \is_string($prop['name'] ?? null)) {
                    $type = \is_string($prop['type'] ?? null) ? $prop['type'] : 'mixed';
                    $props[] = $type . ' $' . $prop['name'];
                }
            }
            $lines[] = sprintf('- `%s` { %s }', $class, implode(', ', $props));
        }

        return $lines;
    }

    private function agentsSection(): string
    {
        return "This repository uses the **Atoms** platform. Agent skills for authoring, "
            . "testing, and operating Atoms live in `.claude/skills/atoms-*`. Regenerate "
            . "them (and this section) with `atoms ai:install` after every deploy — content "
            . "between the `atoms:generated` markers is overwritten; edits outside them are kept.\n\n"
            . "- `atoms-authoring` — the two-worlds model, serialization algebra, migrations, the error catalog.\n"
            . "- `atoms-testing` — AtomHarness, `Atoms::fake()`, what not to mock.\n"
            . "- `atoms-operating` — the validate→build→diff→deploy loop, expand/contract, rollback.\n"
            . "- `atoms-project-context` — this project's Atom types, contracts, and environments.";
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param mixed $methods
     */
    private static function methodNames(mixed $methods): string
    {
        if (!\is_array($methods)) {
            return '';
        }
        $names = [];
        foreach ($methods as $method) {
            if (\is_array($method) && \is_string($method['name'] ?? null)) {
                $names[] = $method['name'];
            }
        }

        return implode(', ', $names);
    }

    /**
     * @param mixed $methods
     * @return list<string>
     */
    private static function methodList(mixed $methods): array
    {
        if (!\is_array($methods)) {
            return [];
        }
        $out = [];
        foreach ($methods as $method) {
            if (!\is_array($method) || !\is_string($method['name'] ?? null)) {
                continue;
            }
            $return = \is_string($method['return'] ?? null) ? $method['return'] : 'mixed';
            $out[] = sprintf('%s(%s): %s', $method['name'], self::paramList($method['params'] ?? []), $return);
        }

        return $out;
    }

    /**
     * @param mixed $params
     */
    private static function paramList(mixed $params): string
    {
        if (!\is_array($params)) {
            return '';
        }
        $out = [];
        foreach ($params as $param) {
            if (!\is_array($param) || !\is_string($param['name'] ?? null)) {
                continue;
            }
            $type = \is_string($param['type'] ?? null) ? $param['type'] : 'mixed';
            $out[] = $type . ' $' . $param['name'];
        }

        return implode(', ', $out);
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    private function skillPath(string $skill): string
    {
        return $this->rootDir . '/.claude/skills/' . $skill . '/SKILL.md';
    }

    private function hasMarkers(string $content): bool
    {
        return str_contains($content, self::MARKER_START) && str_contains($content, self::MARKER_END);
    }

    private function spliceMarkers(string $content, string $generated): string
    {
        $start = strpos($content, self::MARKER_START);
        $end = strpos($content, self::MARKER_END);
        if ($start === false || $end === false || $end < $start) {
            return $content;
        }

        $before = substr($content, 0, $start + \strlen(self::MARKER_START));
        $after = substr($content, $end);

        return $before . "\n" . $generated . "\n" . $after;
    }

    private function projectContextTemplate(string $generated): string
    {
        $front = "---\nname: atoms-project-context\n"
            . "description: This project's Atom types, Methods contracts, AtomJobs, Shared DTOs, and environments — generated from the manifest. Read before working with any Atom in this repo.\n---\n\n"
            . "# Project context\n\n"
            . "_Generated by `atoms ai:install` from the current manifest. Do not edit between the markers; re-run after changes._\n\n";

        return $front . self::MARKER_START . "\n" . $generated . "\n" . self::MARKER_END . "\n";
    }

    private function put(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}");
        }
        file_put_contents($path, $contents);
    }
}
