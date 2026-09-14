<?php

declare(strict_types=1);

/**
 * Autoloaded by Composer (`autoload.files` in this package's composer.json),
 * which means it runs while `vendor/autoload.php` is still being included —
 * before `bootstrap/app.php`, before any kernel, before any dotenv loader.
 *
 * That timing is the whole point, and it is why this is a `files` entry rather
 * than something a service provider does: every framework bootstrap is itself
 * autoloaded, so nothing a framework offers can run earlier than this.
 *
 * The work is one array copy of the current environment and nothing else — no
 * I/O, no reflection, no container. See
 * {@see \Atoms\Client\Deployment\CallerEnvironment} for what it is for.
 */

\Atoms\Client\Deployment\CallerEnvironment::capture();
