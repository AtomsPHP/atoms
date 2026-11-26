<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Cli\Build\TargetSyntax;

/** The symbol inventories and exact PHP version measured from the selected Worker. */
final readonly class WorkerPhpPlatform
{
    public string $version;
    public string $extensionsFile;
    public string $functionsFile;

    public function __construct(string $series)
    {
        $resources = __DIR__ . '/../../resources/';
        $suffix = $series === TargetSyntax::PHP_SERIES ? '' : '-' . $series;
        $this->extensionsFile = $resources . 'runtime-extensions' . $suffix . '.json';
        $this->functionsFile = $resources . 'runtime-functions' . $suffix . '.json';

        /** @var array{extensions: array{core: string}} $inventory */
        $inventory = json_decode((string) file_get_contents($this->extensionsFile), true, 512, JSON_THROW_ON_ERROR);
        $this->version = $inventory['extensions']['core'];
    }
}
