<?php

declare(strict_types=1);

// A Composer "files"-autoload style function file: loaded eagerly by the
// vendor autoloader, never reachable through any class autoloader.
if (!function_exists('acme_greeting')) {
    function acme_greeting(): string
    {
        return 'greetings from a vendor function file';
    }
}
