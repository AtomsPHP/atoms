# examples/

This directory is executable documentation, not prose. Every CLASS under
`plain-php/src/` is exercised by `tests/Integration/Adapters` (the adapter
conformance suite) — the recipe in `plain-php/README.md` is only trustworthy
because the code it walks through is the same code under test. Two different
tests split that coverage: `PlainPhpAdapterConformanceTest` drives
`AtomsBootstrap`/`PlainPhpApp` through `PlainPhpApp::handleGlobals()` and
`handle()`, but substitutes suite-owned doubles for the queue bridge, the
Atom fixture and the Methods class (so it can share one case table across
every host in the suite); `PlainPhpExampleFidelityTest` is the anchor for the
three example-owned classes that substitution leaves untouched — this
directory's own `ArrayQueueBridge`, `Atoms\GameRoom\Methods`, and the
`GameRoom` Atom itself — exercising each for real, with nothing suite-owned
standing in. `ArrayQueueBridge` and `GameRoom\Methods` go through the real
`AtomsBootstrap::create()` + `PlainPhpApp::handleGlobals()` callback path;
`GameRoom` — never constructed by the callback path at all, since resolving
a methods callback never instantiates the Atom — is booted directly via
`Atoms\Testing\AtomHarness` instead. The one uncovered spot left in this
directory is `public/atoms-callback.php`'s final four lines (the SAPI emit:
`http_response_code()`, the `header()` loop, `echo`), because those talk to a
running PHP SAPI, not to `atoms/client`.

Rules for anything added here:

- Never add a framework dependency (Laravel, Symfony, Slim, Mezzio, ...). This
  is the plain-PHP integration; `packages/laravel` and `packages/symfony`
  already own their own worlds. `examples/plain-php/composer.json` may only
  require `php`, `atoms/client`, and PSR-7/17/18 implementations.
- When an example breaks the adapter conformance suite, fix the example,
  never the test. The suite is the acceptance gate for what "plain PHP" means
  here; loosening it to fit broken example code defeats the point of this
  directory existing.
- Keep fixtures (the `Atoms\Examples\PlainPhp\Atoms\GameRoom` Atom and its
  Methods class) minimal and boundary-legal — see `docs/conventions.md`
  §Serialization type algebra.
