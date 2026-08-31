<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\BadJob;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Deliberately broken Atom used as a parser fixture (never executed). It packs
 * one instance of each boundary/contract violation the validator must catch.
 */
final class BadRoom extends \Atoms\Atom
{
    public function boom(): void
    {
        $collection = new Collection();          // E010: framework symbol
        $user = new User();                      // E012: monolith class
        $helper = new Helper();                  // E012: unclassifiable class, never ships
        $name = config('app.name');              // E011: framework helper
        $secret = env('SECRET_KEY');             // E017: env() in Atom code
        $blob = serialize($collection);          // E018: native serialization
        $back = unserialize($blob);              // E018 (deduped by symbol)

        $this->app()->missingMethod($user);      // E030: no such Methods method
        $this->dispatch(BadJob::class, ['nope' => 1]); // E032: no such constructor parameter
    }
}
