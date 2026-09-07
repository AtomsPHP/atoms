<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Support;

use Atoms\Symfony\Command\ProcessRunner;

/**
 * Records the argv it was given and returns a canned result — never spawns a
 * real subprocess.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    /**
     * The environment each call was given, positionally alongside $calls.
     *
     * @var list<array<string, string>|null>
     */
    public array $envs = [];

    /**
     * @param array{exitCode: int, stdout: string, stderr: string} $result
     */
    public function __construct(private readonly array $result = ['exitCode' => 0, 'stdout' => 'ok', 'stderr' => ''])
    {
    }

    public function run(array $command, ?array $env = null): array
    {
        $this->calls[] = $command;
        $this->envs[] = $env;

        return $this->result;
    }
}
