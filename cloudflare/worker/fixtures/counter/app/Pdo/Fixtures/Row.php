<?php

declare(strict_types=1);

namespace App\Pdo\Fixtures;

/**
 * FETCH_CLASS target for the plain and constructor-args cases.
 * Declared property order matches `probe_rows`' column
 * order so hydration order under reflection is unambiguous. `$tag` is
 * unrelated to any column, so `fetchAll(FETCH_CLASS, Row::class, [$tag])`
 * exercises the constructor-args path distinctly from the plain one.
 */
final class Row
{
    public $k;
    public $i;
    public $r;
    public $s;
    public $n;
    public $tag;

    public function __construct($tag = 'no-ctor-args')
    {
        $this->tag = $tag;
    }
}
