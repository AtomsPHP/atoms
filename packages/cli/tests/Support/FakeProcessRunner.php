<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Support;

use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Process\ProcessRunner;

/**
 * In-memory {@see ProcessRunner}: records commands and answers with canned
 * results instead of spawning anything.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: ?string, env: array<string, string>, stdin: ?string}> */
    public array $runs = [];

    /** @var array<string, string> binary name => resolved path */
    public array $onPath;

    public ProcessResult $result;

    /**
     * Optional per-command override, tried before {@see $result}: given the
     * argv (and the cwd, for a fake that must materialize files where the
     * command ran — the vendor stage's composer install), return a result to
     * use, or null to fall through to $result. Lets a single fake answer two
     * different commands (e.g. `git rev-parse` and `git check-ignore`)
     * differently without complicating the common case.
     *
     * @var (\Closure(list<string>, ?string): ?ProcessResult)|null
     */
    public ?\Closure $resultFor = null;

    /**
     * @param array<string, string> $onPath
     */
    public function __construct(?ProcessResult $result = null, array $onPath = ['node' => '/usr/bin/node'])
    {
        $this->result = $result ?? new ProcessResult(0, '', '');
        $this->onPath = $onPath;
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?float $timeout = null, ?string $stdin = null): ProcessResult
    {
        $this->runs[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env, 'stdin' => $stdin];

        return ($this->resultFor !== null ? ($this->resultFor)($command, $cwd) : null) ?? $this->result;
    }

    public function runForeground(array $command, ?string $cwd = null, array $env = []): ProcessResult
    {
        $this->runs[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env, 'stdin' => null];

        return $this->result;
    }

    public function which(string $binary): ?string
    {
        return $this->onPath[$binary] ?? null;
    }
}
