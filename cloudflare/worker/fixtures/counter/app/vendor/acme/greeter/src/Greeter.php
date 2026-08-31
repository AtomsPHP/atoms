<?php

declare(strict_types=1);

namespace Acme\Greeter;

// Deliberately declared INSIDE a conditional, indented: the bundle's
// line-scanning autoloader indexes declarations at line start only
// (bootstrap.php register_bundle_autoloader), so this shape is invisible to
// it. Conformance check 45 loading this class anyway is the proof that the
// manifest's vendor.autoload classmap — not the scanner — served it.
if (!class_exists(Greeter::class, false)) {
    final class Greeter
    {
        public static function greet(): string
        {
            return 'greetings from the vendor tree';
        }
    }
}
