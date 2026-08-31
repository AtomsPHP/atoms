<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clean;

use Atoms\Atom;

/**
 * A fully compliant ATOM_SIDE Atom exercising db(), a Payload DTO param/return,
 * DateTimeImmutable, a BackedEnum, $this->app(), and dispatch() — asserted to
 * produce zero errors from every rule in this package.
 *
 * @template-extends Atom<Methods>
 */
final class CleanAtom extends Atom
{
    public function getPlayer(string $id): PlayerSnapshot
    {
        $this->db()->query('select 1', []);

        $snapshot = $this->app()->getPlayer($id);

        $this->dispatch(RecordResult::class, ['playerId' => $id, 'recordedAt' => new \DateTimeImmutable()]);

        $this->broadcast('room', ['id' => $id]);

        return $snapshot;
    }

    public function setStatus(Status $status): void
    {
        $this->config('atoms.status_key');
        unset($status);
    }

    protected function onActivation(): void
    {
    }
}
