<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Console;

use Atoms\Client\AtomsConfig;
use Atoms\Laravel\Tests\TestCase;

final class CheckCommandTest extends TestCase
{
    public function testCheckSucceedsWhenAtomsConfigResolvesWithoutLeakingSecrets(): void
    {
        $previous = base64_encode(str_repeat("\x02", 32));
        config([
            'atoms.shared_secret' => self::SHARED_SECRET,
            'atoms.shared_secret_previous' => $previous,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->artisan('atoms:check')
            ->expectsOutputToContain('Atoms configuration is valid.')
            ->doesntExpectOutputToContain(self::SHARED_SECRET)
            ->doesntExpectOutputToContain($previous)
            ->assertExitCode(0);
    }

    public function testCheckFailsWithE105WhenSharedSecretIsMissing(): void
    {
        config(['atoms.shared_secret' => null]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->artisan('atoms:check')
            ->expectsOutputToContain('ATOMS-E105')
            ->assertExitCode(1);
    }

    public function testCheckFailsWithE105WhenSharedSecretIsMalformed(): void
    {
        config(['atoms.shared_secret' => 'not-a-valid-secret']);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->artisan('atoms:check')
            ->expectsOutputToContain('ATOMS-E105')
            ->assertExitCode(1);
    }

    public function testCheckFailsWithE105WhenPreviousSharedSecretIsMalformed(): void
    {
        config([
            'atoms.shared_secret' => self::SHARED_SECRET,
            'atoms.shared_secret_previous' => 'not-a-valid-secret',
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->artisan('atoms:check')
            ->expectsOutputToContain('ATOMS-E105')
            ->assertExitCode(1);
    }

    public function testCheckFailsWhenWsTicketTtlMsIsNotPositive(): void
    {
        config([
            'atoms.shared_secret' => self::SHARED_SECRET,
            'atoms.ws_ticket_ttl_ms' => 0,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->artisan('atoms:check')
            ->expectsOutputToContain('wsTicketTtlMs')
            ->assertExitCode(1);
    }
}
