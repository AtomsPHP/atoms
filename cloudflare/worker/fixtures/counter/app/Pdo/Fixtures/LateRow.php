<?php

declare(strict_types=1);

namespace App\Pdo\Fixtures;

/**
 * FETCH_CLASS / FETCH_PROPS_LATE target (M1 design §3 F-3, measured E9/E13).
 * Real PDO writes declared properties BEFORE invoking the constructor
 * unless FETCH_PROPS_LATE is given — so `$order` records which happened:
 * `isset($this->k)` at construction time is true only when the hydrator
 * already ran.
 */
final class LateRow
{
    public $k;
    public $i;
    public $order;

    public function __construct()
    {
        $this->order = isset($this->k) ? 'props-first' : 'props-late';
    }
}
