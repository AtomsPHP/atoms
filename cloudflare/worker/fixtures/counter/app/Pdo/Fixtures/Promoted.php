<?php

declare(strict_types=1);

namespace App\Pdo\Fixtures;

/**
 * FETCH_CLASS target with constructor-promoted properties (design §3
 * F-3/F-4, measured E13): a promoted-property constructor still runs with
 * NO arguments when `fetchAll(FETCH_CLASS, Promoted::class)` supplies none,
 * so the promoted properties' DEFAULTS overwrite whatever the hydrator just
 * wrote — measured as both properties coming back NULL.
 */
final class Promoted
{
    public function __construct(public $k = null, public $i = null)
    {
    }
}
