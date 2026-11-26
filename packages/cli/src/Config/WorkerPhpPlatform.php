<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Cli\Build\TargetSyntax;
use Atoms\Cli\Release\RuntimeVersion;

/** The symbol inventories and exact PHP version measured from the selected Worker. */
final readonly class WorkerPhpPlatform
{
    public string $version;
    public string $extensionsFile;
    public string $functionsFile;

    /** @var array{extensions: array<string, string>, classes: array<string, string>} */
    public array $inventory;

    public function __construct(string $series)
    {
        $resources = __DIR__ . '/../../resources/';
        $suffix = $series === TargetSyntax::PHP_SERIES ? '' : '-' . $series;
        $this->extensionsFile = $resources . 'runtime-extensions' . $suffix . '.json';
        $this->functionsFile = $resources . 'runtime-functions' . $suffix . '.json';

        $this->inventory = self::readInventory($this->extensionsFile);
        $this->version = $this->inventory['extensions']['core'];
    }

    /** @return array{extensions: array<string, string>, classes: array<string, string>} */
    public static function readInventory(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read the Worker PHP inventory at {$path}");
        }
        /** @var array{extensions: array<string, string>, classes: array<string, string>} $inventory */
        $inventory = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $inventory;
    }

    /** Extension defining a built-in class in any supported guest. */
    public static function knownClassExtension(string $name): ?string
    {
        /** @var array<string, string>|null $classes */
        static $classes = null;
        if ($classes === null) {
            $classes = [];
            foreach (RuntimeVersion::GUEST_PHP as $series) {
                $classes += (new self($series))->inventory['classes'];
            }
        }

        return $classes[strtolower($name)] ?? null;
    }
}
