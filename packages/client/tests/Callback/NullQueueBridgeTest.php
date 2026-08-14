<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\NullQueueBridge;
use Atoms\Client\Tests\Fixtures\SendWelcomeJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class NullQueueBridgeTest extends TestCase
{
    public function testEnqueueThrowsWithCatalogMessage(): void
    {
        $bridge = new NullQueueBridge();
        $job = new SendWelcomeJob('p-1', 4);

        try {
            $bridge->enqueue($job);
            self::fail('Expected AtomsError to be thrown.');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::NoQueueBridgeConfigured, $e->errorCode);
            self::assertStringContainsString('ATOMS-E103', $e->getMessage());
            self::assertStringContainsString(SendWelcomeJob::class, $e->getMessage());
        }
    }

    public function testEnqueueAppendsHintWhenProvided(): void
    {
        $bridge = new NullQueueBridge('Bind Atoms\\Laravel\\Queue\\LaravelQueueBridge.');
        $job = new SendWelcomeJob('p-1', 4);

        try {
            $bridge->enqueue($job);
            self::fail('Expected AtomsError to be thrown.');
        } catch (AtomsError $e) {
            self::assertStringEndsWith('Bind Atoms\\Laravel\\Queue\\LaravelQueueBridge.', $e->getMessage());
        }
    }

    public function testEnqueueWithoutHintDoesNotAppendTrailingSpace(): void
    {
        $bridge = new NullQueueBridge();
        $job = new SendWelcomeJob('p-1', 4);

        try {
            $bridge->enqueue($job);
            self::fail('Expected AtomsError to be thrown.');
        } catch (AtomsError $e) {
            self::assertStringEndsNotWith(' ', $e->getMessage());
        }
    }
}
