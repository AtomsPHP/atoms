<?php

declare(strict_types=1);

namespace Atoms\Migrations;

use Atoms\Database;

/**
 * A PHP data migration. Shipped as `NNN_name.php` returning an instance of this
 * interface; the runtime calls {@see up()} inside the activation path, under the
 * Atom's single-writer guarantee.
 */
interface Migration
{
    public function up(Database $db): void;
}
