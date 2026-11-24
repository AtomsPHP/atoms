<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class TimestampJob extends AtomJob
{
    public function __construct(public readonly string $label)
    {
    }

    public function handle(
        \DateTimeInterface $time,
        #[Autowire(service: 'test.paris')] \DateTimeZone $zone,
        #[Target('publishZone')] \DateTimeZone $publicationZone,
        LoggerInterface $logger,
        ?\DateInterval $offset,
        \DateInterval $delay = new \DateInterval('PT15M'),
        string $format = 'H:i',
    ): void {
        $time = \DateTimeImmutable::createFromInterface($time);
        if ($offset !== null) {
            $time = $time->add($offset);
        }

        $logger->info(sprintf(
            '%s %s %s',
            $this->label,
            $time->setTimezone($zone)->format($format),
            $time->add($delay)->setTimezone($publicationZone)->format($format),
        ));
    }
}
