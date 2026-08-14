<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

use Psr\Log\AbstractLogger;

/**
 * Records every log call for assertions, instead of writing anywhere. Every
 * AdapterHost wires one of these as its PSR-3 logger so
 * {@see \Atoms\Tests\Integration\Adapters\Host\AdapterHost::logRecords()} has
 * something real to report.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
