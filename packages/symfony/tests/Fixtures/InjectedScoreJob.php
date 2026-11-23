<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;
use Psr\Log\LoggerInterface;

/**
 * An AtomJob whose handle() requires an autowired service, accepts an optional
 * nullable service, and declares a defaulted scalar parameter.
 */
final class InjectedScoreJob extends AtomJob
{
    /** @var list<array{playerId: string, formatted: string, logged: bool}> */
    public static array $handled = [];

    public function __construct(
        public readonly string $playerId,
        public readonly int $score,
    ) {
    }

    public function handle(
        ScoreFormatter $formatter,
        ?LoggerInterface $logger = null,
        int $multiplier = 1,
    ): void {
        $formatted = $formatter->format($this->score * $multiplier);
        $logger?->info('Handled injected score job', ['playerId' => $this->playerId]);

        self::$handled[] = [
            'playerId' => $this->playerId,
            'formatted' => $formatted,
            'logged' => $logger !== null,
        ];
    }
}
