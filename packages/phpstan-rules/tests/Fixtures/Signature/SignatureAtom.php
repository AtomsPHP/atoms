<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Signature;

use Atoms\Atom;

final class SignatureAtom extends Atom
{
    public function good(Note $note, ?int $count, array $items, mixed $any): void
    {
    }

    public function badParam(\DateTime $when): void
    {
    }

    public function badReturn(): \App\Services\Calculator
    {
        throw new \RuntimeException('n/a');
    }

    public function ormParam(\Illuminate\Database\Eloquent\Model $model): void
    {
    }

    public function doctrineParam(\Doctrine\ORM\EntityManagerInterface $em): void
    {
    }

    protected function skippedProtected(\DateTime $when): void
    {
    }
}
