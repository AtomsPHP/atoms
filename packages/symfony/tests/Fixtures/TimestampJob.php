<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;

final class TimestampJob extends AtomJob
{
    public static ?string $result = null;

    public function __construct(public readonly string $label)
    {
    }

    public function handle(\DateTimeInterface $time, \DateTimeZone $zone, string $format = 'H:i'): void
    {
        $local = \DateTimeImmutable::createFromInterface($time)->setTimezone($zone);
        self::$result = $this->label . ' ' . $local->format($format);
    }
}
