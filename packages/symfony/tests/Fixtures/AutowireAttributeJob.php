<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An AtomJob whose handle() uses Symfony's #[Autowire] attribute for explicit
 * service id and parameter injection.
 */
final class AutowireAttributeJob extends AtomJob
{
    /** @var list<array{formatted: string, tag: string}> */
    public static array $handled = [];

    public function __construct(
        public readonly int $score,
    ) {
    }

    public function handle(
        #[Autowire(service: 'app.custom_formatter')]
        ScoreFormatter $formatter,
        #[Autowire('%app.job_tag%')]
        string $tag,
    ): void {
        self::$handled[] = [
            'formatted' => $formatter->format($this->score),
            'tag' => $tag,
        ];
    }
}
