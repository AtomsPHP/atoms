<?php

// Handwritten mirror of the file `atoms build` generates (VendorStage): a
// classmap + function-file loader with __DIR__-relative paths, declared by
// the manifest's top-level `vendor.autoload` key. The conformance fixture
// carries it by hand because the fixture pipeline is build-bundle.mjs, not
// `atoms build`.

call_user_func(static function () {
    $classes = [
        'Acme\\Greeter\\Greeter' => '/acme/greeter/src/Greeter.php',
    ];
    $dir = __DIR__;
    spl_autoload_register(static function ($class) use ($classes, $dir) {
        if (isset($classes[$class])) {
            require $dir . $classes[$class];
        }
    });
    foreach ([
        '/acme/greeter/functions.php',
    ] as $file) {
        require_once $dir . $file;
    }
});
