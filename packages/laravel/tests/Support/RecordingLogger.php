<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Records every log call for assertions, instead of writing anywhere. Mirrors
 * atoms/client's own Callback\Tests\RecordingLogger, kept as a small local
 * copy since packages/laravel/tests must not depend on atoms/client's test
 * sources.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
