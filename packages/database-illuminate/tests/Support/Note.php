<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate\Tests\Support;

use Illuminate\Database\Eloquent\Model;

final class Note extends Model
{
    protected $table = 'notes';

    protected $guarded = [];

    public $timestamps = false;
}
